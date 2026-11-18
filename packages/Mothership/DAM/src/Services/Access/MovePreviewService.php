<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The move-preview endpoint's counts (sharing slice 2b, `GET dam/access/move-preview`): how many folders
 * and files would end up with a different access list if the given ids were moved to $targetId right now.
 *
 * A move replaces an item's own list with the destination's, root and subtree alike
 * (AccessListWriter::adoptForAssets()/adoptForSubtree(), AccessListService::rewriteSubtree()), so "would
 * change" means the item's current set of user and group principals is not exactly the target folder's
 * set — same size, and every current principal already in the target's set.
 *
 * Read-only and authorization-free, like AccessListReader: AccessController has already checked that the
 * actor may open every id and the target before asking this, and AccessMoveGuard separately answers
 * whether the move itself would be refused.
 */
class MovePreviewService
{
    /**
     * @param  list<int>  $assetIds
     * @return array{folders: int, files: int}
     */
    public function countChangedAssets(array $assetIds, int $targetId): array
    {
        if ($assetIds === []) {
            return ['folders' => 0, 'files' => 0];
        }

        [$targetUserIds, $targetGroupIds] = $this->targetPrincipals($targetId);

        // A trashed file (P2 S4) keeps its own list through a move, so it is never one that changes.
        $changed = DB::table('dam_assets')
            ->whereIn('dam_assets.id', $assetIds)
            ->whereNull('dam_assets.deleted_at')
            ->where(fn ($query) => $this->whereDiffersFromTarget(
                query: $query,
                column: 'dam_assets.id',
                item: 'asset',
                targetUserIds: $targetUserIds,
                targetGroupIds: $targetGroupIds,
            ))
            ->count();

        return ['folders' => 0, 'files' => $changed];
    }

    /**
     * Codex review of PR #75, finding 7: counted once over the union of the selected subtrees'
     * nested-set ranges, in one pair of statements, rather than once per selected id summed together —
     * summing double-counts a folder or file reachable from two overlapping selections (an ancestor and
     * its own descendant both selected, or one file placed in two folders the selection reaches), since
     * both `dam_directories.id` and `dam_assets.id` are each one physical row regardless of how many of
     * the selected ranges it falls under.
     *
     * @param  list<int>  $directoryIds
     * @return array{folders: int, files: int}
     */
    public function countChangedSubtrees(array $directoryIds, int $targetId): array
    {
        if ($directoryIds === []) {
            return ['folders' => 0, 'files' => 0];
        }

        $ranges = DB::table('dam_directories')->whereIn('id', $directoryIds)->get(['_lft', '_rgt']);

        if ($ranges->isEmpty()) {
            return ['folders' => 0, 'files' => 0];
        }

        [$targetUserIds, $targetGroupIds] = $this->targetPrincipals($targetId);

        $folders = DB::table('dam_directories')
            ->where(fn ($query) => $this->whereInAnyRange(query: $query, column: '_lft', ranges: $ranges))
            ->where(fn ($query) => $this->whereDiffersFromTarget(
                query: $query,
                column: 'dam_directories.id',
                item: 'directory',
                targetUserIds: $targetUserIds,
                targetGroupIds: $targetGroupIds,
            ))
            ->count();

        $files = DB::table('dam_assets')
            ->whereNull('dam_assets.deleted_at')
            ->whereIn('dam_assets.id', DB::table('dam_asset_directory')
                ->join('dam_directories as folder', 'folder.id', '=', 'dam_asset_directory.directory_id')
                ->where(fn ($query) => $this->whereInAnyRange(query: $query, column: 'folder._lft', ranges: $ranges))
                ->select('dam_asset_directory.asset_id'))
            ->where(fn ($query) => $this->whereDiffersFromTarget(
                query: $query,
                column: 'dam_assets.id',
                item: 'asset',
                targetUserIds: $targetUserIds,
                targetGroupIds: $targetGroupIds,
            ))
            ->count();

        return ['folders' => $folders, 'files' => $files];
    }

    /**
     * $column falls inside at least one of $ranges' `[_lft, _rgt]` intervals — the union of every
     * selected subtree, so an item reachable through two overlapping selections is one OR branch, not
     * two summed queries.
     *
     * @param  Collection<int, object{_lft: int, _rgt: int}>  $ranges
     */
    protected function whereInAnyRange($query, string $column, $ranges): void
    {
        $query->where(function ($outer) use ($column, $ranges) {
            foreach ($ranges as $range) {
                $outer->orWhereBetween($column, [(int) $range->_lft, (int) $range->_rgt]);
            }
        });
    }

    /**
     * @return array{0: list<int>, 1: list<int>}
     */
    protected function targetPrincipals(int $targetId): array
    {
        return [
            DB::table('dam_directory_user_access')->where('directory_id', $targetId)->pluck('admin_id')->map(fn ($id) => (int) $id)->all(),
            DB::table('dam_directory_group_access')->where('directory_id', $targetId)->pluck('group_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /**
     * $column's own principal set is not exactly {$targetUserIds} and {$targetGroupIds}. Built entirely
     * through the query builder — no raw identifiers — because CI's MySQL leg runs with a `wk_` prefix.
     *
     * Equality (negated below) is a same-size, no-extra pair per principal kind: a subset of a set the
     * same size as the target equals the target, given the tables' composite primary keys forbid
     * duplicate rows.
     *
     * @param  list<int>  $targetUserIds
     * @param  list<int>  $targetGroupIds
     */
    protected function whereDiffersFromTarget($query, string $column, string $item, array $targetUserIds, array $targetGroupIds): void
    {
        $query->whereNot(fn ($equal) => $equal
            ->where(fn ($sub) => $sub->from("dam_{$item}_user_access")->whereColumn("dam_{$item}_user_access.{$item}_id", $column)->selectRaw('count(*)'), '=', count($targetUserIds))
            ->where(fn ($sub) => $sub->from("dam_{$item}_group_access")->whereColumn("dam_{$item}_group_access.{$item}_id", $column)->selectRaw('count(*)'), '=', count($targetGroupIds))
            ->whereNotExists(fn ($sub) => $sub->from("dam_{$item}_user_access")
                ->whereColumn("dam_{$item}_user_access.{$item}_id", $column)
                ->when($targetUserIds !== [], fn ($withTarget) => $withTarget->whereNotIn('admin_id', $targetUserIds)))
            ->whereNotExists(fn ($sub) => $sub->from("dam_{$item}_group_access")
                ->whereColumn("dam_{$item}_group_access.{$item}_id", $column)
                ->when($targetGroupIds !== [], fn ($withTarget) => $withTarget->whereNotIn('group_id', $targetGroupIds))));
    }
}
