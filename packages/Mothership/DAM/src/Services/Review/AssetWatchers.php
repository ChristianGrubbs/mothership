<?php

namespace Mothership\DAM\Services\Review;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;

/**
 * Who watches an asset (P7): its creator and everyone who has commented on it, plus anyone who chose Watch, minus
 * anyone who chose Unwatch. Only the explicit choices are stored (dam_asset_watchers); the rest is derived, so a
 * commenter starts watching without a write and an Unwatch outlasts later comments.
 */
class AssetWatchers
{
    /** @return list<int> */
    public function ids(Asset $asset): array
    {
        $choices = $this->choices(assetId: (int) $asset->id);

        $implied = AssetComments::query()
            ->where('dam_asset_id', $asset->id)
            ->whereNotNull('admin_id')
            ->distinct()
            ->pluck('admin_id')
            ->map(fn ($id) => (int) $id)
            ->push((int) $asset->created_by)
            ->filter(fn (int $id) => $id > 0);

        return $implied
            ->merge(array_keys(array_filter($choices)))
            ->unique()
            ->reject(fn (int $id) => ($choices[$id] ?? true) === false)
            ->values()
            ->all();
    }

    public function isWatching(Asset $asset, int $adminId): bool
    {
        return in_array($adminId, $this->ids($asset), true);
    }

    public function set(Asset $asset, int $adminId, bool $watching): void
    {
        $now = now();

        DB::table('dam_asset_watchers')->upsert(
            [['dam_asset_id' => $asset->id, 'admin_id' => $adminId, 'watching' => $watching, 'created_at' => $now, 'updated_at' => $now]],
            ['dam_asset_id', 'admin_id'],
            ['watching', 'updated_at'],
        );
    }

    /** @return array<int, bool> admin id => the explicit choice */
    protected function choices(int $assetId): array
    {
        return DB::table('dam_asset_watchers')
            ->where('dam_asset_id', $assetId)
            ->get(['admin_id', 'watching'])
            ->mapWithKeys(fn ($row) => [(int) $row->admin_id => (bool) $row->watching])
            ->all();
    }
}
