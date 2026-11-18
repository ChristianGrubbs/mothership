<?php

namespace Mothership\DAM\Services;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Exceptions\MergeActiveException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PathReferences;
use RuntimeException;
use Throwable;

class AssetDeletionService
{
    private const TOMBSTONE_TABLE = 'dam_file_deletion_tombstones';

    /** The layered image editor's working area; each asset's resources live under `<root>/<asset id>/`. */
    private const EDITOR_ROOT = 'assets/.editor';

    /** Every extension MetadataExtractionService::storeCoverArt() gives `covers/<asset id>.<ext>`. */
    private const COVER_EXTENSIONS = ['jpg', 'png', 'gif', 'webp'];

    /** An asset-keyed cover (`covers/<asset id>.<ext>`), as a copy's inherited `cover_art_path` names it. */
    private const COVER_PATTERN = CoverArt::PATTERN;

    /** Where WebPageBundler unpacks each web page, `bundles/<asset id>/<version>/`. */
    private const BUNDLE_ROOT = 'bundles';

    /** Tombstones queued per statement, so a large bundle stays under every driver's parameter limit. */
    private const QUEUE_CHUNK = 200;

    private const DEPENDENCY_TYPES = [
        AssetRelationship::TYPE_CONTAINS,
        AssetRelationship::TYPE_USES_TEXTURE,
        AssetRelationship::TYPE_SIDECAR_OF,
        AssetRelationship::TYPE_SUBTITLE_FOR,
        AssetRelationship::TYPE_TRANSCRIPT_OF,
        AssetRelationship::TYPE_SOURCE_FOR,
    ];

    /**
     * @return array{blocking: array<int, array<string, int|string|null>>, warnings: array<int, array<string, int|string|null>>}
     */
    public function analyze(Asset $asset): array
    {
        $edges = AssetRelationship::query()
            ->where(function ($query) use ($asset) {
                $query->where('from_asset_id', $asset->getKey())
                    ->orWhere('to_asset_id', $asset->getKey());
            })
            ->orderBy('id')
            ->get();

        $serialize = static fn (AssetRelationship $edge): array => [
            'id'            => $edge->id,
            'type'          => $edge->type,
            'from_asset_id' => $edge->from_asset_id,
            'to_asset_id'   => $edge->to_asset_id,
        ];

        $blocking = $edges
            ->filter(fn (AssetRelationship $edge) => (int) $edge->to_asset_id === (int) $asset->getKey()
                && in_array($edge->type, self::DEPENDENCY_TYPES, true))
            ->map($serialize)
            ->values()
            ->all();
        $blockingIds = array_column($blocking, 'id');

        return [
            'blocking' => $blocking,
            // Custom relationship types are deliberately supported by ADR 0002.
            // Surface every non-blocking edge rather than silently discarding a
            // custom dependency from the deletion analysis.
            'warnings' => $edges
                ->filter(fn (AssetRelationship $edge) => ! in_array($edge->id, $blockingIds, true))
                ->map($serialize)
                ->values()
                ->all(),
        ];
    }

