<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Services\AssetUploadPublisher;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Support\ChunkUploadError;
use Mothership\DAM\Support\GuestIntakeLog;

/**
 * P5a (D1): the guest's side of the chunk protocol, on top of ChunkUploadService.
 *
 * - **Limits.** Count, size and type are checked at chunk init, against what the browser declared, and again at
 *   finalize, against the request as it is then and the type read from the assembled bytes. A request closed or
 *   expired in between fails the upload at finalize.
 * - **Count.** `max_files` counts the request's files across every visit. At init every guest upload still under way
 *   or finished counts; at finalize only finished ones do, checked under the request's row lock in the transaction
 *   that creates the asset, so two finalizes cannot both take the last place.
 * - **Visit.** Every upload is bound to its visit's submission (`upload_submission_id`); find() answers another
 *   visit's upload exactly like a missing one.
 * - **Placement.** The destination is always the request's intake folder. A client that names a folder, a path or an
 *   existing asset is refused (ClientPlacement), never followed.
 */
class GuestIntakeService
{
    /** Request fields a guest may never send: the destination is the server's choice (§3.3 "server-placed"). */
    public const PLACEMENT_FIELDS = ['directory_id', 'relative_path', 'preserve_root', 'path', 'existing_asset', 'existing_asset_id', 'asset_id', 'session_uuid', 'destination', 'attach_asset_id', 'role'];

    /** Chunk states that hold a place under `max_files` at init. */
    private const COUNTED_STATES = [ChunkUpload::STATE_RECEIVING, ChunkUpload::STATE_QUEUED, ChunkUpload::STATE_ASSEMBLING, ChunkUpload::STATE_COMPLETED];

    public function __construct(
        protected ChunkUploadService $chunks,
        protected AssetUploadPublisher $publisher,
    ) {}

    /**
     * Start, or resume, one file of this visit. The submission is created with the visit's first file, then reused.
     *
     * @param  array{original_name: string, mime_type?: ?string, size: int, last_modified: int, fingerprint: string, guest_name?: ?string, guest_email?: ?string}  $metadata
     */
    public function initialize(UploadRequest $request, string $nonce, array $metadata): ChunkUpload
    {
        $extension = strtolower(pathinfo($metadata['original_name'], PATHINFO_EXTENSION)) ?: null;
        $this->assertWithinLimits(request: $request, extension: $extension, mimeType: $metadata['mime_type'] ?? null, bytes: (int) $metadata['size']);

        $created = false;

        $upload = DB::transaction(function () use ($request, $nonce, $metadata, &$created): ChunkUpload {
            // Serializes every init of this request, so the count below cannot be raced past max_files.
            $locked = UploadRequest::query()->with('creator')->lockForUpdate()->where('id', $request->id)->first();

            if ($locked === null || ! $locked->isAccepting()) {
                throw self::unavailable();
            }

            $submission = $this->submissionFor(request: $locked, nonce: $nonce, metadata: $metadata, created: $created);
            $resumed = $this->chunks->resumableGuestUpload(submission: $submission, metadata: $metadata);

            if ($resumed !== null) {
                return $resumed;
            }

            if ($locked->max_files !== null && $this->countedUploads(requestId: (int) $locked->id) >= $locked->max_files) {
                throw new ChunkUploadError(409, 'request_full', false, trans('dam::app.upload-portal.errors.request-full'));
            }

            return $this->chunks->initializeGuest(metadata: $metadata, submission: $submission, request: $locked);
        });

        if ($created) {
            $this->submissionCreated(request: $request, submissionId: (int) $upload->upload_submission_id);
        }

        GuestIntakeLog::write(event: $upload->wasRecentlyCreated ? 'portal.upload.initialized' : 'portal.upload.resumed', ctx: [
            'upload_request_id' => (int) $request->id, 'submission_id' => (int) $upload->upload_submission_id, 'upload_id' => (int) $upload->id,
            'expected_bytes'    => (int) $upload->expected_bytes,
        ]);

        return $upload;
    }

    /** This visit's upload with this uuid; another visit's, or none, answers 404 the same way. */
    public function find(UploadRequest $request, string $nonce, string $uuid): ChunkUpload
    {
        $upload = ChunkUpload::query()
            ->where('uuid', $uuid)
            ->whereIn('upload_submission_id', UploadSubmission::query()->select('id')
                ->where('upload_request_id', $request->id)
                ->where('visit_hash', PortalGate::visitHash($nonce)))
            ->first();

        if ($upload === null) {
            throw new ChunkUploadError(404, 'upload_not_found', false, trans('dam::app.upload-portal.errors.upload-not-found'));
        }

        return $upload;
    }

