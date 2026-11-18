<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\ReviewRequest;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Review\AssetWatchers;
use Mothership\DAM\Services\Review\ReviewNotifier;
use Mothership\DAM\Services\Review\ReviewRequests;
use Mothership\DAM\Services\Review\ReviewRounds;
use Mothership\DAM\Traits\AssetAccessControl;
use Mothership\User\Models\Admin;

/**
 * The Comments tab's review actions (P7): its rounds, Watch, feedback requests and the people picker for mentions
 * and requests. A hidden asset answers 404 exactly like a missing one; the route ACL (Config/acl.php) gates each
 * action by the comment permission it extends.
 */
class ReviewController extends Controller
{
    use AssetAccessControl;

    public function __construct(
        protected ReviewRounds $rounds,
        protected ReviewRequests $requests,
        protected AssetWatchers $watchers,
    ) {}

    public function show(int $id): JsonResponse
    {
        $asset = $this->damVisibleAssetOrFail($id);

        return new JsonResponse($this->rounds->payload(asset: $asset, viewer: $this->actor(), viewerMayEdit: $this->mayEdit($asset->id)));
    }

    public function watch(Request $request, int $id): JsonResponse
    {
        $asset = $this->damVisibleAssetOrFail($id);
        $watching = (bool) $request->validate(['watching' => ['required', 'boolean']])['watching'];

        $this->watchers->set(asset: $asset, adminId: (int) $this->actor()->id, watching: $watching);

        return new JsonResponse([
            'message'  => trans($watching ? 'dam::app.admin.dam.asset.comments.review.watch-on' : 'dam::app.admin.dam.asset.comments.review.watch-off'),
            'watching' => $watching,
        ]);
    }

    public function storeRequest(Request $request, int $id): JsonResponse
    {
        $asset = $this->damVisibleAssetOrFail($id);
        $data = $request->validate(ReviewRequests::rules());

        $review = $this->requests->create(
            asset: $asset,
            requester: $this->actor(),
            userIds: (array) ($data['user_ids'] ?? []),
            groupIds: (array) ($data['group_ids'] ?? []),
            message: $data['message'] ?? null,
            dueAt: empty($data['due_at']) ? null : Carbon::parse($data['due_at']),
        );

        return new JsonResponse(['message' => trans('dam::app.admin.dam.asset.comments.review.request-sent'), 'id' => $review->id], 201);
    }

    public function closeRequest(int $id, int $request): JsonResponse
    {
        $asset = $this->damVisibleAssetOrFail($id);
        $review = ReviewRequest::query()->where('dam_asset_id', $asset->id)->whereKey($request)->firstOrFail();

        $this->requests->close(request: $review, actor: $this->actor(), actorMayEdit: $this->mayEdit($asset->id));

        return new JsonResponse(['message' => trans('dam::app.admin.dam.asset.comments.review.request-closed')]);
    }

    /**
     * The people picker for mentions and feedback requests: the Share dialog's candidates (active people and
     * groups, by name or email), under either access model. It names no asset, so it reveals nothing about one.
     */
    public function people(Request $request, AccessListService $access, ReviewNotifier $notifier): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:255', 'asset_id' => 'nullable|integer|min:1']);

        if (! $request->filled('asset_id')) {
            return new JsonResponse($access->principals(query: $request->input('q'), limit: 8));
        }

        // Named for an asset: suggest only people who can open it (posting a mention of anyone else is refused), and
        // only to someone who can open it themselves, so the list reveals nothing about a hidden asset.
        $asset = $this->damVisibleAssetOrFail($request->integer('asset_id'));
        $people = $access->principals(query: $request->input('q'), limit: 40);
        $people['users'] = array_values(array_slice(array_filter(
            $people['users'],
            fn (array $user) => ($admin = Admin::query()->find($user['id'])) !== null && $notifier->canSee(asset: $asset, admin: $admin),
        ), 0, 8));

        return new JsonResponse($people);
    }

    protected function actor(): Admin
    {
        return Auth::guard('admin')->user();
    }

    /** Whether the viewer may change the asset: the asset-edit gate plus the editor permission, as availability uses. */
    protected function mayEdit(int $assetId): bool
    {
        return bouncer()->hasPermission('dam.asset.update') && $this->damCanEditAsset($assetId);
    }
}
