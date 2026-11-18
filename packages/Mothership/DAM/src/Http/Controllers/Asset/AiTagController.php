<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiBridgeUnavailable;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\AiIndex\MemberDescriptions;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Tags\AiTagReview;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Accept turns one AI vocabulary suggestion into a tag (source `ai:<model>`); dismiss hides it; accept-all takes every
 * open one; describe asks the AI for a file's description and suggestions. Same gate as TagController. The decision itself is AiTagReview's, the code path the AI tag review queue shares (P5c).
 */
class AiTagController extends Controller
{
    use AssetAccessControl;

    public function __construct(
        protected AiTagReview $review,
        protected AiIndexStore $store,
    ) {}

    /** The asset page's AI card: what the AI said, and whether a person here can ask it or review it. */
    public function show(int $id): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $this->authorizeAsset(id: $id);

        return response()->json($this->card(id: $id));
    }

    /**
     * One stacked file's own description (it has no asset row: AiIndex\MemberDescriptions). Reading needs the same access as
     * the file list; describing is tagging, as for the cover, so own-only roles cannot describe another person's stack.
     */
    public function member(int $id, int $fileId, MemberDescriptions $members): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
        abort_unless(bouncer()->hasPermission('dam.asset.files.index'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $this->authorizeAsset(id: $id);
        $asset = Asset::query()->findOrFail($id);

        return response()->json($members->card(asset: $asset, file: MemberDescriptions::resolve(asset: $asset, fileId: $fileId)));
    }

    public function describeMember(int $id, int $fileId, MemberDescriptions $members, AiBridgeClient $bridge): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.files.index'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $this->authorizeTagging(id: $id);
        $asset = Asset::query()->findOrFail($id);
        $card = $members->card(asset: $asset, file: MemberDescriptions::resolve(asset: $asset, fileId: $fileId));

        // Never trust the client: a file the AI cannot describe (yet) is refused here, with the server's reading of it.
        if ($card['ai'] === null && ! $card['eligibility']['can_generate'] && in_array($card['eligibility']['state'], [AiEligibility::UNSUPPORTED, AiEligibility::AWAITING_PREVIEW], true)) {
            return response()->json(['message' => $card['eligibility']['message']] + $card, 422);
        }

        if ($card['ai'] !== null) {
            return response()->json($card);
        }

        abort_unless($bridge->ready(), 503, trans('dam::app.admin.dam.asset.edit.ai.unavailable'));

        try {
            return response()->json($members->describe(asset: $asset, fileId: $fileId));
        } catch (AiBridgeUnavailable) {
            return response()->json(['message' => trans('dam::app.admin.dam.asset.edit.ai.member.failed'), 'retryable' => true] + $card, 503);
        }
    }

    /**
     * "Create AI description and tags": queues the same index job an upload does, for one file, even with automatic
     * indexing off. A run already queued or underway is left to finish. A file the AI already found no picture in is
     * refused, unless `retry` asks for the one manual try each content is allowed (`eligibility.can_retry`).
     */
    public function describe(Request $request, int $id, AiBridgeClient $bridge): JsonResponse
    {
        $this->authorizeTagging(id: $id);
        $eligibility = app(AiEligibility::class)->forAsset(Asset::query()->findOrFail($id));

        // A file the AI has no picture of (yet) is not queued: the run could only end without a description.
        if (in_array($eligibility['state'], [AiEligibility::UNSUPPORTED, AiEligibility::AWAITING_PREVIEW], true)) {
            return response()->json(['message' => $eligibility['message'], 'eligibility' => $eligibility] + $this->card(id: $id), 422);
        }

        // Asking again says the same, unless this is the one try a person gets for these bytes.
        $retry = $eligibility['state'] === AiEligibility::FAILED && $eligibility['reason'] === AiEligibility::NO_IMAGE;

        if ($retry && ! ($request->boolean('retry') && $eligibility['can_retry'])) {
            return response()->json(['message' => $eligibility['message'], 'eligibility' => $eligibility] + $this->card(id: $id), 422);
        }

        abort_unless($bridge->ready(), 503, trans('dam::app.admin.dam.asset.edit.ai.unavailable'));

        // One caller wins the claim (a conditional update, so it holds under concurrent requests); a run already queued
        // or underway is left to finish, a failed file's spent tries start over, and a run whose job is gone is taken over.
        // The token it returns is the job's: a run queued before it ends the moment it finds the token is not its own.
        if ($token = $this->store->claim(assetId: $id, retrySha: $retry ? $this->currentSha(id: $id) : null)) {
            // The row the job reads exists before it runs.
            $this->store->refreshLexical(Asset::query()->findOrFail($id));
            AiIndexAsset::dispatch($id, $token);
        }

        return response()->json($this->card(id: $id));
    }

    /**
     * Which of the posted files (`ids`, at most 500) the AI can describe or rename, by id, in the shape of the card's
     * `eligibility`. A missing or hidden id is left out, so the answer tells nothing the Library would not show.
     */
    public function eligibility(Request $request): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer']);

        $ids = collect($request->input('ids'))->map(fn ($id) => (int) $id)->unique()->values();
        $assets = Asset::query()->whereIn('id', $ids)->get()->filter(fn (Asset $asset) => $this->damCanAccessAsset((int) $asset->id));

        return response()->json([
            'eligibility' => app(AiEligibility::class)->forAssets($assets),
            'available'   => app(AiBridgeClient::class)->health(),
        ]);
    }

    /** Accepts every open suggestion on one file, each through the same review step as a single accept. */
    public function acceptAll(int $id): JsonResponse
    {
        $this->authorizeTagging(id: $id);

        $items = array_map(fn ($tag) => ['asset_id' => $id, 'term' => $tag['term']], $this->store->suggestedTags($id));
        $result = $this->review->bulk(action: 'accept', items: $items, context: AccessContext::library(), actorId: auth('admin')->id());

        return response()->json([
            'success' => true,
            'done'    => $result['done'],
            'total'   => count($items),
            'tags'    => Asset::query()->findOrFail($id)->tags()->get()
                ->map(fn ($tag) => ['id' => (int) $tag->id, 'name' => (string) $tag->name])->values()->all(),
        ] + $this->card(id: $id));
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $term = $this->authorizeTerm(request: $request, id: $id);
        $this->review->accept(assetId: $id, term: $term, actorId: auth('admin')->id());

        return response()->json(['success' => true, 'ai' => $this->store->infoFor(assetId: $id, canReview: true)]);
    }

    public function dismiss(Request $request, int $id): JsonResponse
    {
        $term = $this->authorizeTerm(request: $request, id: $id);
        $this->review->dismiss(assetId: $id, term: $term, actorId: auth('admin')->id());

        return response()->json(['success' => true, 'ai' => $this->store->infoFor(assetId: $id, canReview: true)]);
    }

    private function authorizeTerm(Request $request, int $id): string
    {
        $request->validate(['term' => 'required|string|max:64']);
        $this->authorizeTagging(id: $id);

        return $this->review->openTerm(assetId: $id, term: (string) $request->input('term'));
    }

    private function currentSha(int $id): ?string
    {
        try {
            return app(ContentStore::class)->current(Asset::query()->findOrFail($id))->sha256;
        } catch (ContentUnavailable) {
            return null;
        }
    }

    private function authorizeTagging(int $id): void
    {
        // Reviewing AI tags is tagging: its own permission (P4 D8), apart from editing the asset.
        abort_unless(bouncer()->hasPermission('dam.asset.tag'), 403, trans('dam::app.admin.permissions.unauthorized'));
        $this->authorizeAsset(id: $id);
        // Accepting, dismissing or asking for tags changes the file's tags: only the person who added it, when limited to own items.
        app(RoleBuilder::class)->abortUnlessOwn(model: Asset::query()->findOrFail($id));
    }

    private function authorizeAsset(int $id): void
    {
        // Same answer for a missing and a hidden file.
        abort_unless(Asset::query()->whereKey($id)->exists() && $this->damCanAccessAsset($id), 404);
    }

    /** @return array{ai: ?array, available: ?bool, eligibility: array, can_review: bool} */
    private function card(int $id): array
    {
        $canReview = bouncer()->hasPermission('dam.asset.tag');

        return [
            'ai'          => $this->store->infoFor(assetId: $id, canReview: $canReview),
            'available'   => app(AiBridgeClient::class)->health(),
            'eligibility' => app(AiEligibility::class)->forAsset(Asset::query()->findOrFail($id)),
            'can_review'  => $canReview,
        ];
    }
}
