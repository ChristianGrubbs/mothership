<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Merge\AssetMergeService;
use Mothership\DAM\Services\Merge\MergeGate;
use Mothership\DAM\Services\Merge\MergePreview;
use Mothership\DAM\Services\Similarity\SimilarImages;
use Mothership\User\Models\Admin;

/**
 * Merge existing assets into a stack, then split or finalize (P3b-1, D7), from the Library and the asset page. The
 * REST twin is API\Asset\MergeController; both go through MergeGate and AssetMergeService, so they apply the same
 * rules. Every action needs `dam.asset.edit` and the right to change each participant (a hidden one answers 404).
 */
class AssetMergeController
{
    public function __construct(
        protected MergeGate $gate,
        protected MergePreview $preview,
        protected AssetMergeService $merges,
        protected AssetAccessDecision $access,
    ) {}

    /** The destination's merges that are not split, active and finalized, for its "Merged from N assets" list. */
    public function index(int $asset): JsonResponse
    {
        $destination = $this->access->for(AccessContext::library(), $asset)->visibleOrFail();

        return $this->respond([
            'merges'   => $this->preview->merges(destination: $destination, viewer: $this->actor()),
            // Where a split file lands, named in the "Remove from stack" confirmation.
            'folder'   => $destination->directories()->orderBy('dam_directories.id')->value('dam_directories.name'),
            'can_edit' => $this->mayEdit()
                && $this->access->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $destination)->allowed,
        ]);
    }

    /** What merging the sources into this asset would do, before anyone confirms. */
    public function preview(Request $request, int $asset): JsonResponse
    {
        $this->assertMayEdit();
        [$destination, $sources] = $this->gate->participants($asset, MergeGate::sourceIds($request->query('sources'), $asset));
        $review = self::review(request: $request, keyField: 'review_key', membersField: 'review_members');

        if ($review !== null) {
            $this->assertReviewed(review: $review, ids: [$asset, ...$sources->pluck('id')->map(fn ($id) => (int) $id)->all()]);
        }

        return $this->respond($this->preview->merge(destination: $destination, sources: $sources, viewer: $this->actor(), unionTags: $request->boolean('union_tags', true)));
    }

    public function store(Request $request, int $asset): JsonResponse
    {
        $this->assertMayEdit();
        [$destination, $sources] = $this->gate->participants($asset, MergeGate::sourceIds($request->input('sources'), $asset));
        $this->gate->assertMayMerge(actor: $this->actor(), destination: $destination, sources: $sources);
        $sourceIds = $sources->pluck('id')->map(fn ($id) => (int) $id)->all();
        $review = self::review(request: $request, keyField: 'review.group_key', membersField: 'review.members');

        $merges = $this->merges->merge(
            destination: $destination,
            sourceIds: $sourceIds,
            unionTags: $request->boolean('union_tags', true),
            actor: $this->actor(),
            via: $this->via(),
            // Decided again once the rows are locked: a reviewed group that changed since the preview merges nothing.
            revalidate: $review === null ? null : fn () => $this->assertReviewed(review: $review, ids: [$asset, ...$sourceIds]),
        );

        return $this->respond([
            'message' => trans_choice('dam::app.admin.dam.asset.merge.merged', $merges->count(), ['count' => $merges->count()]),
            'merges'  => $this->preview->merges(destination: $destination, viewer: $this->actor()),
        ], 201);
    }

    /** The split preview: per slot, whether it gained revisions since the merge, and so asks where they go. */
    public function splitPreview(int $asset, int $merge): JsonResponse
    {
        $this->assertMayEdit();

        return $this->respond($this->preview->split($this->gate->activeMerge(destination: $this->destination($asset), mergeId: $merge)));
    }

    public function split(Request $request, int $asset, int $merge): JsonResponse
    {
        $this->assertMayEdit();
        $active = $this->gate->activeMerge(destination: $this->destination($asset), mergeId: $merge);
        $this->merges->split(merge: $active, choices: AssetMergeService::choices($request->input('choices')), actor: $this->actor(), via: $this->via());

        return $this->respond([
            'message' => trans('dam::app.admin.dam.asset.merge.split-done'),
            'merges'  => $this->preview->merges(destination: Asset::query()->findOrFail($asset), viewer: $this->actor()),
        ]);
    }

    /** Irreversible: needs `confirm: true` in the request, so no stray request finalizes a merge. */
    public function finalize(Request $request, int $asset, int $merge): JsonResponse
    {
        $this->assertMayEdit();
        $active = $this->gate->activeMerge(destination: $this->destination($asset), mergeId: $merge);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $this->merges->finalize(merge: $active, actor: $this->actor(), via: $this->via());

        return $this->respond([
            'message' => trans('dam::app.admin.dam.asset.merge.finalized'),
            'merges'  => $this->preview->merges(destination: Asset::query()->findOrFail($asset), viewer: $this->actor()),
        ]);
    }

    /** Revokes the merged source's own links; that needs sharing authority on the source (its owner or a full admin). */
    public function revokeLinks(int $asset, int $merge): JsonResponse
    {
        $this->assertMayEdit();
        $active = $this->gate->activeMerge(destination: $this->destination($asset), mergeId: $merge);
        if ($active->source === null || ! $this->gate->canShare(actor: $this->actor(), source: $active->source)) {
            abort(response()->json(['success' => false, 'message' => trans('dam::app.admin.dam.access.not-owner')], 403));
        }
        $count = $this->merges->revokeSourceLinks(merge: $active, actor: $this->actor(), via: $this->via());

        return $this->respond([
            'message' => trans_choice('dam::app.admin.dam.asset.merge.links-revoked', $count, ['count' => $count]),
            'revoked' => $count,
        ]);
    }

    /**
     * The near-duplicate group this confirmation was opened from (Similar images / Suggested stacks), when it names one: its key
     * and the members the viewer reviewed. Absent for every other merge.
     *
     * @return array{key: string, members: list<int>}|null
     */
    protected static function review(Request $request, string $keyField, string $membersField): ?array
    {
        if (! $request->has($keyField) && ! $request->has($membersField)) {
            return null;
        }

        $request->validate([
            $keyField           => ['required', 'string', 'max:64'],
            $membersField       => ['required', 'array', 'min:2', 'max:'.SimilarImages::GROUP_MAX_MEMBERS],
            $membersField.'.*'  => ['integer', 'min:1'],
        ]);

        return ['key' => (string) $request->input($keyField), 'members' => array_map('intval', (array) $request->input($membersField))];
    }

    /**
     * A reviewed group may be merged only as reviewed: its key must be the one of the members sent, nothing outside them may be
     * merged (the set can shrink, never widen), and every pair among the files being merged must still be similar and not
     * dismissed, each seen by this viewer. Anything else is a changed group (409) and nothing is merged.
     *
     * @param  array{key: string, members: list<int>}  $review
     * @param  list<int>  $ids  the destination and sources being merged
     */
    protected function assertReviewed(array $review, array $ids): void
    {
        $members = array_values(array_unique($review['members']));
        sort($members);
        $context = $this->via() === AccessContext::VIA_API ? AccessContext::api(AccessContext::PURPOSE_LIST) : AccessContext::library(AccessContext::PURPOSE_LIST);
        $changed = ! hash_equals(SimilarImages::groupKey($members), $review['key']) || array_diff($ids, $members) !== []
            || app(SimilarImages::class)->reviewedEdges(viewer: $this->actor(), context: $context, ids: $ids, applyDismissals: true) === null;

        if ($changed) {
            SimilarImages::changed();
        }
    }

    /** The destination, when the viewer may change it: 404 when hidden or missing, 403 when only visible. */
    protected function destination(int $asset): Asset
    {
        return $this->access->for(AccessContext::library(AccessContext::PURPOSE_EDIT), $asset)->allowedOrFail();
    }

    protected function assertMayEdit(): void
    {
        abort_unless($this->mayEdit(), 403, trans('dam::app.admin.permissions.denied-merge'));
    }

    /** The role's edit permission. The REST twin answers true: its api.scope middleware checks the key per route. */
    protected function mayEdit(): bool
    {
        return (bool) bouncer()->hasPermission('dam.asset.edit');
    }

    protected function actor(): Admin
    {
        return auth()->guard('admin')->user();
    }

    /** The surface, whose key the service asks for again under the lock: the role's in the Library, the API key's over REST. */
    protected function via(): string
    {
        return AccessContext::VIA_LIBRARY;
    }

    /** @param  array<string, mixed>  $data */
    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }
}
