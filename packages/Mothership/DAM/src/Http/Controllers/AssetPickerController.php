<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Contracts\Asset;
use Mothership\DAM\DataGrids\Asset\PickerDataGrid;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

class AssetPickerController extends Controller
{
    public function __construct(protected AssetRepository $assetRepository) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(PickerDataGrid::class)->toJson();
        }
    }

    public function fetchAssets(): JsonResponse
    {
        $assetIds = request()->get('assetIds') ?? '';

        if (empty($assetIds)) {
            return response()->json([]);
        }

        if (is_string($assetIds)) {
            $assetIds = str_contains($assetIds, ',') ? explode(',', $assetIds) : [$assetIds];
        }

        $query = $this->assetRepository->withDisplayName()->whereIn('id', $assetIds);

        // An id the viewer cannot open behaves like a missing one.
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        $assets = $query->get();

        $response = [];

        foreach ($assets as $asset) {
            $response[] = $this->formatAsset($asset);
        }

        return response()->json($response);
    }

    protected function formatAsset(Asset $asset): array
    {
        $assetId = $asset->id;

        $filePath = $asset->path;

        return [
            'id'                => $assetId,
            'url'               => route('admin.dam.file.thumbnail', ['path' => urlencode($filePath)]),
            'value'             => $assetId,
            'file_name'         => AssetHelper::getDisplayFileName($asset->file_name),
            'display_name'      => $asset->display_name,
            'file_type'         => $asset->file_type,
        ];
    }
}
