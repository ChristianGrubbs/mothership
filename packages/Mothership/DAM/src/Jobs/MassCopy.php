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
use Mothership\DAM\Services\StackMemberState;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\ClonesOriginalFileObjects;

class MassCopy implements ShouldQueue
{
    use ActionRequestTrait, ClonesOriginalFileObjects, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function retryUntil(): \DateTime
    {
        return now()->addSeconds(3600);
    }

    private const MAX_DEPTH = 100;

    private const ASSET_CHUNK_SIZE = 500;

    private const SOURCE_COLUMNS = ['id', 'file_name', 'file_type', 'extension', 'file_size', 'path', 'mime_type', 'meta_data', 'availability', 'available_from', 'available_until'];

    private array $usedNames = [];

    /** @var array<int, list<int>> Directory id => the source asset ids in it this run holds locked. */
    private array $lockedSourceIdsByDirectory = [];

    /** @var array<int, true> Directory id => the userId can open it (sharing slice 2b), set membership. */
    private array $accessibleDirectorySet = [];

    private int $progressTotal = 0;

    private int $progressDone = 0;

    public function __construct(
        protected array $assetIds,
        protected array $dirIds,
        protected int $targetId,
        protected int $userId
    ) {}

    public function handle(): void
    {
        if (! $this->checkedUser($this->userId)) {
            $this->markFailed(EventType::MASS_COPY->value, $this->userId, 'User not found');
            $this->clearProgress(EventType::MASS_COPY->value, $this->userId);

            return;
        }

        try {
            $copyableSources = [];
            $this->progressTotal = count($this->assetIds);
            $this->progressDone = 0;
            $dirTable = (new Directory)->getTable();
            $pivotTable = (new Asset)->directories()->getTable();

            foreach ($this->dirIds as $id) {
                $src = Directory::find($id);

                if (! $src || ! $src->isCopyable()) {
                    continue;
                }

                $copyableSources[$id] = $src;

                $this->progressTotal += DB::table($pivotTable)
                    ->join($dirTable, "{$dirTable}.id", '=', "{$pivotTable}.directory_id")
                    ->where("{$dirTable}._lft", '>=', $src->_lft)
                    ->where("{$dirTable}._rgt", '<=', $src->_rgt)
                    ->distinct()
                    ->count("{$pivotTable}.asset_id");
            }

            $this->progressTotal = max(1, $this->progressTotal);

            $assetTable = (new Asset)->getTable();

            // Codex review round 1, findings 2 & 3: the destination must be read and its path derived
            // under the lock (a rename that commits while this job waits would otherwise leave assets
            // with a stale prefix), and the directory-copy branch below must share that same lock and
            // transaction as the selected-asset copy above it — a rename between chunks, or a failure
            // partway through, otherwise left later assets stale or the batch half-committed.
            // withTreeLock is re-entrant per connection, so Directory::create()/save() inside deepCopy()
            // nesting back into it below is free — one outer call covers the whole operation.
            Directory::withTreeLock(function () use ($assetTable, $pivotTable, $copyableSources) {
                $targetDirectory = Directory::findOrFail($this->targetId);
                $targetDirPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $targetDirectory->generatePath());

                // Codex review round 2, finding 1: $copyableSources[$id] was loaded before this tree
                // lock was even requested (~:66-73). Reload the source here, inside the lock, the same
                // way the destination above is — a concurrent move that reparents the destination
                // beneath this source while the job waited for the lock must be caught by comparing
                // fresh intervals on both sides, not the source's pre-lock ones; a source deleted
                // before lock acquisition must be skipped like any other invalid selection, not
                // deep-copied from a stale, gone row. Codex review round 4: every source is reloaded
                // and its subtree read here, before anything is copied — the source asset rows below
                // are locked as one set first, and each Directory::create() shifts the intervals.
                // Under `lists` a source folder the actor cannot open is skipped together with its whole
                // subtree, path-only included (spec, "Copies"); re-checked here for $this->userId rather
                // than trusted from the controller's selection, the same as every id above is re-verified
                // against a freshly loaded row. `grants` never gains a filter that was not there before
                // this slice (accessibleDirectoryIds()).
                $permissions = app(DirectoryPermissionService::class)->forAdmin($this->user);
                $lists = app(AccessModel::class)->lists();

                $sources = [];

                foreach ($this->dirIds as $id) {
                    if (! isset($copyableSources[$id])) {
                        continue;
                    }

                    $source = Directory::find($id);

                    if (! $source || ! $source->isCopyable()) {
                        continue;
                    }

                    if ($targetDirectory->id === $source->id || $targetDirectory->isDescendantOf($source)) {
                        throw new \RuntimeException(trans('dam::app.admin.dam.index.directory.cannot-copy'));
                    }

                    // Codex review of PR #75, finding 1: the controller's canAccess() check on this root
                    // ran before the job was even dispatched, so it is stale by the time the tree lock is
                    // granted. Re-checked here, fresh; skipped like any other invalid selection rather
                    // than refusing the whole batch, matching the asset branch's own per-item skip.
                    if ($lists && ! $permissions->bypass() && ! $permissions->canAccess($source->id)) {
                        continue;
                    }

                    $sources[] = $source;
                }

                $subtreeDirectoriesBySource = [];
                $subtreeDirectoryIds = [];

                foreach ($sources as $source) {
                    $subtreeDirectoriesBySource[$source->id] = Directory::query()
                        ->whereBetween('_lft', [$source->_lft, $source->_rgt])
                        ->get(['id', 'parent_id']);
                    $subtreeDirectoryIds = array_merge(
                        $subtreeDirectoryIds,
                        $subtreeDirectoriesBySource[$source->id]->map(fn ($directory) => (int) $directory->id)->all()
                    );
                }

                $this->accessibleDirectorySet = array_fill_keys(
                    $this->accessibleDirectoryIds(array_values(array_unique($subtreeDirectoryIds)), $permissions),
                    true
                );

                // Codex review of PR #75 round 2, finding 4: reachable, not just accessible, per source
                // root — a folder the actor can individually open still contributes no file candidate
                // below if a hidden ancestor sits between it and the source it was selected under.
                $reachableDirectoryIds = [];

                foreach ($sources as $source) {
                    $reachableDirectoryIds += $this->reachableDirectoryIds($source->id, $subtreeDirectoriesBySource[$source->id], $this->accessibleDirectorySet);
                }

                // Codex review round 4 — lock order: the tree lock (held), then EVERY source asset row (the
                // selected assets and each source folder's whole subtree) in ascending id order, then the
                // shared FileObjects only implicitly, via the attachments' foreign key. Locking a chunk at
                // a time, after earlier chunks' attachments already held KEY SHARE on a shared FileObject,
                // deadlocked with AssetDeletionService::delete() of a later source (lockSourceAssets()).
                // A source deleted before its lock is granted is skipped.
                //
                // Codex review of PR #75, findings 2 and 4: both the selected-asset ids and the subtree
                // ids are filtered to what the actor can see BEFORE lockSourceAssets() runs, so a hidden
                // file's row is never locked by this copy at all — then re-checked again from the
                // now-locked, stable state, because that first filter read an unlocked snapshot and
                // AccessListService::rewriteAsset() locks only the one file row it changes (never the tree
                // lock), so a revocation could still have committed in the gap between the filter and the
                // lock being granted. Once locked, nothing else can change those rows until this
                // transaction ends, so the second read is the authoritative one.
                $sourceIdsByDirectory = [];

                foreach ($sources as $source) {
                    $sourceIdsByDirectory += $this->sourceAssetIdsBySubtreeDirectory($source);
                }

                // Pruned to $reachableDirectoryIds first (round 2, finding 4) — see CopyDirectory.php's
                // own comment on the same pattern.
                $sourceIdsByDirectory = array_intersect_key($sourceIdsByDirectory, $reachableDirectoryIds);
                $subtreeAssetIds = $sourceIdsByDirectory === [] ? [] : array_merge(...array_values($sourceIdsByDirectory));
                $selectedCandidateIds = $this->accessibleAssetIds(array_map('intval', $this->assetIds), $permissions);
                $subtreeCandidateIds = $this->accessibleAssetIds($subtreeAssetIds, $permissions);

                $locked = $this->lockSourceAssets(array_merge($selectedCandidateIds, $subtreeCandidateIds));
                // Locking read (round 2, finding 2): see CopyDirectory.php's own comment on the same
                // MySQL/MariaDB REPEATABLE READ hazard.
                $revalidated = array_fill_keys($this->accessibleAssetIds(array_keys($locked), $permissions, locked: true), true);

                $this->lockedSourceIdsByDirectory = array_map(
                    fn (array $ids) => array_values(array_filter($ids, fn (int $id) => isset($locked[$id]) && isset($revalidated[$id]))),
                    $sourceIdsByDirectory
                );

                if (! empty($this->assetIds)) {
                    $now = now()->toDateTimeString();

                    // Codex review round 3, finding 3 (CopyController::copyAsset ~:80 — same
                    // descriptor-vs-bytes split): attachOriginalFileObjects() reads each source's current
                    // original FileObject in a separate, later query, after these descriptors are already
                    // captured and inserted. Both read rows lockSourceAssets() holds for the rest of this
                    // transaction — a concurrent writer's own Asset::lockedForGraph() call (every graph
                    // write, including the editor's) blocks on them until this job's tree-lock transaction
                    // commits or rolls back, so the later file_object_id read is guaranteed to see the same
                    // state as this one even though it is a separate query.
                    $copySelected = function ($assets) use ($targetDirPath, $assetTable, $pivotTable, $now) {
                        $rows = [];
                        $sourceAssetIdByNewPath = [];

                        foreach ($assets as $asset) {
                            if (! $asset->path) {
                                continue;
                            }

                            $newName = $this->resolveUniqueName($targetDirPath, $asset->file_name, $this->targetId);
                            $newPath = $targetDirPath.'/'.$newName;

                            $rows[] = [
                                'file_name'  => $newName,
                                'file_type'  => $asset->file_type,
                                'extension'  => $asset->extension,
                                'file_size'  => $asset->file_size,
                                'path'       => $newPath,
                                'mime_type'  => $asset->mime_type,
                                'meta_data'  => json_encode(array_diff_key($asset->meta_data ?? [], [StackMemberState::KEY => true]), JSON_THROW_ON_ERROR),
                                ...Asset::availabilityOf($asset),
                                'created_by' => $this->userId,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            $sourceAssetIdByNewPath[$newPath] = $asset->id;
                        }

                        if (empty($rows)) {
                            return;
                        }

                        DB::transaction(function () use ($assetTable, $pivotTable, $rows) {
                            DB::table($assetTable)->insert($rows);

                            $newIds = DB::table($assetTable)
                                ->whereIn('path', array_column($rows, 'path'))
                                ->pluck('id');

                            if ($newIds->isNotEmpty()) {
                                DB::table($pivotTable)->insert(
                                    $newIds->map(fn ($id) => ['asset_id' => $id, 'directory_id' => $this->targetId])->all()
                                );
                                // Each copy takes the destination folder's access list, in the same
                                // transaction as its pivot row (ADR 0010, spec "Copy"). The tree lock
                                // this runs under already holds the fence's row.
                                app(AccessListWriter::class)->inheritAssets($newIds->all());
                            }
                        });

                        $this->attachOriginalFileObjects($sourceAssetIdByNewPath);

                        $this->progressDone += count($rows);
                        $this->updateProgress(
                            EventType::MASS_COPY->value,
                            $this->userId,
                            (int) min(99, round($this->progressDone / $this->progressTotal * 100))
                        );
                    };

                    // $locked and $revalidated above already restrict this to ids the actor can see, from
                    // rows this run holds locked — the same double check (filter, lock, re-check) as the
                    // subtree branch, over the selected-asset candidates instead.
                    $selectedIds = array_values(array_filter(
                        $selectedCandidateIds,
                        fn (int $id) => isset($locked[$id]) && isset($revalidated[$id])
                    ));

                    foreach (array_chunk($selectedIds, self::ASSET_CHUNK_SIZE) as $batch) {
                        $copySelected(Asset::whereIn('id', $batch)->orderBy('id')->get(self::SOURCE_COLUMNS));
                    }
                }

                foreach ($sources as $source) {
                    $newName = Directory::uniqueName($source->name, $this->targetId);

                    $newRoot = Directory::create(['name' => $newName, 'parent_id' => $this->targetId, 'created_by' => $this->userId]);
                    $this->usedNames[$newRoot->id] = [];
                    $newRootStoragePath = $targetDirPath.'/'.$newName;
                    $this->deepCopy($source, $newRoot, $newRootStoragePath, 0);
                }
            });

            $this->completed(EventType::MASS_COPY->value, $this->userId);
            $this->clearProgress(EventType::MASS_COPY->value, $this->userId);
        } catch (\Throwable $e) {
            $this->markFailed(EventType::MASS_COPY->value, $this->userId, $e->getMessage());
            $this->clearProgress(EventType::MASS_COPY->value, $this->userId);
        }
    }

