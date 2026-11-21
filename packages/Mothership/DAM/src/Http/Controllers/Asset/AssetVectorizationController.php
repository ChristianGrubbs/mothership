<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\DAM\Vectorization\Allowance;
use Mothership\DAM\Vectorization\Intake;
use Mothership\DAM\Vectorization\Requests;
use Mothership\DAM\Vectorization\Vectorizer;

class AssetVectorizationController extends Controller
{
    use AssetAccessControl;

    private function authorizeSource(int $id): Asset
    {
        app(HomeWidgetRegistry::class)->allowed('vectorization');
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless($this->damCanEditAsset($id) && bouncer()->hasPermission('dam.asset.files'), 403);

        return $asset;
    }

    public function state(Request $request, int $id, Requests $requests, Allowance $allowance, Vectorizer $provider)
    {
        $this->authorizeSource($id);
        $adminId = (int) auth('admin')->id();
        $data = $request->validate(['result_id' => ['nullable', 'uuid']]);
        $row = DB::table('dam_vectorizations')->where('source_asset_id', $id)->where('admin_id', $adminId)
            ->when($data['result_id'] ?? null, fn ($query, $resultId) => $query->where('id', $resultId))
            ->orderByRaw("CASE WHEN status IN ('queued', 'processing') THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')->orderByDesc('created_at')->orderByDesc('id')->first();

        return response()->json([
            'ready'  => $provider->ready(), 'allowance' => $allowance->read(adminId: $adminId),
            'result' => $row ? $requests->publicResult(row: $row) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $id, Requests $requests, Intake $intake, Vectorizer $provider)
    {
        $asset = $this->authorizeSource($id);
        abort_unless($provider->ready(), 503, 'Image conversion is currently unavailable.');
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'format' => ['required', 'in:svg,pdf']]);
        $row = $intake->fromAsset(adminId: (int) auth('admin')->id(), asset: $asset, format: $data['format'], requestId: $data['request_id']);

        return response()->json(['result' => $requests->publicResult(row: $row)], 202)->header('Cache-Control', 'private, no-store');
    }
}
