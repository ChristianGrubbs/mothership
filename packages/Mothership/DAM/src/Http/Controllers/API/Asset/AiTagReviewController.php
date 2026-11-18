<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\AiTagReviewController as LibraryAiTagReviewController;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Tags\AiTagReview;

/**
 * P5c (D8) REST twins of the AI tag review page: the queue and bulk accept or dismiss, through AiTagReview, the code
 * path the asset page uses. Assets are filtered through the seam for the key's admin; an item on an asset they may
 * not see answers `not_found`, like a missing one.
 */
class AiTagReviewController extends Controller
{
    public function __construct(
        private AiTagReview $review,
        private DirectoryPermissionService $permissions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = LibraryAiTagReviewController::filters(request: $request, permissions: $this->permissions);
        $queue = $this->review->queue(
            context: AccessContext::api(AccessContext::PURPOSE_LIST),
            group: $filters['group'],
            term: $filters['term'],
            folderId: $filters['folder_id'],
            page: max(1, (int) $request->query('page', 1)),
            perPage: (int) $request->query('per_page', AiTagReview::PER_PAGE),
        );

        return response()->json(['success' => true, ...$queue]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = LibraryAiTagReviewController::validateBulk($request);
        $result = $this->review->bulk(action: $data['action'], items: $data['items'], context: AccessContext::api(AccessContext::PURPOSE_LIST), actorId: auth('api')->id());

        return response()->json(['success' => true, 'data' => $result]);
    }
}
