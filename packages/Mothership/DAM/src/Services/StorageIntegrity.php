<?php

namespace Mothership\DAM\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Support\PathReferences;
use Mothership\DAM\Support\ReclaimOptions;
use Mothership\DAM\Support\RequestId;
use Mothership\DAM\Support\ReservedDirectoryNames;
use Mothership\DAM\Support\TarListing;
use Normalizer;
use Throwable;

/**
 * Detection and repair of the locator invariant (ADR 0009), separated the way DirectoryTreeIntegrity is
 * (ADR 0004): `report()` never writes; `repair()` makes every FileObject name its own intact blob, touching
 * metadata and copying only files that hash to a row whose blob is missing; `verifyContent()` is the
 * resumable, report-only pass that re-hashes Library files against their current originals; `reclaim()`
 * (slice 4) deletes the copies, working files and derivatives the storage no longer needs, behind a backup
 * gate. Each appends JSONL to storage/logs/storage-integrity.jsonl.
 *
 * Since slice 3 a Library path names no file: writers put bytes in a blob, so a file still at a Library
 * path is pre-slice-3 data that may be stale, or may belong to a deleted asset whose path was reused. It
 * is never ingested as an asset's content.
 */
class StorageIntegrity
{
    public const SAMPLE_LIMIT = 20;

    public const LOG = 'logs/storage-integrity.jsonl';

    private const CHUNK = 500;

    private const FINDINGS = ['non_object_locators', 'missing_blobs', 'assets_without_original', 'editor_resources_without_blob'];

    /** The layered image editor's pre-blob working area; a resource row's legacy path never names anything else. */
    private const EDITOR_AREA = 'assets/.editor/';

    /** The editor area's folder directly under `assets/`: reclaim set 2. */
    private const EDITOR_FOLDER = '.editor';

    /**
     * Dot-folders directly under `assets/` whose files reclaim treats as Library copies (set 1). Each is a legacy
     * write area verified from code and git history:
     * - `.models`: ModelPrimaryViewController wrote every 3D primary-view correction to
     *   `assets/.models/<uuid>/<file name>` and pointed the asset's path there, until slice 3 (commit 4da5a20).
     *
     * `.mindmaps` is deliberately absent: the Mind Maps purge migration (2026_09_21_000001) removed that tree, so
     * a file there now is unexplained. A file in any dot-folder not listed here, at any depth, is never deleted.
     * The derivative mirrors are read as the asset paths they mirror, so this list and the editor area decide there.
     */
    private const RECLAIM_LEGACY_DOT_FOLDERS = ['.models'];

    /** Finder's folder metadata: never reclaimed on its own, only removed with a directory it is the last thing in. */
    private const FINDER_FILE = '.DS_Store';

    /** The pre-slice-2 thumbnail mirror, `thumbnails/<asset path>[.webp|.jpg]` (reclaim set 3). */
    private const THUMBNAIL_MIRROR = 'thumbnails/'.Directory::ASSETS_DIRECTORY;

    /** Holds the pre-slice-2 preview mirrors, `preview/<size>/<asset path>[.webp]` (reclaim set 3). */
    private const PREVIEW_ROOT = 'preview';

    /** Where dam:storage:backup writes each database dump and asset archive pair (DamUpdater::backupDir()). */
    private const BACKUPS = 'dam-backups';

    private const RECLAIM_SETS = ['library', 'editor', 'derivatives'];

    /**
     * Families that keep a content file (sets 1 and 2 alike): a legacy FileObject locator still reads the file, and
     * an editor resource's path or details name it. An asset's Library path and an editor document's content path
     * are names only, wherever they point, `assets/.editor/results/` included: every read goes through the blob,
     * and the document uses its content path as a lookup key.
     */
    private const RECLAIM_PROTECTORS = [PathReferences::FILE_OBJECT_PATH, PathReferences::RESOURCE_PATH, PathReferences::RESOURCE_DETAILS];

    /** Why a file a reclaim looked at stays. `kept_referenced` is a file in use; the rest need a person or a rerun. */
    private const RECLAIM_KEPT = ['kept_invalid_utf8', 'kept_referenced', 'kept_no_blob', 'kept_changed', 'kept_blob_unverified', 'kept_now_named', 'kept_unlink_failed', 'kept_error'];

    public function __construct(private ContentStore $store) {}

    /**
     * Detection only. Blobs are stat-checked on local disks; remote rows are counted, not probed. Library
     * files are not checked: since slice 3 a Library path names no file. `library_orphans` is informational
     * and never part of `clean`: files a pre-slice-3 delete, rename or primary-view correction left under
     * `assets/` are wasted bytes, not a broken invariant (see libraryOrphans()), but a count that grows means a
     * writer still puts files in the Library tree.
     *
     * @return array{checked_at: string, file_objects: int, assets: int, remote_objects_skipped: int, library_orphans: array{count: int, bytes: int, sample: list<string>, invalid_utf8: int, complete: bool}|null, clean: bool, findings: array<string, array{count: int, sample: list<int|string>}>}
     */
    public function report(): array
    {
        $findings = array_fill_keys(self::FINDINGS, ['count' => 0, 'sample' => []]);
        $objects = 0;
        $remote = 0;

        FileObject::query()->select(['id', 'sha256', 'size', 'disk', 'path'])->lazyById(self::CHUNK)
            ->each(function (FileObject $object) use (&$findings, &$objects, &$remote): void {
                $objects++;

                if (! $this->store->isObjectPath($object->path, $object->sha256)) {
                    $this->note($findings['non_object_locators'], (int) $object->id);
                } elseif (! $this->isLocal((string) $object->disk)) {
                    $remote++;
                } elseif (! $this->store->holdsBlob($object)) {
                    $this->note($findings['missing_blobs'], (int) $object->id);
                }
            });

        $this->withoutOriginal()->select(['id'])->lazyById(self::CHUNK)
            ->each(function (Asset $asset) use (&$findings): void {
                $this->note($findings['assets_without_original'], (int) $asset->id);
            });

        // A layered document names these images; without a blob one cannot reopen (repairEditorResources()).
        ImageEditorResource::query()->whereNull('file_object_id')->select(['id'])->lazyById(self::CHUNK)
            ->each(function (ImageEditorResource $resource) use (&$findings): void {
                $this->note($findings['editor_resources_without_blob'], (string) $resource->id);
            });

        $report = [
            'checked_at'             => now()->toIso8601String(),
            'file_objects'           => $objects,
            'assets'                 => $this->withLibraryPath()->count(),
            'remote_objects_skipped' => $remote,
            'library_orphans'        => $this->orphanSummary(),
            'clean'                  => array_sum(array_column($findings, 'count')) === 0,
            'findings'               => $findings,
        ];

        $this->record('integrity.checked', $report, $report['clean'] ? 'info' : 'warning');

        return $report;
    }

    /**
     * For every FileObject whose locator is not an intact blob, in order: repoint it at an intact blob that
     * already exists; else copy a legacy or Library file that hashes to its sha256 into the blob; else record
     * it as unresolved. Then report every asset that has no original as unresolved: a Library file is never
     * trusted as an asset's content (slice 3), so nothing is backfilled from one. Idempotent; never throws on
     * a row it cannot resolve.
     *
     * @return array<string, mixed>
     */
    public function repair(bool $dryRun = false): array
    {
        $outcomes = array_fill_keys(['repointed', 'copied', 'unresolved', 'originals_unresolved'], ['count' => 0, 'sample' => []]);
        $unresolved = [];

        FileObject::query()->select(['id', 'sha256', 'size', 'disk', 'path'])->lazyById(self::CHUNK)
            ->each(function (FileObject $object) use (&$outcomes, &$unresolved, $dryRun): void {
                $isObjectPath = $this->store->isObjectPath($object->path, $object->sha256);

                if ($isObjectPath && (! $this->isLocal((string) $object->disk) || $this->store->holdsBlob($object))) {
                    return;
                }

                $outcome = $this->repairObject($object, $dryRun);
                $this->note($outcomes[$outcome], (int) $object->id);

                if ($outcome === 'unresolved') {
                    $unresolved[] = ['id' => (int) $object->id, 'disk' => $object->disk, 'path' => $object->path, 'sha256' => $object->sha256];
                }
            });

        $this->withoutOriginal()->select(['id'])->lazyById(self::CHUNK)
            ->each(function (Asset $asset) use (&$outcomes): void {
                $this->note($outcomes['originals_unresolved'], (int) $asset->id);
            });

        $result = ['dry_run' => $dryRun] + $outcomes + ['unresolved_rows' => $unresolved];

        $this->record(
            $dryRun ? 'integrity.repair_planned' : 'integrity.repaired',
            $result,
            $outcomes['unresolved']['count'] + $outcomes['originals_unresolved']['count'] > 0 ? 'warning' : 'info',
        );

        return $result;
    }

