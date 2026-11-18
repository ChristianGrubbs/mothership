<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Http\Controllers\CollectionController as WebCollectionController;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Services\Collections\CollectionPresenter;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\User\Models\Admin;

/**
 * P4 D2/D23 over REST, with exactly the web rules (CollectionService): a collection the caller does not open answers
 * 404 like a missing one, an audience member changing it 403, an asset the caller cannot see 404 and one they may not
 * redistribute 403. Member metadata comes through the collection's own context; bytes stay on the web member routes.
 * The link is changed through the share endpoints (`shares/{id}`: update, reissue, revoke), which a collection's
 * manager alone reaches. No URL token is shown to anyone the #126 rule leaves out.
 */
class CollectionController extends Controller
{
    public function __construct(
        protected CollectionService $collections,
        protected CollectionPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $actor = $this->actor();

        return response()->json([
            'data' => [
                'managed' => $this->collections->managedBy($actor)->map(fn (AssetCollection $c) => $this->presenter->summary(collection: $c, actor: $actor))->values(),
                'shared'  => $this->collections->sharedWith($actor)->map(fn (AssetCollection $c) => $this->presenter->summary(collection: $c, actor: $actor))->values(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->openOrFail(id: $id, actor: $actor);

        abort_unless($collection->isLive() || $this->collections->manages(collection: $collection, actor: $actor), 410);

        return response()->json(['data' => $this->presenter->detail(collection: $collection, actor: $actor, api: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(WebCollectionController::rules(creating: true));
        $collection = $this->collections->create(actor: $this->actor(), data: WebCollectionController::attributes($data));

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.collections.created'),
            'data'    => $this->presenter->detail(collection: $collection, actor: $this->actor(), api: true),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->manageOrFail(id: $id, actor: $actor);
        $data = $request->validate(WebCollectionController::rules(creating: false));
        $collection = $this->collections->update(collection: $collection, actor: $actor, data: WebCollectionController::attributes($data));

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.collections.updated'),
            'data'    => $this->presenter->detail(collection: $collection, actor: $actor, api: true),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $actor = $this->actor();
        $this->collections->delete(collection: $this->collections->manageOrFail(id: $id, actor: $actor), actor: $actor);

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.dam.collections.deleted')]);
    }

    /** The members the caller is served, in position order (every row, flagged, for a manager). */
    public function members(int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->openOrFail(id: $id, actor: $actor);

        abort_unless($collection->isLive() || $this->collections->manages(collection: $collection, actor: $actor), 410);

        return response()->json(['data' => $this->presenter->members(collection: $collection, actor: $actor)]);
    }

    public function addMembers(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->manageOrFail(id: $id, actor: $actor);
        $data = $request->validate([
            'asset_ids'   => 'required|array|min:1|max:'.CollectionService::MAX_MEMBERS_PER_REQUEST,
            'asset_ids.*' => 'integer|min:1',
        ]);

        $result = $this->collections->addMembers(collection: $collection, actor: $actor, assetIds: $data['asset_ids']);

        return response()->json([
            'success' => true,
            'message' => trans_choice('dam::app.admin.dam.collections.members-added', count($result['added']), ['count' => count($result['added'])]),
            'data'    => $result,
        ]);
    }

    public function removeMember(int $id, int $assetId): JsonResponse
    {
        $actor = $this->actor();
        $this->collections->removeMember(collection: $this->collections->manageOrFail(id: $id, actor: $actor), actor: $actor, assetId: $assetId);

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.dam.collections.member-removed')]);
    }

    public function reorder(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->manageOrFail(id: $id, actor: $actor);
        $data = $request->validate([
            'asset_ids'   => 'present|array|max:5000',
            'asset_ids.*' => 'integer|min:1',
        ]);

        $this->collections->reorder(collection: $collection, actor: $actor, assetIds: $data['asset_ids']);

        return response()->json(['success' => true, 'message' => trans('dam::app.admin.dam.collections.reordered')]);
    }

    public function audience(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->manageOrFail(id: $id, actor: $actor);
        $data = $request->validate([
            'user_ids'    => 'present|array|max:'.CollectionService::MAX_PRINCIPALS,
            'user_ids.*'  => 'integer|min:1',
            'group_ids'   => 'present|array|max:'.CollectionService::MAX_PRINCIPALS,
            'group_ids.*' => 'integer|min:1',
        ]);

        $this->collections->setAudience(collection: $collection, actor: $actor, userIds: $data['user_ids'], groupIds: $data['group_ids']);

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.collections.audience-saved'),
            'data'    => $this->presenter->detail(collection: $collection->fresh(), actor: $actor, api: true),
        ]);
    }

    public function createLink(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->manageOrFail(id: $id, actor: $actor);
        $request->validate(ShareAccessOptions::rules() + [
            'name'        => 'nullable|string|max:255',
            'expires_at'  => 'nullable|date|after:now',
            'expiry_days' => 'nullable|integer|min:1|max:365',
            'no_expiry'   => 'nullable|boolean',
        ]);

        $share = $this->collections->createLink(
            collection: $collection,
            actor: $actor,
            access: ShareAccessOptions::columns($request),
            expiresAt: CollectionService::linkExpiry(expiresAt: $request->input('expires_at'), noExpiry: $request->boolean('no_expiry'), expiryDays: $request->filled('expiry_days') ? $request->integer('expiry_days') : null),
            name: is_string($name = $request->input('name')) && trim($name) !== '' ? trim($name) : null,
        );

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.collections.link-created'),
            'data'    => $this->presenter->link(share: $share, actor: $actor, api: true),
        ], 201);
    }

    protected function actor(): Admin
    {
        $actor = auth()->guard('api')->user();
        abort_unless($actor instanceof Admin, 401);

        return $actor;
    }
}
