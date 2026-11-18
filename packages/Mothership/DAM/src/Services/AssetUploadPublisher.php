<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Filesystem\FileStorer;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Duplicates\ExactDuplicateNotices;
use Mothership\DAM\Services\Duplicates\UploadReceipt;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Traits\Directory as DirectoryTrait;
use Mothership\User\Models\Admin;

/**
 * Publishes an upload as an asset (ADR 0009 slice 3): the bytes are staged on the asset disk, moved into
 * their content-addressed blob, and handed to the asset graph as a FileObject. The asset's `path` is the
 * logical Library path and names no file.
 */
class AssetUploadPublisher
{
    use DirectoryTrait;

    /** How many names an upload tries before a collision on dam_assets.path is the caller's error. */
    private const NAME_ATTEMPTS = 5;

    public function __construct(
        protected FileStorer $fileStorer,
        protected ContentStore $contentStore,
        protected AccessModel $accessModel,
        protected AccessListWriter $accessLists,
    ) {}

    public function publishUploadedFile(array $input): Asset
    {
        /** @var UploadedFile $file */
        $file = $input['source'];

        // Classify before staging: sanitizing an SVG rewrites the local temp file.
        $fileType = AssetHelper::getFileType($file);

        return $this->publishObject(
            input: $input,
            object: $this->ingestUploadedFile(file: $file, disk: (string) $input['disk'], mimeType: $input['mime_type'] ?? null),
            fileType: $fileType,
        );
    }

    /**
     * Publish a file the caller already staged (the chunk finalizer): on the asset disk, or on the local disk
     * `source_disk` names when the asset disk is remote (ADR 0017). `sha256`, when given, is the checksum of
     * exactly those bytes, so the file is moved into its blob without being read again.
     * `owner_id` names the uploader: the finalizer runs queued, with no session to take the owner from.
     */
    public function publishStagedFile(array $input): Asset
    {
        $disk = (string) $input['disk'];
        $sourceDisk = (string) ($input['source_disk'] ?? $disk);
        $stagedPath = (string) $input['source'];

        // A local staged file is classified from its own bytes: a zip is a web page only when it
        // really carries index.html. A remote one has no local path, so the extension/MIME pair decides.
        $realPath = $this->localStagedPath(disk: $sourceDisk, path: $stagedPath);
        $fileType = AssetHelper::classifyFile(
            mimeType: (string) $input['mime_type'],
            extension: strtolower((string) $input['extension']),
            realPath: $realPath,
        );

        // On failure the staged file is left in place for the caller, which owns its cleanup.
        $object = $this->contentStore->adoptStored(
            disk: $disk,
            sourcePath: $stagedPath,
            sha256: $input['sha256'] ?? null,
            mimeType: $input['mime_type'] ?: null,
            sourceDisk: $sourceDisk,
        );

        return $this->publishObject(input: $input, object: $object, fileType: $fileType);
    }

