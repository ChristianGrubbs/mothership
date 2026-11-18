<?php

namespace Mothership\DAM\Services\Collections;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\CollectionMember;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\ShareRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\CollectionGrant;
use Mothership\DAM\Services\Automation\AutomationOrigin;
use Mothership\DAM\Services\Automation\ValueDecisions;
use Mothership\DAM\Support\CollectionLog;
use Mothership\DAM\Support\CollectionSlugs;
use Mothership\User\Models\Admin;

/**
 * P4 D2/D23: every change to a collection, for the web controller and REST alike, so the two never drift.
 *
 * - **Who.** A collection is managed by its owner or a full admin (CollectionGrant::manages()). Anyone else who opens
 *   it (an internal audience member) reads it and changes nothing (403); anyone who does not open it gets the 404 a
 *   missing collection gets, so an id says nothing about whether it exists.
 * - **Redistribute.** Adding an asset needs the right to redistribute it (roadmap §3.3): its owner, a full admin, or a
 *   holder of `dam.asset.share` who can see it. A hidden asset answers 404 and a visible one without the right 403,
 *   checked for every id before anything is written, so a request adds all its assets or none.
 * - **Order.** Member writes take the collection row's lock first, so concurrent adds get distinct positions and a
 *   reorder never interleaves with an add.
 */
class CollectionService
{
    public const MAX_PRINCIPALS = 100;

    public const MAX_MEMBERS_PER_REQUEST = 500;

    public function __construct(
        protected AssetAccessDecision $access,
        protected CollectionGrant $grant,
        protected ShareRepository $shares,
    ) {}

    /** The collection when $actor opens it (manages it, or is in its audience); otherwise the 404 a missing one gets. */
    public function openOrFail(int $id, Admin $actor): AssetCollection
    {
        $collection = AssetCollection::query()->where('id', $id)->first();

        abort_unless($collection !== null && $this->opens(collection: $collection, actor: $actor), 404);

        return $collection;
    }

    /** As openOrFail(), then 403 for an audience member: reading a collection is not managing it. */
    public function manageOrFail(int $id, Admin $actor): AssetCollection
    {
        $collection = $this->openOrFail(id: $id, actor: $actor);

        abort_unless($this->grant->manages(collection: $collection, viewer: $actor), 403, trans('dam::app.admin.permissions.unauthorized'));

        return $collection;
    }

    public function opens(AssetCollection $collection, Admin $actor): bool
    {
        return $this->grant->opens(collection: $collection, context: AccessContext::collection(collectionId: (int) $collection->id, viewer: $actor));
    }

    public function manages(AssetCollection $collection, Admin $actor): bool
    {
        return $this->grant->manages(collection: $collection, viewer: $actor);
    }

    /** The collections $actor manages: their own, or every one for a full admin. */
    public function managedBy(Admin $actor): Collection
    {
        return AssetCollection::query()
            ->when(! CollectionGrant::fullAdmin($actor), fn ($q) => $q->where('owner_id', $actor->id))
            ->with('owner:id,name')
            ->orderBy('name')->orderBy('id')
            ->limit(500)
            ->get();
    }