    /**
     * Deletes the asset for good and reclaims what only it used. Since the trash (P2 S4) every user-facing delete
     * goes through AssetTrashService instead; this is its permanent delete (the Trash page and dam:trash:purge), so
     * it finds a trashed asset as well as one that is not.
     *
     * $onlyIf, when given, is asked about the asset as this delete has locked and re-read it, inside the same
     * transaction as the delete itself. A false answer refuses the delete with ModelNotFoundException and changes
     * nothing, so a decision a caller made from an earlier, unlocked read (the trash's purge: still trashed, still
     * past its 30 days) can never outlive a Restore or a re-trash that committed since. An asset in an active merge
     * is refused with MergeActiveException (P3b-1).
     *
     * @param  (Closure(Asset): bool)|null  $onlyIf
     * @return array{blocking: array<int, array<string, int|string|null>>, warnings: array<int, array<string, int|string|null>>}
     */
    public function delete(Asset $asset, bool $force = false, ?Closure $onlyIf = null): array
    {
        [$analysis, $tombstoneIds, $disk, $directories] = DB::transaction(function () use ($asset, $force, $onlyIf): array {
            $asset = Asset::withTrashed()->lockForUpdate()->findOrFail($asset->getKey());

            if ($onlyIf !== null && ! $onlyIf($asset)) {
                throw (new ModelNotFoundException)->setModel(Asset::class, [$asset->getKey()]);
            }

            // P3b-1: a merge source, or a destination holding merged files, is not deleted for good until its merge is
            // finalized (or split). Decided under this delete's row lock, which merge, split and finalize also take.
            if (AssetMerge::query()->active()->where(fn ($q) => $q->where('dest_id', $asset->getKey())->orWhere('source_id', $asset->getKey()))->exists()) {
                throw new MergeActiveException((int) $asset->getKey());
            }

            $analysis = $this->analyze($asset);

            if (! $force && $analysis['blocking'] !== []) {
                throw new AssetDeletionException($analysis);
            }

            $fileObjectIds = AssetFile::query()
                ->where('dam_asset_id', $asset->getKey())
                ->pluck('file_object_id')
                ->merge(Rendition::query()
                    ->where('dam_asset_id', $asset->getKey())
                    ->whereNotNull('output_file_object_id')
                    ->pluck('output_file_object_id'))
                // Read before releaseEditorResources() deletes the asset's editor resource rows: their blobs
                // are reference-counted like any other, so one only this asset's documents used is reclaimed.
                ->merge(ImageEditorResource::query()
                    ->where('asset_id', $asset->getKey())
                    ->whereNotNull('file_object_id')
                    ->pluck('file_object_id'))
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            $fileObjects = FileObject::query()
                ->whereIn('id', $fileObjectIds)
                ->lockForUpdate()
                ->get();

            AssetRelationship::query()
                ->where('from_asset_id', $asset->getKey())
                ->orWhere('to_asset_id', $asset->getKey())
                ->delete();

            // A share link targets its asset by id with no foreign key, so it would outlive the asset.
            Share::query()
                ->where('share_type', Share::TYPE_ASSET)
                ->where('target_id', $asset->getKey())
                ->delete();

            $legacyDisk = Directory::getAssetDisk();
            // A trashed asset's Library file, where a pre-ADR-0009 one is left, is at the path it was trashed from;
            // processPendingFileDeletions() keeps it when another asset has taken that path since.
            $legacyPath = (string) ($asset->trashed_from_path ?: $asset->path);
            $customThumbnail = CustomThumbnailService::location($asset);
            $assetKeyedPaths = $this->assetKeyedPaths(asset: $asset, fileObjects: $fileObjects);
            $editorFiles = $this->releaseEditorResources(asset: $asset, disk: $legacyDisk);

            $asset->forceDelete();

            // Any editor file, in the asset's own editor folder or not, may be named by a row this delete leaves:
            // a save-as-new copies a resource's details unchanged, so a copy on another asset can name a legacy
            // input or mask here. With this asset's rows gone, whatever still names a file keeps it.
            $editorPaths = array_values(array_filter(
                $editorFiles,
                fn (string $path): bool => ! PathReferences::isNamed(path: $path, disk: $legacyDisk),
            ));

            // Load the remaining references once, in one query, retaining blobs another asset's attachment,
            // rendition or editor document still names (FileObject::referenced() is the one definition).
            $referencedIds = FileObject::query()
                ->whereIn('id', $fileObjectIds)
                ->referenced()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);

            $orphaned = $fileObjects->whereNotIn('id', $referencedIds->all());
            $locations = collect();
            foreach ($customThumbnail ? CustomThumbnailService::storedPaths($customThumbnail) : [] as $stored) {
                $locations->push(['disk' => $legacyDisk, 'path' => $stored]);
            }

            foreach ([...$assetKeyedPaths, ...$editorPaths] as $path) {
                $locations->push(['disk' => $legacyDisk, 'path' => $path]);
            }

            // One orphaned FileObject, one blob: its locator, which may carry a suffix. The suffix-less
            // twin is queued too because the pre-ADR-0009 observer copied every ingest there even when an
            // existing row named a suffixed blob; no other row can name either path (sha256 is unique).
            foreach ($orphaned as $fileObject) {
                $locations->push(['disk' => $fileObject->disk, 'path' => $fileObject->path]);
                $locations->push([
                    'disk' => $fileObject->disk,
                    'path' => app(ContentStore::class)->objectPath($fileObject->sha256),
                ]);
            }

            if ($legacyPath !== '') {
                $locations->push(['disk' => $legacyDisk, 'path' => $legacyPath]);

                // Sha-keyed derivatives (ADR 0009 slice 2) are no longer purged here: they outlive
                // this asset's Library path and are only reclaimed with their blob, in
                // processPendingFileDeletions() below. Path-keyed mirrors are left for slice 4.
            }

            if ($orphaned->isNotEmpty()) {
                FileObject::query()->whereIn('id', $orphaned->pluck('id'))->delete();
            }

            $tombstoneIds = $this->queueFileDeletions($locations
                ->filter(fn (array $location) => $location['disk'] !== '' && $location['path'] !== '')
                ->unique(fn (array $location) => $this->locationKey($location['disk'], $location['path']))
                ->values()
                ->all());

            // Per-asset directories whose files were just tombstoned; removed once they hold nothing.
            $directories = array_values(array_filter([
                $customThumbnail ? dirname($customThumbnail['path']) : null,
                $editorPaths !== [] ? self::EDITOR_ROOT.'/'.$asset->getKey() : null,
            ]));

            return [$analysis, $tombstoneIds, $legacyDisk, $directories];
        });