    /**
     * Give every layered image editor resource still named only by its pre-blob `assets/.editor/` working file a
     * blob. Each file is copied in through putStored(), which reuses an intact blob for the same bytes, so a base
     * lands on its asset's original. The row is attached the way every late attach is: asset row lock, then
     * retain() under the blob lock. The old file stays until reclaim() removes it. Each row commits on its own, so an
     * interrupted run resumes, and a finished row is never touched again. A row whose file is missing or lies
     * outside the working area is reported as unresolved and left alone. Never throws on a row it cannot resolve.
     *
     * @return array{dry_run: bool, ingested: array{count: int, sample: list<int|string>}, unresolved: array{count: int, sample: list<int|string>}}
     */
    public function repairEditorResources(bool $dryRun = false): array
    {
        $outcomes = array_fill_keys(['ingested', 'unresolved'], ['count' => 0, 'sample' => []]);
        $disk = Directory::getAssetDisk();

        ImageEditorResource::query()->whereNull('file_object_id')->select(['id', 'asset_id', 'path'])->lazyById(self::CHUNK)
            ->each(function (ImageEditorResource $resource) use (&$outcomes, $disk, $dryRun): void {
                $this->note($outcomes[$this->ingestEditorResource($resource, $disk, $dryRun)], (string) $resource->id);
            });

        $result = ['dry_run' => $dryRun] + $outcomes;

        $this->record(
            $dryRun ? 'integrity.editor_resources_planned' : 'integrity.editor_resources_ingested',
            $result,
            $outcomes['unresolved']['count'] > 0 ? 'warning' : 'info',
        );

        return $result;
    }

    /** @return 'ingested'|'unresolved' */
    private function ingestEditorResource(ImageEditorResource $resource, string $disk, bool $dryRun): string
    {
        $path = (string) $resource->path;

        if (! str_starts_with($path, self::EDITOR_AREA) || str_contains($path, '..') || $this->sizeOrNull($disk, $path) === null) {
            return 'unresolved';
        }

        if ($dryRun) {
            return 'ingested';
        }

        try {
            $mimeType = Storage::disk($disk)->mimeType($path) ?: null;
            $object = $this->store->putStored(
                disk: $disk,
                sourcePath: $path,
                mimeType: $mimeType,
                extension: match ($mimeType) {
                    'image/png'  => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    default      => null,
                },
            );

            // Ingested in its own transaction, attached in this one: asset row before blob (ADR 0009).
            DB::transaction(function () use ($resource, $object): void {
                Asset::withTrashed()->lockedForGraph()->findOrFail($resource->asset_id);
                $live = $this->store->retain($object);

                ImageEditorResource::query()->whereKey($resource->getKey())->whereNull('file_object_id')
                    ->update(['file_object_id' => $live->getKey()]);
            });

            return 'ingested';
        } catch (Throwable $e) {
            Log::warning('DAM storage repair could not ingest an image editor resource.', ['resource' => $resource->getKey(), 'exception' => $e]);

            return 'unresolved';
        }
    }

    /**
     * Re-hash each Library file against its asset's current original, in id order, persisting a cursor after
     * every chunk so an interrupted pass resumes. Report-only since slice 3: a Library file that differs is
     * stale (the asset's newer content went to a blob) or belongs to a deleted asset at a reused path, so it
     * is never ingested. A missing Library file is the normal case and only counted.
     *
     * @return array<string, mixed>
     */
    public function verifyContent(?int $limit = null): array
    {
        $statePath = storage_path('app/storage-integrity-verify.json');
        $state = $this->readState($statePath) ?? [
            'run_id'     => (string) Str::uuid(),
            'started_at' => now()->toIso8601String(),
            'after_id'   => 0,
            'totals'     => array_fill_keys(['checked', 'matched', 'divergent', 'skipped_missing', 'skipped_no_original', 'failed'], 0),
            'divergent'  => [],
        ];
        $disk = Directory::getAssetDisk();
        $processed = 0;
        $complete = true;

        $this->withLibraryPath()->where('id', '>', $state['after_id'])->select(['id', 'path'])
            ->chunkById(self::CHUNK, function ($assets) use (&$state, &$processed, &$complete, $disk, $limit, $statePath) {
                foreach ($assets as $asset) {
                    if ($limit !== null && $processed >= $limit) {
                        $complete = false;

                        return false;
                    }

                    $processed++;
                    $state['after_id'] = (int) $asset->id;
                    $outcome = $this->verifyAsset($asset, $disk);
                    $state['totals']['checked']++;
                    $state['totals'][$outcome]++;

                    if ($outcome === 'divergent' && count($state['divergent']) < self::SAMPLE_LIMIT) {
                        $state['divergent'][] = (int) $asset->id;
                    }
                }

                $this->writeState($statePath, $state);
                $this->record('integrity.verify_chunk', ['run_id' => $state['run_id'], 'after_id' => $state['after_id'], 'totals' => $state['totals']]);
            });

        $result = ['complete' => $complete] + $state;

        if ($complete) {
            @unlink($statePath);
        } else {
            $this->writeState($statePath, $state);
        }

        $this->record(
            $complete ? 'integrity.verified' : 'integrity.verify_paused',
            $result,
            $state['totals']['divergent'] + $state['totals']['failed'] > 0 ? 'warning' : 'info',
        );

        return $result;
    }

    /**
     * Orphan Library files: files under `assets/` whose path has no segment starting with `.` (so nothing in
     * `.objects`, `.staging`, `.editor`, `.models` or any other dot-folder at any depth) and that no row names
     * (PathReferences: an asset's Library path, a FileObject locator, an editor resource or document, or any
     * string in a resource's details, compared case- and Unicode-normalization-insensitively, as APFS compares
     * names). Slice 3 writes no Library file, so these are what pre-slice-3 code left behind: a delete that
     * queued only an asset's off-tree path, a rename or move since slice 3 of an asset whose file still sits at
     * its old name, a primary-view correction that repointed the asset off-tree. Each is hashed (streaming)
     * only when some FileObject has its exact size, since no other row can hold its bytes, and is
     * `blob_backed` when a FileObject with that sha256 names an intact blob. A name that is not valid UTF-8 is
     * never queried (PostgreSQL refuses it as text) and never deleted: it is listed under `invalid_utf8` for a
     * person, and the rest still run.
     *
     * Read-only unless `$force`. With it, each blob-backed orphan is revalidated and unlinked directly, never
     * through a deletion tombstone, so nothing retries the unlink later without these checks: see
     * reclaimOrphan(). Every directory under `assets/` with no dot segment that is then empty is removed (a
     * folder is a database row only). An orphan no intact blob holds is never deleted here: it is listed for a
     * person. Appends JSONL to storage/logs/storage-integrity.jsonl: one `integrity.orphan_deleted` receipt per
     * deleted file and one summary record per run.
     *
     * @return array<string, mixed>
     */
    public function libraryOrphans(bool $force = false): array
    {
        $runId = (string) Str::uuid();
        $disk = Directory::getAssetDisk();
        $outcomes = array_fill_keys(['orphans', 'blob_backed', 'unbacked', 'deleted', 'kept', 'failed', 'invalid_utf8'], ['count' => 0, 'bytes' => 0, 'sample' => []]);
        $files = [];
        $listed = $this->libraryFiles($disk);
        [$paths, $invalid] = $this->splitByEncoding($listed);

        foreach ($invalid as $path) {
            $size = $this->sizeOrNull($disk, $path);
            $files[] = ['path' => $path, 'encoded_path' => $this->encodedPath($path), 'size' => $size, 'sha256' => null, 'file_object_id' => null, 'blob_backed' => false, 'action' => 'kept_invalid_utf8'];
            $this->noteFile($outcomes['invalid_utf8'], $path, (int) $size);
        }

        $named = PathReferences::namedAmong(paths: $paths, disk: $disk);

        foreach ($paths as $path) {
            if (isset($named[$path])) {
                continue;
            }

            $file = $this->inspectOrphan(disk: $disk, path: $path, force: $force, runId: $runId);
            $files[] = $file;
            $size = (int) $file['size'];

            $this->noteFile($outcomes['orphans'], $path, $size);
            $this->noteFile($outcomes[$file['blob_backed'] ? 'blob_backed' : 'unbacked'], $path, $size);

            if (in_array($file['action'], ['deleted', 'failed'], true)) {
                $this->noteFile($outcomes[$file['action']], $path, $size);
            } elseif ($file['blob_backed'] && str_starts_with($file['action'], 'kept_')) {
                $this->noteFile($outcomes['kept'], $path, $size);
            }
        }

        $pruned = $force ? $this->pruneEmptyLibraryDirectories($disk) : [];

        $result = [
            'run_id'             => $runId,
            'dry_run'            => ! $force,
            'disk'               => $disk,
            'library_files'      => count($listed),
        ] + $outcomes + [
            'pruned_directories' => ['count' => count($pruned), 'sample' => array_slice($pruned, 0, self::SAMPLE_LIMIT)],
            'files'              => $files,
        ];

        $remaining = $outcomes['orphans']['count'] - $outcomes['deleted']['count'] + $outcomes['invalid_utf8']['count'];

        $this->record(
            $force ? 'integrity.orphans_reclaimed' : 'integrity.orphans_reported',
            array_diff_key($result, ['files' => true]),
            $remaining > 0 ? 'warning' : 'info',
        );

        return $result;
    }

