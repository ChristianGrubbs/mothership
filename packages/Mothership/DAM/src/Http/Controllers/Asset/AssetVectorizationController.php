<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\HomeWidgetRegistry;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\DAM\Vectorization\Allowance;
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

    public function store(Request $request, int $id, Requests $requests, Vectorizer $provider)
    {
        $asset = $this->authorizeSource($id);
        abort_unless($provider->ready(), 503, 'Image conversion is currently unavailable.');
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'format' => ['required', 'in:svg,pdf']]);
        abort_unless(in_array(strtolower($asset->extension ?? ''), ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'gif', 'tif', 'tiff'], true), 422, 'Choose a supported raster image.');

        $contentStore = app(ContentStore::class);

        try {
            $object = $contentStore->current($asset);
        } catch (ContentUnavailable) {
            abort(404, 'The original image is unavailable.');
        }

        abort_if($object->size > 20 * 1024 * 1024, 422, 'Choose an image up to 20 MiB.');

        try {
            $stream = $contentStore->open($object);
        } catch (ContentUnavailable) {
            abort(404, 'The original image is unavailable.');
        }

        try {
            $bytes = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        $dimensions = @getimagesizefromstring($bytes);
        abort_unless($dimensions && in_array($dimensions['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp', 'image/bmp', 'image/x-ms-bmp', 'image/gif', 'image/tiff'], true)
            && $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] * $dimensions[1] <= 33554432, 422, 'Choose a supported image up to 33,554,432 pixels.');
        $path = 'vectorizations/input/'.Str::uuid();
        $disk = Storage::disk('private');
        abort_unless($disk->put($path, $bytes), 503, 'Image could not be stored.');
        $keep = false;
        try {
            $row = $requests->submit(adminId: (int) auth('admin')->id(), requestId: $data['request_id'],
                inputPath: $path, sha: hash('sha256', $bytes), assetId: $id, format: $data['format']);
            $keep = $row->input_path === $path;

            return response()->json(['result' => $requests->publicResult(row: $row)], 202)->header('Cache-Control', 'private, no-store');
        } finally {
            if (! $keep) {
                $disk->delete($path);
            }
        }
    }
}
