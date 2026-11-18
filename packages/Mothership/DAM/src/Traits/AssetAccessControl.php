<?php

namespace Mothership\DAM\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;

trait AssetAccessControl
{
    protected function damPermissionService(): DirectoryPermissionService
    {
        return app(DirectoryPermissionService::class);
    }

    protected function damAssetDirectoryId(?Asset $asset): ?int
    {
        if (! $asset) {
            return null;
        }

        $dirId = DB::table('dam_asset_directory')
            ->where('asset_id', $asset->id)
            ->orderBy('id')
            ->value('directory_id');

        return $dirId ? (int) $dirId : null;
    }

    /** The seam's visibility rule (P2 S2): any folder under `grants`, the file's own list under `lists` (audit A1). */
    protected function damCanAccessAsset(int $assetId): bool
    {
        try {
            return app(AssetAccessDecision::class)->visible(AccessContext::library(), $assetId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The asset when it exists and the viewer may open it, else the same 404 a missing id gets, so an
     * id probe cannot tell a hidden asset from one that does not exist. Checks that refuse a visible
     * asset (edit permission, file type) run after this and may keep their own status.
     */
    protected function damVisibleAssetOrFail(mixed $id): Asset
    {
        $asset = is_numeric($id) ? Asset::find((int) $id) : null;

        abort_unless($asset && $this->damCanAccessAsset($asset->id), 404);

        return $asset;
    }

    /** As damVisibleAssetOrFail() for a route-bound asset: a hidden one answers like a missing binding. */
    protected function damAbortUnlessVisible(Asset $asset): void
    {
        abort_unless($this->damCanAccessAsset($asset->id), 404);
    }

    /**
     * Refuses an asset the viewer cannot open with the same 404 a missing one gets (P2 S1: the 403 it used to
     * answer told a probe the id exists). A visible asset the viewer may not change is refused by the caller's
     * own permission check, which keeps its 403.
     */
    protected function damAuthorizeAsset(int $assetId): void
    {
        abort_unless($this->damCanAccessAsset($assetId), 404);
    }

    /**
     * A row that belongs to an asset (a comment, a property) when it exists and its asset is one the viewer may
     * open, else the same 404 either way, so a child id cannot reveal what a hidden asset holds.
     *
     * @template T of Model
     *
     * @param  T|null  $row
     * @return T
     */
    protected function damVisibleChildOrFail(?Model $row, string $assetColumn = 'dam_asset_id'): Model
    {
        abort_unless($row !== null && $this->damCanAccessAsset((int) $row->getAttribute($assetColumn)), 404);

        return $row;
    }

    /**
     * The single definition of "may this user mutate this asset": directory
     * access plus the edit permission. Both the aborting gate every mutation
     * route runs and the can_edit flag the preview UI uses to decide whether
     * to render write controls read it, so a UI that offers a button and a
     * request that accepts it can never disagree.
     */
    protected function damCanEditAsset(int $assetId): bool
    {
        if (! $this->damCanAccessAsset($assetId)) {
            return false;
        }

        try {
            return (bool) bouncer()->hasPermission('dam.asset.edit');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