    /**
     * P5a guest intake (§3.3 "Guest intake publication"): publish a guest's staged file as a NEW asset, never touching
     * an existing one. Unlike publishStagedFile() it is:
     *
     * - **create-only:** always the first free name in the folder (`name (1).ext`…); the overwrite path
     *   (overwriteUnlessMerged) is never called, and `existing_asset` is never read, so a same-name file keeps its
     *   bytes, versions and sha256;
     * - **server-placed:** the folder is the submission's request's intake folder, read here, never taken from $input;
     * - **private:** owned by the request's creator, with an empty access list ("Only me"): it does not take the
     *   folder's list until a staff member accepts it (IntakeReview);
     * - **pending:** `availability = pending_review`, attributed to its submission (`upload_submission_id`), which
     *   records that a guest sent it;
     * - **atomic:** the asset row, its folder, its submission's file count and $input['guard'] (the caller's
     *   re-check of the request under its row lock) commit in one transaction, or none of them do.
     *
     * The returned asset carries an UploadReceipt (P3b-2) for staff; the guest is never shown it.
     *
     * @param  array{submission: UploadSubmission, original_name: string, mime_type: ?string, extension: ?string, source: string, sha256: string, disk: string, source_disk?: string, guard?: Closure(): void}  $input
     */
    public function publishIntake(array $input): Asset
    {
        $submission = $input['submission'];
        $request = $submission->uploadRequest()->firstOrFail();
        $directory = Directory::query()->findOrFail($request->directory_id);
        $disk = (string) $input['disk'];
        $sourceDisk = (string) ($input['source_disk'] ?? $disk);
        $stagedPath = (string) $input['source'];
        $realPath = $this->localStagedPath(disk: $sourceDisk, path: $stagedPath);
        $fileType = AssetHelper::classifyFile(
            mimeType: (string) $input['mime_type'],
            extension: strtolower((string) $input['extension']),
            realPath: $realPath,
        );
        $object = $this->contentStore->adoptStored(disk: $disk, sourcePath: $stagedPath, sha256: $input['sha256'], mimeType: $input['mime_type'] ?: null, sourceDisk: $sourceDisk);
        $directoryPath = $this->directoryPath($directory);
        $guard = $input['guard'] ?? null;

        $asset = $this->createUnderFreeName(
            directoryPath: $directoryPath,
            fileName: (string) $input['original_name'],
            create: function (string $fileName) use ($guard, $request, $submission, $directory, $directoryPath, $object, $fileType, $input): Asset {
                if ($guard !== null) {
                    $guard();
                }

                $asset = (new Asset([
                    'file_name'    => $fileName,
                    'file_type'    => $fileType,
                    'file_size'    => (int) $object->size,
                    'mime_type'    => $input['mime_type'],
                    'extension'    => $input['extension'],
                    'path'         => $directoryPath.'/'.$fileName,
                    'created_by'   => $request->created_by,
                    'availability' => Asset::AVAILABILITY_PENDING_REVIEW,
                ]))->withGraphContent($object);
                $asset->forceFill(['upload_submission_id' => $submission->id]);
                $asset->save();
                $asset->directories()->attach($directory->id);
                // No inheritAssets(): the empty list keeps it to its owner until it is accepted.
                UploadSubmission::query()->where('id', $submission->id)->increment('file_count');

                return $asset;
            },
            flagNewUserUpload: false,
        );

        $this->queueAssetFinalization($asset, null);
        $asset->uploadReceipt = new UploadReceipt(assetId: (int) $asset->id, sha256: (string) $object->sha256, outcome: UploadReceipt::CREATED);

        return $asset;
    }

    /**
     * Stage an upload and move it into its blob; nothing is written at a Library path. On failure only the
     * staging file is removed: a blob that already holds these bytes may be shared with other assets.
     */
    public function ingestUploadedFile(UploadedFile $file, string $disk, ?string $mimeType = null): FileObject
    {
        $stagingPath = $this->contentStore->stagingPath($file->getClientOriginalExtension());

        try {
            $this->fileStorer->stage(file: $file, disk: $disk, stagingPath: $stagingPath);

            return $this->contentStore->adoptStored(
                disk: $disk,
                sourcePath: $stagingPath,
                mimeType: $mimeType ?: $file->getMimeType(),
            );
        } catch (\Throwable $error) {
            Storage::disk($disk)->delete($stagingPath);

            throw $error;
        }
    }

