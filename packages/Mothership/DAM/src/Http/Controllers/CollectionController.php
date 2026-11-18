<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Mothership\DAM\Http\Controllers\PublicShare\SharedViewerController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Collections\CollectionPresenter;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Support\CollectionLog;
use Mothership\User\Models\Admin;

/**
 * The Collections area (P4 D2, ADR 0011). The page, its lists and every member read are open to any signed-in admin
 * and decided per collection: a collection the actor does not open answers 404, exactly like a missing one. Changes
 * are gated twice: the ACL keys (`dam.collections.create|update|delete`, Config/acl.php) and managing the collection
 * (its owner or a full admin, CollectionService).
 *
 * Member reads (thumbnail, preview, download, proxy, zip) are the collection's own routes: they ask the seam with
 * `AccessContext::collection()`, the only context in which an audience grant counts. After the collection expires or
 * is archived they answer 410 for everyone; a member that is not served (withdrawn, pending, out of its window,
 * trashed, or no longer a member) answers 404.
 */
class CollectionController extends Controller
{
    public function __construct(
        protected CollectionService $collections,
        protected CollectionPresenter $presenter,
        protected AssetAccessDecision $access,
    ) {}

    public function index()
    {
        return view('dam::collections.index');
    }

    /** The collections the actor manages, and those shared with them (live internal audiences that name them). */
    public function list(): JsonResponse
    {
        $actor = $this->actor();

        return response()->json([
            'managed' => $this->collections->managedBy($actor)->map(fn (AssetCollection $c) => $this->presenter->summary(collection: $c, actor: $actor))->values(),
            'shared'  => $this->collections->sharedWith($actor)->map(fn (AssetCollection $c) => $this->presenter->summary(collection: $c, actor: $actor))->values(),
            'can'     => ['create' => bouncer()->hasPermission('dam.collections.create')],
        ]);
    }

