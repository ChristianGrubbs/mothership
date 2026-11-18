<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Services\AssetStackService;
use Mothership\DAM\Traits\AssetAccessControl;

class AssetStackController
{
    use AssetAccessControl;

    public function update(Request $request, int $asset, AssetStackService $stacks): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.edit'), 403, trans('dam::app.admin.permissions.unauthorized'));
        // A hidden asset answers exactly like a missing one; edit rights are checked only on a visible asset.
        $model = $this->damVisibleAssetOrFail($asset);
        abort_unless($this->damCanEditAsset($model->id), 403, trans('dam::app.admin.permissions.unauthorized'));

        $data = AssetStackService::validate($request);

        return response()->json(['stack' => $stacks->set(asset: $model, basis: $data['basis'], key: $data['key'] ?? null)]);
    }
}
