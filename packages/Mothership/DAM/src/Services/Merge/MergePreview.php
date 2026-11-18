<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Merge;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\User\Models\Admin;

/**
 * What a merge, a split or a destination's merges look like before anyone confirms (P3b-1, D7; audit §3.1). Read
 * only. It names only what the viewer can see: an incoming edge from an asset the viewer cannot open is counted, not
 * named, and a folder link on a folder the viewer cannot open is counted without the folder's name.
 */
class MergePreview
{
    /** How many of a source's comments the destination shows through provenance. */
    private const COMMENTS_SHOWN = 20;

    public function __construct(
        protected MergeGate $gate,
        protected AssetAccessDecision $access,
        protected AssetMergeService $merges,
    ) {}

    /**
     * The merge preview: per source, the files it links, its share links, its incoming edges and who gains or loses
     * sight of its files; for the whole merge, where each tag, property and field comes from.
     *
     * @param  Collection<int, Asset>  $sources
     * @return array<string, mixed>
     */
    public function merge(Asset $destination, Collection $sources, Admin $viewer, bool $unionTags): array
    {
        $nested = AssetMerge::query()->active()->whereIn('dest_id', $sources->pluck('id'))->pluck('dest_id')->map(fn ($id) => (int) $id)->all();
        $rows = $sources->map(function (Asset $source) use ($destination, $viewer, $nested): array {
            $impact = $this->gate->impact(destination: $destination, source: $source);
            $canShare = $this->gate->canShare(actor: $viewer, source: $source);

            return [
                'id'             => (int) $source->id,
                'name'           => (string) $source->display_name,
                'file_name'      => (string) $source->file_name,
                'owner'          => $source->creator?->name,
                'files'          => $this->linkedFiles(source: $source),
                'kept_on_source' => $this->unlinkedFiles(source: $source),
                'comments'       => AssetComments::query()->where('dam_asset_id', $source->id)->count(),
                'links'          => $this->links(source: $source, viewer: $viewer),
                'incoming'       => $this->incomingEdges(source: $source, viewer: $viewer),
                'access'         => [
                    'gains'  => $this->people($impact['gains']),
                    'losses' => $this->people($impact['losses']),
                    // Public links that serve the destination but not this source: counted, never named or linked.
                    'links'  => count($impact['links']),
                    'widens' => MergeGate::widens($impact),
                    // Widening needs sharing authority on this source (ADR 0010): its owner or a full admin.
                    'allowed' => ! MergeGate::widens($impact) || $canShare,
                ],
                'nested' => in_array((int) $source->id, $nested, true),
            ];
        })->values();

        $blocked = match (true) {
            $rows->contains(fn (array $row) => $row['nested'])              => ['status' => 409, 'reason' => 'nested', 'message' => MergeGate::nestedMessage($destination)],
            $rows->contains(fn (array $row) => ! $row['access']['allowed']) => ['status' => 403, 'reason' => 'widening', 'message' => trans('dam::app.admin.dam.asset.merge.widening')],
            default                                                         => null,
        };

        return [
            'destination' => [
                'id'        => (int) $destination->id,
                'name'      => (string) $destination->display_name,
                'file_name' => (string) $destination->file_name,
                'files'     => (int) AssetFile::query()->where('dam_asset_id', $destination->id)->where('is_current', true)->whereNotIn('role', Asset::UNSTACKED_ROLES)->count(),
            ],
            'sources'    => $rows->all(),
            'metadata'   => [
                'tags'       => $this->tags(destination: $destination, sources: $sources, unionTags: $unionTags),
                'properties' => $this->properties(destination: $destination, sources: $sources),
                'fields'     => $this->fields(destination: $destination, sources: $sources),
            ],
            'union_tags' => $unionTags,
            'blocked'    => $blocked,
        ];
    }

    /**
     * The destination's merges that are not split, for its "Merged from N assets" list: each source by the name it had
     * and the files it gave. An active one adds its comments (shown here through provenance; they stay on the source)
     * and its links still working; a finalized one stays listed as finalized, its comments now copied into the
     * destination's own comments.
     *
     * @return list<array<string, mixed>>
     */
    public function merges(Asset $destination, Admin $viewer): array
    {
        $merges = AssetMerge::query()->where('dest_id', $destination->id)->whereNull('split_at')
            ->with(['merger:id,name', 'source'])->orderBy('id')->get();

        return $merges->map(function (AssetMerge $merge) use ($viewer): array {
            $source = $merge->source;
            $active = $merge->isActive();
            $comments = $active
                ? AssetComments::query()->where('dam_asset_id', $merge->source_id)->with('admin:id,name')->orderByDesc('id')->limit(self::COMMENTS_SHOWN)->get()
                : collect();

            return [
                'id'           => (int) $merge->id,
                'status'       => $active ? 'active' : 'finalized',
                'source'       => [
                    'id'        => (int) $merge->source_id,
                    'name'      => (string) ($merge->snapshot['source']['display_name'] ?? $source?->display_name ?? ''),
                    'file_name' => (string) ($merge->snapshot['source']['file_name'] ?? $source?->file_name ?? ''),
                ],
                'merged_at'    => $merge->merged_at?->toIso8601String(),
                'merged_by'    => $merge->merger?->name,
                'finalized_at' => $merge->finalized_at?->toIso8601String(),
                'files'        => $active
                    ? Attachment::query()->where('dam_asset_id', $merge->dest_id)->where('merge_id', $merge->id)->count()
                    : count($merge->snapshot['slots'] ?? []),
                'comments'     => [
                    'count' => $active
                        ? AssetComments::query()->where('dam_asset_id', $merge->source_id)->count()
                        : (int) ($merge->snapshot['finalized']['comments_copied'] ?? 0),
                    'items' => $comments->map(fn (AssetComments $comment) => [
                        'id'         => (int) $comment->id,
                        'author'     => $comment->admin?->name,
                        'text'       => (string) $comment->comments,
                        'created_at' => $comment->created_at?->toIso8601String(),
                    ])->values()->all(),
                ],
                'active_links'     => $active ? Share::query()->active()->where('share_type', Share::TYPE_ASSET)->where('target_id', $merge->source_id)->count() : 0,
                'can_revoke_links' => $active && $source !== null && $this->gate->canShare(actor: $viewer, source: $source),
            ];
        })->values()->all();
    }

