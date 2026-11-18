<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\ReviewRequest;
use Mothership\DAM\Services\Review\AssetWatchers;
use Mothership\DAM\Services\Review\ReviewRequests;
use Mothership\DAM\Services\Review\ReviewRounds;
use Mothership\DAM\Traits\ApiKeyPermission;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * REST twin of the Comments tab's review actions (P7): the rounds read, Watch and feedback requests, through the
 * same services. A hidden asset answers 404 like a missing one; "may change the asset" is a key that may update
 * assets (api.dam.assets.update), the REST form of the editor permission.
 */
class ReviewController extends Controller
{
    use ApiKeyPermission;
    use AssetAccessControl;

    public function __construct(
        protected ReviewRounds $rounds,
        protected ReviewRequests $requests,
        protected AssetWatchers $watchers,
    ) {}

    public function show(int $asset): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);

        return response()->json(['success' => true, 'data' => $this->rounds->payload(asset: $model, viewer: Auth::user(), viewerMayEdit: $this->apiKeyAllows('api.dam.assets.update'))]);
    }

    public function watch(Request $request, int $asset): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $watching = (bool) $request->validate(['watching' => ['required', 'boolean']])['watching'];

        $this->watchers->set(asset: $model, adminId: (int) Auth::id(), watching: $watching);

        return response()->json(['success' => true, 'data' => ['watching' => $watching]]);
    }

    public function storeRequest(Request $request, int $asset): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $data = $request->validate(ReviewRequests::rules());

        $review = $this->requests->create(
            asset: $model,
            requester: Auth::user(),
            userIds: (array) ($data['user_ids'] ?? []),
            groupIds: (array) ($data['group_ids'] ?? []),
            message: $data['message'] ?? null,
            dueAt: empty($data['due_at']) ? null : Carbon::parse($data['due_at']),
        );

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.comments.review.request-sent'),
            'data'    => [
                'id'            => $review->id,
                'asset_file_id' => $review->asset_file_id,
                'due_at'        => $review->due_at?->toIso8601String(),
                'recipients'    => $review->recipients()->pluck('admins.id')->map(fn ($id) => (int) $id)->values()->all(),
            ],
        ], 201);
    }

    public function closeRequest(int $asset, int $request): JsonResponse
    {
        $model = $this->damVisibleAssetOrFail($asset);
        $review = ReviewRequest::query()->where('dam_asset_id', $model->id)->whereKey($request)->firstOrFail();

        $review = $this->requests->close(request: $review, actor: Auth::user(), actorMayEdit: $this->apiKeyAllows('api.dam.assets.update'));

        return response()->json(['success' => true, 'data' => ['id' => $review->id, 'closed_at' => $review->closed_at?->toIso8601String()]]);
    }
}
