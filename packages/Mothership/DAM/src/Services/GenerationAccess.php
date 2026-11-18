<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Traits\AssetAccessControl;

class GenerationAccess
{
    use AssetAccessControl;

    public function find(int $assetId): GenerationAsset
    {
        $query = GenerationAsset::where('asset_id', $assetId);
        $visible = $this->damCanAccessAsset(assetId: $assetId);
        if (! ($visible && bouncer()->hasPermission('dam.asset.files.index'))) {
            // Imported source metadata never grants access; the application job does.
            $query->whereIn('generation_id', DB::table('dam_home_generations')->selectRaw("CONCAT('mothership-', id)")->where('admin_id', auth('admin')->id()));
            // A hidden asset answers like a missing one; only a visible asset's refusal may say 403.
            abort_unless((clone $query)->exists(), $visible ? 403 : 404);
        }

        return $query->orderByDesc('updated_at')->orderByDesc('id')->firstOrFail();
    }
}
