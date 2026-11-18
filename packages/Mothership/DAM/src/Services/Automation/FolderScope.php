<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;

/**
 * A folder and everything under it (nested-set bounds), for a mapping's scope, a rule's folder condition and a
 * run over a folder. A folder that no longer exists is an empty subtree, so a stale scope matches nothing.
 */
final class FolderScope
{
    /** @var array<int, list<int>> */
    private array $subtrees = [];

    /** @return list<int> the folder's id and every descendant's */
    public function subtree(int $directoryId): array
    {
        if (isset($this->subtrees[$directoryId])) {
            return $this->subtrees[$directoryId];
        }

        $folder = Directory::query()->where('id', $directoryId)->first(['id', '_lft', '_rgt']);

        return $this->subtrees[$directoryId] = $folder === null ? [] : Directory::query()
            ->where('_lft', '>=', $folder->_lft)->where('_rgt', '<=', $folder->_rgt)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Every asset filed anywhere in the subtree, in id order (trashed and merged assets are left out).
     *
     * @return list<int>
     */
    public function assetIds(int $directoryId): array
    {
        $folders = $this->subtree($directoryId);

        if ($folders === []) {
            return [];
        }

        return DB::table('dam_asset_directory')->join('dam_assets', 'dam_assets.id', '=', 'dam_asset_directory.asset_id')
            ->whereIn('dam_asset_directory.directory_id', $folders)
            ->whereNull('dam_assets.deleted_at')->whereNull('dam_assets.merged_into_id')
            ->distinct()->orderBy('dam_assets.id')->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Each asset's folders.
     *
     * @param  list<int>  $assetIds
     * @return array<int, list<int>>
     */
    public function foldersOf(array $assetIds): array
    {
        $folders = array_fill_keys($assetIds, []);

        foreach (array_chunk($assetIds, 500) as $chunk) {
            foreach (DB::table('dam_asset_directory')->whereIn('asset_id', $chunk)->get(['asset_id', 'directory_id']) as $row) {
                $folders[(int) $row->asset_id][] = (int) $row->directory_id;
            }
        }

        return $folders;
    }

    /** @param  list<int>  $assetFolders */
    public function within(array $assetFolders, int $directoryId): bool
    {
        return array_intersect($assetFolders, $this->subtree($directoryId)) !== [];
    }
}