    /**
     * @return array{path: string, size: int|null, sha256: string|null, file_object_id: int|null, blob_backed: bool, action: string}
     */
    private function inspectOrphan(string $disk, string $path, bool $force, string $runId): array
    {
        $size = $this->sizeOrNull($disk, $path);
        $file = ['path' => $path, 'size' => $size, 'sha256' => null, 'file_object_id' => null, 'blob_backed' => false, 'action' => 'reported'];

        if ($size === null) {
            return ['action' => 'failed'] + $file;
        }

        try {
            $evidence = $this->blobEvidence(disk: $disk, path: $path, size: $size);
            $file['sha256'] = $evidence['sha256'];

            if ($evidence['object'] !== null && $evidence['blob_backed']) {
                $file['file_object_id'] = (int) $evidence['object']->id;
                $file['blob_backed'] = true;
            }

            if (! $force) {
                return $file;
            }

            if (! $file['blob_backed']) {
                return ['action' => 'kept_no_blob'] + $file;
            }

            $action = $this->reclaimOrphan(disk: $disk, path: $path, size: (int) $size, sha256: (string) $file['sha256']);
            $file = ['action' => $action] + $file;

            if ($action === 'deleted') {
                $this->record('integrity.orphan_deleted', ['run_id' => $runId] + $file);
            }

            return $file;
        } catch (Throwable $e) {
            Log::warning('DAM storage orphan check could not inspect a Library file.', ['path' => $path, 'exception' => $e]);

            return ['action' => 'failed'] + $file;
        }
    }

    /**
     * What a blob could hold of a file: its sha256, hashed (streaming) only when some FileObject has its exact
     * size, since bytes of a size no FileObject has cannot be held by any blob, and whether a FileObject with that
     * sha256 names an intact blob.
     *
     * @return array{sha256: string|null, object: FileObject|null, blob_backed: bool}
     */
    private function blobEvidence(string $disk, string $path, int $size): array
    {
        $sha256 = null;
        $object = null;

        if (FileObject::query()->where('size', $size)->exists()) {
            $sha256 = $this->store->checksum($disk, $path);
            $object = FileObject::query()->where('sha256', $sha256)->first();
        }

        return ['sha256' => $sha256, 'object' => $object, 'blob_backed' => $object !== null && $this->store->holdsBlob($object)];
    }

    /**
     * Delete one blob-backed orphan, or keep it: unlinkBlobHeld() with the five PathReferences families as the
     * naming check (`kept_now_named`).
     *
     * @return 'deleted'|'kept_changed'|'kept_blob_unverified'|'kept_now_named'|'kept_unlink_failed'
     */
    private function reclaimOrphan(string $disk, string $path, int $size, string $sha256): string
    {
        return $this->unlinkBlobHeld(
            disk: $disk,
            path: $path,
            size: $size,
            sha256: $sha256,
            protectedNow: fn (): bool => PathReferences::isNamed(path: $path, disk: $disk),
        );
    }

    /**
     * Delete one file whose bytes a blob holds, or keep it, deciding under the blob lock (ContentStore::lockBlob(),
     * the lock tombstone cleanup takes before it unlinks a blob) so the blob cannot be reclaimed between the check
     * and the unlink. Everything the report found is looked at again, in this order, and any change keeps the
     * file: the file still has its size and re-hashes to its sha256 (`kept_changed`); a FileObject with that
     * sha256 names an intact blob that re-hashes to it, so no bytes are lost (`kept_blob_unverified`);
     * `$protectedNow` finds no row that keeps the file (`kept_now_named`). The unlink is then direct. A failed
     * unlink is reported (`kept_unlink_failed`) and leaves nothing queued: a deletion tombstone would be retried
     * hourly by dam:storage:reap, which re-checks none of this.
     *
     * @param  Closure(): bool  $protectedNow  the fresh naming check, run under the blob lock
     * @return 'deleted'|'kept_changed'|'kept_blob_unverified'|'kept_now_named'|'kept_unlink_failed'
     */
    private function unlinkBlobHeld(string $disk, string $path, int $size, string $sha256, Closure $protectedNow): string
    {
        return DB::transaction(function () use ($disk, $path, $size, $sha256, $protectedNow): string {
            $this->store->lockBlob($sha256);

            $kept = $this->revalidateBlobHeld(disk: $disk, path: $path, size: $size, sha256: $sha256);

            if ($kept !== null) {
                return $kept;
            }

            if ($protectedNow()) {
                return 'kept_now_named';
            }

            return $this->unlinkQuietly(disk: $disk, path: $path) ? 'deleted' : 'kept_unlink_failed';
        });
    }

    /**
     * The first two re-checks of every blob-verified unlink, made under the blob lock the caller holds: the file
     * still has `$size` bytes that hash to `$sha256`, and a FileObject with that sha256 names an intact blob that
     * re-hashes to it. Null when both hold, else the reason the file stays.
     *
     * @return 'kept_changed'|'kept_blob_unverified'|null
     */
    private function revalidateBlobHeld(string $disk, string $path, int $size, string $sha256): ?string
    {
        if ($this->sizeOrNull($disk, $path) !== $size || $this->store->checksum($disk, $path) !== $sha256) {
            return 'kept_changed';
        }

        $object = FileObject::query()->where('sha256', $sha256)->first();

        if ($object === null
            || ! $this->store->holdsBlob($object)
            || $this->store->checksum((string) $object->disk, (string) $object->path) !== $sha256) {
            return 'kept_blob_unverified';
        }

        return null;
    }