    /** "Shared with me": live internal collections whose audience names $actor, one of their groups, or Everyone. */
    public function sharedWith(Admin $actor): Collection
    {
        return AssetCollection::query()
            ->live()
            ->where('audience', AssetCollection::AUDIENCE_INTERNAL)
            ->whereIn('id', $this->grant->audienceQuery(viewer: $actor)->select('collection_id'))
            ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $actor->id))
            ->with('owner:id,name')
            ->orderBy('name')->orderBy('id')
            ->limit(500)
            ->get();
    }

    /**
     * @param  array{name: string, description?: ?string, audience?: string, expires_at?: ?DateTimeInterface, slug?: ?string, status?: string, user_ids?: list<int>, group_ids?: list<int>}  $data
     */
    public function create(Admin $actor, array $data): AssetCollection
    {
        $collection = DB::transaction(function () use ($actor, $data) {
            $collection = AssetCollection::query()->create([
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'owner_id'    => $actor->id,
                'audience'    => $data['audience'] ?? AssetCollection::AUDIENCE_INTERNAL,
                'expires_at'  => $data['expires_at'] ?? null,
                'status'      => $data['status'] ?? AssetCollection::STATUS_ACTIVE,
            ]);

            if (($slug = CollectionSlugs::normalize($data['slug'] ?? null)) !== null) {
                $this->writeSlug(collection: $collection, slug: $slug);
            }

            if (isset($data['user_ids']) || isset($data['group_ids'])) {
                $this->writeAudience(collection: $collection, userIds: $data['user_ids'] ?? [], groupIds: $data['group_ids'] ?? []);
            }

            return $collection;
        });

        CollectionLog::write('collection.created', ['collection_id' => (int) $collection->id, 'actor_id' => (int) $actor->id, 'audience' => (string) $collection->audience]);

        return $collection->fresh();
    }

    /**
     * Only the keys present in $data change. `slug` null clears it (the old one keeps redirecting). Moving the audience
     * to internal revokes the collection's live links: a link is only ever for a public audience.
     *
     * @param  array{name?: string, description?: ?string, audience?: string, expires_at?: ?DateTimeInterface, slug?: ?string, status?: string}  $data
     */
    public function update(AssetCollection $collection, Admin $actor, array $data): AssetCollection
    {
        $revoked = 0;

        DB::transaction(function () use ($collection, $data, &$revoked) {
            $locked = AssetCollection::query()->where('id', $collection->id)->lockForUpdate()->firstOrFail();

            $locked->fill(array_intersect_key($data, array_flip(['name', 'description', 'audience', 'expires_at', 'status'])))->save();

            if (array_key_exists('slug', $data)) {
                $this->writeSlug(collection: $locked, slug: CollectionSlugs::normalize($data['slug']));
            }

            if (! $locked->isPublic()) {
                $revoked = Share::query()->active()->where('share_type', Share::TYPE_COLLECTION)->where('target_id', $locked->id)
                    ->update(['revoked_at' => now()]);
            }
        });

        CollectionLog::write('collection.updated', [
            'collection_id' => (int) $collection->id,
            'actor_id'      => (int) $actor->id,
            'fields'        => implode(',', array_keys($data)) === '' ? 'none' : substr(implode(',', array_keys($data)), 0, 32),
            'links_revoked' => (int) $revoked,
        ]);

        return $collection->fresh();
    }

    /** Deletes the collection with its members, audience and redirects (cascade), and its links. Never an asset. */
    public function delete(AssetCollection $collection, Admin $actor): void
    {
        DB::transaction(function () use ($collection) {
            Share::query()->where('share_type', Share::TYPE_COLLECTION)->where('target_id', $collection->id)->delete();
            AssetCollection::query()->where('id', $collection->id)->delete();
            $this->decisions()->forgetTarget(kind: ValueDecisions::KIND_COLLECTION, targetIds: (int) $collection->id);
        });

        CollectionLog::write('collection.deleted', ['collection_id' => (int) $collection->id, 'actor_id' => (int) $actor->id]);
    }

    /**
     * Replaces the internal audience. Every id must name an active admin or an existing group (422 otherwise), checked
     * before anything is written.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     */
    public function setAudience(AssetCollection $collection, Admin $actor, array $userIds, array $groupIds): void
    {
        DB::transaction(function () use ($collection, $userIds, $groupIds) {
            AssetCollection::query()->where('id', $collection->id)->lockForUpdate()->first();
            $this->writeAudience(collection: $collection, userIds: $userIds, groupIds: $groupIds);
        });

        CollectionLog::write('collection.audience.changed', [
            'collection_id' => (int) $collection->id,
            'actor_id'      => (int) $actor->id,
            'user_ids'      => array_values(array_unique(array_map('intval', $userIds))),
            'group_ids'     => array_values(array_unique(array_map('intval', $groupIds))),
        ]);
    }

    /** @return array{users: list<array{id: int, name: string, email: string}>, groups: list<array{id: int, name: string, system_key: ?string}>} */
    public function audience(AssetCollection $collection): array
    {
        $rows = DB::table('dam_collection_audience')->where('collection_id', $collection->id)->get(['principal_type', 'principal_id']);
        $userIds = $rows->where('principal_type', AssetCollection::PRINCIPAL_USER)->pluck('principal_id')->map(fn ($id) => (int) $id)->all();
        $groupIds = $rows->where('principal_type', AssetCollection::PRINCIPAL_GROUP)->pluck('principal_id')->map(fn ($id) => (int) $id)->all();

        return [
            'users' => $userIds === [] ? [] : DB::table('admins')->whereIn('id', $userIds)->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'email' => (string) $row->email])->all(),
            'groups' => $groupIds === [] ? [] : DB::table('dam_groups')->whereIn('id', $groupIds)->orderBy('name')->get(['id', 'name', 'system_key'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'system_key' => $row->system_key])->all(),
        ];
    }

    /**
     * Adds assets after the current last member, in the order given; ids already in the collection are left where they
     * are. Every id is checked first (404 for a hidden or missing asset, then 403 for one the actor may not
     * redistribute), so the request adds all of them or none.
     *
     * @param  list<int>  $assetIds
     * @return array{added: list<int>, already: list<int>}
     */
    public function addMembers(AssetCollection $collection, Admin $actor, array $assetIds, string $source = 'manual'): array
    {
        $ids = array_values(array_unique(array_map('intval', $assetIds)));

        $refusals = array_map(fn (int $id) => $this->redistributeRefusal(actor: $actor, assetId: $id), $ids);

        abort_if(in_array(404, $refusals, true), 404, trans('dam::app.admin.dam.collections.asset-not-found'));
        abort_if(in_array(403, $refusals, true), 403, trans('dam::app.admin.dam.collections.cannot-redistribute'));

        $result = DB::transaction(function () use ($collection, $actor, $ids, $source) {
            AssetCollection::query()->where('id', $collection->id)->lockForUpdate()->first();

            $existing = DB::table('dam_collection_assets')->where('collection_id', $collection->id)->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
            $position = (int) DB::table('dam_collection_assets')->where('collection_id', $collection->id)->max('position');
            $added = [];

            foreach ($ids as $id) {
                if (in_array($id, $existing, true)) {
                    continue;
                }

                $position = $existing === [] && $added === [] ? 0 : $position + 1;

                DB::table('dam_collection_assets')->insert([
                    'collection_id' => $collection->id,
                    'asset_id'      => $id,
                    'position'      => $position,
                    'added_by'      => $actor->id,
                    'source'        => $source,
                    'created_at'    => now(),
                ]);

                $added[] = $id;
            }

            // A person adding it again lifts their earlier removal (roadmap §3.4); a rule never does.
            if ($source === 'manual' && $added !== []) {
                $this->decisions()->clear(assetIds: $added, kind: ValueDecisions::KIND_COLLECTION, targetId: (int) $collection->id);
            }

            return ['added' => $added, 'already' => array_values(array_intersect($ids, $existing))];
        });

        CollectionLog::write('collection.member.added', [
            'collection_id' => (int) $collection->id,
            'actor_id'      => (int) $actor->id,
            'asset_ids'     => $result['added'],
            'already'       => count($result['already']),
        ]);

        return $result;
    }

    /** Removes one member; 404 when it is not one. Access through the collection ends with the next request. */
    public function removeMember(AssetCollection $collection, Admin $actor, int $assetId): void
    {
        $deleted = DB::table('dam_collection_assets')->where('collection_id', $collection->id)->where('asset_id', $assetId)->delete();

        abort_if($deleted === 0, 404, trans('dam::app.admin.dam.collections.member-not-found'));

        // A person's removal is a tombstone no automation rule overrides (roadmap §3.4).
        if (! AutomationOrigin::active()) {
            $this->decisions()->remove(assetIds: $assetId, kind: ValueDecisions::KIND_COLLECTION, targetId: (int) $collection->id, actorId: (int) $actor->id);
        }

        CollectionLog::write('collection.member.removed', ['collection_id' => (int) $collection->id, 'actor_id' => (int) $actor->id, 'asset_id' => $assetId]);
    }

    /**
     * Writes the members' order. $assetIds must be exactly the current members (422 otherwise, e.g. after another
     * manager added one meanwhile), so a stale screen never drops or duplicates a position.
     *
     * @param  list<int>  $assetIds
     */
    public function reorder(AssetCollection $collection, Admin $actor, array $assetIds): void
    {
        $ids = array_map('intval', $assetIds);

        DB::transaction(function () use ($collection, $ids) {
            AssetCollection::query()->where('id', $collection->id)->lockForUpdate()->first();

            $current = DB::table('dam_collection_assets')->where('collection_id', $collection->id)->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
            $given = $ids;
            sort($current);
            sort($given);

            abort_unless($given === $current && count(array_unique($ids)) === count($ids), 422, trans('dam::app.admin.dam.collections.reorder-stale'));

            foreach ($ids as $position => $id) {
                DB::table('dam_collection_assets')->where('collection_id', $collection->id)->where('asset_id', $id)->update(['position' => $position]);
            }
        });

        CollectionLog::write('collection.member.reordered', ['collection_id' => (int) $collection->id, 'actor_id' => (int) $actor->id, 'count' => count($ids)]);
    }

    /**
     * The servable members through the seam, in position order, as an Asset query with `member_position` and the
     * display name. $context is a collection context (AccessContext::collection() or collectionLink()).
     */
    public function servableMembers(AssetCollection $collection, AccessContext $context): Builder
    {
        // A correlated subquery, not a join: the zip stream and pagination add an unqualified `id` select.
        $position = fn () => CollectionMember::query()->select('position')
            ->whereColumn('dam_collection_assets.asset_id', 'dam_assets.id')
            ->where('dam_collection_assets.collection_id', $collection->id)
            ->limit(1);
        $query = Asset::query()->withDisplayName()
            ->addSelect(['member_position' => $position()])
            ->orderBy($position())->orderBy('dam_assets.id');

        $this->access->scopeListable(query: $query, context: $context);

        return $query;
    }

    /** How many members the context is served: the count every listing shows. */
    public function servableCount(AssetCollection $collection, AccessContext $context): int
    {
        $query = Asset::query();
        $this->access->scopeListable(query: $query, context: $context);

        return $query->count();
    }

    /** The newest link of a collection, live or not. */
    public function link(AssetCollection $collection): ?Share
    {
        return Share::query()->where('share_type', Share::TYPE_COLLECTION)->where('target_id', $collection->id)
            ->orderByDesc('id')->first();
    }

    /**
     * A public collection's link: an ordinary share (hashed token, password, download-off, creator access, Reissue,
     * expiry), so ShareGate and EnforceShareGate apply to it unchanged. 422 for an internal audience, and while a live
     * link exists (reissue or revoke that one instead).
     *
     * @param  array{password_hash?: ?string, allow_download?: bool, requires_creator_access?: bool}  $access
     */
    public function createLink(AssetCollection $collection, Admin $actor, array $access, ?DateTimeInterface $expiresAt, ?string $name): Share
    {
        abort_unless($collection->isPublic(), 422, trans('dam::app.admin.dam.collections.link-needs-public'));

        $share = DB::transaction(function () use ($collection, $actor, $access, $expiresAt, $name) {
            AssetCollection::query()->where('id', $collection->id)->lockForUpdate()->first();

            abort_if(Share::query()->active()->where('share_type', Share::TYPE_COLLECTION)->where('target_id', $collection->id)->exists(),
                422, trans('dam::app.admin.dam.collections.link-exists'));

            return $this->shares->createForCollection(collectionId: (int) $collection->id, expiresAt: $expiresAt, userId: (int) $actor->id, name: $name, access: $access);
        });

        CollectionLog::write('collection.link.created', ['collection_id' => (int) $collection->id, 'actor_id' => (int) $actor->id, 'share_id' => (int) $share->id]);

        return $share;
    }

    /**
     * Null when $actor may redistribute the asset; otherwise the status: 404 for a missing or hidden asset (exactly as
     * a missing one), 403 for one they see but may not redistribute (roadmap §3.3).
     */
    public function redistributeRefusal(Admin $actor, int $assetId): ?int
    {
        $decision = $this->access->for(AccessContext::forAdmin($actor, AccessContext::PURPOSE_SHARE), $assetId);

        if (! $decision->visible || $decision->asset === null) {
            return 404;
        }

        if (CollectionGrant::fullAdmin($actor) || (int) $decision->asset->created_by === (int) $actor->id) {
            return null;
        }

        return $actor->hasPermission('dam.asset.share') ? null : 403;
    }

    private function decisions(): ValueDecisions
    {
        return app(ValueDecisions::class);
    }

    /**
     * Validates, registers (AddressSlugs) and writes the slug. Every slug the collection has held stays registered to
     * it, so an old one keeps redirecting and the collection may take it back; no other collection or share can.
     */
    protected function writeSlug(AssetCollection $collection, ?string $slug): void
    {
        $old = $collection->slug;

        if ($slug === $old) {
            return;
        }

        if ($slug !== null && ($problem = CollectionSlugs::claim(slug: $slug, collectionId: (int) $collection->id)) !== null) {
            throw ValidationException::withMessages(['slug' => trans('dam::app.admin.dam.collections.slug-'.$problem)]);
        }

        try {
            if ($old !== null) {
                // Already registered when it was set; this only registers a slug written before the register existed.
                CollectionSlugs::claim(slug: $old, collectionId: (int) $collection->id);
            }

            AssetCollection::query()->where('id', $collection->id)->update(['slug' => $slug]);
        } catch (QueryException) {
            // Another collection took the same slug between the check and the write: the unique index decides.
            throw ValidationException::withMessages(['slug' => trans('dam::app.admin.dam.collections.slug-taken')]);
        }

        $collection->slug = $slug;

        CollectionLog::write('collection.slug.changed', ['collection_id' => (int) $collection->id, 'had_slug' => $old !== null, 'has_slug' => $slug !== null]);
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     */
    protected function writeAudience(AssetCollection $collection, array $userIds, array $groupIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)));
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn ($id) => $id > 0)));

        abort_if(count($userIds) > self::MAX_PRINCIPALS || count($groupIds) > self::MAX_PRINCIPALS, 422, trans('dam::app.admin.dam.collections.too-many-principals'));

        $users = $userIds === [] ? 0 : DB::table('admins')->whereIn('id', $userIds)->where('status', true)->count();
        $groups = $groupIds === [] ? 0 : DB::table('dam_groups')->whereIn('id', $groupIds)->count();

        abort_if($users !== count($userIds) || $groups !== count($groupIds), 422, trans('dam::app.admin.dam.collections.unknown-principal'));

        DB::table('dam_collection_audience')->where('collection_id', $collection->id)->delete();

        $now = now();
        $rows = array_merge(
            array_map(fn ($id) => ['collection_id' => $collection->id, 'principal_type' => AssetCollection::PRINCIPAL_USER, 'principal_id' => $id, 'created_at' => $now], $userIds),
            array_map(fn ($id) => ['collection_id' => $collection->id, 'principal_type' => AssetCollection::PRINCIPAL_GROUP, 'principal_id' => $id, 'created_at' => $now], $groupIds),
        );

        if ($rows !== []) {
            DB::table('dam_collection_audience')->insert($rows);
        }
    }

    /** The share-link expiry rule: 30 days unless one is named, none only when asked for outright. */
    public static function linkExpiry(?string $expiresAt, bool $noExpiry, ?int $expiryDays): ?Carbon
    {
        if ($expiresAt !== null && $expiresAt !== '') {
            return Carbon::parse($expiresAt);
        }

        if ($noExpiry) {
            return null;
        }

        return now()->addDays(max(1, min(365, $expiryDays ?? 30)));
    }
}
