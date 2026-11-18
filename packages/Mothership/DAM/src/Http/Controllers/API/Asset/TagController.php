<?php

namespace Mothership\DAM\Http\Controllers\API\Asset;

use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Repositories\AssetTagRepository;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\DAM\Services\Tags\TagVocabulary;
use Mothership\DAM\Traits\AssetAccessControl;

class TagController extends Controller
{
    use AssetAccessControl;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetTagRepository $assetTagRepository,
        protected AssetTagWriter $tagWriter,
        protected TagVocabulary $vocabulary,
    ) {}

    public function allTags(Request $request)
    {
        $search = trim((string) $request->input('query', ''));

        $perPage = (int) $request->input('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        $query = Tag::query()->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $paginator = $query->paginate($perPage, ['id', 'name']);

        return response()->json([
            'success'      => true,
            'data'         => $paginator->getCollection()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                ->values(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
        ], 200);
    }

    public function tags(int $id)
    {
        $tags = $this->assetTagRepository->find($id);

        if (! $tags) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.tags.not-found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.tags.found-success'),
            'data'    => $tags,
        ], 200);
    }

    public function addTag(Request $request)
    {
        $request->validate([
            'tag'      => ['required', new TagNameRule],
            // VisibleAsset, not `exists`: a hidden asset fails exactly as a missing one does.
            'asset_id' => ['required', new VisibleAsset],
        ]);

        $assetId = $request->get('asset_id');

        $this->damAuthorizeAsset((int) $assetId);

        $asset = $this->assetRepository->find($assetId);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $tag = $this->tagWriter->resolve($request->get('tag'))->first();

        // One caller adding one tag to one asset: an earlier removal of it is cleared (P4 D8).
        if (! $this->tagWriter->addManual(asset: $asset, tag: $tag, actorId: auth()->id())) {
            return response()->json([
                'success' => false,
                'file'    => $asset->load('tags'),
                'message' => trans('dam::app.admin.dam.asset.edit.tag-already-exists'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.tags.create.create-success'),
            'file'    => $asset->load('tags'),
        ], 201);
    }

    public function removeTag(Request $request)
    {
        $request->validate([
            'tag'      => 'required',
            // VisibleAsset, not `exists`: a hidden asset fails exactly as a missing one does.
            'asset_id' => ['required', new VisibleAsset],
        ]);

        $assetId = $request->get('asset_id');

        $this->damAuthorizeAsset((int) $assetId);

        $asset = $this->assetRepository->find($assetId);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $assetTag = $this->assetTagRepository->whereRaw('LOWER(name) = ?', [mb_strtolower(TagNameRule::normalize((string) $request->get('tag')))])->first();

        if (! $assetTag) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.tags.not-found'),
            ], 404);
        }

        // Recorded as a removal, so no re-index, AI accept, rule, import or bulk add puts it back (P4 D8).
        $this->tagWriter->remove(asset: $asset, tag: $assetTag, actorId: auth()->id());

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.asset.tags.delete-success'),
        ], 201);
    }

    public function bulkAssign(Request $request)
    {
        $request->validate([
            'asset_ids'   => 'required|array|min:1|max:500',
            'asset_ids.*' => 'integer|min:1',
            'tags'        => 'required|array|min:1',
            'tags.*'      => ['required', 'string', new TagNameRule],
        ]);

        $tagIds = $this->tagWriter->resolve(array_values((array) $request->input('tags')), attribute: 'tags')->pluck('id')->all();

        if ($tagIds === []) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.tag.mass-action.no-tags'),
            ], 422);
        }

        $visible = array_values(array_filter(
            array_map('intval', (array) $request->input('asset_ids')),
            fn (int $assetId) => $this->damCanAccessAsset($assetId)
        ));
        $existing = Asset::query()->whereIn('id', $visible)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // A role limited to its own items tags only the files it added; the rest are skipped and counted.
        $owned = app(RoleBuilder::class)->ownedAssetIds($existing);
        $skipped = count($existing) - count($owned);
        $existing = $owned;

        if ($skipped > 0 && $existing === []) {
            return response()->json([
                'success' => false,
                'skipped' => $skipped,
                'message' => trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => $skipped]),
            ], 403);
        }

        // A bulk add never undoes a removal of a tag from one of these assets (P4 D8).
        $this->tagWriter->addRespectingRemovals(assetIds: $existing, tagIds: $tagIds, source: AssetTagWriter::SOURCE_MANUAL);

        return response()->json(array_filter([
            'success' => true,
            'message' => $skipped > 0
                ? trans('dam::app.admin.permissions.own-only-mass-details', ['updated' => count($existing), 'skipped' => $skipped])
                : trans_choice('dam::app.admin.dam.tag.mass-action.assign-success', count($existing), ['count' => count($existing)]),
            'count'   => count($existing),
            'skipped' => $skipped ?: null,
        ], fn ($value) => $value !== null), 200);
    }

    public function destroy(int $id)
    {
        $tag = $this->assetTagRepository->find($id);

        if (! $tag) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.tag.not-found'),
            ], 404);
        }

        // Re-indexes every asset that carried it (P4 D8).
        $this->vocabulary->delete(tagIds: [(int) $id]);

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.tag.delete-success'),
        ], 200);
    }
}
