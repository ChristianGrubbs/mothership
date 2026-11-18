<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Repositories\AssetResourceMappingRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

class LinkedResourcesController extends Controller
{
    public function __construct(
        protected AssetResourceMappingRepository $assetResourceMappingRepository,
    ) {}

    public function getLinkedResource(int $id)
    {
        $resource = $this->assetResourceMappingRepository->find($id);

        // A mapping on an asset the caller cannot open (hidden, a draft, outside its window) answers exactly like a
        // missing mapping, so a mapping id never reveals that asset (P2 S3 review).
        if (! $resource || ! app(AssetAccessDecision::class)->visible(AccessContext::api(), (int) $resource->dam_asset_id)) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.linked-resources.not-found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.linked-resources.found-success'),
            'data'    => $resource,
        ], 200);
    }
}
