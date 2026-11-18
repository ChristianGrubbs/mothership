<?php

namespace Mothership\DAM\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Database\Backfill\AttachmentSlotBackfill;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;

/**
 * CopyDirectory and MassCopy insert copied assets with raw SQL for speed and create no
 * FileObject/AssetFile rows of their own. Content is content-addressed and immutable (ADR 0009),
 * so a copy can point straight at its source's current original FileObject instead of rehashing
 * or rewriting a blob: no new FileObject, just another AssetFile row naming the same one.
 */
trait ClonesOriginalFileObjects
{
    /**
     * Lock every source asset row a copy will read, before it inserts a single copied row or attachment, and
     * return the ids still present (as a set). A source deleted before its lock is granted simply drops out.
     *
     * Lock order for every copy (ADR 0009): the directory tree lock (outermost, the caller's
     * Directory::withTreeLock), then the source asset rows in ascending id order, then — only implicitly,
     * through the dam_asset_files foreign key as attachOriginalFileObjects() inserts — the shared FileObjects.
     * Codex review round 4: the copy jobs used to lock one batch of sources at a time, after an earlier batch's
     * attachments already held KEY SHARE on a FileObject those sources share. AssetDeletionService::delete()
     * takes an asset row and then its FileObjects FOR UPDATE, so a delete of a later-batch source closed a
     * deadlock cycle on PostgreSQL. With the whole set held first, a delete either waits for the copy on its
     * asset row, or already holds that row and finishes before the copy has touched any FileObject.
     *
     * The rows stay locked (Asset::lockedForGraph) until the caller's transaction ends, so a later plain read
     * of a returned id — its descriptors, its current original — sees exactly the state this lock saw.
     *
     * @param  array<int, int|string>  $assetIds
     * @return array<int, true>
     */
    protected function lockSourceAssets(array $assetIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $assetIds)));
        sort($ids);

        $locked = [];

        // Ascending ids across every batch, so the whole set is taken in one order.
        foreach (array_chunk($ids, 500) as $batch) {
            foreach (Asset::query()->whereIn('id', $batch)->orderBy('id')->lockedForGraph()->pluck('id') as $id) {
                $locked[(int) $id] = true;
            }
        }

        return $locked;
    }

    /**
     * Source asset ids per directory, for every directory in `$root`'s subtree, in ascending id order. Read by
     * nested-set interval, so call it under the tree lock and before the copy creates a directory (which shifts
     * the intervals).
     *
     * @return array<int, list<int>> directory id => source asset ids
     */
    protected function sourceAssetIdsBySubtreeDirectory(Directory $root): array
    {
        $dirTable = $root->getTable();
        $pivotTable = (new Asset)->directories()->getTable();
        $idsByDirectory = [];

        DB::table($pivotTable)
            ->join($dirTable, "{$dirTable}.id", '=', "{$pivotTable}.directory_id")
            ->where("{$dirTable}._lft", '>=', $root->_lft)
            ->where("{$dirTable}._rgt", '<=', $root->_rgt)
            ->orderBy("{$pivotTable}.asset_id")
            ->get(["{$pivotTable}.directory_id", "{$pivotTable}.asset_id"])
            ->each(function ($row) use (&$idsByDirectory): void {
                $idsByDirectory[(int) $row->directory_id][(int) $row->asset_id] = (int) $row->asset_id;
            });

        return array_map(array_values(...), $idsByDirectory);
    }

    /**
     * $ids restricted to the folders the actor can open, under `lists` only (sharing slice 2b, spec
     * "Copies"): a folder the actor cannot open is skipped together with its whole subtree, path-only
     * included. Gated explicitly on the access model rather than left to canAccess()'s own per-model
     * dispatch, so `grants` never gains a filter that was not there before this slice.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    protected function accessibleDirectoryIds(array $ids, DirectoryPermissionService $permissions): array
    {
        if ($ids === [] || ! app(AccessModel::class)->lists() || $permissions->bypass()) {
            return $ids;
        }

        return array_values(array_intersect($ids, $permissions->directlyGrantedIds()));
    }

    /**
     * $rootId's subtree, pruned to the folders reachable from it without crossing a folder the actor
     * cannot open — the same path-only exclusion the copy's own recursive walk (deepCopyDirectory(),
     * deepCopyStructure()) already enforces structurally, by never recursing into a folder missing
     * from its accessible set. accessibleDirectoryIds() is a flat intersection: an individually
     * accessible folder buried under an inaccessible ancestor still passes it, so a file-candidate
     * query built straight from the subtree's ids still reached lockSourceAssets() for a file the
     * walk was never going to copy (Codex review of PR #75 round 2, finding 4). Computed once, as a
     * BFS from $rootId over parent_id, so a caller can prune file candidates to it before any row is
     * locked, rather than after.
     *
     * @param  Collection<int, object{id: int|string, parent_id: int|string|null}>  $subtreeDirectories
     * @param  array<int, true>  $accessibleIds  every subtree id the actor can open, unfiltered by ancestry
     * @return array<int, true> $rootId plus every descendant reachable without crossing a gap
     */
    protected function reachableDirectoryIds(int $rootId, $subtreeDirectories, array $accessibleIds): array
    {
        $childrenByParent = [];

        foreach ($subtreeDirectories as $directory) {
            $childrenByParent[(int) $directory->parent_id][] = (int) $directory->id;
        }

        $reachable = [$rootId => true];
        $queue = [$rootId];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach ($childrenByParent[$parentId] ?? [] as $childId) {
                if (! isset($accessibleIds[$childId]) || isset($reachable[$childId])) {
                    continue;
                }

                $reachable[$childId] = true;
                $queue[] = $childId;
            }
        }

        return $reachable;
    }

    /**
     * $ids restricted to the source files the actor can open: under `lists` a file has its own list, and
     * its folder never widens it (ADR 0010), so this is the same rule applyAssetDirectoryGrantFilter()
     * applies to a listing; under both models, only files out for the actor (P2 S3).
     *
     * $locked (Codex review of PR #75 round 2, finding 2): true for the authoritative re-check a
     * caller makes after lockSourceAssets() — a shared lock on this query and on every list subquery
     * it depends on, so the answer is a current read rather than the transaction's original snapshot.
     * The first, pre-lock filter pass stays unlocked; there is nothing to hold yet. $ids doubles as
     * the locked read's own candidate restriction (round 3, finding 2): without it, each locked list
     * subquery was uncorrelated — every asset the admin can see library-wide, not just these — so a
     * small, pruned re-check could lock or wait on unrelated rows anywhere in the library.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    protected function accessibleAssetIds(array $ids, DirectoryPermissionService $permissions, bool $locked = false): array
    {
        if ($ids === []) {
            return $ids;
        }

        $byList = app(AccessModel::class)->lists() && ! $permissions->bypass();

        // Availability (P2 S3) applies under both models: a file that is not out for the actor is never copied,
        // so a copy cannot turn someone else's draft into a file the actor owns.
        return Asset::query()
            ->whereIn('dam_assets.id', $ids)
            ->when($byList, fn ($query) => $permissions->applyAssetDirectoryGrantFilter($query, locked: $locked, candidateIds: $locked ? $ids : null))
            ->tap(fn ($query) => app(AssetAccessDecision::class)->scopeOutForUse($query, AccessContext::library(AccessContext::PURPOSE_EDIT), permissions: $permissions))
            ->when($locked && $byList, fn ($query) => $query->sharedLock())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, int>  $sourceAssetIdByNewPath  New (already-inserted) asset path => the
     *                                                      source asset's id it was copied from.
     */
    protected function attachOriginalFileObjects(array $sourceAssetIdByNewPath): void
    {
        if (empty($sourceAssetIdByNewPath)) {
            return;
        }

        $newAssetIdByPath = Asset::query()
            ->whereIn('path', array_keys($sourceAssetIdByNewPath))
            ->pluck('id', 'path');

        $sourceAssetIds = array_values(array_unique($sourceAssetIdByNewPath));

        $originalFileObjectIdBySourceAssetId = AssetFile::query()
            ->where('role', AssetFile::ROLE_ORIGINAL)
            ->whereIn('dam_asset_id', $sourceAssetIds)
            ->pluck('file_object_id', 'dam_asset_id');

        $now = now();
        $rows = [];

        foreach ($sourceAssetIdByNewPath as $newPath => $sourceAssetId) {
            $newAssetId = $newAssetIdByPath[$newPath] ?? null;
            // A source with no original (never ingested a blob) gets no row here; the storage
            // repair command backfills it later rather than this copy inventing content.
            $fileObjectId = $originalFileObjectIdBySourceAssetId[$sourceAssetId] ?? null;

            if ($newAssetId === null || $fileObjectId === null) {
                continue;
            }

            $rows[] = [
                'dam_asset_id'   => $newAssetId,
                'file_object_id' => $fileObjectId,
                'role'           => AssetFile::ROLE_ORIGINAL,
                'sort'           => 0,
                'created_at'     => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table((new AssetFile)->getTable())->insert($rows);
            // Raw rows skip AssetFile's slot hook, so the copies get their representative slot here.
            app(AttachmentSlotBackfill::class)->run(assetIds: array_column($rows, 'dam_asset_id'));
        }
    }
}
