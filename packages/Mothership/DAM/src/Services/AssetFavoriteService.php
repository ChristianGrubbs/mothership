<?php

namespace Mothership\DAM\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

class AssetFavoriteService
{
    public function __construct(private DirectoryPermissionService $permissions) {}

    public function accessibleAssets(): Builder
    {
        $query = Asset::query()->visibleCaptures();
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        return $query;
    }

    public function findAccessible(int $assetId): Asset
    {
        return $this->accessibleAssets()->findOrFail($assetId);
    }

    public function favorite(int $assetId): void
    {
        $this->findAccessible($assetId);
        $inserted = DB::table('dam_asset_favorites')->insertOrIgnore([
            'admin_id' => auth('admin')->id(), 'dam_asset_id' => $assetId, 'created_at' => now(),
        ]);
        if ($inserted) {
            HomeTelemetry::record(event: 'favorite.saved', ctx: ['admin_id' => auth('admin')->id(), 'asset_id' => $assetId]);
        }
    }

    public function isFavorite(int $assetId): bool
    {
        $this->findAccessible($assetId);

        return DB::table('dam_asset_favorites')->where('admin_id', auth('admin')->id())->where('dam_asset_id', $assetId)->exists();
    }

    public function statuses(array $assetIds): array
    {
        $accessible = $this->accessibleAssets()->whereKey($assetIds)->pluck('dam_assets.id')->map(fn ($id) => (int) $id);
        $favoriteIds = DB::table('dam_asset_favorites')->where('admin_id', auth('admin')->id())
            ->whereIn('dam_asset_id', $accessible)->pluck('dam_asset_id')->map(fn ($id) => (int) $id)->all();
        $favorites = [];
        foreach ($accessible as $assetId) {
            $favorites[(string) $assetId] = in_array($assetId, $favoriteIds, true);
        }

        return $favorites;
    }

    public function unfavorite(int $assetId): void
    {
        $this->findAccessible($assetId);
        $deleted = DB::table('dam_asset_favorites')->where('admin_id', auth('admin')->id())->where('dam_asset_id', $assetId)->delete();
        if ($deleted) {
            HomeTelemetry::record(event: 'favorite.removed', ctx: ['admin_id' => auth('admin')->id(), 'asset_id' => $assetId]);
        }
    }

    public function list(int $limit = 24)
    {
        return $this->accessibleAssets()->withDisplayName()
            ->join('dam_asset_favorites', 'dam_asset_favorites.dam_asset_id', '=', 'dam_assets.id')
            ->where('dam_asset_favorites.admin_id', auth('admin')->id())
            ->orderByDesc('dam_asset_favorites.created_at')->orderByDesc('dam_assets.id')
            ->limit(max(1, min(24, $limit)))->get();
    }
}