    /**
     * Create the asset, or update the one it overwrites, with `$object` as its content. An overwrite keeps
     * the asset and its path, and the graph observer versions the previous original. The returned asset carries
     * an UploadReceipt (P3b-2) saying which of the three it was.
     *
     * The actor is `owner_id` when the caller names one (the queued chunk finalizer), else the session admin. A
     * same-name asset is overwritten only when that actor may replace it (mayReplace()); otherwise the upload is
     * published as a new asset under the next free name, so it can never change another person's file.
     */
    protected function publishObject(array $input, FileObject $object, string $fileType): Asset
    {
        /** @var Directory $directory */
        $directory = $input['directory'];
        $directoryPath = $this->directoryPath($directory);
        $originalName = (string) $input['original_name'];
        $actorId = array_key_exists('owner_id', $input) ? $input['owner_id'] : auth('admin')->id();
        $actorId = $actorId === null ? null : (int) $actorId;
        $existing = array_key_exists('existing_asset', $input)
            ? $input['existing_asset']
            : Asset::query()->where('path', $directoryPath.'/'.$originalName)->first();

        if ($existing && ! $this->mayReplace(existing: $existing, actorId: $actorId)) {
            Log::notice('DAM upload found its name on an asset its uploader may not replace; publishing it as a new asset.', [
                'event'    => 'upload.overwrite_not_permitted',
                'asset_id' => (int) $existing->id,
                'actor_id' => $actorId,
            ]);
            $existing = null;
        }

        $payload = fn (string $fileName): array => [
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => (int) $object->size,
            'mime_type' => $input['mime_type'],
            'extension' => $input['extension'],
            'path'      => $directoryPath.'/'.$fileName,
        ];

        // P3b-2: the entry's current file, read before an overwrite replaces it.
        $previousSha256 = $existing ? (app(ExactDuplicateNotices::class)->currentSha256([(int) $existing->id])[(int) $existing->id] ?? null) : null;

        $overwritten = $existing ? $this->overwriteUnlessMerged(existing: $existing, object: $object, attributes: $payload($originalName), actorId: $actorId) : null;

        if ($overwritten === null) {
            $existing = null;
        }

        // P3b-2: what this upload did. A refused overwrite of a merge source (P3b-1) publishes a new entry, so it reads as created.
        $outcome = ExactDuplicateNotices::outcome(
            replaced: $existing,
            previousSha256: $existing ? $previousSha256 : null,
            sha256: (string) $object->sha256,
        );

        $asset = $overwritten
            ?? $this->createUnderFreeName(
                directoryPath: $directoryPath,
                fileName: $originalName,
                create: function (string $fileName) use ($payload, $directory, $object, $input, $actorId): Asset {
                    // A queued caller (the chunk finalizer) has no session for Asset::creating to take the
                    // owner from, so it names the uploader as `owner_id` (sharing spec, "Inherit on create").
                    // An overwrite above keeps the asset's existing owner.
                    $asset = (new Asset([...$payload($fileName), 'created_by' => $input['owner_id'] ?? null]))->withGraphContent($object, $actorId);
                    $asset->save();
                    $asset->directories()->attach($directory->id);
                    // The new file takes its folder's access list, in the same transaction as the attach
                    // (ADR 0010, spec D3).
                    $this->accessLists->inheritAssets([$asset->id]);

                    // A caller that must find this asset again after a failure (the chunk finalizer) records it here,
                    // in the transaction that creates it: the asset and the caller's marker commit or roll back together.
                    if (isset($input['on_created'])) {
                        ($input['on_created'])($asset);
                    }

                    return $asset;
                },
            );

        if ($existing) {
            $this->clearAssetCache($existing, (string) $input['disk']);
        }

        $this->queueAssetFinalization(
            asset: $asset,
            tracker: $input['tracker'] ?? null,
            bytesChanged: $existing !== null && $previousSha256 !== (string) $object->sha256,
            finalizationKey: $input['finalization_key'] ?? null,
        );
        $asset->uploadReceipt = new UploadReceipt(assetId: (int) $asset->id, sha256: (string) $object->sha256, outcome: $outcome);

        return $asset;
    }

    /**
     * Overwrite an asset found at the upload's path: the graph observer versions its previous original. A merge source
     * is never overwritten (P3b-1: its split must give it back exactly), asked once here and again under the row lock a
     * merge also takes, so whichever commits first wins. Null when refused: the caller publishes the upload as a new
     * asset under the next free name, as when the name belongs to a trashed file, and the uploader sees the new name.
     *
     * `$actorId` is the uploader, recorded as the new version's author. mayReplace() is asked again here, of the row
     * just locked and under the access-model fence: an access-list rewrite locks the same asset row (AccessListWriter
     * holds files with lockedForGraph()), so a revocation committed after the caller's own check is seen here and the
     * upload becomes a new asset instead. No actor never replaces.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function overwriteUnlessMerged(Asset $existing, FileObject $object, array $attributes, ?int $actorId): ?Asset
    {
        $refusal = 'merged';
        $overwritten = $existing->isMerged() ? null : $this->accessModel->sharedly(function () use ($existing, $object, $attributes, $actorId, &$refusal): ?Asset {
            $locked = Asset::query()->whereKey($existing->id)->lockedForGraph()->first();

            if ($locked === null || $locked->isMerged()) {
                return null;
            }

            if (! $this->mayReplace(existing: $locked, actorId: $actorId)) {
                $refusal = 'not_permitted';

                return null;
            }

            $locked->withGraphContent($object, $actorId)->update($attributes);

            return $locked->refresh();
        });

        if ($overwritten === null) {
            Log::notice('DAM upload could not replace the asset at its name; publishing it as a new asset.', [
                'event'    => $refusal === 'not_permitted' ? 'upload.overwrite_not_permitted' : 'upload.overwrite_refused',
                'asset_id' => (int) $existing->id,
                'actor_id' => $actorId,
            ]);
        }

        return $overwritten;
    }

    /**
     * Whether an upload by `$actorId` may replace `$existing`, the asset already at its path, with a new version.
     * The re-upload endpoint's rule for a named admin: the `dam.asset.re_upload` key (a full admin holds every key)
     * and edit rights on that very asset under the active access model (AssetAccessDecision, PURPOSE_EDIT), and, for a role
     * limited to its own items, that the asset is theirs. No
     * actor, an unknown or disabled one, never may: the upload then becomes a new asset under a free name.
     */
    public function mayReplace(Asset $existing, ?int $actorId): bool
    {
        $actor = $actorId === null ? null : Admin::query()->with('role')->find($actorId);

        if ($actor === null || ! (bool) $actor->status || $actor->role === null) {
            return false;
        }

        if ($actor->role->permission_type !== 'all' && ! $actor->hasPermission('dam.asset.re_upload')) {
            return false;
        }

        // A role limited to its own items never replaces another person's asset (or an ownerless one): the upload is
        // published as a new asset under the next free name instead. Asked of every caller of this method, so the
        // direct and chunked uploads share it, and again under the row lock in overwriteUnlessMerged().
        if (! app(RoleBuilder::class)->canUserDelete(model: $existing, admin: $actor)) {
            return false;
        }

        return app(AssetAccessDecision::class)
            ->for(AccessContext::forAdmin($actor, AccessContext::PURPOSE_EDIT), $existing)
            ->allowed;
    }