        // Database truth commits first. A failed unlink leaves a durable retry
        // record and cannot resurrect a partly deleted asset graph.
        $assetId = (int) $asset->getKey();
        $this->afterOutermostCommit(function () use ($assetId, $tombstoneIds, $disk, $directories): void {
            $this->purgeBundles(assetId: $assetId, disk: $disk);
            $this->processPendingFileDeletions($tombstoneIds);
            $this->pruneEmptyDirectories(disk: $disk, directories: $directories);
        });

        return $analysis;
    }

    /**
     * Remove the asset's web-page bundles (WebPageBundler::purge(), after commit like every other unlink). The
     * purge reports no failure of its own, so whatever is still under `bundles/<asset id>/` afterwards gets one
     * tombstone per file: dam:storage:reap retries each hourly, and processPendingFileDeletions() removes the
     * bundle's empty folders once its last file is gone.
     */
    private function purgeBundles(int $assetId, string $disk): void
    {
        $root = self::BUNDLE_ROOT.'/'.$assetId;

        try {
            app(WebPageBundler::class)->purge($assetId);
        } catch (Throwable $e) {
            Log::error('DAM web-page bundle purge failed; the files it left are queued for retry.', ['asset_id' => $assetId, 'exception' => $e]);
        }

        try {
            $left = Storage::disk($disk)->allFiles($root);
        } catch (Throwable $e) {
            Log::error('DAM web-page bundle cleanup could not list what the purge left.', ['asset_id' => $assetId, 'exception' => $e]);

            return;
        }

        if ($left === []) {
            return;
        }

        foreach (array_chunk($left, self::QUEUE_CHUNK) as $chunk) {
            $this->queueFileDeletions(array_map(fn (string $path): array => ['disk' => $disk, 'path' => $path], $chunk));
        }

        Log::warning('DAM web-page bundle purge left files; each is queued for dam:storage:reap.', ['asset_id' => $assetId, 'files' => count($left)]);
    }

    /**
     * Run physical cleanup once the outermost transaction commits, not when delete()'s own (possibly nested)
     * transaction does. Inside a caller's transaction (a folder delete under DirectoryTreeLock, a demo-data
     * clear) a later failure rolls this asset's rows and tombstones back, and bytes unlinked before that would
     * leave restored rows naming nothing. Laravel drops the callback on that rollback and runs it at once when
     * no transaction is open, so a caller outside any transaction still sees a cleanup failure. A deferred
     * failure only stays on its tombstone for a later pass (processPendingFileDeletions() counts and logs it):
     * the caller's work has already committed, and the connection's other after-commit callbacks must still run.
     */
    private function afterOutermostCommit(Closure $cleanup): void
    {
        $deferred = false;

        DB::afterCommit(function () use (&$deferred, $cleanup): void {
            if (! $deferred) {
                $cleanup();

                return;
            }

            try {
                $cleanup();
            } catch (Throwable $e) {
                Log::error('DAM deferred physical file cleanup failed; its tombstones stay pending.', ['exception' => $e]);
            }
        });

        $deferred = true;
    }

    /**
     * Files named after an asset's id rather than its content, which therefore go with the last asset naming
     * them: the audio cover art (`covers/<id>.<ext>`, MetadataExtractionService::storeCoverArt()) and the
     * browser-captured model thumbnail (`thumbnails/models/<id>-<sha12>.jpg`, ModelThumbnailService), one per
     * original the model has had. This asset's own are built from its id. A copy (CopyController, MassCopy)
     * inherits its source's meta_data, so it names the source's cover and capture too: a `cover_art_path` or
     * `thumbnail_path` of either shape is included whatever id it carries, and any path another asset's
     * `cover_art_path` or `thumbnail_path` still names is left out, so the surviving copy keeps it and its own
     * delete removes it. A sha-keyed poster in `thumbnail_path` matches neither shape: it is shared and is
     * reclaimed only with its blob.
     *
     * @param  iterable<FileObject>  $fileObjects
     * @return list<string>
     */
    private function assetKeyedPaths(Asset $asset, iterable $fileObjects): array
    {
        $id = (int) $asset->getKey();
        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        $paths = StackMemberState::artworkPaths($meta);

        if (str_starts_with((string) $asset->mime_type, 'audio/') || ! empty($meta['cover_art_path'])) {
            foreach (self::COVER_EXTENSIONS as $extension) {
                $paths[] = 'covers/'.$id.'.'.$extension;
            }
        }

        // Hashed covers are never deleted while the asset lives (a replaced one is only left behind), and an entry can stop
        // being audio (a promoted PDF, image or model), so whatever family it is now, this is where they go: every
        // `covers/<id>-*`, less any a remaining row names (below), decided under the asset's row lock.
        foreach ($this->hashedCovers(id: $id) as $cover) {
            $paths[] = $cover;
        }

        $patterns = [
            'cover_art_path' => self::COVER_PATTERN,
            'thumbnail_path' => '#^'.preg_quote(ModelThumbnailService::DIRECTORY, '#').'\d+-[a-f0-9]{12}\.jpg$#D',
        ];

        foreach ($patterns as $key => $pattern) {
            $named = $meta[$key] ?? null;

            if (is_string($named) && preg_match($pattern, $named) === 1) {
                $paths[] = $named;
            }
        }

        if (ModelThumbnailService::supports($asset)) {
            foreach ($fileObjects as $fileObject) {
                $paths[] = ModelThumbnailService::generatedPath($asset, (string) $fileObject->sha256);
            }
        }

        $paths = array_values(array_unique($paths));

        return array_values(array_diff($paths, $this->namedByOtherAssets(assetId: $id, paths: $paths)));
    }

    /** @return list<string> the asset's hashed cover files on the asset disk */
    private function hashedCovers(int $id): array
    {
        try {
            return array_values(array_filter(
                Storage::disk(Directory::getAssetDisk())->files('covers'),
                fn (string $file): bool => str_starts_with($file, 'covers/'.$id.'-') && preg_match(CoverArt::PATTERN, $file) === 1,
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Which of `$paths` another asset's `cover_art_path` or `thumbnail_path` names. A JSON where clause, so the
     * stored form (Laravel's `array` cast escapes `/` as `\/`) does not matter: PostgreSQL `->>` on the json
     * column, `json_extract` on SQLite, `json_unquote(json_extract())` on MySQL.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function namedByOtherAssets(int $assetId, array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        // A trashed copy still names them: Restore must find its cover and capture where it left them (P2 S4).
        return Asset::withTrashed()
            ->whereKeyNot($assetId)
            ->where(function ($query) use ($paths): void {
                $query->whereIn('meta_data->cover_art_path', $paths)->orWhereIn('meta_data->thumbnail_path', $paths)
                    ->orWhereIn('meta_data->custom_thumbnail->path', $paths)->orWhereNotNull('meta_data->'.StackMemberState::KEY)->orWhereIn('meta_data->stack_cover->path', $paths);
            })
            ->select(['id', 'meta_data'])->cursor()
            ->flatMap(fn (Asset $other): array => is_array($other->meta_data)
                ? StackMemberState::artworkPaths($other->meta_data)
                : [])
            ->filter(fn ($path): bool => is_string($path) && in_array($path, $paths, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The layered image editor's rows and working files for this asset (ADR 0009 known gap: its resources
     * are the one remaining physical store). Its documents and resources go here, before the asset (their
     * foreign keys would cascade anyway). Returns the files the caller tombstones with the asset's other
     * locations, less each that a remaining row still names (PathReferences, resource details included), so
     * each is unlinked only after the outermost commit:
     *
     * - every file in the asset's own editor folder `assets/.editor/<asset id>/`, each a row names and each a
     *   failed write left there with none. Another asset's resource can still name one through its details;
     * - each other file under `assets/` a resource (its path, or a legacy working file its `details` names) or
     *   a document's content path names, outside the blob store and staging. A document's content path equal to
     *   the asset's own Library path (in the trash, the one it was trashed from) is left out: that file is queued
     *   with the asset already.
     *
     * A resource with no path contributes no file here.
     *
     * @return list<string>
     */
    private function releaseEditorResources(Asset $asset, string $disk): array
    {
        $folder = self::EDITOR_ROOT.'/'.$asset->getKey();
        $named = [];

        foreach (ImageEditorResource::query()->where('asset_id', $asset->getKey())->get(['path', 'details']) as $resource) {
            $named[] = $resource->path;

            // Every value at any depth, as PathReferences reads details: a path protection finds nested must
            // also be reclaimed from here, or the last owner's delete would leave it behind for good.
            $details = $resource->details;

            if (is_array($details)) {
                array_walk_recursive($details, function ($value) use (&$named): void {
                    $named[] = $value;
                });
            }
        }

        array_push($named, ...ImageEditorDocument::query()->where('asset_id', $asset->getKey())->pluck('content_path')->all());

        $owned = [];
        $elsewhere = [];

        foreach ($named as $path) {
            if (! is_string($path) || $path === '' || str_contains($path, '..')) {
                continue;
            }

            if (str_starts_with($path, $folder.'/')) {
                $owned[] = $path;
            } elseif (str_starts_with($path, Directory::ASSETS_DIRECTORY.'/')
                && ! str_starts_with($path, ContentStore::ROOT.'/')
                && ! str_starts_with($path, ContentStore::STAGING.'/')
                && ! in_array($path, [(string) $asset->path, (string) $asset->trashed_from_path], true)) {
                $elsewhere[] = $path;
            }
        }

        try {
            array_push($owned, ...Storage::disk($disk)->allFiles($folder));
        } catch (Throwable $e) {
            // An unlistable folder never blocks the delete; the files rows name are still queued.
            Log::warning('DAM delete could not list the asset\'s editor folder.', ['asset_id' => $asset->getKey(), 'exception' => $e]);
        }

        ImageEditorDocument::query()->where('asset_id', $asset->getKey())->delete();
        ImageEditorResource::query()->where('asset_id', $asset->getKey())->delete();

        return array_values(array_unique([...$owned, ...$elsewhere]));
    }

    /**
     * Remove per-asset directories (`custom-thumbnails/<id>`, `assets/.editor/<id>`, `bundles/<id>`) once their
     * tombstoned files are gone. A directory that still holds anything, such as a file whose unlink failed and
     * waits on its tombstone, is left alone.
     *
     * @param  list<string>  $directories
     */
    private function pruneEmptyDirectories(string $disk, array $directories): void
    {
        $storage = Storage::disk($disk);

        foreach ($directories as $directory) {
            if ($storage->allFiles($directory) === []) {
                $storage->deleteDirectory($directory);
            }
        }
    }

    /**
     * Retry all pending tombstones, or only the supplied IDs.
     *
     * @param  array<int, int>|null  $ids
     */
    public function processPendingFileDeletions(?array $ids = null, bool $continueOnError = false): void
    {
        $query = DB::table(self::TOMBSTONE_TABLE)->orderBy('id');

        if ($ids !== null) {
            if ($ids === []) {
                return;
            }

            $query->whereIn('id', $ids);
        }

        $tombstones = $query->get();

        if ($tombstones->isEmpty()) {
            return;
        }

        $referenced = $this->referencedLocations($tombstones->all());
        $store = app(ContentStore::class);
        $bundles = [];

        foreach ($tombstones as $tombstone) {
            $key = $this->locationKey($tombstone->disk, $tombstone->path);

            if (isset($referenced[$key])) {
                DB::table(self::TOMBSTONE_TABLE)->where('id', $tombstone->id)->delete();

                continue;
            }

            try {
                $sha256 = $store->objectSha((string) $tombstone->path);

                $state = 'unlinked';

                if ($sha256 === null) {
                    $this->unlink($tombstone);
                } else {
                    // One blob lock at a time, and a fresh look under it: an ingest writes its FileObject row and
                    // its reference in one transaction under the same lock (ContentStore::record), so a row naming
                    // this path now means the blob is wanted again. Otherwise the ingest waits for this unlink
                    // and writes the blob anew.
                    $state = DB::transaction(function () use ($store, $sha256, $tombstone): string {
                        $store->lockBlob($sha256);
                        $row = FileObject::query()->where('disk', $tombstone->disk)->where('path', $tombstone->path)->first();

                        if ($row === null) {
                            $this->unlink($tombstone);
                            // The blob is reclaimed under the same lock: its derivatives (ADR 0009,
                            // keyed by this sha) can never be looked up again.
                            DerivativeImages::purgeContent(Storage::disk(Directory::getAssetDisk()), $sha256);

                            return 'unlinked';
                        }

                        return $row->isReferenced() ? 'wanted' : 'unreferenced';
                    });
                }

                if ($state === 'unreferenced') {
                    // A row nothing references: left by an ingest that never attached. Keep the blob and the
                    // tombstone, visibly, rather than forget the blob; a later pass retries it.
                    DB::table(self::TOMBSTONE_TABLE)->where('id', $tombstone->id)->update([
                        'attempts'   => DB::raw('attempts + 1'),
                        'last_error' => 'unreferenced_file_object',
                        'updated_at' => now(),
                    ]);
                    Log::warning('DAM physical file cleanup kept a blob an unreferenced FileObject still names.', [
                        'disk' => $tombstone->disk,
                        'path' => $tombstone->path,
                    ]);

                    continue;
                }

                DB::table(self::TOMBSTONE_TABLE)->where('id', $tombstone->id)->delete();

                // A bundle file purgeBundles() queued: its asset is gone, so its folders go once they are empty.
                if ($state === 'unlinked' && preg_match('#^'.self::BUNDLE_ROOT.'/\d+(?=/)#', (string) $tombstone->path, $bundle) === 1) {
                    $bundles[$tombstone->disk][$bundle[0]] = true;
                }
            } catch (Throwable $e) {
                DB::table(self::TOMBSTONE_TABLE)
                    ->where('id', $tombstone->id)
                    ->update([
                        'attempts'   => DB::raw('attempts + 1'),
                        'last_error' => $e->getMessage(),
                        'updated_at' => now(),
                    ]);

                Log::error('DAM physical file cleanup failed.', [
                    'disk'      => $tombstone->disk,
                    'path'      => $tombstone->path,
                    'exception' => $e,
                ]);

                if (! $continueOnError) {
                    throw $e;
                }
            }
        }

        foreach ($bundles as $disk => $roots) {
            $this->pruneEmptyDirectories(disk: (string) $disk, directories: array_keys($roots));
        }
    }

    /**
     * The scheduled retry (`dam:storage:reap`, hourly): bounded batches of pending tombstones, so a deleted
     * item's files are removed even when the unlink after its delete failed, and one file that still cannot be
     * removed never strands the others queued behind it. processPendingFileDeletions() records each failure on
     * its tombstone. Returns how many tombstones this pass cleared and how many are still pending.
     *
     * @return array{cleared: int, pending: int}
     */
    public function retryPendingFileDeletions(): array
    {
        $cleared = 0;
        DB::table(self::TOMBSTONE_TABLE)->orderBy('id')->chunkById(200, function ($rows) use (&$cleared): void {
            $ids = $rows->pluck('id')->all();
            $this->processPendingFileDeletions(ids: $ids, continueOnError: true);
            $cleared += count($ids) - DB::table(self::TOMBSTONE_TABLE)->whereIn('id', $ids)->count();
        });

        return ['cleared' => $cleared, 'pending' => DB::table(self::TOMBSTONE_TABLE)->count()];
    }

    private function unlink(object $tombstone): void
    {
        $storage = Storage::disk($tombstone->disk);

        if ($storage->exists($tombstone->path) && ! $storage->delete($tombstone->path)) {
            throw new RuntimeException("Unable to delete asset file [{$tombstone->disk}:{$tombstone->path}].");
        }
    }

    /**
     * @param  array<int, array{disk: string, path: string}>  $locations
     * @return array<int, int>
     */
    public function queueFileDeletions(array $locations): array
    {
        if ($locations === []) {
            return [];
        }

        $now = now();
        $rows = array_map(fn (array $location): array => [
            'disk'       => $location['disk'],
            'path'       => $location['path'],
            'attempts'   => 0,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $locations);

        DB::table(self::TOMBSTONE_TABLE)->upsert(
            $rows,
            ['disk', 'path'],
            ['updated_at']
        );

        return DB::table(self::TOMBSTONE_TABLE)
            ->where(function ($query) use ($locations): void {
                foreach ($locations as $location) {
                    $query->orWhere(function ($query) use ($location): void {
                        $query->where('disk', $location['disk'])->where('path', $location['path']);
                    });
                }
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, object>  $tombstones
     * @return array<string, true>
     */
    private function referencedLocations(array $tombstones): array
    {
        $referenced = [];

        $fileObjects = FileObject::query()
            ->where(function ($query) use ($tombstones): void {
                foreach ($tombstones as $tombstone) {
                    $query->orWhere(function ($query) use ($tombstone): void {
                        $query->where('disk', $tombstone->disk)->where('path', $tombstone->path);
                    });
                }
            })
            ->referenced()
            ->get(['disk', 'path']);

        foreach ($fileObjects as $fileObject) {
            $referenced[$this->locationKey($fileObject->disk, $fileObject->path)] = true;
        }

        $legacyDisk = Directory::getAssetDisk();
        $legacyPaths = collect($tombstones)
            ->where('disk', $legacyDisk)
            ->pluck('path')
            ->unique()
            ->values();

        if ($legacyPaths->isNotEmpty()) {
            foreach ($this->namedByOtherAssets(assetId: 0, paths: $legacyPaths->all()) as $path) {
                $referenced[$this->locationKey($legacyDisk, $path)] = true;
            }
            foreach (Asset::withTrashed()->whereIn('path', $legacyPaths)->pluck('path') as $path) {
                $referenced[$this->locationKey($legacyDisk, $path)] = true;
            }
        }

        return $referenced;
    }

    private function locationKey(string $disk, string $path): string
    {
        return $disk."\0".$path;
    }
}
