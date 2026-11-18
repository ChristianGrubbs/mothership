<?php

namespace Mothership\DAM\Services\Collections;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\CollectionMember;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\CollectionGrant;
use Mothership\DAM\Services\Share\ShareAccessOptions;
use Mothership\DAM\Services\Share\SharePlayback;
use Mothership\User\Models\Admin;

/**
 * P4 D2/D23: what the web page and REST show of a collection, in one place.
 *
 * - **Counts** are what the viewer is served: the seam's collection listing, so a withdrawn, pending, out-of-window
 *   or trashed member is never counted, and a closed collection counts nothing. A manager also gets `total_members`,
 *   the members the collection holds, so a closed one is not shown as empty.
 * - **Managers only:** the audience, the full member list (each flagged `servable`), and the link. A member that is
 *   not served is named only when the manager can open it in the Library; otherwise it is an unnamed row they can
 *   remove.
 * - **Link URL (#126 rule):** the capability URL goes only to the link's creator, the collection's owner, or a full
 *   admin (Share::tokenVisibleTo()); anyone else who may see the link gets its state without the URL.
 */
class CollectionPresenter
{
    public function __construct(
        protected CollectionService $collections,
        protected AssetAccessDecision $access,
    ) {}

    public function summary(AssetCollection $collection, Admin $actor): array
    {
        $manages = $this->collections->manages(collection: $collection, actor: $actor);

        $summary = [
            'id'           => (int) $collection->id,
            'name'         => (string) $collection->name,
            'description'  => $collection->description,
            'audience'     => (string) $collection->audience,
            'status'       => (string) $collection->status,
            'state'        => $collection->statusLabel(),
            'expires_at'   => $collection->expires_at?->toIso8601String(),
            'slug'         => $collection->slug,
            'slug_url'     => $collection->slug === null ? null : route('dam.collections.slug', ['slug' => $collection->slug]),
            'owner'        => $collection->owner === null ? null : ['id' => (int) $collection->owner->id, 'name' => (string) $collection->owner->name],
            'can_manage'   => $manages,
            'member_count' => $this->collections->servableCount(collection: $collection, context: $this->context(collection: $collection, actor: $actor)),
            'url'          => route('admin.dam.collections.show', $collection->id),
            'zip_url'      => route('admin.dam.collections.zip', $collection->id),
            'updated_at'   => $collection->updated_at?->toIso8601String(),
        ];

        // A manager also gets the members the collection holds, which a closed one still has though it serves none.
        return $manages
            ? $summary + ['total_members' => CollectionMember::query()->where('collection_id', $collection->id)->count()]
            : $summary;
    }

    public function detail(AssetCollection $collection, Admin $actor, bool $api = false): array
    {
        $summary = $this->summary(collection: $collection, actor: $actor);

        if (! $summary['can_manage']) {
            return $summary;
        }

        $link = $this->collections->link($collection);

        return $summary + [
            'audience_principals' => $this->collections->audience($collection),
            'link'                => $link === null ? null : $this->link(share: $link, actor: $actor, api: $api),
        ] + ($api ? [] : [
            'update_url'   => route('admin.dam.collections.update', $collection->id),
            'audience_url' => route('admin.dam.collections.audience', $collection->id),
            'order_url'    => route('admin.dam.collections.members.order', $collection->id),
            'link_url'     => route('admin.dam.collections.link.store', $collection->id),
        ]);
    }

    /**
     * The members in position order. The audience gets the servable ones; a manager gets every row, each flagged.
     *
     * @return list<array<string, mixed>>
     */
    public function members(AssetCollection $collection, Admin $actor): array
    {
        $served = $this->collections->servableMembers(collection: $collection, context: $this->context(collection: $collection, actor: $actor))
            ->get()
            ->keyBy('id');

        if (! $this->collections->manages(collection: $collection, actor: $actor)) {
            return $served->values()->map(fn (Asset $asset) => $this->member(collection: $collection, asset: $asset, position: (int) $asset->member_position))->all();
        }

        $library = AccessContext::forAdmin($actor);

        return CollectionMember::query()->where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->get()
            ->map(function (CollectionMember $row) use ($collection, $served, $library) {
                if ($served->has($row->asset_id)) {
                    return $this->member(collection: $collection, asset: $served->get($row->asset_id), position: (int) $row->position);
                }

                $decision = $this->access->for($library, (int) $row->asset_id);
                $asset = $decision->visible ? $decision->asset : null;

                return [
                    'id'        => (int) $row->asset_id,
                    'name'      => $asset?->display_name,
                    'file_name' => $asset?->file_name,
                    'file_type' => $asset?->file_type,
                    'position'  => (int) $row->position,
                    'servable'  => false,
                    // Withdrawn, pending, out of its window or trashed: the manager is not told which unless the Library shows it.
                    'reason'    => $collection->isLive() ? 'not_served' : 'collection_closed',
                ];
            })->values()->all();
    }

    /** A link's state; its URL only to the #126 rule's three (the creator, the collection's owner, a full admin). */
    public function link(Share $share, Admin $actor, bool $api): array
    {
        $token = $share->tokenVisibleTo(adminId: (int) $actor->id, fullAdmin: CollectionGrant::fullAdmin($actor)) ? $share->urlToken() : null;

        return [
            'id'         => (int) $share->id,
            'token'      => $token,
            'public_url' => $token !== null ? route('dam.share.show', ['token' => $token]) : null,
            'name'       => $share->name,
            'status'     => $share->statusLabel(),
            'expires_at' => $share->expires_at?->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'view_count' => (int) $share->view_count,
            'created_by' => $share->created_by === null ? null : (int) $share->created_by,
        ] + ShareAccessOptions::present($share) + ($api ? [] : [
            'update_url'      => route('admin.dam.shared-links.update', $share->id),
            'reissue_url'     => route('admin.dam.shared-links.reissue', $share->id),
            'revoke_url'      => route('admin.dam.shared-links.revoke', $share->id),
            'reauthorize_url' => route('admin.dam.shared-links.reauthorize', $share->id),
        ]);
    }

    protected function member(AssetCollection $collection, Asset $asset, int $position): array
    {
        $params = ['id' => $collection->id, 'assetId' => $asset->id];

        return [
            'id'            => (int) $asset->id,
            'name'          => (string) $asset->display_name,
            'file_name'     => (string) $asset->file_name,
            'file_type'     => $asset->file_type,
            'extension'     => strtolower((string) $asset->extension),
            'mime_type'     => $asset->mime_type,
            'position'      => $position,
            'servable'      => true,
            'thumbnail_url' => route('admin.dam.collections.members.thumbnail', $params),
            'preview_url'   => route('admin.dam.collections.members.preview', $params),
            'download_url'  => route('admin.dam.collections.members.download', $params),
            // What the player streams: the published proxy when there is one (P2 S6), else the original inline.
            'playback_url'  => $asset->file_type === 'video' && SharePlayback::proxyFile($asset) !== null
                ? route('admin.dam.collections.members.proxy', $params)
                : route('admin.dam.collections.members.preview', $params),
        ];
    }

    protected function context(AssetCollection $collection, Admin $actor): AccessContext
    {
        return AccessContext::collection(collectionId: (int) $collection->id, purpose: AccessContext::PURPOSE_LIST, viewer: $actor);
    }
}