    /** A direct unlink that reports failure instead of throwing, so a caller can keep the file and say why. */
    private function unlinkQuietly(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            Log::warning('DAM storage cleanup could not unlink a file.', ['path' => $path, 'exception' => $e]);

            return false;
        }
    }

    /**
     * Slice 4's reclaim (ADR 0009): delete what the asset disk no longer needs once every byte lives in a blob.
     * Operator-run only, never scheduled. Without `force` it changes nothing and reports what it would do.
     *
     * Three sets, all on the asset disk:
     * 1. `library`: every file under `assets/` outside `.objects`, `.staging` and `.editor`, whether an asset still
     *    names it or not, including the legacy dot-folders in RECLAIM_LEGACY_DOT_FOLDERS. A file under any other
     *    dot-folder, or named with a leading dot, is never deleted: it is counted under `skipped_dot_folder`.
     * 2. `editor`: every file under `assets/.editor/`, the pre-blob working files of the layered image editor.
     * 3. `derivatives`: every file under `thumbnails/assets/` and `preview/<size>/assets/`, the pre-slice-2
     *    path-keyed mirrors nothing has read or written since slice 2, under set 1's dot-segment policy as the
     *    asset path they mirror: only a `.models` or `.editor` mirror is a candidate, any other dot segment is skipped.
     * Then every directory those roots are left with empty is removed, deepest first, with a `.DS_Store` that is
     * the last thing in it, under the same dot-segment policy. A directory whose name is not valid UTF-8 is never
     * removed; one that holds nothing else is reported for a person.
     *
     * A content file (sets 1 and 2) is reclaimable when its name is valid UTF-8, no protecting row names it, and a
     * FileObject with its sha256 names an intact blob. Otherwise it stays: `kept_invalid_utf8`, `kept_referenced`
     * or `kept_no_blob` (the only copy of those bytes). One rule decides which rows protect, in both sets
     * (RECLAIM_PROTECTORS): a FileObject locator, an editor resource's path and any string in a resource's details.
     * An asset's Library path and a document's content path are names only. In set 2 the resource rows whose path
     * names the file are cleared with it, when each of them already names that very blob; their details still
     * protect. With `force` each content file is re-checked under its blob lock just before a direct unlink, never
     * through a tombstone (unlinkBlobHeld(), reclaimEditorFile()). A derivative is derived data: it stays while a
     * row, or any string in an asset's meta_data, names it, which is read again for that path just before its
     * unlink (reclaimDerivative()).
     *
     * The gate runs before any change, and any failure refuses the run with nothing changed. `force` needs a
     * `backup` on a local asset disk. A backup must be a plain name under storage/dam-backups holding a non-empty
     * dam-tables.sql and a dam-files.tgz that verifies to its gzip trailer (TarListing) and lists every content
     * file this run would delete at its current size. A dry run with a backup checks it the same way.
     *
     * Appends JSONL to storage/logs/storage-integrity.jsonl, every record under the run's id: one
     * `integrity.reclaim_deleted` receipt per deleted file, then `integrity.reclaimed` (or
     * `integrity.reclaim_planned` for a dry run), or one `integrity.reclaim_refused`.
     *
     * @return array<string, mixed>
     */
    public function reclaim(ReclaimOptions $options): array
    {
        $runId = (string) Str::uuid();
        $disk = Directory::getAssetDisk();
        $run = ['run_id' => $runId, 'dry_run' => ! $options->force, 'disk' => $disk, 'refused' => null, 'backup' => null];
        $pair = null;
        $refusal = $this->reclaimFlagRefusal(options: $options, disk: $disk);

        if ($refusal === null && $options->backup !== null) {
            ['pair' => $pair, 'refusal' => $refusal] = $this->backupPair($options->backup);
        }

        if ($refusal !== null) {
            return $this->refuseReclaim(run: $run, refusal: $refusal, options: $options, plan: null);
        }

        try {
            $plan = $this->planReclaim($disk);
        } catch (Throwable $e) {
            Log::warning('DAM storage reclaim could not list or classify the asset disk.', ['exception' => $e]);
            $refusal = ['reason' => 'listing_failed', 'message' => 'Listing or classifying the asset disk failed, so nothing was changed; see the log.'];

            return $this->refuseReclaim(run: $run, refusal: $refusal, options: $options, plan: null);
        }

        if ($pair !== null) {
            ['backup' => $run['backup'], 'refusal' => $refusal] = $this->backupCoverage(pair: $pair, needed: $this->contentToDelete($plan['files']));

            if ($refusal !== null) {
                return $this->refuseReclaim(run: $run, refusal: $refusal, options: $options, plan: $plan);
            }
        }

        $pruned = ['pruned' => [], 'finder_files' => [], 'retained_invalid_utf8' => []];

        if ($options->force) {
            foreach ($plan['files'] as $index => $file) {
                if ($file['action'] === 'reclaimable') {
                    $plan['files'][$index] = $this->reclaimFile(disk: $disk, file: $file, runId: $runId);
                }
            }

            $pruned = $this->pruneReclaimedDirectories(disk: $disk);

            foreach ($pruned['finder_files'] as $finder) {
                $this->record('integrity.reclaim_deleted', ['run_id' => $runId, 'set' => 'finder', 'path' => $finder['path'], 'size' => $finder['size'], 'sha256' => null, 'file_object_id' => null, 'asset_ids' => []]);
            }
        }

        $result = $this->reclaimResult(run: $run, plan: $plan, pruned: $pruned);

        $this->record(
            $options->force ? 'integrity.reclaimed' : 'integrity.reclaim_planned',
            array_diff_key($result, ['files' => true]),
            $result['clean'] ? 'info' : 'warning',
        );

        return $result;
    }

    /**
     * The flag half of the reclaim gate, which needs no listing.
     *
     * @return array{reason: string, message: string}|null
     */
    private function reclaimFlagRefusal(ReclaimOptions $options, string $disk): ?array
    {
        if ($options->force && $options->dryRun) {
            return ['reason' => 'dry_run_with_force', 'message' => '--dry-run and --force contradict each other, so nothing was changed.'];
        }

        if ($options->force && $options->backup === null) {
            return ['reason' => 'backup_required', 'message' => '--force needs --backup=<name>, so nothing was changed. Take a backup first with `php artisan dam:storage:backup` and pass the name it prints.'];
        }

        if ($options->force && ! $this->isLocal($disk)) {
            return ['reason' => 'remote_disk', 'message' => "--force runs only on a local asset disk, and [{$disk}] is not: the backup pair covers only a local tree. Nothing was changed."];
        }

        return null;
    }

    /**
     * The backup directory the operator named, checked before any listing: a plain name (what dam:update:restore
     * accepts, so `/`, `..` and an empty name are refused) of a directory under storage/dam-backups holding a
     * non-empty dam-tables.sql and a dam-files.tgz.
     *
     * @return array{pair: array{name: string, archive: string, dump_bytes: int}|null, refusal: array{reason: string, message: string}|null}
     */
    private function backupPair(string $name): array
    {
        $refuse = fn (string $reason, string $message): array => ['pair' => null, 'refusal' => ['reason' => $reason, 'message' => $message]];

        if (preg_match('/^[\w-]+$/', $name) !== 1) {
            return $refuse('backup_name_invalid', '--backup must be the plain name of a directory under storage/dam-backups (letters, digits, _ and -), as dam:storage:backup prints it. Nothing was changed.');
        }

        $directory = storage_path(self::BACKUPS.'/'.$name);

        if (! is_dir($directory)) {
            return $refuse('backup_missing', "There is no backup storage/dam-backups/{$name}; take one with `php artisan dam:storage:backup`. Nothing was changed.");
        }

        clearstatcache();
        $dump = $directory.'/dam-tables.sql';
        $dumpBytes = is_file($dump) ? (int) filesize($dump) : 0;

        if ($dumpBytes <= 0) {
            return $refuse('backup_dump_missing', "Backup {$name} has no non-empty dam-tables.sql, so it could not restore the rows its files belong to. Nothing was changed.");
        }

        if (! is_file($directory.'/dam-files.tgz')) {
            return $refuse('backup_archive_missing', "Backup {$name} has no dam-files.tgz. Nothing was changed.");
        }

        return ['pair' => ['name' => $name, 'archive' => $directory.'/dam-files.tgz', 'dump_bytes' => $dumpBytes], 'refusal' => null];
    }

    /**
     * Whether the archive lists every content file this run would delete at its current size. The archive is read
     * header by header (TarListing), never extracted. Names are compared exactly, then in composed (NFC) form,
     * because a tar may store another Unicode normalization than the disk lists. When a path is archived twice
     * the later entry counts, as it would on extraction. Derivatives are never checked: the archive does not hold
     * them, and they are made again from the blobs.
     *
     * @param  array{name: string, archive: string, dump_bytes: int}  $pair
     * @param  array<string, int>  $needed  path => size
     * @return array{backup: array{name: string, dump_bytes: int, archive_entries: int, covered: bool}, refusal: array<string, mixed>|null}
     */
    private function backupCoverage(array $pair, array $needed): array
    {
        $byForm = [];

        foreach (array_keys($needed) as $path) {
            $byForm[$this->composed((string) $path)][] = (string) $path;
        }

        $archived = [];
        $entries = 0;

        try {
            foreach (TarListing::entries($pair['archive']) as $entry) {
                $entries++;

                if ($entry['type'] !== 'file') {
                    continue;
                }

                $name = str_starts_with($entry['name'], './') ? substr($entry['name'], 2) : $entry['name'];

                foreach (isset($needed[$name]) ? [$name] : ($byForm[$this->composed($name)] ?? []) as $path) {
                    $archived[$path] = $entry['size'];
                }
            }
        } catch (Throwable $e) {
            $backup = ['name' => $pair['name'], 'dump_bytes' => $pair['dump_bytes'], 'archive_entries' => $entries, 'covered' => false];

            return ['backup' => $backup, 'refusal' => [
                'reason'  => 'backup_unreadable',
                'message' => "The archive of backup {$pair['name']} could not be read ({$e->getMessage()}). Nothing was changed.",
            ]];
        }

        $missing = [];
        $wrongSize = [];

        foreach ($needed as $path => $size) {
            if (! array_key_exists($path, $archived)) {
                $missing[] = (string) $path;
            } elseif ($archived[$path] !== $size) {
                $wrongSize[] = (string) $path;
            }
        }

        $backup = ['name' => $pair['name'], 'dump_bytes' => $pair['dump_bytes'], 'archive_entries' => $entries, 'covered' => $missing === [] && $wrongSize === []];

        if ($backup['covered']) {
            return ['backup' => $backup, 'refusal' => null];
        }

        return ['backup' => $backup, 'refusal' => [
            'reason'     => 'backup_incomplete',
            'message'    => sprintf(
                'Backup %s does not hold %d file(s) this run would delete at their current size (%d missing, %d at another size). Take a new backup with `php artisan dam:storage:backup`. Nothing was changed.',
                $pair['name'],
                count($missing) + count($wrongSize),
                count($missing),
                count($wrongSize),
            ),
            'missing'    => ['count' => count($missing), 'sample' => array_slice($missing, 0, self::SAMPLE_LIMIT)],
            'wrong_size' => ['count' => count($wrongSize), 'sample' => array_slice($wrongSize, 0, self::SAMPLE_LIMIT)],
        ]];
    }

    /** A name in composed (NFC) form, or as it is when it is not valid UTF-8. */
    private function composed(string $path): string
    {
        $normalized = class_exists(Normalizer::class) ? Normalizer::normalize($path, Normalizer::FORM_C) : false;

        return is_string($normalized) ? $normalized : $path;
    }

    /**
     * Every content file (sets 1 and 2) the plan would delete, with its size: what the backup must hold.
     *
     * @param  list<array<string, mixed>>  $files
     * @return array<string, int>
     */
    private function contentToDelete(array $files): array
    {
        $needed = [];

        foreach ($files as $file) {
            if ($file['action'] === 'reclaimable' && $file['set'] !== 'derivatives') {
                $needed[$file['path']] = (int) $file['size'];
            }
        }

        return $needed;
    }

    /**
     * The report pass: list the three sets and classify every file, changing nothing. Names are looked up in one
     * stream of each row family per set (PathReferences::namersAmong()), so the pass is linear in the files and
     * the rows. A content file is hashed only when some FileObject has its size.
     *
     * @return array{files: list<array<string, mixed>>, skipped: list<array{path: string, size: int}>}
     */
    private function planReclaim(string $disk): array
    {
        $content = ['library' => [], 'editor' => []];
        $skipped = [];
        $never = [ContentStore::ROOT, ContentStore::STAGING];

        foreach ($this->assetTreeFiles(disk: $disk, descend: fn (string $directory): bool => ! in_array($directory, $never, true)) as $path) {
            $area = $this->reclaimArea($path);

            if ($area === 'skipped') {
                $skipped[] = ['path' => $path, 'size' => (int) $this->sizeOrNull($disk, $path)];
            } elseif ($area !== null) {
                $content[$area][] = $path;
            }
        }

        $valid = fn (array $paths): array => array_values(array_filter($paths, fn (string $path): bool => mb_check_encoding($path, 'UTF-8')));
        $files = [];
        $namers = PathReferences::namersAmong(paths: $valid([...$content['library'], ...$content['editor']]), disk: $disk);

        foreach ($content as $set => $paths) {
            foreach ($paths as $path) {
                $files[] = $this->planContentFile(disk: $disk, set: $set, path: $path, namers: $namers[$path] ?? []);
            }
        }

        $derivatives = $this->derivativeMirrorFiles($disk);

        // After the Library tree's, so the whole list stays sorted: `assets/` < `preview/` < `thumbnails/`.
        foreach ($derivatives['skipped'] as $path) {
            $skipped[] = ['path' => $path, 'size' => (int) $this->sizeOrNull($disk, $path)];
        }

        $namers = PathReferences::namersAmong(paths: $valid($derivatives['files']), disk: $disk, withAssetMetadata: true);

        foreach ($derivatives['files'] as $path) {
            $files[] = $this->planDerivative(disk: $disk, path: $path, namers: $namers[$path] ?? []);
        }

        return ['files' => $files, 'skipped' => $skipped];
    }

    /**
     * Which reclaim set a file under `assets/` belongs to: `library`, `editor`, `skipped` (a dot-folder that is not
     * a legacy write area, or a dot-file), or null for Finder's `.DS_Store`, which only goes with its directory.
     */
    private function reclaimArea(string $path): ?string
    {
        $segments = explode('/', $path);
        $name = (string) array_pop($segments);
        $area = $this->folderArea(implode('/', $segments));

        if ($area === null) {
            return 'skipped';
        }

        if ($name === self::FINDER_FILE) {
            return null;
        }

        return ReservedDirectoryNames::isReserved($name) ? 'skipped' : $area;
    }

    /**
     * Which reclaim set a directory under `assets/` (or `assets` itself) belongs to: `library`, or `editor` for the
     * editor area. Null when it lies in no set: in a top-level dot-folder that is neither the editor area nor a
     * legacy write area, or under a dot segment below its top-level folder.
     */
    private function folderArea(string $directory): ?string
    {
        $segments = array_slice(explode('/', $directory), 1);
        $area = 'library';

        if (($segments[0] ?? null) === self::EDITOR_FOLDER) {
            $area = 'editor';
            array_shift($segments);
        } elseif (in_array($segments[0] ?? null, self::RECLAIM_LEGACY_DOT_FOLDERS, true)) {
            array_shift($segments);
        }

        foreach ($segments as $segment) {
            if (ReservedDirectoryNames::isReserved($segment)) {
                return null;
            }
        }

        return $area;
    }

    /**
     * Every file in the pre-slice-2 derivative mirrors (set 3), sorted by path, under the dot-segment policy of the
     * asset tree they mirror. A mirror path is read as the asset path it mirrors (`thumbnails/assets/X` and
     * `preview/<size>/assets/X` mirror `assets/X`), so a dot segment is allowed only where it mirrors one of the two
     * legacy areas the reclaim covers. Those are `.models` (RECLAIM_LEGACY_DOT_FOLDERS) and the editor area
     * `.editor`: a pre-slice-3 edited asset's path was `assets/.editor/results/<uuid>.png`, so its thumbnail is
     * `thumbnails/assets/.editor/results/…`. Any other dot-folder or dot-file is `skipped` and never deleted.
     * Finder's `.DS_Store` only goes with its directory.
     *
     * @return array{files: list<string>, skipped: list<string>}
     */
    private function derivativeMirrorFiles(string $disk): array
    {
        $listed = ['files' => [], 'skipped' => []];

        foreach ($this->derivativeMirrors($disk) as $root) {
            foreach (Storage::disk($disk)->allFiles($root) as $path) {
                $area = $this->reclaimArea(Directory::ASSETS_DIRECTORY.substr($path, strlen($root)));

                if ($area === 'library' || $area === 'editor') {
                    $listed['files'][] = $path;
                } elseif ($area !== null) {
                    $listed['skipped'][] = $path;
                }
            }
        }

        sort($listed['files'], SORT_STRING);
        sort($listed['skipped'], SORT_STRING);

        return $listed;
    }

    /**
     * The roots of set 3: `thumbnails/assets` and `preview/<size>/assets` for each numeric size directory.
     *
     * @return list<string>
     */
    private function derivativeMirrors(string $disk): array
    {
        $roots = [self::THUMBNAIL_MIRROR];

        foreach (Storage::disk($disk)->directories(self::PREVIEW_ROOT) as $size) {
            if (ctype_digit(basename($size))) {
                $roots[] = $size.'/'.Directory::ASSETS_DIRECTORY;
            }
        }

        return $roots;
    }

    /**
     * @param  list<array{family: string, id: int|string, asset_id: int|null}>  $namers
     * @return array<string, mixed>
     */
    private function reclaimEntry(string $path, string $set, array $namers): array
    {
        return [
            'path'           => $path,
            'set'            => $set,
            'size'           => null,
            'sha256'         => null,
            'file_object_id' => null,
            'named_by'       => array_values(array_unique(array_column($namers, 'family'))),
            'action'         => 'reclaimable',
            '_reclaimable'   => false,
            '_namers'        => $namers,
        ];
    }

    /**
     * Classify one content file (set 1 or 2) for the report pass.
     *
     * @param  list<array{family: string, id: int|string, asset_id: int|null}>  $namers
     * @return array<string, mixed>
     */
    private function planContentFile(string $disk, string $set, string $path, array $namers): array
    {
        $file = $this->reclaimEntry(path: $path, set: $set, namers: $namers);
        $file['size'] = $this->sizeOrNull($disk, $path);

        if (! mb_check_encoding($path, 'UTF-8')) {
            $file['encoded_path'] = $this->encodedPath($path);
            $file['action'] = 'kept_invalid_utf8';

            return $file;
        }

        if ($file['size'] === null) {
            $file['action'] = 'kept_error';

            return $file;
        }

        // Only an editor working file's naming rows are cleared with it; a Library copy a resource row names stays.
        $rows = $set === 'editor' ? array_values(array_filter($namers, fn (array $namer): bool => $namer['family'] === PathReferences::RESOURCE_PATH)) : [];

        if ($this->protectingNamers(namers: $namers, clearing: array_column($rows, 'id')) !== []) {
            $file['action'] = 'kept_referenced';

            return $file;
        }

        // A resource row with no blob has only this file.
        $resources = $rows === [] ? collect() : ImageEditorResource::query()->whereKey(array_column($rows, 'id'))->get(['id', 'file_object_id']);

        if ($resources->count() !== count($rows) || $resources->contains(fn (ImageEditorResource $resource): bool => $resource->file_object_id === null)) {
            $file['action'] = 'kept_referenced';

            return $file;
        }

        try {
            $evidence = $this->blobEvidence(disk: $disk, path: $path, size: (int) $file['size']);
        } catch (Throwable $e) {
            Log::warning('DAM storage reclaim could not read a file; it is kept.', ['path' => $path, 'exception' => $e]);
            $file['action'] = 'kept_error';

            return $file;
        }

        $file['sha256'] = $evidence['sha256'];

        if ($evidence['object'] === null || ! $evidence['blob_backed']) {
            $file['action'] = 'kept_no_blob';

            return $file;
        }

        $file['file_object_id'] = (int) $evidence['object']->id;

        // Each naming row must hold this very blob; then the file is a spare copy of the row's own bytes.
        if ($resources->contains(fn (ImageEditorResource $resource): bool => (int) $resource->file_object_id !== $file['file_object_id'])) {
            $file['action'] = 'kept_referenced';

            return $file;
        }

        if ($rows !== []) {
            $file['editor_rows'] = array_values(array_map('strval', array_column($rows, 'id')));
        }

        $file['_reclaimable'] = true;

        return $file;
    }

    /**
     * Classify one derivative (set 3): derived data, so no blob check and no hash. It stays while any row, or any
     * string in an asset's meta_data, names it.
     *
     * @param  list<array{family: string, id: int|string, asset_id: int|null}>  $namers
     * @return array<string, mixed>
     */
    private function planDerivative(string $disk, string $path, array $namers): array
    {
        $file = $this->reclaimEntry(path: $path, set: 'derivatives', namers: $namers);
        $file['size'] = $this->sizeOrNull($disk, $path);

        if (! mb_check_encoding($path, 'UTF-8')) {
            $file['encoded_path'] = $this->encodedPath($path);
            $file['action'] = 'kept_invalid_utf8';
        } elseif ($file['size'] === null) {
            $file['action'] = 'kept_error';
        } elseif ($namers !== []) {
            $file['action'] = 'kept_referenced';
        } else {
            $file['_reclaimable'] = true;
        }

        return $file;
    }

    /**
     * The rows that keep a content file, the same rule in sets 1 and 2 (RECLAIM_PROTECTORS): a FileObject locator,
     * an editor resource's path or any string in a resource's details. Only the path of the resource rows being
     * cleared with the file is left out; those rows' details still protect.
     *
     * @param  list<array{family: string, id: int|string, asset_id: int|null}>  $namers
     * @param  list<int|string>  $clearing  resource ids whose path the same transaction clears
     * @return list<array{family: string, id: int|string, asset_id: int|null}>
     */
    private function protectingNamers(array $namers, array $clearing = []): array
    {
        $clearing = array_map('strval', $clearing);

        return array_values(array_filter($namers, fn (array $namer): bool => in_array($namer['family'], self::RECLAIM_PROTECTORS, true)
            && ! ($namer['family'] === PathReferences::RESOURCE_PATH && in_array((string) $namer['id'], $clearing, true))));
    }

    /**
     * The assets whose rows name a file, in ascending id order.
     *
     * @param  list<array{family: string, id: int|string, asset_id: int|null}>  $namers
     * @return list<int>
     */
    private function namingAssetIds(array $namers): array
    {
        $ids = array_values(array_unique(array_filter(array_column($namers, 'asset_id'), fn ($id): bool => $id !== null)));
        sort($ids);

        return array_map('intval', $ids);
    }

    /**
     * Delete one file the report pass found reclaimable, or keep it and say why, then write its receipt.
     *
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function reclaimFile(string $disk, array $file, string $runId): array
    {
        try {
            [$action, $assetIds] = match (true) {
                $file['set'] === 'derivatives'      => $this->reclaimDerivative(disk: $disk, path: $file['path']),
                ($file['editor_rows'] ?? []) !== [] => $this->reclaimEditorFile(disk: $disk, file: $file),
                default                             => $this->reclaimContentFile(disk: $disk, file: $file),
            };
        } catch (Throwable $e) {
            Log::warning('DAM storage reclaim could not check a file; it is kept.', ['path' => $file['path'], 'exception' => $e]);
            [$action, $assetIds] = ['kept_error', []];
        }

        $file['action'] = $action;

        if ($action === 'deleted') {
            $this->record('integrity.reclaim_deleted', [
                'run_id'         => $runId,
                'set'            => $file['set'],
                'path'           => $file['path'],
                'size'           => $file['size'],
                'sha256'         => $file['sha256'],
                'file_object_id' => $file['file_object_id'],
                'asset_ids'      => $assetIds,
            ] + (isset($file['editor_rows']) ? ['editor_rows_cleared' => $file['editor_rows']] : []));
        }

        return $file;
    }

    /**
     * A derivative: every naming family, every string in every asset's meta_data included, is read again for this
     * one path just before the unlink, so a reference added since the plan keeps the file (`kept_now_named`).
     * Nothing orders the read against a writer, but since slice 2 no writer creates a path-keyed derivative or a new
     * reference to one; a copy's meta_data can only repeat a reference the plan already saw.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function reclaimDerivative(string $disk, string $path): array
    {
        if (PathReferences::namers(path: $path, disk: $disk, withAssetMetadata: true) !== []) {
            return ['kept_now_named', []];
        }

        return [$this->unlinkQuietly(disk: $disk, path: $path) ? 'deleted' : 'kept_unlink_failed', []];
    }

    /**
     * A content file with no resource rows to clear: unlinkBlobHeld() with the protecting rows as the fresh naming
     * check. Returns the action and the assets whose rows named the file at that moment (a Library path, a
     * document's content path: names only, which the receipt keeps).
     *
     * @param  array<string, mixed>  $file
     * @return array{0: string, 1: list<int>}
     */
    private function reclaimContentFile(string $disk, array $file): array
    {
        $namers = [];
        $action = $this->unlinkBlobHeld(
            disk: $disk,
            path: $file['path'],
            size: (int) $file['size'],
            sha256: (string) $file['sha256'],
            protectedNow: function () use (&$namers, $disk, $file): bool {
                $namers = PathReferences::namers(path: $file['path'], disk: $disk);

                return $this->protectingNamers(namers: $namers) !== [];
            },
        );

        return [$action, $action === 'deleted' ? $this->namingAssetIds($namers) : []];
    }

    /**
     * A legacy editor working file that resource rows name, each of which already names its blob: the rows' path
     * is cleared and the file unlinked in one transaction. Locks follow ADR 0009: the naming rows' assets in
     * ascending id order (Asset::lockedForGraph()), then the blob. Under them every check of unlinkBlobHeld() runs
     * again, with the rows being cleared left out of the naming check, and the same rows must still name the file
     * and hold that blob. Each row's path is cleared only where it is still the path read (`whereKey` and the old
     * path). The unlink comes last, inside the transaction; if it fails, everything rolls back and the rows keep
     * their path. Should the commit itself fail after the unlink, each row still reads its bytes from its blob.
     *
     * @param  array<string, mixed>  $file
     * @return array{0: string, 1: list<int>}
     */
    private function reclaimEditorFile(string $disk, array $file): array
    {
        $rowIds = array_values(array_map('strval', $file['editor_rows']));
        sort($rowIds);
        $assetIds = $this->namingAssetIds(array_values(array_filter(
            $file['_namers'],
            fn (array $namer): bool => $namer['family'] === PathReferences::RESOURCE_PATH,
        )));

        DB::beginTransaction();

        try {
            $outcome = $this->clearAndUnlinkEditorFile(disk: $disk, file: $file, rowIds: $rowIds, assetIds: $assetIds);
            $outcome[0] === 'deleted' ? DB::commit() : DB::rollBack();

            return $outcome;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  list<string>  $rowIds  sorted
     * @param  list<int>  $assetIds  ascending
     * @return array{0: string, 1: list<int>}
     */
    private function clearAndUnlinkEditorFile(string $disk, array $file, array $rowIds, array $assetIds): array
    {
        foreach ($assetIds as $assetId) {
            Asset::withTrashed()->lockedForGraph()->find($assetId);
        }

        $this->store->lockBlob((string) $file['sha256']);

        $kept = $this->revalidateBlobHeld(disk: $disk, path: $file['path'], size: (int) $file['size'], sha256: (string) $file['sha256']);

        if ($kept !== null) {
            return [$kept, []];
        }

        $namers = PathReferences::namers(path: $file['path'], disk: $disk);
        $naming = array_map('strval', array_column(array_filter($namers, fn (array $namer): bool => $namer['family'] === PathReferences::RESOURCE_PATH), 'id'));
        sort($naming);

        if ($naming !== $rowIds || $this->protectingNamers(namers: $namers, clearing: $rowIds) !== []) {
            return ['kept_now_named', []];
        }

        $object = FileObject::query()->where('sha256', $file['sha256'])->first();
        $rows = ImageEditorResource::query()->whereKey($rowIds)->get(['id', 'asset_id', 'path', 'file_object_id']);

        if ($object === null || $rows->count() !== count($rowIds) || $rows->contains(fn (ImageEditorResource $row): bool => (int) $row->file_object_id !== (int) $object->id
            || ! in_array((int) $row->asset_id, $assetIds, true))) {
            return ['kept_now_named', []];
        }

        foreach ($rows as $row) {
            $cleared = ImageEditorResource::query()->whereKey($row->getKey())->where('path', $row->path)->update(['path' => null]);

            if ($cleared !== 1) {
                return ['kept_now_named', []];
            }
        }

        // The receipt names every asset whose rows named the file: the cleared rows' and any Library or content path.
        return $this->unlinkQuietly(disk: $disk, path: $file['path']) ? ['deleted', $this->namingAssetIds($namers)] : ['kept_unlink_failed', []];
    }

    /**
     * Remove the directories the reclaim roots are left with empty, deepest first, each with a `.DS_Store` that is
     * the last thing in it. That covers every folder under `assets/` in set 1 or 2, and every folder inside
     * `thumbnails/assets/` and `preview/<size>/assets/` that mirrors a set-1 or set-2 folder. The same dot-segment policy
     * as the files applies, so the blob store, staging and every other dot-folder are never touched. The roots
     * themselves stay. A directory whose name is not valid UTF-8 is never touched either, nor the `.DS_Store` in
     * it. One that holds nothing else is reported as `retained_invalid_utf8` for a person. Local disks only.
     *
     * @return array{pruned: list<string>, finder_files: list<array{path: string, size: int}>, retained_invalid_utf8: list<string>}
     */
    private function pruneReclaimedDirectories(string $disk): array
    {
        if (! $this->isLocal($disk)) {
            return ['pruned' => [], 'finder_files' => [], 'retained_invalid_utf8' => []];
        }

        $storage = Storage::disk($disk);
        $directories = [];

        // The blob store, staging and unlisted dot-folders are not even listed.
        foreach ($storage->directories(Directory::ASSETS_DIRECTORY) as $directory) {
            if ($this->folderArea($directory) !== null) {
                array_push($directories, $directory, ...$storage->allDirectories($directory));
            }
        }

        $directories = array_values(array_filter($directories, fn (string $directory): bool => $this->folderArea($directory) !== null));

        foreach ($this->derivativeMirrors($disk) as $root) {
            foreach ($storage->allDirectories($root) as $directory) {
                if ($this->folderArea(Directory::ASSETS_DIRECTORY.substr($directory, strlen($root))) !== null) {
                    $directories[] = $directory;
                }
            }
        }

        [$valid, $invalid] = $this->splitByEncoding($directories);
        $result = $this->removeEmptyDirectories(disk: $disk, directories: $valid, removeFinderFiles: true);

        // Read after the pruning: one whose children were just pruned may hold nothing now.
        $retained = array_values(array_filter($invalid, fn (string $directory): bool => $this->holdsAtMostAFinderFile(disk: $disk, directory: $directory)));
        sort($retained, SORT_STRING);

        return $result + ['retained_invalid_utf8' => $retained];
    }

    /** Whether a directory holds nothing, or nothing but Finder's `.DS_Store`: what pruning would have removed. */
    private function holdsAtMostAFinderFile(string $disk, string $directory): bool
    {
        $entries = @scandir(Storage::disk($disk)->path($directory));

        return $entries !== false && array_diff($entries, ['.', '..', self::FINDER_FILE]) === [];
    }

    /**
     * The run's JSON: the plan and what the force pass did, counted per set and per outcome. `clean` is true when
     * nothing is left reclaimable and nothing is kept for a person: every kept reason but `kept_referenced`, every
     * skipped dot-folder file and every directory retained for a name that is not valid UTF-8 counts.
     *
     * @param  array<string, mixed>  $run
     * @param  array{files: list<array<string, mixed>>, skipped: list<array{path: string, size: int}>}  $plan
     * @param  array{pruned: list<string>, finder_files: list<array{path: string, size: int}>, retained_invalid_utf8: list<string>}  $pruned
     * @return array<string, mixed>
     */
    private function reclaimResult(array $run, array $plan, array $pruned): array
    {
        $empty = ['count' => 0, 'bytes' => 0, 'sample' => []];
        $sets = array_fill_keys(self::RECLAIM_SETS, $empty);
        $outcomes = array_fill_keys(['reclaimable', 'deleted', ...self::RECLAIM_KEPT, 'skipped_dot_folder'], $empty);
        $cleared = ['count' => 0, 'sample' => []];
        $files = [];

        foreach ($plan['files'] as $file) {
            $size = (int) $file['size'];

            if ($file['_reclaimable']) {
                $this->noteFile($sets[$file['set']], $file['path'], $size);
                $this->noteFile($outcomes['reclaimable'], $file['path'], $size);
            }

            if ($file['action'] !== 'reclaimable') {
                $this->noteFile($outcomes[$file['action']], $file['path'], $size);
            }

            if ($file['action'] === 'deleted') {
                foreach ($file['editor_rows'] ?? [] as $uuid) {
                    $this->note($cleared, (string) $uuid);
                }
            }

            $files[] = array_filter($file, fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        }

        foreach ($plan['skipped'] as $skipped) {
            $this->noteFile($outcomes['skipped_dot_folder'], $skipped['path'], $skipped['size']);
        }

        $remaining = count(array_filter($plan['files'], fn (array $file): bool => $file['action'] === 'reclaimable'));
        $forAPerson = $outcomes['skipped_dot_folder']['count'] + count($pruned['retained_invalid_utf8']);

        foreach (array_diff(self::RECLAIM_KEPT, ['kept_referenced']) as $reason) {
            $forAPerson += $outcomes[$reason]['count'];
        }

        return $run + ['clean' => $run['refused'] === null && $remaining === 0 && $forAPerson === 0, 'sets' => $sets] + $outcomes + [
            'editor_paths_cleared' => $cleared,
            'pruned_directories'   => [
                'count'                 => count($pruned['pruned']),
                'sample'                => array_slice($pruned['pruned'], 0, self::SAMPLE_LIMIT),
                'finder_files'          => count($pruned['finder_files']),
                'retained_invalid_utf8' => [
                    'count'  => count($pruned['retained_invalid_utf8']),
                    'sample' => array_map(
                        fn (string $directory): string => $this->encodedPath($directory),
                        array_slice($pruned['retained_invalid_utf8'], 0, self::SAMPLE_LIMIT),
                    ),
                ],
            ],
            'files'                => $files,
        ];
    }

    /**
     * A refused run: nothing was changed. The result carries whatever the report pass found before the refusal,
     * and one `integrity.reclaim_refused` record is written.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $refusal
     * @param  array{files: list<array<string, mixed>>, skipped: list<array{path: string, size: int}>}|null  $plan
     * @return array<string, mixed>
     */
    private function refuseReclaim(array $run, array $refusal, ReclaimOptions $options, ?array $plan): array
    {
        $run['refused'] = $refusal;
        $result = $this->reclaimResult(run: $run, plan: $plan ?? ['files' => [], 'skipped' => []], pruned: ['pruned' => [], 'finder_files' => [], 'retained_invalid_utf8' => []]);

        $this->record('integrity.reclaim_refused', [
            'run_id'  => $run['run_id'],
            'force'   => $options->force,
            'dry_run' => $options->dryRun,
            'backup'  => $options->backup,
            'disk'    => $run['disk'],
            'refused' => $refusal,
        ], 'warning');

        return $result;
    }

    /**
     * Informational counterpart of libraryOrphans() for the hourly check: count, bytes and a sample, with no
     * hashing, plus how many names are not valid UTF-8 (never queried, never counted as orphans). `complete` is
     * false when the listing or the lookup failed, so a partial count is never read as the whole. Local disks
     * only; a remote Library tree is not listed every hour (null).
     *
     * @return array{count: int, bytes: int, sample: list<string>, invalid_utf8: int, complete: bool}|null
     */
    private function orphanSummary(): ?array
    {
        $disk = Directory::getAssetDisk();

        if (! $this->isLocal($disk)) {
            return null;
        }

        $summary = ['count' => 0, 'bytes' => 0, 'sample' => [], 'invalid_utf8' => 0, 'complete' => true];

        try {
            [$paths, $invalid] = $this->splitByEncoding($this->libraryFiles($disk));
            $summary['invalid_utf8'] = count($invalid);
            $named = PathReferences::namedAmong(paths: $paths, disk: $disk);

            foreach ($paths as $path) {
                if (! isset($named[$path])) {
                    $this->noteFile($summary, $path, (int) $this->sizeOrNull($disk, $path));
                }
            }
        } catch (Throwable $e) {
            $summary['complete'] = false;
            Log::warning('DAM storage check could not list Library files.', ['exception' => $e]);
        }

        return $summary;
    }

    /**
     * Set aside every name that is not valid UTF-8 before any query sees it: a PostgreSQL text parameter
     * refuses it and would abort the whole pass, and no row can name it.
     *
     * @param  list<string>  $paths
     * @return array{0: list<string>, 1: list<string>} [valid, invalid]
     */
    private function splitByEncoding(array $paths): array
    {
        $valid = [];
        $invalid = [];

        foreach ($paths as $path) {
            if (mb_check_encoding($path, 'UTF-8')) {
                $valid[] = $path;
            } else {
                $invalid[] = $path;
            }
        }

        return [$valid, $invalid];
    }

    /** A lossless ASCII spelling of a name that is not valid UTF-8 (JSON output substitutes its bad bytes). */
    private function encodedPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Every file under `assets/` with no segment starting with `.`: never a dot-folder at any depth (`.objects`,
     * `.staging`, `.editor`, `.models`, any reserved name) and never a dot-file. Top-level dot-folders, the blob
     * store above all, are not even listed.
     *
     * @return list<string>
     */
    private function libraryFiles(string $disk): array
    {
        $files = $this->assetTreeFiles(disk: $disk, descend: fn (string $directory): bool => ! ReservedDirectoryNames::isReserved(basename($directory)));

        return array_values(array_filter($files, fn (string $path) => ! $this->hasDotSegment($path)));
    }

    /**
     * Every file directly under `assets/`, and every file at any depth beneath each top-level folder `$descend`
     * accepts, sorted by path. A folder it refuses is not even listed. The disk is read with files('assets'),
     * directories('assets') and allFiles(<folder>) only.
     *
     * @param  Closure(string): bool  $descend  given each top-level folder as `assets/<name>`
     * @return list<string>
     */
    private function assetTreeFiles(string $disk, Closure $descend): array
    {
        $storage = Storage::disk($disk);
        $files = $storage->files(Directory::ASSETS_DIRECTORY);

        foreach ($storage->directories(Directory::ASSETS_DIRECTORY) as $directory) {
            if ($descend($directory)) {
                array_push($files, ...$storage->allFiles($directory));
            }
        }

        // files() and allFiles() each return a list sorted by path (Flysystem's sortByPath()), but directories()
        // keeps the filesystem's own order, and that order decides where each folder's files land in this list.
        // No disk here lists a directory sorted: APFS returns a deterministic name-hash order, and Linux
        // filesystems a hash order of their own. Sort the whole list so every report, sample and test sees one order.
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Remove every directory under `assets/` with no dot segment that holds nothing, deepest first so a
     * parent its children emptied goes too. rmdir() removes only an empty directory, so a file that lands
     * meanwhile keeps its directory. Local disks only: an object store has no directories.
     *
     * @return list<string>
     */
    private function pruneEmptyLibraryDirectories(string $disk): array
    {
        if (! $this->isLocal($disk)) {
            return [];
        }

        $storage = Storage::disk($disk);
        $directories = [];

        foreach ($storage->directories(Directory::ASSETS_DIRECTORY) as $directory) {
            if (! ReservedDirectoryNames::isReserved(basename($directory))) {
                array_push($directories, $directory, ...$storage->allDirectories($directory));
            }
        }

        $directories = array_filter($directories, fn (string $directory) => ! $this->hasDotSegment($directory));

        return $this->removeEmptyDirectories(disk: $disk, directories: array_values($directories), removeFinderFiles: false)['pruned'];
    }

    /**
     * Remove each of `$directories` that holds nothing, deepest first so a parent its children emptied goes too.
     * rmdir() removes only an empty directory, so a file that lands meanwhile keeps its directory. With
     * `$removeFinderFiles`, a directory whose only entry is Finder's `.DS_Store` loses that file and then the
     * directory. Local disks only: the caller checks.
     *
     * @param  list<string>  $directories
     * @return array{pruned: list<string>, finder_files: list<array{path: string, size: int}>}
     */
    private function removeEmptyDirectories(string $disk, array $directories, bool $removeFinderFiles): array
    {
        $storage = Storage::disk($disk);
        usort($directories, fn (string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));

        $pruned = [];
        $finderFiles = [];

        foreach ($directories as $directory) {
            $absolute = $storage->path($directory);

            if ($removeFinderFiles) {
                $entries = @scandir($absolute);
                $finder = $absolute.'/'.self::FINDER_FILE;

                if ($entries !== false && array_values(array_diff($entries, ['.', '..'])) === [self::FINDER_FILE] && is_file($finder)) {
                    $size = (int) @filesize($finder);

                    if (@unlink($finder)) {
                        $finderFiles[] = ['path' => $directory.'/'.self::FINDER_FILE, 'size' => $size];
                    }
                }
            }

            if (@rmdir($absolute)) {
                $pruned[] = $directory;
            }
        }

        return ['pruned' => $pruned, 'finder_files' => $finderFiles];
    }

    /** Whether any segment of `$path` below `assets/` (a folder or the file name) starts with `.`. */
    private function hasDotSegment(string $path): bool
    {
        foreach (array_slice(explode('/', $path), 1) as $segment) {
            if (ReservedDirectoryNames::isReserved($segment)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array{count: int, bytes: int, sample: list<string>}  $finding */
    private function noteFile(array &$finding, string $path, int $size): void
    {
        $finding['count']++;
        $finding['bytes'] += $size;

        if (count($finding['sample']) < self::SAMPLE_LIMIT) {
            $finding['sample'][] = $path;
        }
    }

    /** @return 'repointed'|'copied'|'unresolved' */
    private function repairObject(FileObject $object, bool $dryRun): string
    {
        try {
            if (($blob = $this->intactBlob($object)) !== null) {
                if (! $dryRun) {
                    DB::transaction(function () use ($object, $blob): void {
                        FileObject::query()->whereKey($object->getKey())->lockForUpdate()->firstOrFail()
                            ->forceFill(['path' => $blob])->save();
                    });
                }

                return 'repointed';
            }

            foreach ($this->sources($object) as [$disk, $path]) {
                if ($this->sizeOrNull($disk, $path) !== (int) $object->size || $this->store->checksum($disk, $path) !== $object->sha256) {
                    continue;
                }

                if (! $dryRun) {
                    $this->store->putStored(disk: $disk, sourcePath: $path, sha256: $object->sha256);
                }

                return 'copied';
            }
        } catch (Throwable $e) {
            Log::warning('DAM storage repair could not resolve a file object.', ['file_object' => $object->getKey(), 'exception' => $e]);
        }

        return 'unresolved';
    }

    /** The blob this row names, or one written for its sha256 under another suffix, when it holds exactly its size. */
    private function intactBlob(FileObject $object): ?string
    {
        $disk = (string) $object->disk;
        $legacyExtension = strtolower((string) pathinfo((string) $object->path, PATHINFO_EXTENSION));
        $candidates = [$this->store->objectPath($object->sha256)];

        if (preg_match('/^[a-z0-9]{1,16}$/', $legacyExtension) === 1) {
            $candidates[] = $this->store->objectPath($object->sha256, $legacyExtension);
        }

        foreach ($candidates as $candidate) {
            if ($this->sizeOrNull($disk, $candidate) === (int) $object->size) {
                return $candidate;
            }
        }

        try {
            foreach (Storage::disk($disk)->files(dirname($candidates[0])) as $file) {
                if ($this->store->isObjectPath($file, $object->sha256) && $this->sizeOrNull($disk, $file) === (int) $object->size) {
                    return $file;
                }
            }
        } catch (Throwable) {
            // An unlistable shard means no blob to reuse; the source search still runs.
        }

        return null;
    }

    /**
     * Files that may still hold this row's bytes: its own legacy locator, then the Library path of every asset
     * that references it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function sources(FileObject $object): array
    {
        $sources = [];

        if (! $this->store->isObjectPath($object->path, $object->sha256) && (string) $object->path !== '') {
            $sources[] = [(string) $object->disk, (string) $object->path];
        }

        $assetDisk = Directory::getAssetDisk();
        $paths = Asset::withTrashed()
            ->whereIn('id', AssetFile::query()->where('file_object_id', $object->getKey())->select('dam_asset_id'))
            ->whereNotNull('path')->where('path', '!=', '')
            ->orderBy('id')
            ->pluck('path');

        foreach ($paths as $path) {
            $sources[] = [$assetDisk, (string) $path];
        }

        return array_values(array_unique($sources, SORT_REGULAR));
    }

    /** @return 'matched'|'divergent'|'skipped_missing'|'skipped_no_original'|'failed' */
    private function verifyAsset(Asset $asset, string $disk): string
    {
        $original = AssetFile::query()
            ->where('dam_asset_id', $asset->getKey())
            ->where('role', AssetFile::ROLE_ORIGINAL)
            ->with('fileObject')
            ->first()?->fileObject;

        if ($original === null) {
            return 'skipped_no_original';
        }

        $size = $this->sizeOrNull($disk, (string) $asset->path);

        if ($size === null) {
            return 'skipped_missing';
        }

        try {
            if ($size !== (int) $original->size) {
                return 'divergent';
            }

            return $this->store->checksum($disk, (string) $asset->path) === $original->sha256 ? 'matched' : 'divergent';
        } catch (Throwable $e) {
            Log::warning('DAM storage verify could not check an asset.', ['asset' => $asset->getKey(), 'exception' => $e]);

            return 'failed';
        }
    }

    private function withoutOriginal()
    {
        return $this->withLibraryPath()
            ->whereDoesntHave('files', fn ($query) => $query->where('role', AssetFile::ROLE_ORIGINAL));
    }

    /** Trashed assets included (P2 S4): their files are kept for Restore, so they are checked like any other. */
    private function withLibraryPath()
    {
        return Asset::withTrashed()->whereNotNull('path')->where('path', '!=', '');
    }

    /** @param  array{count: int, sample: list<int|string>}  $finding */
    private function note(array &$finding, int|string $id): void
    {
        $finding['count']++;

        if (count($finding['sample']) < self::SAMPLE_LIMIT) {
            $finding['sample'][] = $id;
        }
    }

    private function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    private function exists(string $disk, string $path): bool
    {
        try {
            return $path !== '' && Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    private function sizeOrNull(string $disk, string $path): ?int
    {
        try {
            return $this->exists($disk, $path) ? Storage::disk($disk)->size($path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function readState(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) && isset($state['run_id'], $state['after_id'], $state['totals']) ? $state : null;
    }

    private function writeState(string $path, array $state): void
    {
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function record(string $event, array $context, string $level = 'info'): void
    {
        $record = [
            'ts'     => now()->toIso8601String(),
            'run_id' => $context['run_id'] ?? (string) Str::uuid(),
            'level'  => $level,
            'event'  => $event,
            'loc'    => self::class,
            'ctx'    => RequestId::ctx($context),
        ];

        // The sink never breaks a repair: the migration runs this on every boot until it succeeds.
        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
            @file_put_contents(storage_path(self::LOG), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            Log::warning('DAM storage integrity record could not be written.', ['event' => $event, 'exception' => $e]);
        }
    }
}
