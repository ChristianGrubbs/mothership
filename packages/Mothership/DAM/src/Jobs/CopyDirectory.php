<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\ClonesOriginalFileObjects;

class CopyDirectory implements ShouldQueue
{
    use ActionRequestTrait, ClonesOriginalFileObjects, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_DEPTH = 100;

    public int $timeout = 3600;

    /** @var array<int, list<int>> Directory id => the source asset ids in it this run holds locked. */
    private array $lockedSourceIdsByDirectory = [];

    /** @var array<int, true> Directory id => the userId can open it (sharing slice 2b), set membership. */
    private array $accessibleDirectorySet = [];

    public function __construct(
        protected int $sourceId,
        protected int $targetId,
        protected int $userId
    ) {}

    public function handle(): void
    {
        if (! $this->checkedUser($this->userId)) {
            $this->markFailed(EventType::COPY_DIRECTORY->value, $this->userId, 'User not found');

            return;
        }

        try {
            // The whole copy — the new root, every descendant row and asset row it inserts — runs
            // under the directory tree lock (Decision 4, ADR 0009 slice 3): nothing it reads can
            // shift underneath it, and no name it picks can collide with a concurrent writer's.
            // Codex review round 4: the source, target, ancestry guard and name are therefore read
            // inside the lock too (they were read before it), since the source's fresh interval now
            // decides which asset rows are locked below.
            Directory::withTreeLock(function () {
                $source = Directory::with('children')->findOrFail($this->sourceId);
                $target = Directory::findOrFail($this->targetId);

                if ($target->id === $source->id || $target->isDescendantOf($source)) {
                    throw new \RuntimeException(trans('dam::app.admin.dam.index.directory.cannot-copy'));
                }

                $permissions = app(DirectoryPermissionService::class)->forAdmin($this->user);

                // Codex review of PR #75, finding 1: the controller's canAccess() check on $this->sourceId
                // ran before this job was even dispatched, so it is stale by the time the tree lock is
                // granted — a revocation racing the dispatch would otherwise still get copied. Re-checked
                // here, fresh, before anything is read or created.
                if (app(AccessModel::class)->lists() && ! $permissions->bypass() && ! $permissions->canAccess($source->id)) {
                    throw new \RuntimeException(trans('dam::app.admin.dam.index.directory.cannot-copy'));
                }

                $newName = Directory::uniqueName($source->name, $this->targetId);

                // Under `lists` a folder the actor cannot open is skipped together with its whole subtree
                // (spec, "Copies"); computed once, before any Directory::create() below shifts the source
                // subtree's intervals, the same timing sourceAssetIdsBySubtreeDirectory() already depends on.
                $subtreeDirectories = Directory::query()
                    ->whereBetween('_lft', [$source->_lft, $source->_rgt])
                    ->get(['id', 'parent_id']);
                $subtreeDirectoryIds = $subtreeDirectories->map(fn ($directory) => (int) $directory->id)->all();
                $this->accessibleDirectorySet = array_fill_keys(
                    $this->accessibleDirectoryIds($subtreeDirectoryIds, $permissions),
                    true
                );

                // Codex review of PR #75 round 2, finding 4: reachable, not just accessible — a folder the
                // actor can individually open still never gets its files locked below if a hidden ancestor
                // sits between it and $source, matching what the walk beneath will actually copy.
                $reachableDirectoryIds = $this->reachableDirectoryIds($source->id, $subtreeDirectories, $this->accessibleDirectorySet);

                // Codex review round 4 — lock order: the tree lock (held), then EVERY source asset row in
                // the subtree in ascending id order, before any row is inserted; FileObjects are taken
                // only implicitly, by the attachments' foreign key. Locking each folder's sources as the
                // walk reached it, after the parent folder's attachments already held KEY SHARE on a
                // shared FileObject, deadlocked with AssetDeletionService::delete() of a child folder's
                // source (lockSourceAssets()). A source deleted before its lock is granted is skipped.
                //
                // Codex review of PR #75, findings 2 and 4: filtered to what the actor can see BEFORE
                // lockSourceAssets() runs, so a hidden file's row is never locked by this copy at all —
                // then re-checked again from the now-locked, stable state, because that first filter read
                // an unlocked snapshot and AccessListService::rewriteAsset() locks only the one file row
                // it changes (never the tree lock), so a revocation could still have committed in the gap
                // between the filter and the lock being granted. Once locked, nothing else can change
                // those rows until this transaction ends, so the second read is the authoritative one —
                // as a locking read (round 2, finding 2): on MySQL/MariaDB's REPEATABLE READ, an ordinary
                // SELECT still answers from the snapshot this transaction opened with, lock or no lock, so
                // only a locking read of the file's own list rows forces it to see a revocation that
                // committed while lockSourceAssets() waited.
                //
                // Pruned to $reachableDirectoryIds first (round 2, finding 4): a directory this run will
                // never actually copy — because a hidden ancestor sits between it and $source — never
                // contributes a file candidate here, however accessible that one file's own list makes it.
                $sourceIdsByDirectory = array_intersect_key($this->sourceAssetIdsBySubtreeDirectory($source), $reachableDirectoryIds);
                $allSourceIds = $sourceIdsByDirectory === [] ? [] : array_merge(...array_values($sourceIdsByDirectory));
                $candidateIds = $this->accessibleAssetIds($allSourceIds, $permissions);
                $locked = $this->lockSourceAssets($candidateIds);
                $revalidated = array_fill_keys($this->accessibleAssetIds(array_keys($locked), $permissions, locked: true), true);
                $this->lockedSourceIdsByDirectory = array_map(
                    fn (array $ids) => array_values(array_filter($ids, fn (int $id) => isset($locked[$id]) && isset($revalidated[$id]))),
                    $sourceIdsByDirectory
                );

                $newRoot = Directory::create(['name' => $newName, 'parent_id' => $this->targetId, 'created_by' => $this->userId]);
                $this->deepCopyDirectory($source, $newRoot, 0);
            });

            $this->completed(EventType::COPY_DIRECTORY->value, $this->userId);
        } catch (\Throwable $e) {
            $this->markFailed(EventType::COPY_DIRECTORY->value, $this->userId, $e->getMessage());
        }
    }

