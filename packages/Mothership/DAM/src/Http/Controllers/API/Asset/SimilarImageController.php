<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\SimilarImageController as LibrarySimilarImageController;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\User\Models\Admin;

/**
 * P5c (D16) REST twins of the Similar images page: the pairs list (optionally for one asset or folder) and "Not
 * duplicates". The api.scope middleware checks each route's scope; every pair is filtered through the seam for the
 * key's admin on both sides, and a hidden asset or folder filter answers 404 like a missing one.
 */
class SimilarImageController extends Controller
{
    public function __construct(
        private SimilarImages $similar,
        private AssetAccessDecision $access,
        private DirectoryPermissionService $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->list(request: $request, assetId: null);
    }

    public function forAsset(Request $request, int $asset): JsonResponse
    {
        abort_unless($this->access->for(AccessContext::api(AccessContext::PURPOSE_LIST), $asset)->visible, 404);

        return $this->list(request: $request, assetId: $asset);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $data = $request->validate(['pairs' => ['required', 'array', 'min:1', 'max:200'], 'pairs.*' => ['string', 'max:48']]);
        $dismissed = $this->similar->dismiss(viewer: $this->viewer(), context: AccessContext::api(AccessContext::PURPOSE_LIST), pairKeys: $data['pairs']);

        return response()->json(['success' => true, 'data' => ['dismissed' => $dismissed]]);
    }

    private function list(Request $request, ?int $assetId): JsonResponse
    {
        $context = AccessContext::api(AccessContext::PURPOSE_LIST);
        $filters = LibrarySimilarImageController::filters(request: $request, access: $this->access, permissions: $this->permissions, context: $context);
        // Pairs stay the REST default: a client that never asked for groups keeps the payload it was built against.
        $page = LibrarySimilarImageController::list(
            similar: $this->similar,
            viewer: $this->viewer(),
            context: $context,
            filters: $filters,
            request: $request,
            view: LibrarySimilarImageController::view(request: $request, default: LibrarySimilarImageController::VIEW_PAIRS),
            assetId: $assetId,
        );

        return response()->json(['success' => true, ...$page]);
    }

    private function viewer(): Admin
    {
        $viewer = auth('api')->user();
        abort_unless($viewer instanceof Admin, 403);

        return $viewer;
    }
}
