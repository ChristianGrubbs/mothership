<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Services\AssetAvailability;
use Mothership\DAM\Traits\AssetAccessControl;

/** The Properties tab's Availability panel (P2 S3): state and window, for editors. */
class AssetAvailabilityController
{
    use AssetAccessControl;

    public function update(Request $request, int $asset, AssetAvailability $availability): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.update'), 403, trans('dam::app.admin.permissions.unauthorized'));
        // A hidden asset answers exactly like a missing one; edit rights are checked only on a visible asset.
        $model = $this->damVisibleAssetOrFail($asset);
        abort_unless($this->damCanEditAsset($model->id), 403, trans('dam::app.admin.permissions.unauthorized'));

        return response()->json([
            'message'      => trans('dam::app.admin.dam.asset.availability.saved'),
            'availability' => $availability->set(asset: $model, data: AssetAvailability::validate($request, $model)),
        ]);
    }
}