    private function deepCopyDirectory(Directory $source, Directory $newParent, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('Directory tree exceeds maximum copy depth of '.self::MAX_DEPTH);
        }

        $source->loadMissing('children');

        $newParentStoragePath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $newParent->generatePath());

        // Codex review round 3, finding 3 (CopyController::copyAsset ~:80 — same descriptor-vs-bytes
        // split): loaded fresh here rather than off $source->assets, and only rows handle() already
        // holds locked (lockSourceAssets(), round 4), so the later attachOriginalFileObjects() read of
        // each source's current original is guaranteed consistent with these descriptors — a
        // concurrent writer's own Asset::lockedForGraph() call blocks on the row until this
        // transaction ends. A source that joined the folder after that lock is not in the list, so no
        // row is ever locked here, out of order.
        $sourceAssets = collect();

        foreach (array_chunk($this->lockedSourceIdsByDirectory[$source->id] ?? [], 500) as $batch) {
            $sourceAssets = $sourceAssets->concat(Asset::whereIn('id', $batch)->orderBy('id')
                ->get(['id', 'file_name', 'file_type', 'extension', 'file_size', 'path', 'mime_type', 'meta_data', 'availability', 'available_from', 'available_until']));
        }

        if ($sourceAssets->isNotEmpty()) {
            $reservedNames = [];

            $assetRows = [];
            $assetPaths = [];
            $sourceAssetIdByNewPath = [];

            foreach ($sourceAssets as $asset) {
                $newFileName = $this->uniqueAssetNameFromSet($newParentStoragePath, $asset->file_name, $reservedNames);
                $newStoragePath = $newParentStoragePath.'/'.$newFileName;

                $assetRows[] = [
                    'file_name'  => $newFileName,
                    'file_type'  => $asset->file_type,
                    'extension'  => $asset->extension,
                    'file_size'  => $asset->file_size,
                    'path'       => $newStoragePath,
                    'mime_type'  => $asset->mime_type,
                    'meta_data'  => $asset->meta_data !== null ? json_encode($asset->meta_data) : null,
                    ...Asset::availabilityOf($asset),
                    'created_by' => $this->userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $assetPaths[] = $newStoragePath;
                $sourceAssetIdByNewPath[$newStoragePath] = $asset->id;
            }

            DB::table((new Asset)->getTable())->insert($assetRows);

            $newIds = Asset::whereIn('path', $assetPaths)->pluck('id');

            DB::table('dam_asset_directory')->insert(
                $newIds->map(fn ($id) => ['asset_id' => $id, 'directory_id' => $newParent->id])->all()
            );

            // Each copy takes its new folder's access list (ADR 0010, spec "Copy"). These inserts run
            // without a DB::transaction of their own, inside the tree lock's transaction, which already
            // holds the fence's row; the list write stays in that same transaction.
            app(AccessListWriter::class)->inheritAssets($newIds->all());

            $this->attachOriginalFileObjects($sourceAssetIdByNewPath);
        }

        foreach ($source->children as $child) {
            // Under `lists`, path-only included (spec, "Copies"); $accessibleDirectorySet is the whole
            // library under `grants`, so this never filters anything there (accessibleDirectoryIds()).
            if (! isset($this->accessibleDirectorySet[$child->id])) {
                continue;
            }

            $newChild = Directory::create(['name' => $child->name, 'parent_id' => $newParent->id, 'created_by' => $this->userId]);
            $this->deepCopyDirectory($child, $newChild, $depth + 1);
        }
    }

    /**
     * A name free in `$directoryPath`, checked against `dam_assets.path` (LibraryPaths, shared with C6)
     * and this call's own `$reservedNames` — the whole directory's assets are named before any of their
     * rows exist, so two source assets with the same name never collide with each other either.
     */
    private function uniqueAssetNameFromSet(string $directoryPath, string $fileName, array &$reservedNames): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $candidate = $fileName;

        for (
            $counter = 1;
            isset($reservedNames[$candidate]) || LibraryPaths::taken($directoryPath.'/'.$candidate);
            $counter++
        ) {
            $candidate = $extension === '' ? "{$stem}({$counter})" : "{$stem}({$counter}).{$extension}";
        }

        $reservedNames[$candidate] = true;

        return $candidate;
    }
}