    /**
     * A file a non-admin user uploaded waits on the "New user uploads" page until an admin sorts it. The owner
     * is the asset's `created_by` (the session user, or `owner_id` for the queued chunk finalizer); an asset
     * with no owner, or an admin's own upload, is never flagged.
     */
    protected function flagNewUserUpload(Asset $asset): void
    {
        if ($asset->created_by === null || NewUserUploads::isAdmin(adminId: (int) $asset->created_by)) {
            return;
        }

        $now = now();

        Asset::query()->whereKey($asset->id)->update(['needs_sorting_at' => $now]);
        $asset->setAttribute('needs_sorting_at', $now);
        $asset->syncOriginalAttribute('needs_sorting_at');
    }

    /**
     * Save a new asset under the first free name in `$directoryPath`, starting from `$fileName`: `$create` saves
     * it under the name it is given. Two uploads can pick the same free name, and the one that loses the insert to
     * the UNIQUE constraint on dam_assets.path asks again for the next `name(n)` in a fresh transaction (ADR 0009
     * slice 3), up to NAME_ATTEMPTS times. The writer's already-ingested FileObject is handed over again on every
     * attempt, and ContentStore::retain() recreates its row at attach if a concurrent delete reaped it meanwhile.
     * Any other failure propagates.
     *
     * $flagNewUserUpload false leaves the "New user uploads" flag to the caller (guest intake is flagged when accepted).
     *
     * @param  Closure(string): Asset  $create
     */
    public function createUnderFreeName(string $directoryPath, string $fileName, Closure $create, bool $flagNewUserUpload = true): Asset
    {
        for ($attempt = 1; ; $attempt++) {
            $name = $this->generateUniqueFileName($directoryPath, $fileName);

            try {
                // sharedly(), not DB::transaction(): this is the outermost transaction of every upload
                // that creates an asset, so ADR 0010's fence belongs here — the DAM_ACCESS_MODEL row
                // FOR SHARE as its first statement, before the asset row, its blob and its list.
                return $this->accessModel->sharedly(function () use ($create, $name, $flagNewUserUpload): Asset {
                    $asset = $create($name);

                    if ($flagNewUserUpload) {
                        $this->flagNewUserUpload($asset);
                    }

                    return $asset;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::NAME_ATTEMPTS || ! $this->isPathCollision($e)) {
                    throw $e;
                }

                Log::notice('DAM upload lost its name to a concurrent insert; asking for the next free name.', [
                    'event'   => 'upload.name_collision',
                    'path'    => rtrim($directoryPath, '/').'/'.$name,
                    'attempt' => $attempt,
                ]);
            }
        }
    }

    /**
     * Whether `$e` is the UNIQUE constraint on dam_assets.path and not some other key. PostgreSQL and MySQL/MariaDB
     * name the index (`"dam_assets_path_unique"`, `for key 'dam_assets.dam_assets_path_unique'`); SQLite names the
     * column (`UNIQUE constraint failed: dam_assets.path`).
     */
    private function isPathCollision(UniqueConstraintViolationException $e): bool
    {
        $table = DB::connection()->getTablePrefix().(new Asset)->getTable();
        $detail = (string) ($e->errorInfo[2] ?? $e->getMessage());

        return preg_match('/["\'.]'.preg_quote($table.'_path_unique', '/').'["\']/', $detail) === 1
            || preg_match('/UNIQUE constraint failed: '.preg_quote($table.'.path', '/').'$/m', $detail) === 1;
    }

    /** The staged file's absolute path when its disk is local, for classifying it from its bytes; else null. */
    protected function localStagedPath(string $disk, string $path): ?string
    {
        return $this->contentStore->isLocal($disk) ? Storage::disk($disk)->path($path) : null;
    }

    protected function directoryPath(Directory $directory): string
    {
        return Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath();
    }

    /**
     * An overwrite gives the asset a new original under a new sha256 (ADR 0009): the derivatives this
     * asset now serves are keyed by that new sha, in a directory nothing has published to yet — the
     * previous sha's derivatives are simply never looked up again, so no path-keyed purge is needed here.
     */
    protected function clearAssetCache(Asset $asset, string $disk): void
    {
        CoverArt::purgeLegacy(assetId: (int) $asset->id, disk: $disk);
    }

    /**
     * Retry a published asset's finalization through its original batch. A pending batch can mean the queue push
     * failed after the row committed, so retrying that same batch is safe: the job claims it before processing.
     * Trackerless callers opt into this recovery by supplying a stable key for the publication request.
     */
    public function ensureFinalizationQueued(Asset $asset, ?UploadTracker $tracker, ?string $finalizationKey = null): void
    {
        $batch = $tracker
            ? UploadBatch::query()->where('upload_tracker_id', $tracker->id)->where('asset_id', $asset->id)->latest('id')->first()
            : ($finalizationKey === null ? null : UploadBatch::query()->where('finalization_key', $finalizationKey)->first());

        if ($batch !== null) {
            if ((int) $batch->asset_id !== (int) $asset->id) {
                throw new \LogicException('The finalization key belongs to a different asset.');
            }

            if ($finalizationKey !== null && $batch->state === UploadBatch::STATE_FAILED) {
                UploadBatch::query()->whereKey($batch->id)->where('state', UploadBatch::STATE_FAILED)
                    ->update([
                        'state'             => UploadBatch::STATE_PENDING,
                        'error'             => null,
                        'recovery_attempts' => 0,
                        'last_recovered_at' => null,
                    ]);
                $batch->refresh();
            }

            if ($batch->state === UploadBatch::STATE_PENDING) {
                ProcessAssetUpload::dispatch($asset->id, $batch->id);
            }

            return;
        }

        $this->queueAssetFinalization(asset: $asset, tracker: $tracker, finalizationKey: $finalizationKey);
    }

    protected function queueAssetFinalization(Asset $asset, ?UploadTracker $tracker, bool $bytesChanged = false, ?string $finalizationKey = null): void
    {
        if ($finalizationKey !== null && ($tracker !== null || preg_match('/\A[a-f0-9]{64}\z/', $finalizationKey) !== 1)) {
            throw new \InvalidArgumentException('A finalization key must be a 64-character lowercase digest without a tracker.');
        }

        if ($tracker !== null) {
            $batch = UploadBatch::create([
                'upload_tracker_id' => $tracker->id,
                'asset_id'          => $asset->id,
                'state'             => UploadBatch::STATE_PENDING,
            ]);
        } elseif ($finalizationKey !== null) {
            $batch = UploadBatch::query()->createOrFirst(
                ['finalization_key' => $finalizationKey],
                ['asset_id' => $asset->id, 'state' => UploadBatch::STATE_PENDING],
            );

            if ((int) $batch->asset_id !== (int) $asset->id) {
                throw new \LogicException('The finalization key belongs to a different asset.');
            }

            if ($batch->state !== UploadBatch::STATE_PENDING) {
                return;
            }
        } else {
            $batch = null;
        }

        // PendingDispatch pushes when it is destroyed at the end of this statement. If that push throws after
        // the batch insert, the pending row remains the durable identity for the next attempt's dispatch.
        ProcessAssetUpload::dispatch($asset->id, $batch?->id, bytesChanged: $bytesChanged);
    }
}