    /**
     * What the guest sees of one upload: its progress, never where it landed, its asset id or a duplicate notice, so a
     * same-name or same-bytes file in the library is never revealed.
     *
     * @return array<string, mixed>
     */
    public function status(ChunkUpload $upload): array
    {
        $status = $this->chunks->status($upload);

        return [
            'uuid'               => $status['uuid'],
            'state'              => $status['state'],
            'expected_bytes'     => $status['expected_bytes'],
            'received_bytes'     => $status['received_bytes'],
            'finalization_bytes' => $status['finalization_bytes'],
            'chunk_size'         => $status['chunk_size'],
            'chunk_count'        => $status['chunk_count'],
            'received_indices'   => $status['received_indices'],
            'failure_code'       => $status['failure_code'],
            'expires_at'         => $status['expires_at'],
            'name'               => $upload->original_name,
        ];
    }

    /**
     * The names this visit sent, as the guest typed them (never the name the library gave them), with their state.
     *
     * @return list<array{uuid: string, name: string, state: string}>
     */
    public function visitFiles(UploadRequest $request, string $nonce): array
    {
        return ChunkUpload::query()
            ->whereIn('upload_submission_id', UploadSubmission::query()->select('id')
                ->where('upload_request_id', $request->id)
                ->where('visit_hash', PortalGate::visitHash($nonce)))
            ->whereNotIn('state', [ChunkUpload::STATE_CANCELLED, ChunkUpload::STATE_EXPIRED])
            ->orderBy('id')
            ->limit(500)
            ->get(['uuid', 'original_name', 'state'])
            ->map(fn (ChunkUpload $upload) => ['uuid' => $upload->uuid, 'name' => $upload->original_name, 'state' => $upload->state])
            ->all();
    }

    /**
     * Finalize for a guest upload, called by ChunkUploadService with the assembled file: the request must still accept
     * files and the file must still meet its limits, with the type read from the bytes; then publishIntake() creates
     * the asset, private and pending, in the intake folder.
     */
    public function publish(ChunkUpload $upload, string $disk, string $stagedPath, string $sha256, string $localPath, ?string $sourceDisk = null): Asset
    {
        $submission = UploadSubmission::query()->with('uploadRequest.creator')->where('id', $upload->upload_submission_id)->first();
        $request = $submission?->uploadRequest;

        if ($submission === null || $request === null || ! $request->isAccepting()) {
            $this->logRefusal(upload: $upload, code: 'request_unavailable');

            throw self::unavailable();
        }

        $detected = self::detectedMimeType($localPath);

        try {
            $this->assertWithinLimits(request: $request, extension: $upload->extension, mimeType: $detected, bytes: (int) $upload->expected_bytes);
        } catch (ChunkUploadError $error) {
            $this->logRefusal(upload: $upload, code: $error->errorCode);

            throw $error;
        }

        $asset = $this->publisher->publishIntake([
            'submission'    => $submission,
            'original_name' => $upload->original_name,
            'mime_type'     => $upload->mime_type ?: $detected,
            'extension'     => $upload->extension,
            'source'        => $stagedPath,
            'sha256'        => $sha256,
            'disk'          => $disk,
            'source_disk'   => $sourceDisk ?? $disk,
            // Inside the transaction that creates the asset: the request row is locked, so a close or the last free
            // place taken by another finalize is seen here.
            'guard'         => fn () => $this->assertPlaceUnderLock(requestId: (int) $request->id, uploadId: (int) $upload->id),
        ]);

        GuestIntakeLog::write(event: 'portal.upload.published', ctx: [
            'upload_request_id' => (int) $request->id, 'submission_id' => (int) $submission->id, 'upload_id' => (int) $upload->id, 'asset_id' => (int) $asset->id,
        ]);

        return $asset;
    }

    /** The answer for an unknown, closed or expired request, identical for all three. */
    public static function unavailable(): ChunkUploadError
    {
        return new ChunkUploadError(410, 'request_unavailable', false, trans('dam::app.upload-portal.unavailable.message'));
    }

