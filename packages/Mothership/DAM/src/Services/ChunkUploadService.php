<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Filesystem\FileStorer;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\ChunkUploadPart;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\UploadRequest;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Services\UploadRequests\GuestIntakeService;
use Mothership\DAM\Support\ChunkUploadError;
use Mothership\DAM\Support\ChunkUploadLogger;
use Mothership\DAM\Support\FolderUploadPaths;
use Mothership\DAM\Support\Sha256File;
use Mothership\DAM\Support\UploadRefusal;
use Mothership\User\Models\Admin;

class ChunkUploadService
{
    public function __construct(
        protected DirectoryPermissionService $permissionService,
        protected ChunkUploadLogger $logger,
        protected AssetUploadPublisher $publisher,
        protected ContentStore $contentStore,
        protected FileStorer $fileStorer,
    ) {}

    public function initialize(array $metadata, int $userId): ChunkUpload
    {
        $expectedBytes = $this->assertUploadable($metadata);

        $tracker = UploadTracker::query()
            ->where('uuid', $metadata['session_uuid'])
            ->where('user_id', $userId)
            ->first();

        if (! $tracker) {
            throw new ChunkUploadError(404, 'tracker_not_found', false, 'The upload tracker was not found.');
        }

        $directoryId = (int) $metadata['directory_id'];

        if ((int) $tracker->directory_id !== $directoryId) {
            throw new ChunkUploadError(409, 'destination_mismatch', false, 'The upload destination changed.');
        }

        if (! $this->permissionService->canAccess($directoryId)) {
            throw new ChunkUploadError(403, 'directory_forbidden', false, 'The upload destination is not accessible.');
        }

        $directoryId = $this->resolveDestination(metadata: $metadata, rootId: $directoryId);

        $identity = [
            'upload_tracker_id' => $tracker->id,
            'user_id'           => $userId,
            'directory_id'      => $directoryId,
            'original_name'     => $metadata['original_name'],
            'relative_path'     => $metadata['relative_path'] ?? null,
            'preserve_root'     => (bool) $metadata['preserve_root'],
            // A create-only upload is a different upload from a normal one of the same file, so it never resumes it.
            'create_only'       => (bool) ($metadata['create_only'] ?? false),
        ];
        $existing = ChunkUpload::query()->where($identity)->first();

        if ($existing) {
            if ((int) $existing->expected_bytes !== $expectedBytes
                || (int) $existing->source_modified_ms !== (int) $metadata['last_modified']
                || ! hash_equals($existing->source_fingerprint, $metadata['fingerprint'])) {
                throw new ChunkUploadError(409, 'source_mismatch', false, 'The source file changed.');
            }

            return $existing;
        }

        return $this->createUpload(identity: $identity, metadata: $metadata, expectedBytes: $expectedBytes);
    }

