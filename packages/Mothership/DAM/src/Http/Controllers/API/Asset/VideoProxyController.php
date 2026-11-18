<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\Asset\VideoProxyController as LibraryVideoProxyController;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\VideoProxyStatus;

/**
 * REST Retry for a video's preview proxy (P6 D26). The status itself is the `video_proxy` field of the asset read.
 * Existence and visibility through the seam (404 as if missing), then the edit rule for this asset (403); the route's
 * api.dam.assets.video-proxy.retry scope and throttle sit in front.
 */
class VideoProxyController extends Controller
{
    public function retry(int $asset, VideoProxyStatus $proxyStatus): JsonResponse
    {
        $model = app(AssetAccessDecision::class)
            ->for(AccessContext::api(AccessContext::PURPOSE_EDIT), $asset)
            ->allowedOrFail();

        $id = auth()->guard('api')->id();
        $result = $proxyStatus->retry(asset: $model, requestedBy: $id === null ? null : (int) $id);

        return LibraryVideoProxyController::retryResponse(result: $result, canRetry: true);
    }
}
