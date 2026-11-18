<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\AssetPathPrefixGuard;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\Directory as DirectoryTrait;

class MassMove implements ShouldQueue
{
    use ActionRequestTrait, DirectoryTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    private const ASSET_CHUNK_SIZE = 500;

    public function retryUntil(): \DateTime
    {
        return now()->addSeconds(3600);
    }

    public function __construct(
        protected array $assetIds,
        protected array $dirIds,
        protected int $targetId,
        protected int $userId
    ) {}

    public function handle(): void
    {
        if (! $this->checkedUser($this->userId)) {
            $this->markFailed(EventType::MASS_MOVE->value, $this->userId, 'User not found');

            return;
        }

        try {
            $targetDirectory = Directory::findOrFail($this->targetId);

            $totalDirs = count($this->dirIds);
            $totalAssets = count($this->assetIds);
            $total = max(1, $totalAssets + $totalDirs);
            $done = 0;

            if (! empty($this->assetIds)) {
                $pivotTable = (new Asset)->directories()->getTable();
                $reservedNames = [];

                // A tree-structure change (Decision 4, ADR 0009 slice 3): the target's path prefix and
                // every name this batch picks must not shift under a concurrent folder rename/move.
                // Codex review round 1, finding 2: the destination is read and its path derived here,
                // inside the lock, so a rename that commits while this job waits for the lock is never
                // missed.
                Directory::withTreeLock(function () use ($pivotTable, &$reservedNames, &$done, $total) {
                    $targetDirectory = Directory::findOrFail($this->targetId);
                    $targetDirPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $targetDirectory->generatePath());

                    Asset::whereIn('id', $this->assetIds)
                        ->with(['directories:id,parent_id'])
                        ->chunk(self::ASSET_CHUNK_SIZE, function ($assets) use ($targetDirPath, $pivotTable, &$reservedNames, &$done, $total) {
                            $updates = [];

                            foreach ($assets as $asset) {
                                if (! $asset->directories->first()) {
                                    continue;
                                }

                                // The own-items rule again, on the row as stored now: a role limited to its own items
                                // moves only what it created, whatever the request carried.
                                if (! app(RoleBuilder::class)->canUserDelete(model: $asset, admin: $this->user)) {
                                    continue;
                                }

                                $newName = $this->resolveUniqueName($targetDirPath, $asset->file_name, $reservedNames, $asset->id);
                                $newPath = $targetDirPath.'/'.$newName;

                                $updates[] = ['id' => $asset->id, 'path' => $newPath, 'file_name' => $newName];
                            }

                            if (empty($updates)) {
                                return;
                            }

                            $successIds = array_column($updates, 'id');

                            DB::transaction(function () use ($pivotTable, $successIds, $updates) {
                                DB::table($pivotTable)->whereIn('asset_id', $successIds)->delete();
                                DB::table($pivotTable)->insert(
                                    array_map(fn ($id) => ['asset_id' => $id, 'directory_id' => $this->targetId], $successIds)
                                );
                                // The moved files take the destination's access list and keep their owners
                                // (ADR 0010, spec "Move"), one call per chunk, in the chunk's own
                                // transaction. The tree lock above already holds the fence's row.
                                app(AccessListWriter::class)->adoptForAssets(assetIds: $successIds, destinationId: $this->targetId, actorId: $this->userId);

                                $assetTable = DB::getTablePrefix().(new Asset)->getTable();

                                $pathCase = 'CASE id';
                                $nameCase = 'CASE id';
                                $pathBindings = [];
                                $nameBindings = [];

                                foreach ($updates as $update) {
                                    $pathCase .= ' WHEN ? THEN ?';
                                    $nameCase .= ' WHEN ? THEN ?';
                                    $pathBindings[] = $update['id'];
                                    $pathBindings[] = $update['path'];
                                    $nameBindings[] = $update['id'];
                                    $nameBindings[] = $update['file_name'];
                                }

                                $pathCase .= ' END';
                                $nameCase .= ' END';

                                DB::update(
                                    sprintf(
                                        'update %s set path = %s, file_name = %s where id in (%s)',
                                        $assetTable,
                                        $pathCase,
                                        $nameCase,
                                        implode(',', array_fill(0, count($successIds), '?'))
                                    ),
                                    array_merge($pathBindings, $nameBindings, $successIds)
                                );
                            });

                            $done += count($updates);
                            $this->updateProgress(
                                EventType::MASS_MOVE->value,
                                $this->userId,
                                (int) min(99, round($done / $total * 100))
                            );
                        });
                });
            }

            $blockedDirIds = [];
            $foreignInside = 0;

            if (! empty($this->dirIds)) {
                // Directory moves rewrite the nested set: read the target and sources and move them
                // under the tree lock so no other writer's shift interleaves (ADR 0004).
                Directory::withTreeLock(function () use ($targetDirectory, $total, &$done, &$blockedDirIds, &$foreignInside) {
                    $targetDirectory->refresh();

                    $directories = Directory::whereIn('id', $this->dirIds)->get()->keyBy('id');

                    foreach ($this->dirIds as $id) {
                        $directory = $directories->get($id);

                        if (! $directory || ! $directory->isDeletable()) {
                            continue;
                        }

                        if ($id === $this->targetId || $directory->isAncestorOf($targetDirectory)) {
                            continue;
                        }

                        // Re-checked here, inside the tree lock, so the subtree cannot change between the
                        // controller's check and this one (ADR 0010, spec "Move"). Skipped per item, like
                        // every other check in this loop, rather than failing the whole job. checkLocked()
                        // locks the subtree's folder and file rows first (Codex review of PR #75, findings
                        // 1 and 2), so the answer is authoritative and those rows stay locked through the
                        // move below.
                        $locked = app(AccessMoveGuard::class)->checkLocked(admin: $this->user, directoryId: $id);

                        if ($locked['blocked']) {
                            $blockedDirIds[] = $id;

                            continue;
                        }

                        // The own-items rule on the locked subtree (a descendant added since the request stays put).
                        if ($locked['foreign'] > 0) {
                            $blockedDirIds[] = $id;
                            $foreignInside += $locked['foreign'];

                            continue;
                        }

                        $oldRelativePath = $directory->generatePath();
                        $oldStoragePath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $oldRelativePath);

                        $directory->name = $this->setDirectoryNameForCopy($directory->name, $this->targetId);

                        $directory->appendToNode($targetDirectory)->save();
                        $directory->refresh();

                        $newRelativePath = $directory->generatePath();
                        $newStoragePath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $newRelativePath);

                        $this->batchReplaceAssetPaths($directory, $oldStoragePath, $newStoragePath);

                        // The moved folder and everything beneath it take the destination's access list,
                        // through the same cascade the Share dialog runs (ADR 0010, spec "Move").
                        app(AccessListWriter::class)->adoptForSubtree(movedFolderId: $directory->id, destinationId: $this->targetId, actorId: $this->userId);

                        $done++;
                        $this->updateProgress(
                            EventType::MASS_MOVE->value,
                            $this->userId,
                            (int) min(99, round($done / $total * 100))
                        );
                    }
                });
            }

            // A folder blocked by the access guard is skipped like any other per-item check above, so a
            // batch that moved something else still reports success. But when every requested folder was
            // blocked and nothing else was moved either, reporting "completed" would be a false success:
            // the tree UI's checkActionStatus() only reverts an optimistic move on "failed".
            if ($done === 0 && $blockedDirIds !== []) {
                $this->markFailed(EventType::MASS_MOVE->value, $this->userId, $foreignInside > 0
                    ? trans('dam::app.admin.permissions.own-only-subtree-mass', ['count' => $foreignInside])
                    : trans('dam::app.admin.dam.access.move-blocked'));
            } else {
                $this->completed(EventType::MASS_MOVE->value, $this->userId);
            }

            // Folder moves rewrite paths in raw SQL; only the asset ids this job holds are refreshed here and
            // the nightly dam:ai-index:reconcile covers everything under a moved folder.
            AiIndexDispatcher::lexical(array_map('intval', $this->assetIds));

            $this->clearProgress(EventType::MASS_MOVE->value, $this->userId);
        } catch (\Throwable $e) {
            $this->markFailed(EventType::MASS_MOVE->value, $this->userId, $e->getMessage());
            $this->clearProgress(EventType::MASS_MOVE->value, $this->userId);
        }
    }

    private function batchReplaceAssetPaths(Directory $directory, string $oldPrefix, string $newPrefix): void
    {
        if ($oldPrefix === $newPrefix) {
            return;
        }

        $subtreeDirIds = $directory->descendants()->toBase()->pluck('id')->prepend($directory->id);

        $pivotTable = (new Asset)->directories()->getTable();
        $assetIds = DB::table($pivotTable)
            ->whereIn('directory_id', $subtreeDirIds)
            ->distinct()
            ->pluck('asset_id');

        if ($assetIds->isEmpty()) {
            return;
        }

        [$oldPrefix, $newPrefix, $likePattern] = AssetPathPrefixGuard::boundedPrefixes($oldPrefix, $newPrefix);

        DB::table((new Asset)->getTable())
            ->whereIn('id', $assetIds)
            ->whereRaw('path LIKE ? ESCAPE ?', [$likePattern, '\\'])
            ->update([
                'path' => DB::raw(
                    'REPLACE(path, '.DB::getPdo()->quote($oldPrefix).', '.DB::getPdo()->quote($newPrefix).')'
                ),
            ]);
    }

    /**
     * A name free in `$directoryPath`, checked against `dam_assets.path` (LibraryPaths, shared with C6)
     * and this run's own `$reservedNames` — the whole batch is picked before any row is written, so two
     * assets in the same move never collide with each other, not just with what was already on disk.
     */
    private function resolveUniqueName(string $directoryPath, string $fileName, array &$reservedNames, int $ignoreAssetId): string
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $candidate = $fileName;

        for (
            $counter = 1;
            isset($reservedNames[$candidate]) || LibraryPaths::taken($directoryPath.'/'.$candidate, $ignoreAssetId);
            $counter++
        ) {
            $candidate = $extension === '' ? "{$stem}({$counter})" : "{$stem}({$counter}).{$extension}";
        }

        $reservedNames[$candidate] = true;

        return $candidate;
    }

    public function failed(\Throwable $e): void
    {
        $this->markFailed(EventType::MASS_MOVE->value, $this->userId, $e->getMessage());
        $this->clearProgress(EventType::MASS_MOVE->value, $this->userId);
    }
}
