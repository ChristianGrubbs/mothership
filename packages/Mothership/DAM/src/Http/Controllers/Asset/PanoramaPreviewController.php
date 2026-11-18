<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\PanoramaPreviewService;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Idempotent preview API orchestration (Task 3): inspect state, change mode,
 * create/rebuild/retry one conversion attempt, and request cancellation.
 * Directory access is checked first, then the view/edit permission split —
 * state is view-gated, every mutation is edit-gated.
 */
class PanoramaPreviewController extends Controller
{
    use AssetAccessControl;

    public function __construct(protected PanoramaPreviewService $panoramaPreviewService) {}

    public function state(Asset $asset): JsonResponse
    {
        $this->authorizeView($asset);

        return response()->json($this->panoramaPreviewService->state($asset));
    }

    public function build(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeEdit($asset);

        return $this->renditionResponse(
            $this->panoramaPreviewService->request($asset, browserRejected: $this->browserRejected($request))
        );
    }

    public function rebuild(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeEdit($asset);

        return $this->renditionResponse(
            $this->panoramaPreviewService->request($asset, true, browserRejected: $this->browserRejected($request))
        );
    }

    public function retry(Asset $asset): JsonResponse
    {
        $this->authorizeEdit($asset);

        return $this->renditionResponse($this->panoramaPreviewService->retry($asset));
    }

    public function cancel(Asset $asset): JsonResponse
    {
        $this->authorizeEdit($asset);

        $rendition = $this->panoramaPreviewService->cancel($asset);

        return response()->json([
            'rendition' => $rendition ? $this->renditionPayload($rendition) : null,
        ]);
    }

    public function mode(Request $request, Asset $asset): JsonResponse
    {
        $this->authorizeEdit($asset);

        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(Asset::PANORAMA_MODES)],
        ]);

        $asset = $this->panoramaPreviewService->setMode($asset, $data['mode']);

        return response()->json([
            'asset' => [
                'id'             => $asset->id,
                'panorama_mode'  => $asset->panorama_mode,
            ],
        ]);
    }

    /**
     * The client's report that this browser could not play the source the
     * server classified as directly playable — canPlayType() returned empty,
     * or the <video> element raised a decode/unsupported-source error. The
     * server only reads container metadata, so it cannot see that itself;
     * without the hint a stripped-metadata HEVC is refused a proxy it needs.
     */
    private function browserRejected(Request $request): bool
    {
        $data = $request->validate([
            'browser_rejected' => ['sometimes', 'boolean'],
        ]);

        return (bool) ($data['browser_rejected'] ?? false);
    }

    private function authorizeView(Asset $asset): void
    {
        $this->damAbortUnlessVisible($asset);

        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    private function authorizeEdit(Asset $asset): void
    {
        $this->damAbortUnlessVisible($asset);
        abort_unless($this->damCanEditAsset($asset->id), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    private function renditionResponse(Rendition $rendition): JsonResponse
    {
        return response()->json(['rendition' => $this->renditionPayload($rendition)], 202);
    }

    private function renditionPayload(Rendition $rendition): array
    {
        return [
            'id'          => $rendition->id,
            'status'      => $rendition->status,
            'progress'    => $rendition->progress,
            'recipe'      => $rendition->recipe,
            'fingerprint' => $rendition->fingerprint,
        ];
    }
}
