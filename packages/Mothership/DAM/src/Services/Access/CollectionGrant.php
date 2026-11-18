<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\User\Models\Admin;

/**
 * P4 D2: the collection-audience grant, the seam's answer for a `collection:<id>` context and for nothing else
 * (roadmap §3.3). AssetAccessDecision hands a collection context here before its own rules run, and no other context
 * ever reaches this class, so the grant is bound to its container: it never widens the Library, search, Find similar,
 * Related, REST, an ordinary zip or a file route.
 *
 * Inside the collection, a member is served when all of these hold:
 * - the viewer opens the collection: its owner, a full admin, or a listed principal of an internal audience (a user,
 *   one of their groups, or Everyone); or a public collection's link, already opened by ShareGate;
 * - the collection is live: active and not expired. Otherwise every member read answers `expired` (the controllers
 *   answer 410 before asking);
 * - the asset is a member right now, read on every request, so removing it revokes at once;
 * - the asset is out for use: available now and inside its window, and not trashed. Strict for every viewer: the
 *   owner and editor exemptions of the Library do not carry into a collection, so a draft, pending, archived, expired
 *   or trashed member is not served or counted.
 *
 * The asset's own access list is deliberately not asked: listing an asset in a collection is the redistribution, which
 * only its owner, a full admin, or a holder of `dam.asset.share` who can see it may make (CollectionService).
 */
class CollectionGrant
{
    /** Purposes a collection serves. Changing or re-sharing a member is never done through a collection. */
    public const SERVED_PURPOSES = [AccessContext::PURPOSE_LIST, AccessContext::PURPOSE_PREVIEW, AccessContext::PURPOSE_DOWNLOAD];

    public function decide(AccessContext $context, int|Asset $asset): AccessDecision
    {
        $collection = $this->collection($context);
        $assetId = $asset instanceof Asset ? (int) $asset->id : $asset;

        if ($collection === null || ! $this->isMember(collectionId: (int) $collection->id, assetId: $assetId)) {
            return AccessDecision::missing();
        }

        $model = $asset instanceof Asset ? $asset : Asset::withTrashed()->where('id', $assetId)->first();

        if ($model === null) {
            return AccessDecision::missing();
        }

        if (! $this->opens(collection: $collection, context: $context)) {
            return AccessDecision::hidden($model);
        }

        if (! $collection->isLive()) {
            return AccessDecision::unavailable($model, AccessDecision::REASON_EXPIRED);
        }

        if ($model->trashed()) {
            return AccessDecision::trashed($model);
        }

        // P3b-1: a merge source is never served through a collection, as through the Library: it answers like trash.
        if ($model->merged_into_id !== null) {
            return AccessDecision::merged($model);
        }

        if (($because = $model->unavailableBecause()) !== null) {
            return AccessDecision::unavailable($model, $because === Asset::AVAILABILITY_EXPIRED ? AccessDecision::REASON_EXPIRED : AccessDecision::REASON_UNAVAILABLE);
        }

        return in_array($context->purpose, self::SERVED_PURPOSES, true) ? AccessDecision::allowed($model) : AccessDecision::forbidden($model);
    }

    /**
     * The listing half: restricts an asset query to the collection's servable members, or to nothing when the viewer
     * does not open it or it is not live. $column names the asset id column when the query is not over dam_assets.
     */
    public function scope(Builder $query, AccessContext $context, string $column = 'dam_assets.id'): void
    {
        $collection = $this->collection($context);

        if ($collection === null || ! $collection->isLive() || ! $this->opens(collection: $collection, context: $context)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($column, fn ($members) => $members->select('dam_collection_assets.asset_id')->from('dam_collection_assets')
            ->where('dam_collection_assets.collection_id', $collection->id));

        // Live, out for use and not a merge source (P3b-1): what the collection may serve and count.
        $servable = fn ($q) => Asset::whereAvailableNow($q)->whereNull('dam_assets.deleted_at')->whereNull('dam_assets.merged_into_id');

        if ($column === 'dam_assets.id') {
            $query->where($servable);

            return;
        }

        $query->whereIn($column, fn ($ids) => $servable($ids->select('dam_assets.id')->from('dam_assets')));
    }

    /** Whether the context's viewer (or link) opens the collection at all, live or not. */
    public function opens(AssetCollection $collection, AccessContext $context): bool
    {
        if ($context->throughLink) {
            return $collection->isPublic();
        }

        $viewer = $this->viewer($context);

        return $viewer !== null && ($this->manages(collection: $collection, viewer: $viewer) || $this->inAudience(collection: $collection, viewer: $viewer));
    }

    /** Its owner, or a full admin (who also manages a collection whose owner is gone). A disabled admin manages nothing. */
    public function manages(AssetCollection $collection, ?Admin $viewer): bool
    {
        if ($viewer === null || ! (bool) $viewer->status) {
            return false;
        }

        return self::fullAdmin($viewer) || ($collection->owner_id !== null && (int) $collection->owner_id === (int) $viewer->id);
    }

    /** A listed principal of an internal audience: the user, one of their groups, or Everyone. */
    public function inAudience(AssetCollection $collection, Admin $viewer): bool
    {
        if ($collection->isPublic() || ! (bool) $viewer->status) {
            return false;
        }

        return $this->audienceQuery(viewer: $viewer)->where('dam_collection_audience.collection_id', $collection->id)->exists();
    }

    /**
     * The audience rows that name $viewer, over every collection: the "Shared with me" list and inAudience() share it.
     */
    public function audienceQuery(Admin $viewer): \Illuminate\Database\Query\Builder
    {
        $groupIds = DB::table('dam_group_members')->where('admin_id', $viewer->id)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
        $everyoneId = DB::table('dam_groups')->where('system_key', AccessListReader::EVERYONE)->value('id');

        if ($everyoneId !== null) {
            $groupIds[] = (int) $everyoneId;
        }

        return DB::table('dam_collection_audience')->where(function ($principal) use ($viewer, $groupIds) {
            $principal->where(fn ($user) => $user->where('principal_type', AssetCollection::PRINCIPAL_USER)->where('principal_id', $viewer->id))
                ->when($groupIds !== [], fn ($q) => $q->orWhere(fn ($group) => $group->where('principal_type', AssetCollection::PRINCIPAL_GROUP)->whereIn('principal_id', $groupIds)));
        });
    }

    /** The viewer the context names, else the session's admin (web or API). Null for a disabled admin. */
    public function viewer(AccessContext $context): ?Admin
    {
        $viewer = $context->viewer;

        if ($viewer === null) {
            try {
                $viewer = auth()->guard('admin')->user() ?? auth()->guard('api')->user();
            } catch (\BadMethodCallException) {
                $viewer = null;
            }
        }

        return $viewer instanceof Admin && (bool) $viewer->status ? $viewer : null;
    }

    /** As the seam: an admin with no role, or a role of permission type `all`. */
    public static function fullAdmin(Admin $admin): bool
    {
        $role = $admin->role;

        return $role === null || $role->permission_type === 'all';
    }

    protected function isMember(int $collectionId, int $assetId): bool
    {
        return DB::table('dam_collection_assets')->where('collection_id', $collectionId)->where('asset_id', $assetId)->exists();
    }

    /** Read on every call, never cached: an expiry, archive or audience change counts on the next request. */
    protected function collection(AccessContext $context): ?AssetCollection
    {
        return AssetCollection::query()->where('id', (int) $context->collectionId)->first();
    }
}