    private function deepCopy(Directory $source, Directory $newParent, string $newParentStoragePath, int $initialDepth): void
    {
        $assetTable = (new Asset)->getTable();
        $pivotTable = (new Asset)->directories()->getTable();
        $now = now()->toDateTimeString();

        $queue = [[$source->id, $newParent, $newParentStoragePath, $initialDepth]];

        while (! empty($queue)) {
            [$srcId, $destDir, $destPath, $curDepth] = array_shift($queue);

            if ($curDepth > self::MAX_DEPTH) {
                throw new \RuntimeException('Directory tree exceeds maximum copy depth of '.self::MAX_DEPTH);
            }

            // Codex review round 3, finding 3: same descriptor-vs-bytes split as the selected-asset
            // branch above — these are rows handle() already holds locked (lockSourceAssets(), round 4),
            // so the later attachOriginalFileObjects() read of each source's current original is
            // guaranteed consistent with these descriptors. A source that joined this folder after
            // that lock is not in the list, so no row is ever locked here, out of order.
            $copyChunk = function ($assets) use ($destPath, $destDir, $assetTable, $pivotTable, $now) {
                $rows = [];
                $sourceAssetIdByNewPath = [];

                foreach ($assets as $asset) {
                    $newName = $this->resolveUniqueName($destPath, $asset->file_name, $destDir->id);
                    $newPath = $destPath.'/'.$newName;

                    $rows[] = [
                        'file_name'  => $newName,
                        'file_type'  => $asset->file_type,
                        'extension'  => $asset->extension,
                        'file_size'  => $asset->file_size,
                        'path'       => $newPath,
                        'mime_type'  => $asset->mime_type,
                        'meta_data'  => json_encode(array_diff_key($asset->meta_data ?? [], [StackMemberState::KEY => true]), JSON_THROW_ON_ERROR),
                        ...Asset::availabilityOf($asset),
                        'created_by' => $this->userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $sourceAssetIdByNewPath[$newPath] = $asset->id;
                }

                if (empty($rows)) {
                    return;
                }

                DB::transaction(function () use ($assetTable, $pivotTable, $rows, $destDir) {
                    DB::table($assetTable)->insert($rows);

                    $newIds = DB::table($assetTable)
                        ->whereIn('path', array_column($rows, 'path'))
                        ->pluck('id');

                    if ($newIds->isNotEmpty()) {
                        DB::table($pivotTable)->insert(
                            $newIds->map(fn ($id) => ['asset_id' => $id, 'directory_id' => $destDir->id])->all()
                        );
                        // Each copied file takes its new folder's access list, which the Directory::created
                        // listener has already given that folder from its own parent (ADR 0010).
                        app(AccessListWriter::class)->inheritAssets($newIds->all());
                    }
                });

                $this->attachOriginalFileObjects($sourceAssetIdByNewPath);

                $this->progressDone += count($rows);
                $this->updateProgress(
                    EventType::MASS_COPY->value,
                    $this->userId,
                    (int) min(99, round($this->progressDone / $this->progressTotal * 100))
                );
            };

            foreach (array_chunk($this->lockedSourceIdsByDirectory[$srcId] ?? [], self::ASSET_CHUNK_SIZE) as $batch) {
                $copyChunk(Asset::whereIn('id', $batch)->orderBy('id')->get(self::SOURCE_COLUMNS));
            }

            $srcDir = Directory::with('children:id,name,parent_id')->find($srcId);

            if (! $srcDir) {
                continue;
            }

            foreach ($srcDir->children as $child) {
                // Under `lists`, path-only included (spec, "Copies"); $accessibleDirectorySet is the
                // whole subtree under `grants`, so this never filters anything there.
                if (! isset($this->accessibleDirectorySet[$child->id])) {
                    continue;
                }

                $newChild = Directory::create(['name' => $child->name, 'parent_id' => $destDir->id, 'created_by' => $this->userId]);
                $this->usedNames[$newChild->id] = [];
                $queue[] = [$child->id, $newChild, $destPath.'/'.$newChild->name, $curDepth + 1];
            }
        }
    }

    /**
     * A name free in `$directoryPath`, checked against `dam_assets.path` (LibraryPaths, shared with C6)
     * and this run's own reservations for `$dirId` — copies land before any row for them exists, so two
     * assets copied into the same new folder never collide with each other either.
     */
    private function resolveUniqueName(string $directoryPath, string $fileName, int $dirId): string
    {
        $this->usedNames[$dirId] ??= [];

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $candidate = $fileName;

        for (
            $counter = 1;
            isset($this->usedNames[$dirId][$candidate]) || LibraryPaths::taken($directoryPath.'/'.$candidate);
            $counter++
        ) {
            $candidate = $extension === '' ? "{$stem}({$counter})" : "{$stem}({$counter}).{$extension}";
        }

        $this->usedNames[$dirId][$candidate] = true;

        return $candidate;
    }

    public function failed(\Throwable $e): void
    {
        $this->markFailed(EventType::MASS_COPY->value, $this->userId, $e->getMessage());
        $this->clearProgress(EventType::MASS_COPY->value, $this->userId);
    }
}