    /**
     * Start (or resume) a chunked upload that finishes as an attachment on `$asset` under `role`, not as a
     * new asset. The caller has already authorized the asset. A resumable row is reused only for the same
     * source file; a finished, failed, cancelled or expired one never is, so re-attaching a removed file works.
     */
    public function initializeAttachment(array $metadata, int $userId, Asset $asset): ChunkUpload
    {
        $expectedBytes = $this->assertUploadable($metadata);
        $directoryId = (int) DB::table('dam_asset_directory')->where('asset_id', $asset->id)->orderBy('id')->value('directory_id');
        $identity = [
            'upload_tracker_id' => null,
            'user_id'           => $userId,
            'directory_id'      => $directoryId,
            'original_name'     => $metadata['original_name'],
            'relative_path'     => null,
            'preserve_root'     => false,
            'attach_asset_id'   => $asset->id,
            'attach_role'       => $metadata['role'],
            'attach_label'      => ($metadata['label'] ?? null) ?: null,
        ];
        $existing = ChunkUpload::query()->where($identity)
            // Never a failed row: a failure is terminal (chunk_corrupt, forbidden_file…) and keeps its part
            // rows, so resuming it would re-assemble the same broken bytes. Retryable errors re-queue instead.
            ->whereIn('state', [ChunkUpload::STATE_RECEIVING, ChunkUpload::STATE_QUEUED, ChunkUpload::STATE_ASSEMBLING])
            ->where('expected_bytes', $expectedBytes)
            ->where('source_modified_ms', (int) $metadata['last_modified'])
            ->where('source_fingerprint', $metadata['fingerprint'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        return $existing ?? $this->createUpload(identity: $identity, metadata: $metadata, expectedBytes: $expectedBytes);
    }

    /**
     * P5a: start a guest's upload through an upload request. The destination is the request's intake folder and the
     * owner its creator; nothing about either comes from the guest. The row is bound to the visit's submission, so
     * only that visit's portal calls reach it and the staff chunk routes never do. The caller holds the request's
     * row lock and has checked its limits.
     */
    public function initializeGuest(array $metadata, UploadSubmission $submission, UploadRequest $request): ChunkUpload
    {
        $expectedBytes = $this->assertUploadable($metadata);

        return $this->createUpload(identity: [
            'upload_tracker_id'    => null,
            'user_id'              => (int) $request->created_by,
            'directory_id'         => (int) $request->directory_id,
            'original_name'        => $metadata['original_name'],
            'relative_path'        => null,
            'preserve_root'        => false,
            'upload_submission_id' => $submission->id,
        ], metadata: $metadata, expectedBytes: $expectedBytes);
    }

    /** P5a: this visit's unfinished upload of the same source file, to resume rather than start again; else null. */
    public function resumableGuestUpload(UploadSubmission $submission, array $metadata): ?ChunkUpload
    {
        return ChunkUpload::query()
            ->where('upload_submission_id', $submission->id)
            ->where('original_name', $metadata['original_name'])
            ->whereIn('state', [ChunkUpload::STATE_RECEIVING, ChunkUpload::STATE_QUEUED, ChunkUpload::STATE_ASSEMBLING])
            ->where('expected_bytes', (int) $metadata['size'])
            ->where('source_modified_ms', (int) $metadata['last_modified'])
            ->where('source_fingerprint', $metadata['fingerprint'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /** Name and size checks shared by both initializers; returns the declared size in bytes. */
    protected function assertUploadable(array $metadata): int
    {
        $name = $metadata['original_name'];

        if ($name === '.' || $name === '..' || str_contains($name, '/')
            || str_contains($name, '\\') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new ChunkUploadError(422, 'invalid_upload_name', false, 'The upload filename is invalid.');
        }

        $expectedBytes = (int) $metadata['size'];
        $maxFileBytes = (int) config('dam.explorer.upload.max_file_bytes');

        if ($expectedBytes > $maxFileBytes) {
            throw new ChunkUploadError(413, 'file_too_large', false, 'The file exceeds the upload limit.');
        }

        return $expectedBytes;
    }

    protected function createUpload(array $identity, array $metadata, int $expectedBytes): ChunkUpload
    {
        $chunkBytes = (int) config('dam.explorer.upload.chunk_bytes');
        $uuid = (string) Str::uuid();

        $upload = ChunkUpload::create(array_merge($identity, [
            'uuid'                => $uuid,
            'mime_type'           => $metadata['mime_type'] ?? null,
            'extension'           => strtolower(pathinfo($metadata['original_name'], PATHINFO_EXTENSION)) ?: null,
            'expected_bytes'      => $expectedBytes,
            'source_modified_ms'  => (int) $metadata['last_modified'],
            'source_fingerprint'  => $metadata['fingerprint'],
            'chunk_bytes'         => $chunkBytes,
            'chunk_count'         => intdiv($expectedBytes + $chunkBytes - 1, $chunkBytes),
            'received_bytes'      => 0,
            'finalization_bytes'  => 0,
            'state'               => ChunkUpload::STATE_RECEIVING,
            'staged_root'         => '.uploads/'.$uuid,
            'expires_at'          => now()->addHours((int) config('dam.explorer.upload.stale_hours')),
        ]));

        $this->logger->write(
            runId: $upload->uuid,
            level: 'info',
            event: 'upload.initialized',
            loc: self::class.'::initialize',
            ctx: [
                'upload_id'       => (int) $upload->id,
                'directory_id'    => (int) $upload->directory_id,
                'attach_asset_id' => $upload->attach_asset_id ? (int) $upload->attach_asset_id : null,
                'expected_bytes'  => $expectedBytes,
                'chunk_count'     => (int) $upload->chunk_count,
                'state'           => $upload->state,
            ],
        );

        return $upload;
    }

    public function findAuthorized(string $uuid, int $userId): ChunkUpload
    {
        $upload = ChunkUpload::query()->where('uuid', $uuid)->first();

        // P5a: a guest's upload is reached only through its own visit on the portal, never by a staff session, the
        // request's creator included (it carries their id as the owner-to-be).
        if (! $upload || $upload->isGuest()) {
            throw new ChunkUploadError(404, 'upload_not_found', false, 'The upload was not found.');
        }

        if ((int) $upload->user_id !== $userId) {
            throw new ChunkUploadError(403, 'upload_forbidden', false, 'The upload belongs to another user.');
        }

        // An attachment is authorized by its asset (which may sit in several folders), re-checked on every
        // chunk and on completion, so losing access mid-upload stops it.
        if ($upload->isAttachment()) {
            if (! $this->canAttachTo((int) $upload->attach_asset_id)) {
                throw new ChunkUploadError(403, 'attach_forbidden', false, 'You can no longer add files to this asset.');
            }

            return $upload;
        }

        if (! $this->permissionService->canAccess((int) $upload->directory_id)) {
            throw new ChunkUploadError(403, 'directory_forbidden', false, 'The upload destination is not accessible.');
        }

        return $upload;
    }

    /** The same gate AssetFileController::store() applies: the files.store permission plus edit access. */
    public function canAttachTo(int $assetId): bool
    {
        return Asset::query()->whereKey($assetId)->exists()
            && bouncer()->hasPermission('dam.asset.files.store')
            && bouncer()->hasPermission('dam.asset.edit')
            && $this->permissionService->canAccessAsset($assetId);
    }

    /**
     * FU: the folder a file of a dropped or picked folder lands in, under the target. Its path is sanitized by the same
     * rule that made the folders (FolderUploadPaths), so a segment the panel's createStructure renamed is found under
     * its new name. The folders must already exist: never silently flatten files if that prerequisite failed.
     */
    protected function resolveDestination(array $metadata, int $rootId): int
    {
        $segments = FolderUploadPaths::fileFolders(relativePath: $metadata['relative_path'] ?? null, preserveRoot: (bool) $metadata['preserve_root']);

        if (count($segments) > FolderUploadPaths::maxDepth()) {
            throw new ChunkUploadError(422, 'folder_too_deep', false, 'The upload folder path is too deep.');
        }

        $directoryId = $rootId;

        foreach ($segments as $segment) {
            $directory = Directory::query()->where('parent_id', $directoryId)->where('name', $segment)->first();

            if (! $directory) {
                throw new ChunkUploadError(422, 'upload_directory_missing', false, 'The upload folder has not been created.');
            }

            $directoryId = (int) $directory->id;

            if (! $this->permissionService->canAccess($directoryId)) {
                throw new ChunkUploadError(403, 'directory_forbidden', false, 'The upload destination is not accessible.');
            }
        }

        return $directoryId;
    }

    /**
     * P3b-2 (D16 exact): the receipt of a finished new-file upload, for the exact-duplicate notice; null while it
     * runs, for an attachment upload, and for one finished before the receipt was recorded.
     */
    public function receipt(ChunkUpload $upload): ?UploadReceipt
    {
        if ($upload->state !== ChunkUpload::STATE_COMPLETED || $upload->isAttachment() || ! $upload->asset_id
            || ! in_array($upload->publish_outcome, UploadReceipt::OUTCOMES, true) || ! is_string($upload->published_sha256)) {
            return null;
        }

        return new UploadReceipt(assetId: (int) $upload->asset_id, sha256: $upload->published_sha256, outcome: $upload->publish_outcome);
    }

    public function status(ChunkUpload $upload): array
    {
        return [
            'uuid'                 => $upload->uuid,
            'state'                => $upload->state,
            'expected_bytes'       => (int) $upload->expected_bytes,
            'received_bytes'       => (int) $upload->received_bytes,
            'finalization_bytes'   => (int) $upload->finalization_bytes,
            'chunk_size'           => (int) $upload->chunk_bytes,
            'chunk_count'          => (int) $upload->chunk_count,
            'received_indices'     => $upload->parts()
                ->orderBy('chunk_index')
                ->pluck('chunk_index')
                ->map(fn ($index): int => (int) $index)
                ->values()
                ->all(),
            // A create-only upload records its asset when the asset is created; it is the upload's result only once completed.
            'asset_id'             => $upload->asset_id && $upload->state === ChunkUpload::STATE_COMPLETED ? (int) $upload->asset_id : null,
            'asset_file_id'        => $upload->asset_file_id ? (int) $upload->asset_file_id : null,
            'failure_code'         => $upload->failure_code,
            // {code, message} in the requester's language, so the tray says why (null for no stored reason).
            'failure_reason'       => UploadRefusal::payload($upload->failure_reason),
            'expires_at'           => $upload->expires_at?->toISOString(),
        ];
    }

    public function storePart(ChunkUpload $upload, int $index, array $headers, mixed $stream): ChunkUploadPart
    {
        if ($upload->isExpired()) {
            throw new ChunkUploadError(409, 'upload_expired', false, 'The upload has expired.');
        }

        if (! $upload->isReceiving()) {
            throw new ChunkUploadError(409, 'upload_not_receiving', false, 'The upload is not accepting chunks.');
        }

        if ($index < 0 || $index >= (int) $upload->chunk_count) {
            throw new ChunkUploadError(422, 'invalid_chunk_index', false, 'The chunk index is invalid.');
        }

        $expectedOffset = $index * (int) $upload->chunk_bytes;
        $expectedLength = min(
            (int) $upload->chunk_bytes,
            (int) $upload->expected_bytes - $expectedOffset,
        );
        $expectedEnd = $expectedOffset + $expectedLength - 1;
        $range = trim((string) ($headers['content_range'] ?? ''));

        if (! preg_match('/\Abytes (\d+)-(\d+)\/(\d+)\z/', $range, $matches)
            || (int) $matches[1] !== $expectedOffset
            || (int) $matches[2] !== $expectedEnd
            || (int) $matches[3] !== (int) $upload->expected_bytes) {
            throw new ChunkUploadError(422, 'invalid_chunk_range', false, 'The chunk range is invalid.');
        }

        $claimedDigest = strtolower(trim((string) ($headers['sha256'] ?? '')));

        if (! preg_match('/\A[a-f0-9]{64}\z/', $claimedDigest)) {
            throw new ChunkUploadError(422, 'invalid_chunk_checksum', false, 'The chunk checksum is invalid.');
        }

        [$spool, $actualLength, $actualDigest] = $this->spool($stream);

        try {
            if ($actualLength !== $expectedLength) {
                throw new ChunkUploadError(422, 'invalid_chunk_length', false, 'The chunk length is invalid.');
            }

            if (! hash_equals($claimedDigest, $actualDigest)) {
                throw new ChunkUploadError(422, 'chunk_checksum_mismatch', false, 'The chunk checksum does not match.');
            }

            $disk = Storage::disk($this->partsDiskName());
            $incomingPath = $upload->staged_root.'/parts/'.$index.'.'.Str::uuid().'.incoming';
            rewind($spool);

            try {
                $written = $disk->writeStream($incomingPath, $spool);
            } catch (\Throwable) {
                $written = false;
            }

            if (! $written) {
                $disk->delete($incomingPath);
                $this->logger->write(
                    runId: $upload->uuid,
                    level: 'error',
                    event: 'chunk.storage_failed',
                    loc: self::class.'::storePart',
                    ctx: ['upload_id' => (int) $upload->id, 'chunk_index' => $index],
                );

                throw new ChunkUploadError(507, 'chunk_storage_failed', true, 'The chunk could not be stored.');
            }

            $finalPath = $upload->staged_root.'/parts/'.$index.'.part';

            try {
                $part = DB::transaction(function () use (
                    $upload,
                    $index,
                    $expectedOffset,
                    $expectedLength,
                    $actualDigest,
                    $incomingPath,
                    $finalPath,
                    $disk,
                ): ChunkUploadPart {
                    $lockedUpload = ChunkUpload::query()->lockForUpdate()->findOrFail($upload->id);

                    if ($lockedUpload->isExpired()) {
                        $disk->delete($incomingPath);
                        throw new ChunkUploadError(409, 'upload_expired', false, 'The upload has expired.');
                    }

                    if (! $lockedUpload->isReceiving()) {
                        $disk->delete($incomingPath);

                        if (in_array($lockedUpload->state, [ChunkUpload::STATE_CANCELLED, ChunkUpload::STATE_EXPIRED], true)) {
                            $this->deleteStaging($lockedUpload);
                        }

                        throw new ChunkUploadError(409, 'upload_not_receiving', false, 'The upload is not accepting chunks.');
                    }

                    $existing = $lockedUpload->parts()->where('chunk_index', $index)->first();

                    if ($existing) {
                        $disk->delete($incomingPath);

                        if ((int) $existing->byte_length !== $expectedLength
                            || ! hash_equals($existing->sha256, $actualDigest)) {
                            throw new ChunkUploadError(409, 'chunk_conflict', false, 'A different chunk already exists at this index.');
                        }

                        $lockedUpload->forceFill([
                            'expires_at' => now()->addHours((int) config('dam.explorer.upload.stale_hours')),
                        ])->save();

                        return $existing;
                    }

                    if (! $disk->move($incomingPath, $finalPath)) {
                        $disk->delete($incomingPath);
                        throw new ChunkUploadError(507, 'chunk_storage_failed', true, 'The chunk could not be finalized.');
                    }

                    try {
                        $part = $lockedUpload->parts()->create([
                            'chunk_index' => $index,
                            'byte_offset' => $expectedOffset,
                            'byte_length' => $expectedLength,
                            'sha256'      => $actualDigest,
                            'staged_path' => $finalPath,
                        ]);

                        $lockedUpload->forceFill([
                            'received_bytes' => (int) $lockedUpload->received_bytes + $expectedLength,
                            'expires_at'     => now()->addHours((int) config('dam.explorer.upload.stale_hours')),
                        ])->save();
                    } catch (\Throwable $error) {
                        $disk->delete($finalPath);

                        throw $error;
                    }

                    return $part;
                });
            } catch (ChunkUploadError $error) {
                $disk->delete($incomingPath);

                throw $error;
            }

            $this->logger->write(
                runId: $upload->uuid,
                level: 'info',
                event: 'chunk.accepted',
                loc: self::class.'::storePart',
                ctx: [
                    'upload_id'    => (int) $upload->id,
                    'chunk_index'  => $index,
                    'byte_length'  => $expectedLength,
                ],
                verbose: true,
            );

            return $part;
        } finally {
            fclose($spool);
        }
    }

    private function spool(mixed $stream): array
    {
        if (! is_resource($stream)) {
            throw new ChunkUploadError(422, 'invalid_chunk_body', false, 'The chunk body is invalid.');
        }

        $spool = fopen('php://temp/maxmemory:5242880', 'w+b');

        if (! is_resource($spool)) {
            throw new ChunkUploadError(507, 'chunk_storage_failed', true, 'The chunk buffer could not be opened.');
        }

        $hash = hash_init('sha256');
        $length = 0;

        while (! feof($stream)) {
            $buffer = fread($stream, 1024 * 1024);

            if ($buffer === false) {
                fclose($spool);
                throw new ChunkUploadError(422, 'invalid_chunk_body', false, 'The chunk body could not be read.');
            }

            if ($buffer === '') {
                continue;
            }

            $length += strlen($buffer);
            hash_update($hash, $buffer);

            if (fwrite($spool, $buffer) !== strlen($buffer)) {
                fclose($spool);
                throw new ChunkUploadError(507, 'chunk_storage_failed', true, 'The chunk buffer could not be written.');
            }
        }

        return [$spool, $length, hash_final($hash)];
    }

    public function queueCompletion(ChunkUpload $upload): ChunkUpload
    {
        return DB::transaction(function () use ($upload): ChunkUpload {
            $locked = ChunkUpload::query()->lockForUpdate()->findOrFail($upload->id);

            if ($locked->state === ChunkUpload::STATE_COMPLETED
                || in_array($locked->state, [ChunkUpload::STATE_QUEUED, ChunkUpload::STATE_ASSEMBLING], true)) {
                return $locked;
            }

            if (! in_array($locked->state, [ChunkUpload::STATE_RECEIVING, ChunkUpload::STATE_FAILED], true)) {
                throw new ChunkUploadError(409, 'upload_not_completable', false, 'The upload cannot be completed.');
            }

            if ($locked->isExpired()) {
                throw new ChunkUploadError(409, 'upload_expired', false, 'The upload has expired.');
            }

            $this->assertCompleteManifest($locked);
            $locked->forceFill([
                'state'              => ChunkUpload::STATE_QUEUED,
                'failure_code'       => null,
                'failure_reason'     => null,
                'finalization_bytes' => 0,
            ])->save();

            FinalizeChunkUpload::dispatch($locked->id, replaySessionId: DamEvents::requestReplaySession(),
                actor: ActorResolver::forChunkUpload(upload: $locked, request: request()))->afterCommit();
            $this->logger->write(
                runId: $locked->uuid,
                level: 'info',
                event: 'upload.queued',
                loc: self::class.'::queueCompletion',
                ctx: ['upload_id' => (int) $locked->id, 'state' => ChunkUpload::STATE_QUEUED],
            );

            return $locked->refresh();
        });
    }

    public function finalize(ChunkUpload $upload): Asset
    {
        return Cache::lock($this->completionLockName($upload), 21_600)
            ->block(1, function () use ($upload): Asset {
                $prepared = DB::transaction(function () use ($upload): ChunkUpload|Asset {
                    $locked = ChunkUpload::query()->lockForUpdate()->findOrFail($upload->id);

                    if ($locked->state === ChunkUpload::STATE_COMPLETED && $locked->asset_id) {
                        return Asset::findOrFail($locked->asset_id);
                    }

                    if ($locked->state !== ChunkUpload::STATE_QUEUED) {
                        throw new ChunkUploadError(409, 'stale_finalization_job', false, 'The finalization job is no longer active.');
                    }

                    $this->assertCompleteManifest($locked);
                    $locked->forceFill([
                        'state'              => ChunkUpload::STATE_ASSEMBLING,
                        'finalization_bytes' => 0,
                    ])->save();

                    return $locked->refresh();
                });

                if ($prepared instanceof Asset) {
                    return $prepared;
                }

                $this->logger->write(
                    runId: $prepared->uuid,
                    level: 'info',
                    event: 'upload.assembling',
                    loc: self::class.'::finalize',
                    ctx: ['upload_id' => (int) $prepared->id, 'state' => ChunkUpload::STATE_ASSEMBLING],
                );

                return $this->assembleAndPublish($prepared);
            });
    }

    public function cancel(ChunkUpload $upload): void
    {
        Cache::lock($this->completionLockName($upload), 21_600)->block(1, function () use ($upload): void {
            $cancelled = DB::transaction(function () use ($upload): ?ChunkUpload {
                $locked = ChunkUpload::query()->lockForUpdate()->findOrFail($upload->id);

                if (in_array($locked->state, [ChunkUpload::STATE_CANCELLED, ChunkUpload::STATE_COMPLETED], true)) {
                    return null;
                }

                $this->deleteStaging($locked);
                $locked->parts()->delete();
                $locked->forceFill([
                    'state'              => ChunkUpload::STATE_CANCELLED,
                    'failure_code'       => null,
                    'failure_reason'     => null,
                    'finalization_bytes' => 0,
                ])->save();

                return $locked;
            });

            if ($cancelled) {
                $this->logger->write(
                    runId: $cancelled->uuid,
                    level: 'info',
                    event: 'upload.cancelled',
                    loc: self::class.'::cancel',
                    ctx: ['upload_id' => (int) $cancelled->id, 'state' => ChunkUpload::STATE_CANCELLED],
                );
            }
        });
    }

    public function expire(CarbonInterface $cutoff): int
    {
        $expired = 0;

        ChunkUpload::query()
            ->whereIn('state', [
                ChunkUpload::STATE_RECEIVING,
                ChunkUpload::STATE_QUEUED,
                ChunkUpload::STATE_FAILED,
            ])
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($uploads) use ($cutoff, &$expired): void {
                foreach ($uploads as $upload) {
                    $didExpire = Cache::lock($this->completionLockName($upload), 21_600)
                        ->get(function () use ($upload, $cutoff): bool {
                            return DB::transaction(function () use ($upload, $cutoff): bool {
                                $locked = ChunkUpload::query()->lockForUpdate()->findOrFail($upload->id);

                                if (! in_array($locked->state, [
                                    ChunkUpload::STATE_RECEIVING,
                                    ChunkUpload::STATE_QUEUED,
                                    ChunkUpload::STATE_FAILED,
                                ], true) || $locked->expires_at === null || $locked->expires_at->greaterThan($cutoff)) {
                                    return false;
                                }

                                $this->deleteStaging($locked);
                                $locked->parts()->delete();
                                $locked->forceFill([
                                    'state'              => ChunkUpload::STATE_EXPIRED,
                                    'failure_code'       => null,
                                    'failure_reason'     => null,
                                    'finalization_bytes' => 0,
                                ])->save();

                                return true;
                            });
                        });

                    if ($didExpire === true) {
                        $expired++;
                        $this->logger->write(
                            runId: $upload->uuid,
                            level: 'info',
                            event: 'upload.expired',
                            loc: self::class.'::expire',
                            ctx: ['upload_id' => (int) $upload->id, 'state' => ChunkUpload::STATE_EXPIRED],
                        );
                    }
                }
            });

        return $expired;
    }

    protected function completionLockName(ChunkUpload $upload): string
    {
        return 'dam:chunk-upload:'.$upload->uuid.':complete';
    }

    protected function deleteStaging(ChunkUpload $upload): void
    {
        $expectedRoot = '.uploads/'.$upload->uuid;

        if (! hash_equals($expectedRoot, (string) $upload->staged_root)) {
            throw new \RuntimeException('The chunk upload staging root is invalid.');
        }

        $disk = Storage::disk($this->partsDiskName());

        if ($disk->directoryExists($expectedRoot) && ! $disk->deleteDirectory($expectedRoot)) {
            throw new \RuntimeException('The chunk upload staging directory could not be deleted.');
        }
    }

    protected function assertCompleteManifest(ChunkUpload $upload): void
    {
        $parts = $upload->parts()->orderBy('chunk_index')->get();
        $indices = $parts->pluck('chunk_index')->map(fn ($index): int => (int) $index)->all();

        if ($indices !== range(0, (int) $upload->chunk_count - 1)
            || (int) $parts->sum('byte_length') !== (int) $upload->expected_bytes) {
            throw new ChunkUploadError(422, 'upload_incomplete', false, 'Not all chunks have been received.');
        }
    }

    /**
     * Where parts and the assembly are staged: the asset disk when it is local, else the local staging disk, so
     * a remote asset disk (ADR 0017) receives each upload once, as its finished blob.
     */
    protected function partsDiskName(): string
    {
        return $this->contentStore->uploadStagingDisk(Directory::getAssetDisk());
    }

    protected function assembleAndPublish(ChunkUpload $upload): Asset
    {
        $diskName = Directory::getAssetDisk();
        // Parts, the assembly and its cleanup live on $disk; the blob lands on $diskName. One disk when local.
        $stagingDiskName = $this->contentStore->uploadStagingDisk($diskName);
        $disk = Storage::disk($stagingDiskName);

        // A create-only upload whose first attempt published its asset and then failed (the completion record, a
        // queue hiccup) is finished from that asset, never published again under the next free name.
        $published = $this->publishedAssetOf($upload);

        if ($published !== null) {
            return $this->completeAdopted(upload: $upload, asset: $published, disk: $disk);
        }

        // Assembled into the staging area, outside every folder; the publisher moves it into its blob (ADR 0009).
        // The staging disk is always local now (a remote asset disk stages on STAGING_DISK, ADR 0017), so this
        // writes straight into the eventual staging file, with no second full copy from a separate assembly
        // buffer. The temp-file branch below remains for a staging file that cannot be opened. A failure
        // opening the local staging file (permissions, a full disk) falls back to the same temp-file path
        // rather than failing outside every other failure path this method already has.
        try {
            $staging = $this->contentStore->openLocalStaging($stagingDiskName, $upload->extension);
        } catch (\Throwable) {
            $staging = null;
        }

        $assemblyPath = $staging['path'] ?? $this->contentStore->stagingPath($upload->extension);
        $localPath = $staging['local_path'] ?? null;
        $output = $staging['handle'] ?? tmpfile();

        if (! is_resource($output)) {
            $this->resetQueued($upload, 'assembly_buffer_failed');
            throw new ChunkUploadError(507, 'assembly_buffer_failed', true, 'The assembly buffer could not be opened.');
        }

        $partDigestLimit = (int) config('dam.explorer.upload.part_digest_in_memory_max_bytes');

        try {
            $copiedBytes = 0;

            foreach ($upload->parts()->orderBy('chunk_index')->get() as $part) {
                $byteLength = (int) $part->byte_length;

                if ($byteLength <= $partDigestLimit) {
                    // Small enough to read whole and verify with hardware-accelerated openssl_digest();
                    // no whole-file digest is kept running here any more (see the hash below assembly).
                    $partBytes = $this->verifyAndAppendInMemory(disk: $disk, part: $part, output: $output);
                } else {
                    // Large enough that reading it whole risks memory, so it still streams through
                    // hash_update() in bounded chunks, as before.
                    $input = $disk->readStream($part->staged_path);

                    if (! is_resource($input)) {
                        throw new ChunkUploadError(422, 'chunk_missing', false, 'A verified chunk is missing from storage.');
                    }

                    try {
                        [$partBytes, $partDigest] = $this->copyAndHash(input: $input, output: $output);
                    } finally {
                        fclose($input);
                    }

                    if ($partBytes !== $byteLength || ! hash_equals($part->sha256, $partDigest)) {
                        throw new ChunkUploadError(422, 'chunk_corrupt', false, 'A verified chunk failed its integrity check.');
                    }
                }

                $copiedBytes += $partBytes;
                ChunkUpload::whereKey($upload->id)->update(['finalization_bytes' => $copiedBytes]);
            }

            if ($copiedBytes !== (int) $upload->expected_bytes) {
                throw new ChunkUploadError(422, 'assembled_size_mismatch', false, 'The assembled upload size is invalid.');
            }

            if ($localPath === null) {
                $localPath = stream_get_meta_data($output)['uri'];
            } else {
                // The local-disk path: closed before anything reads it again, including a native sha256sum
                // running as its own process, so every written byte is guaranteed to be on disk first.
                fclose($output);
                $output = null;
            }

            if (AssetHelper::isForbiddenFile(
                extension: $upload->extension,
                mimeType: $upload->mime_type,
                fileName: $upload->original_name,
                realPath: $localPath,
                inspectWebBundles: ! $upload->isAttachment(),
            )) {
                $reason = AssetHelper::webBundleRefusalReason($upload->extension, $localPath);

                // The reason is stored, not the message: the status poll renders it for the uploader (QRAI-06).
                throw new ChunkUploadError(422, 'forbidden_file', false, $reason ? UploadRefusal::message($reason) : 'The assembled file type is not allowed.', $reason);
            }

            // Sanitize before hashing: an SVG's assembled-file digest would only be thrown away below, and
            // Sha256File's subprocess/file-scan machinery is wasted work for bytes about to be replaced.
            $sanitizeStartedAt = microtime(true);
            try {
                $sanitized = $this->fileStorer->sanitizeLocalSvg(localPath: $localPath, mimeType: $upload->mime_type);
            } catch (ValidationException $error) {
                throw new ChunkUploadError(
                    status: 422,
                    errorCode: 'svg_malformed',
                    retryable: false,
                    message: $error->getMessage(),
                    reason: UploadRefusal::make(code: 'svg_malformed', key: UploadRefusal::GENERIC_KEY),
                );
            }

            if ($sanitized !== null) {
                // The blob holds the sanitized SVG, so the checksum is of those bytes, not the assembly's;
                // already in memory and always small text, so this skips hashing the file a second time.
                $sha256 = hash('sha256', $sanitized);
                $this->logHashed($upload, strlen($sanitized), 'sanitized', $sanitizeStartedAt);
            } else {
                $sha256 = $this->hashAssembledFile($upload, $localPath, $copiedBytes);
            }

            if ($output !== null) {
                // Only the remote-disk branch still holds an open buffer here: stage it once, as before.
                rewind($output);

                if (! $disk->writeStream($assemblyPath, $output)) {
                    throw new \RuntimeException('The assembled upload could not be staged.');
                }
            }

            $assetFile = null;

            if ($upload->isAttachment()) {
                [$asset, $assetFile] = $this->attachStagedFile(upload: $upload, diskName: $diskName, stagedPath: $assemblyPath, sha256: $sha256, sourceDisk: $stagingDiskName);
            } elseif ($upload->isGuest()) {
                // P5a: create-only, server-placed, private and pending (publishIntake), after the request's limits
                // are checked again against the assembled bytes. Resolved here, not injected: it depends on this class.
                $asset = app(GuestIntakeService::class)->publish(upload: $upload, disk: $diskName, stagedPath: $assemblyPath, sha256: $sha256, localPath: $localPath, sourceDisk: $stagingDiskName);
            } else {
                $asset = $this->publisher->publishStagedFile([
                    'directory'     => Directory::findOrFail($upload->directory_id),
                    'original_name' => $upload->original_name,
                    'mime_type'     => $this->sniffedMime(path: $localPath, declared: $upload->mime_type),
                    'extension'     => $upload->extension,
                    'source'        => $assemblyPath,
                    'source_disk'   => $stagingDiskName,
                    'sha256'        => $sha256,
                    'disk'          => $diskName,
                    'tracker'       => $upload->tracker,
                    'owner_id'      => (int) $upload->user_id,
                    // Creative Hub large saves: `existing_asset => null` is the publisher's create-only switch (it tests key presence).
                    ...($upload->create_only ? [
                        'existing_asset' => null,
                        // Recorded in the transaction that creates the asset, so a failure anywhere after it leaves the
                        // marker publishedAssetOf() reads on the retry; the upload stays assembling, not completed, until
                        // completeUpload() writes the rest.
                        'on_created' => fn (Asset $created) => ChunkUpload::whereKey($upload->id)->update(['asset_id' => $created->id]),
                    ] : []),
                ]);
            }

            $this->completeUpload(upload: $upload, asset: $asset, assetFile: $assetFile, disk: $disk);

            return $asset;
        } catch (ChunkUploadError $error) {
            $disk->delete($assemblyPath);
            $this->failUpload($upload, $error->errorCode, $error);

            throw $error;
        } catch (\Throwable $error) {
            $disk->delete($assemblyPath);
            $this->resetQueued($upload, 'finalization_retry');
            $this->logger->write(
                runId: $upload->uuid,
                level: 'error',
                event: 'upload.finalization_retry',
                loc: self::class.'::assembleAndPublish',
                ctx: ['upload_id' => (int) $upload->id, 'error_type' => $error::class],
            );

            throw $error;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /**
     * Record the finished upload and clear its staged parts: the single place an upload becomes completed.
     */
    protected function completeUpload(ChunkUpload $upload, Asset $asset, ?AssetFile $assetFile, Filesystem $disk): void
    {
        DB::transaction(function () use ($upload, $asset, $assetFile): void {
            ChunkUpload::query()->lockForUpdate()->findOrFail($upload->id)->forceFill([
                'asset_id'           => $asset->id,
                'asset_file_id'      => $assetFile?->getKey(),
                'state'              => ChunkUpload::STATE_COMPLETED,
                'failure_code'       => null,
                'failure_reason'     => null,
                'finalization_bytes' => (int) $upload->expected_bytes,
                'finalized_at'       => now(),
                // P3b-2: what the publish did, so status() can say whether these bytes were already there.
                'published_sha256'   => $asset->uploadReceipt?->sha256,
                'publish_outcome'    => $asset->uploadReceipt?->outcome,
            ])->save();
        });

        foreach ($upload->parts as $part) {
            $disk->delete($part->staged_path);
        }

        $upload->parts()->delete();
        $disk->deleteDirectory($upload->staged_root);
        $this->logger->write(
            runId: $upload->uuid,
            level: 'info',
            event: 'upload.completed',
            loc: self::class.'::assembleAndPublish',
            ctx: [
                'upload_id'     => (int) $upload->id,
                'asset_id'      => (int) $asset->id,
                'asset_file_id' => $assetFile ? (int) $assetFile->getKey() : null,
                'state'         => ChunkUpload::STATE_COMPLETED,
            ],
        );
    }

    /**
     * The asset a create-only upload's earlier attempt already published (recorded by `on_created`), or null when
     * there is none, or it was removed since; a missing one is published afresh and the marker is rewritten.
     */
    protected function publishedAssetOf(ChunkUpload $upload): ?Asset
    {
        if (! $upload->create_only || $upload->isAttachment() || $upload->isGuest() || ! $upload->asset_id) {
            return null;
        }

        return Asset::query()->find($upload->asset_id);
    }

    /**
     * Finish a create-only upload from the asset its first attempt published. Processing is queued only if that
     * attempt never reached it, and the same retry path as a failed first attempt applies if this one fails too.
     */
    protected function completeAdopted(ChunkUpload $upload, Asset $asset, Filesystem $disk): Asset
    {
        try {
            $this->publisher->ensureFinalizationQueued(asset: $asset, tracker: UploadTracker::query()->find($upload->upload_tracker_id));
            $asset->uploadReceipt = new UploadReceipt(
                assetId: (int) $asset->id,
                sha256: (string) $this->contentStore->current($asset)->sha256,
                outcome: UploadReceipt::CREATED,
            );
            $this->completeUpload(upload: $upload, asset: $asset, assetFile: null, disk: $disk);
        } catch (\Throwable $error) {
            $this->resetQueued($upload, 'finalization_retry');
            $this->logger->write(
                runId: $upload->uuid,
                level: 'error',
                event: 'upload.finalization_retry',
                loc: self::class.'::completeAdopted',
                ctx: ['upload_id' => (int) $upload->id, 'error_type' => $error::class],
            );

            throw $error;
        }

        return $asset;
    }

    /**
     * Move the assembled file into its blob and attach it to the target asset, mirroring
     * AssetFileController::store(): the asset is locked first (ContentStore::lockBlob's lock order), and
     * the FileObject is re-locked after adoption so one a concurrent last-reference destroy() removed is
     * never referenced. That case throws a plain exception, so the job re-assembles from the kept chunks.
     *
     * @return array{0: Asset, 1: AssetFile}
     */
    protected function attachStagedFile(ChunkUpload $upload, string $diskName, string $stagedPath, string $sha256, ?string $sourceDisk = null): array
    {
        return DB::transaction(function () use ($upload, $diskName, $stagedPath, $sha256, $sourceDisk): array {
            $asset = Asset::whereKey($upload->attach_asset_id)->lockedForGraph()->first();

            if (! $asset) {
                throw new ChunkUploadError(410, 'attach_target_missing', false, 'The asset no longer exists.');
            }

            // The queue worker has no session, so the HTTP-side findAuthorized() check does not cover access
            // revoked after completion was queued: re-check the uploader explicitly before attaching.
            if (! $this->uploaderMayAttach(userId: (int) $upload->user_id, assetId: (int) $asset->id)) {
                throw new ChunkUploadError(403, 'attach_forbidden', false, 'You can no longer add files to this asset.');
            }

            $object = $this->contentStore->adoptStored(
                disk: $diskName,
                sourcePath: $stagedPath,
                sha256: $sha256,
                mimeType: $upload->mime_type ?: null,
                sourceDisk: $sourceDisk,
            );
            $locked = FileObject::whereKey($object->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->contentStore->holdsBlob($locked)) {
                throw new \RuntimeException('The attachment blob vanished during adoption.');
            }

            $assetFile = AssetFile::firstOrCreate([
                'dam_asset_id' => $asset->id, 'file_object_id' => $locked->id, 'role' => $upload->attach_role,
            ], [
                'label' => $upload->attach_label ?: mb_substr($upload->original_name, 0, 191),
                'sort'  => (int) AssetFile::where('dam_asset_id', $asset->id)->max('sort') + 1,
            ]);

            return [$asset, $assetFile];
        });
    }

    /** canAttachTo() for a named admin rather than the session user (Optimization\AssetOutputs' job-context rule). */
    protected function uploaderMayAttach(int $userId, int $assetId): bool
    {
        $admin = Admin::query()->find($userId);

        if (! $admin || ! (bool) $admin->status || ! DirectoryPermissionService::adminCanAccessAsset(adminId: $userId, assetId: $assetId)) {
            return false;
        }

        $role = $admin->role;

        if (! $role) {
            return false;
        }

        return $role->permission_type === 'all'
            || ($admin->hasPermission('dam.asset.edit') && $admin->hasPermission('dam.asset.files.store'));
    }

    /**
     * The assembled file's digest, computed exactly once, after assembly (2026-09-26 perf fix): native
     * sha256sum when it is available and the file is large enough to be worth a process spawn, PHP's
     * hash_file() otherwise. Always logs upload.hashed; a native attempt that failed also logs
     * upload.hash_fallback with the reason, at warning level.
     */
    protected function hashAssembledFile(ChunkUpload $upload, string $localPath, int $bytes): string
    {
        $startedAt = microtime(true);
        $hashed = Sha256File::hash($localPath);
        $this->logHashed($upload, $bytes, $hashed['method'], $startedAt);

        if ($hashed['failure_reason'] !== null) {
            $this->logger->write(
                runId: $upload->uuid,
                level: 'warning',
                event: 'upload.hash_fallback',
                loc: self::class.'::hashAssembledFile',
                ctx: ['upload_id' => (int) $upload->id, 'reason' => $hashed['failure_reason']],
            );
        }

        return $hashed['sha256'];
    }

    /**
     * upload.hashed fires exactly once per finalize, whichever branch produced the checksum: native or
     * PHP via hashAssembledFile(), or 'sanitized' when an SVG's cleaned bytes were hashed directly instead.
     */
    protected function logHashed(ChunkUpload $upload, int $bytes, string $method, float $startedAt): void
    {
        $this->logger->write(
            runId: $upload->uuid,
            level: 'info',
            event: 'upload.hashed',
            loc: self::class.'::assembleAndPublish',
            ctx: [
                'upload_id' => (int) $upload->id,
                'bytes'     => $bytes,
                'method'    => $method,
                'ms'        => (int) round((microtime(true) - $startedAt) * 1000),
            ],
        );
    }

    /**
     * Verify a whole part already read into memory against its stored digest, using hardware-accelerated
     * openssl_digest() instead of PHP's software hash_update(), then append the same bytes to the
     * assembly. Returns its length; throws chunk_missing/chunk_corrupt exactly like the streaming branch.
     *
     * Reads at most byte_length + 1 bytes from a stream rather than the whole file: the routing decision
     * above only compared the recorded byte_length against the in-memory limit, never the actual staged
     * file size, so a corrupted or enlarged staged part could otherwise be read whole here regardless of
     * how large it actually is, and exceed the queue CLI's memory_limit instead of failing as chunk_corrupt.
     */
    protected function verifyAndAppendInMemory(Filesystem $disk, ChunkUploadPart $part, mixed $output): int
    {
        $byteLength = (int) $part->byte_length;
        $input = $disk->readStream($part->staged_path);

        if (! is_resource($input)) {
            throw new ChunkUploadError(422, 'chunk_missing', false, 'A verified chunk is missing from storage.');
        }

        try {
            $bytes = stream_get_contents($input, $byteLength + 1);
        } finally {
            fclose($input);
        }

        if ($bytes === false || strlen($bytes) !== $byteLength || ! hash_equals($part->sha256, (string) openssl_digest($bytes, 'sha256'))) {
            throw new ChunkUploadError(422, 'chunk_corrupt', false, 'A verified chunk failed its integrity check.');
        }

        if (fwrite($output, $bytes) !== $byteLength) {
            throw new \RuntimeException('The assembly buffer could not be written.');
        }

        return $byteLength;
    }

    /** Append one part to the assembly; returns its length and digest. */
    protected function copyAndHash(mixed $input, mixed $output): array
    {
        $hash = hash_init('sha256');
        $bytes = 0;

        while (! feof($input)) {
            $buffer = fread($input, 1024 * 1024);

            if ($buffer === false) {
                throw new ChunkUploadError(422, 'chunk_unreadable', false, 'A verified chunk could not be read.');
            }

            if ($buffer === '') {
                continue;
            }

            $length = strlen($buffer);
            $bytes += $length;
            hash_update($hash, $buffer);

            if (fwrite($output, $buffer) !== $length) {
                throw new \RuntimeException('The assembly buffer could not be written.');
            }
        }

        return [$bytes, hash_final($hash)];
    }

    protected function failUpload(ChunkUpload $upload, string $failureCode, \Throwable $error): void
    {
        ChunkUpload::whereKey($upload->id)->update([
            'state'              => ChunkUpload::STATE_FAILED,
            'failure_code'       => $failureCode,
            // A query-builder update skips the model's array cast, so the reason is encoded here.
            'failure_reason'     => $error instanceof ChunkUploadError && $error->reason ? json_encode($error->reason) : null,
            'finalization_bytes' => 0,
        ]);
        $this->logger->write(
            runId: $upload->uuid,
            level: 'error',
            event: 'upload.failed',
            loc: self::class.'::failUpload',
            ctx: ['upload_id' => (int) $upload->id, 'failure_code' => $failureCode, 'error_type' => $error::class],
        );
    }

    protected function resetQueued(ChunkUpload $upload, string $failureCode): void
    {
        ChunkUpload::whereKey($upload->id)->update([
            'state'              => ChunkUpload::STATE_QUEUED,
            'failure_code'       => $failureCode,
            'failure_reason'     => null,
            'finalization_bytes' => 0,
        ]);
    }

    /**
     * What the assembled bytes are, when they plainly are a different media type from the one the browser declared
     * (a PDF named photo.jpg): the Library lists and filters by this, as the form upload already does by sniffing.
     * Only a confident media family overrides; anything the sniffer cannot place keeps the declared type.
     */
    private function sniffedMime(string $path, ?string $declared): ?string
    {
        if (! is_file($path)) {
            return $declared;
        }

        $sniffed = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $confident = preg_match('#^(image|video|audio)/#', $sniffed) === 1 || $sniffed === 'application/pdf';

        if (! $confident || $sniffed === 'image/svg+xml') {
            return $declared;
        }

        $declaredFamily = explode('/', strtolower((string) $declared))[0];

        // The same media family (a JPEG declared as image/png) is not worth second-guessing; a different one is the point.
        return $declared !== null && $declaredFamily === explode('/', $sniffed)[0] && $declaredFamily !== 'application' ? $declared : $sniffed;
    }
}
