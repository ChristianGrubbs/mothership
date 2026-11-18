<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\VideoProxyStatus;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * The preview proxy's status and Retry on the asset page (P6 D26). As PanoramaPreviewController: the asset is asked
 * through the access seam first (404 as if missing), the status is view-gated, Retry is edit-gated (403) and
 * throttled per person and asset by its route.
 */
class VideoProxyController extends Controller
{
    use AssetAccessControl;

    public function __construct(protected VideoProxyStatus $proxyStatus) {}

    public function state(int $asset): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);

        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));

        return response()->json(['data' => $this->present($model)]);
    }

    public function retry(int $asset): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);

        abort_unless($this->damCanEditAsset($model->id), 403, trans('dam::app.admin.permissions.unauthorized'));

        $adminId = auth()->guard('admin')->id();
        $result = $this->proxyStatus->retry(asset: $model, requestedBy: $adminId === null ? null : (int) $adminId);

        return self::retryResponse(result: $result, canRetry: true);
    }

    /** The status with whether this viewer may press Retry. */
    protected function present(Asset $asset): array
    {
        $status = $this->proxyStatus->for($asset);

        return $status + ['can_retry' => $status['retryable'] && $this->damCanEditAsset($asset->id)];
    }

    /** 202 when a job was queued or one already is (idempotent); 409 when there is nothing to retry. */
    public static function retryResponse(array $result, bool $canRetry): JsonResponse
    {
        $accepted = in_array($result['outcome'], ['queued', 'already_queued'], true);

        return response()->json([
            'success' => $accepted,
            'outcome' => $result['outcome'],
            'message' => trans('dam::app.admin.dam.asset.video-proxy.outcomes.'.$result['outcome']),
            'data'    => $result['status'] + ['can_retry' => $canRetry && $result['status']['retryable']],
        ], $accepted ? 202 : 409);
    }
}