    /** The audience picker: active users and groups, by name or email (the access dialog's own search). */
    public function principals(Request $request): JsonResponse
    {
        $request->validate(['q' => 'nullable|string|max:255']);

        return response()->json(app(AccessListService::class)->principals(query: $request->input('q')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::rules(creating: true));
        $collection = $this->collections->create(actor: $this->actor(), data: self::attributes($data));

        return response()->json([
            'message'    => trans('dam::app.admin.dam.collections.created'),
            'collection' => $this->presenter->detail(collection: $collection, actor: $this->actor()),
        ], 201);
    }

    /**
     * The collection page. A manager always gets it, expired or not, to change it; an audience member gets 410 once
     * it has expired or been archived.
     */
    public function show(int $id)
    {
        $actor = $this->actor();
        $collection = $this->collections->openOrFail(id: $id, actor: $actor);
        $manages = $this->collections->manages(collection: $collection, actor: $actor);

        if (! $manages && ! $collection->isLive()) {
            return response()->view('dam::collections.closed', ['collection' => $collection], 410);
        }

        CollectionLog::write('collection.visit', ['collection_id' => (int) $collection->id, 'viewer_id' => (int) $actor->id, 'via' => $manages ? 'manager' : 'audience']);

        return view('dam::collections.show', ['collection' => $collection, 'manages' => $manages]);
    }

    /** The collection with its members: servable ones for the audience, every row (flagged) for a manager. */
    public function data(int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->openOrFail(id: $id, actor: $actor);

        abort_unless($collection->isLive() || $this->collections->manages(collection: $collection, actor: $actor), 410);

        return response()->json([
            'collection' => $this->presenter->detail(collection: $collection, actor: $actor),
            'members'    => $this->presenter->members(collection: $collection, actor: $actor),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor();
        $collection = $this->collections->manageOrFail(id: $id, actor: $actor);
        $data = $request->validate(self::rules(creating: false));
        $collection = $this->collections->update(collection: $collection, actor: $actor, data: self::attributes($data));

        return response()->json([
            'message'    => trans('dam::app.admin.dam.collections.updated'),
            'collection' => $this->presenter->detail(collection: $collection, actor: $actor),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $actor = $this->actor();
        $this->collections->delete(collection: $this->collections->manageOrFail(id: $id, actor: $actor), actor: $actor);

        return response()->json(['message' => trans('dam::app.admin.dam.collections.deleted')]);
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
            'message'    => trans('dam::app.admin.dam.collections.audience-saved'),
            'collection' => $this->presenter->detail(collection: $collection->fresh(), actor: $actor),
        ]);
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
            'message' => trans_choice('dam::app.admin.dam.collections.members-added', count($result['added']), ['count' => count($result['added'])]),
            'added'   => $result['added'],
            'already' => $result['already'],
        ]);
    }

    public function removeMember(int $id, int $assetId): JsonResponse
    {
        $actor = $this->actor();
        $this->collections->removeMember(collection: $this->collections->manageOrFail(id: $id, actor: $actor), actor: $actor, assetId: $assetId);

        return response()->json(['message' => trans('dam::app.admin.dam.collections.member-removed')]);
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

        return response()->json(['message' => trans('dam::app.admin.dam.collections.reordered')]);
    }

    /** A public collection's link, with the share options (password, download-off, creator access, expiry). */
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
            'message' => trans('dam::app.admin.dam.collections.link-created'),
            'link'    => $this->presenter->link(share: $share, actor: $actor, api: false),
        ], 201);
    }

    public function memberThumbnail(int $id, int $assetId)
    {
        return $this->viewer()->memberThumbnail($this->member(id: $id, assetId: $assetId, purpose: AccessContext::PURPOSE_PREVIEW));
    }

    public function memberPreview(int $id, int $assetId)
    {
        return $this->viewer()->memberOriginal($this->member(id: $id, assetId: $assetId, purpose: AccessContext::PURPOSE_PREVIEW), 'inline');
    }

    public function memberDownload(int $id, int $assetId)
    {
        $asset = $this->member(id: $id, assetId: $assetId, purpose: AccessContext::PURPOSE_DOWNLOAD);
        CollectionLog::write('collection.member.downloaded', ['collection_id' => $id, 'asset_id' => (int) $asset->id, 'viewer_id' => (int) $this->actor()->id]);

        return $this->viewer()->memberOriginal($asset, 'attachment');
    }

    public function memberProxy(int $id, int $assetId)
    {
        return $this->viewer()->memberProxy($this->member(id: $id, assetId: $assetId, purpose: AccessContext::PURPOSE_PREVIEW));
    }

    /** Every servable member, through the seam; nothing else, whatever else the viewer may see. */
    public function zip(int $id)
    {
        $actor = $this->actor();
        $collection = $this->liveOrFail(id: $id, actor: $actor);
        $members = $this->collections->servableMembers(collection: $collection, context: AccessContext::collection(collectionId: (int) $collection->id, purpose: AccessContext::PURPOSE_DOWNLOAD, viewer: $actor));

        CollectionLog::write('collection.zip', ['collection_id' => (int) $collection->id, 'viewer_id' => (int) $actor->id]);

        return $this->viewer()->collectionZip(members: $members, name: (string) $collection->name);
    }

    /** One served member, or: 404 (collection not opened, not a member, or not served), 410 (collection closed). */
    protected function member(int $id, int $assetId, string $purpose): Asset
    {
        $actor = $this->actor();
        $collection = $this->liveOrFail(id: $id, actor: $actor);

        return $this->access->for(AccessContext::collection(collectionId: (int) $collection->id, purpose: $purpose, viewer: $actor), $assetId)->allowedOrFail();
    }

    protected function liveOrFail(int $id, Admin $actor): AssetCollection
    {
        $collection = $this->collections->openOrFail(id: $id, actor: $actor);

        abort_unless($collection->isLive(), 410);

        return $collection;
    }

    protected function viewer(): SharedViewerController
    {
        return app(SharedViewerController::class);
    }

    protected function actor(): Admin
    {
        $actor = auth()->guard('admin')->user();
        abort_unless($actor instanceof Admin, 401);

        return $actor;
    }

    /** The fields both the web form and REST take. */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name'        => $required.'|string|max:191',
            'description' => 'sometimes|nullable|string|max:2000',
            'audience'    => 'sometimes|in:'.implode(',', AssetCollection::AUDIENCES),
            'expires_at'  => 'sometimes|nullable|date',
            'slug'        => 'sometimes|nullable|string|max:64',
            'status'      => 'sometimes|in:'.implode(',', AssetCollection::STATUSES),
        ] + ($creating ? [
            'user_ids'    => 'sometimes|array|max:'.CollectionService::MAX_PRINCIPALS,
            'user_ids.*'  => 'integer|min:1',
            'group_ids'   => 'sometimes|array|max:'.CollectionService::MAX_PRINCIPALS,
            'group_ids.*' => 'integer|min:1',
        ] : []);
    }

    /** Validated input to the service's attributes: names trimmed, an empty description or slug cleared. */
    public static function attributes(array $data): array
    {
        foreach (['name', 'description', 'slug'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        foreach (['description', 'slug'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        if (array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $data['expires_at'] === null ? null : Carbon::parse($data['expires_at'])->setTimezone(config('app.timezone'));
        }

        return $data;
    }
}
