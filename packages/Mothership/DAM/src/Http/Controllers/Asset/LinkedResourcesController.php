<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\DataGrids\Asset\LinkedResourcesDataGrid;
use Mothership\DAM\Traits\AssetAccessControl;

class LinkedResourcesController extends Controller
{
    use AssetAccessControl;

    public function index()
    {
        // The grid lists one asset's links, so it needs an asset the viewer may open. Without one it listed the
        // links of every asset; a missing or hidden id answers 404 either way.
        $this->damVisibleAssetOrFail(request()->get('dam_asset_id'));

        if (request()->ajax()) {
            return app(LinkedResourcesDataGrid::class)->toJson();
        }
    }
}