    protected function assertWithinLimits(UploadRequest $request, ?string $extension, ?string $mimeType, int $bytes): void
    {
        if ($request->max_bytes_per_file !== null && $bytes > $request->max_bytes_per_file) {
            throw new ChunkUploadError(413, 'file_too_large', false, trans('dam::app.upload-portal.errors.file-too-large'));
        }

        if (! AllowedTypes::permits(allowed: $request->allowed_types, extension: $extension, mimeType: $mimeType)) {
            throw new ChunkUploadError(415, 'type_not_allowed', false, trans('dam::app.upload-portal.errors.type-not-allowed'));
        }
    }

    protected function assertPlaceUnderLock(int $requestId, int $uploadId): void
    {
        $locked = UploadRequest::query()->with('creator')->lockForUpdate()->where('id', $requestId)->first();

        if ($locked === null || ! $locked->isAccepting()) {
            throw self::unavailable();
        }

        if ($locked->max_files === null) {
            return;
        }

        $finished = ChunkUpload::query()
            ->whereIn('upload_submission_id', UploadSubmission::query()->select('id')->where('upload_request_id', $requestId))
            ->where('state', ChunkUpload::STATE_COMPLETED)
            ->where('id', '!=', $uploadId)
            ->count();

        if ($finished >= $locked->max_files) {
            throw new ChunkUploadError(409, 'request_full', false, trans('dam::app.upload-portal.errors.request-full'));
        }
    }

    protected function countedUploads(int $requestId): int
    {
        return ChunkUpload::query()
            ->whereIn('upload_submission_id', UploadSubmission::query()->select('id')->where('upload_request_id', $requestId))
            ->whereIn('state', self::COUNTED_STATES)
            ->count();
    }

    /** @param  array<string, mixed>  $metadata */
    protected function submissionFor(UploadRequest $request, string $nonce, array $metadata, bool &$created): UploadSubmission
    {
        $name = self::selfReported($metadata['guest_name'] ?? null);
        $email = self::selfReported($metadata['guest_email'] ?? null);
        $submission = UploadSubmission::query()->where('visit_hash', PortalGate::visitHash($nonce))->first();

        if ($submission !== null) {
            if ((int) $submission->upload_request_id !== (int) $request->id) {
                throw new ChunkUploadError(404, 'upload_not_found', false, trans('dam::app.upload-portal.errors.upload-not-found'));
            }

            // A visit may add or correct its own details before its identity is pruned; it never clears them.
            $changes = array_filter(['guest_name' => $name, 'guest_email' => $email], fn ($value) => $value !== null);

            if ($changes !== [] && $submission->identity_pruned_at === null) {
                $submission->fill($changes)->save();
            }

            return $submission;
        }

        $created = true;

        return UploadSubmission::query()->create([
            'upload_request_id' => $request->id,
            'guest_key'         => Str::lower(Str::random(32)),
            'visit_hash'        => PortalGate::visitHash($nonce),
            'guest_name'        => $name,
            'guest_email'       => $email,
            'file_count'        => 0,
        ]);
    }

    protected function submissionCreated(UploadRequest $request, int $submissionId): void
    {
        app(SecurityEventRecorder::class)->record(new SecurityEvent(
            name: SecurityEvent::GUEST_SUBMISSION_CREATED,
            ctx: ['upload_request_id' => (int) $request->id, 'submission_id' => $submissionId],
        ));
        GuestIntakeFunnel::emit(GuestIntakeFunnel::SUBMISSION_CREATED, ['upload_request_id' => (int) $request->id, 'submission_id' => $submissionId]);
    }

    protected function logRefusal(ChunkUpload $upload, string $code): void
    {
        GuestIntakeLog::write(event: 'portal.upload.refused_at_finalize', ctx: [
            'submission_id' => (int) $upload->upload_submission_id, 'upload_id' => (int) $upload->id, 'code' => $code,
        ], level: 'warning');
    }

    /** The MIME type read from the bytes themselves, or null when it cannot be read. */
    public static function detectedMimeType(string $localPath): ?string
    {
        $type = is_file($localPath) ? (new \finfo(FILEINFO_MIME_TYPE))->file($localPath) : false;

        return is_string($type) && $type !== '' ? strtolower($type) : null;
    }

    /** A self-reported name or email as stored: trimmed, control characters removed, null when blank. */
    protected static function selfReported(mixed $value): ?string
    {
        $value = is_string($value) ? trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value)) : '';

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