    /**
     * The split preview: each slot the merge gave, whether it gained revisions since (those ask where they go), and
     * which must stay; plus the tags and properties the split takes back from the destination.
     *
     * @return array<string, mixed>
     */
    public function split(AssetMerge $merge): array
    {
        $plan = $this->merges->splitPlan($merge);
        $takeBack = $this->merges->takeBackPlan($merge);
        $tags = collect(array_chunk($takeBack['tags'], AssetMergeService::ID_CHUNK))
            ->flatMap(fn (array $chunk) => Tag::query()->whereIn('id', $chunk)->pluck('name'))->sort()->values()->all();

        return [
            'merge'      => ['id' => (int) $merge->id, 'source' => ['id' => (int) $merge->source_id, 'name' => (string) ($merge->snapshot['source']['display_name'] ?? '')]],
            'slots'      => array_map(fn (array $slot) => array_merge(array_diff_key($slot, ['new_revision_ids' => true]), [
                'asks'    => $slot['pinned'] === null && $slot['new_revisions'] > 0,
                'default' => AssetMergeService::KEEP_ON_DESTINATION,
            ]), $plan),
            'tags_removed'       => $tags,
            'properties_removed' => collect($takeBack['properties'])->where('action', 'remove')->pluck('name')->values()->all(),
            'sha256'             => $merge->snapshot['sha256'] ?? [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function linkedFiles(Asset $source): array
    {
        return Attachment::query()->where('dam_asset_id', $source->id)->whereNotIn('role', AssetMergeService::UNLINKED_ROLES)
            ->with(['currentRevision.fileObject'])->withCount('revisions')
            ->orderByDesc('is_representative')->orderBy('position')->orderBy('id')->get()
            ->filter(fn (Attachment $slot) => $slot->revisions_count > 0)
            ->map(fn (Attachment $slot) => [
                'attachment_id'  => (int) $slot->id,
                'role'           => $slot->role,
                'role_on_merge'  => $slot->is_representative ? AssetFile::ROLE_OTHER : $slot->role,
                'label'          => $slot->is_representative ? ($slot->label ?: (string) $source->file_name) : ($slot->label ?: $slot->currentRevision?->label),
                'sha256'         => $slot->currentRevision?->fileObject?->sha256,
                'size'           => $slot->currentRevision?->fileObject?->size,
                'revisions'      => (int) $slot->revisions_count,
            ])->values()->all();
    }

    /** The derived previews (proxy, thumbnail) a merge leaves on the source. @return list<array<string, mixed>> */
    private function unlinkedFiles(Asset $source): array
    {
        return Attachment::query()->where('dam_asset_id', $source->id)->whereIn('role', AssetMergeService::UNLINKED_ROLES)
            ->orderBy('position')->orderBy('id')->get(['id', 'role', 'label'])
            ->map(fn (Attachment $slot) => ['attachment_id' => (int) $slot->id, 'role' => $slot->role, 'label' => $slot->label])->values()->all();
    }

    /**
     * The source's links. Its own (asset) links stay on it and keep serving only its own files until revoked
     * (operator decision Q4); a folder link stops listing it while it is merged. A link's URL is never part of this.
     *
     * @return array<string, mixed>
     */
    private function links(Asset $source, Admin $viewer): array
    {
        $direct = Share::query()->active()->where('share_type', Share::TYPE_ASSET)->where('target_id', $source->id)
            ->with('creator:id,name')->orderBy('id')->get();

        return [
            'direct' => $direct->map(fn (Share $share) => [
                'id'         => (int) $share->id,
                'name'       => $share->name,
                'created_by' => $share->creator?->name,
                'expires_at' => $share->expires_at?->toIso8601String(),
            ])->values()->all(),
            'folders' => app(AccessListService::class)->publicLinks(type: 'asset', id: (int) $source->id, actor: $viewer)['inherited'],
        ];
    }

    /**
     * Edges into the source. They stay on it and are shown, not re-pointed: a split finds them as they were. An edge
     * from an asset the viewer cannot open is counted, never named.
     *
     * @return array{edges: list<array<string, mixed>>, restricted: int}
     */
    private function incomingEdges(Asset $source, Admin $viewer): array
    {
        $context = AccessContext::forAdmin(admin: $viewer);
        $edges = AssetRelationship::query()->where('to_asset_id', $source->id)->orderBy('id')->get();
        $named = [];
        $restricted = 0;

        foreach ($edges as $edge) {
            $from = $this->access->for($context, (int) $edge->from_asset_id);

            if (! $from->visible) {
                $restricted++;

                continue;
            }

            $named[] = ['id' => (int) $edge->id, 'type' => $edge->type, 'from' => ['id' => (int) $from->asset->id, 'name' => (string) $from->asset->display_name]];
        }

        return ['edges' => $named, 'restricted' => $restricted];
    }

    /**
     * Where each tag comes from. With the union, a source's tag the destination lacks is added; without it, the
     * destination's tags are all there is.
     *
     * @param  Collection<int, Asset>  $sources
     * @return list<array<string, mixed>>
     */
    private function tags(Asset $destination, Collection $sources, bool $unionTags): array
    {
        $byAsset = DB::table('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
            ->whereIn('dam_asset_tag.asset_id', [$destination->id, ...$sources->pluck('id')->all()])
            ->orderBy('dam_tags.name')->get(['dam_asset_tag.asset_id', 'dam_tags.id', 'dam_tags.name']);
        $tags = [];

        foreach ($byAsset as $row) {
            $tags[(int) $row->id] ??= ['name' => (string) $row->name, 'destination' => false, 'sources' => []];

            if ((int) $row->asset_id === (int) $destination->id) {
                $tags[(int) $row->id]['destination'] = true;
            } else {
                $tags[(int) $row->id]['sources'][] = (int) $row->asset_id;
            }
        }

        return array_values(array_map(fn (array $tag) => $tag + [
            'result' => $tag['destination'] ? 'kept' : ($unionTags ? 'added' : 'not_added'),
        ], $tags));
    }

    /**
     * Where each property comes from, keyed by name and language. The destination's value wins a conflict; a property
     * it lacks comes from the first source that has one. A conflict is listed so it is seen before confirming.
     *
     * @param  Collection<int, Asset>  $sources
     * @return list<array<string, mixed>>
     */
    private function properties(Asset $destination, Collection $sources): array
    {
        $rows = AssetProperty::query()->whereIn('dam_asset_id', [$destination->id, ...$sources->pluck('id')->all()])->orderBy('id')->get();
        $order = [(int) $destination->id, ...$sources->pluck('id')->map(fn ($id) => (int) $id)->all()];
        $byKey = [];

        foreach ($rows->sortBy(fn (AssetProperty $row) => [array_search((int) $row->dam_asset_id, $order, true), $row->id]) as $row) {
            $key = AssetMergeService::propertyKey($row);
            $byKey[$key] ??= ['name' => (string) $row->name, 'language' => $row->language, 'destination' => null, 'values' => []];

            if ((int) $row->dam_asset_id === (int) $destination->id) {
                $byKey[$key]['destination'] ??= (string) $row->value;
            } else {
                $byKey[$key]['values'][] = ['source_id' => (int) $row->dam_asset_id, 'value' => (string) $row->value];
            }
        }

        return array_values(array_map(function (array $property): array {
            $result = $property['destination'] !== null ? 'destination' : 'source:'.$property['values'][0]['source_id'];
            $winner = $property['destination'] ?? $property['values'][0]['value'];

            return $property + [
                'result'   => $result,
                'value'    => $winner,
                'conflict' => collect($property['values'])->contains(fn (array $value) => $value['value'] !== $winner),
            ];
        }, $byKey));
    }

    /**
     * The destination's controlled fields win (custom fields arrive in P4; today the stack basis and key are the only
     * ones). A source's differing value is listed so it is seen before confirming; it stays on the source.
     *
     * @param  Collection<int, Asset>  $sources
     * @return list<array<string, mixed>>
     */
    private function fields(Asset $destination, Collection $sources): array
    {
        $value = fn (Asset $asset) => $asset->stack_basis === null ? null : $asset->stack_basis.' · '.$asset->stack_key;
        $values = $sources->map(fn (Asset $source) => ['source_id' => (int) $source->id, 'value' => $value($source)])
            ->filter(fn (array $row) => $row['value'] !== null)->values()->all();

        if ($values === [] && $value($destination) === null) {
            return [];
        }

        return [[
            'field'       => 'stack',
            'destination' => $value($destination),
            'values'      => $values,
            'result'      => 'destination',
            'conflict'    => collect($values)->contains(fn (array $row) => $row['value'] !== $value($destination)),
        ]];
    }

    /** @param  list<Admin>  $admins */
    private function people(array $admins): array
    {
        return array_map(fn (Admin $admin) => ['id' => (int) $admin->id, 'name' => (string) $admin->name], $admins);
    }
}
