<?php

namespace Mothership\DAM\Http\Controllers\Asset;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Repositories\AssetTagRepository;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Rules\VisibleAsset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Tags\AssetTagWriter;
use Mothership\DAM\Traits\AssetAccessControl;

class TagController extends Controller
{
    use AssetAccessControl;

    /** How many files a bulk add left alone because they belong to someone else (own-items roles only). */
    protected int $skippedForeign = 0;

    public function __construct(
        protected AssetRepository $assetRepository,
        protected AssetTagRepository $assetTagRepository,
        protected AssetTagWriter $tagWriter,
    ) {}

    protected function addOrUpdateTag(Request $request)
    {
        $request->validate([
            'tag'      => ['required', new TagNameRule],
            // VisibleAsset, not `exists`: a hidden asset fails exactly as a missing one does.
            'asset_id' => ['required', new VisibleAsset],
        ]);

        $this->authorizeTagging();

        $assetId = $request->get('asset_id');

        $asset = $this->assetRepository->find($assetId);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $this->damAuthorizeAsset((int) $assetId);

        $tag = $this->tagWriter->resolve($request->get('tag'))->first();

        // One person adding one tag to one asset: their earlier removal of it is cleared (P4 D8).
        if (! $this->tagWriter->addManual(asset: $asset, tag: $tag, actorId: auth('admin')->id())) {
            return response()->json([
                'success' => false,
                'file'    => $asset->load('tags'),
                'message' => trans('dam::app.admin.dam.asset.edit.tag-already-exists'),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'file'    => $asset->load('tags'),
            'message' => trans('dam::app.admin.dam.asset.edit.tag-attach-success'),
        ], 201);
    }

    protected function removeTag(Request $request)
    {
        $request->validate([
            'tag'      => 'required',
            // VisibleAsset, not `exists`: a hidden asset fails exactly as a missing one does.
            'asset_id' => ['required', new VisibleAsset],
        ]);

        $this->authorizeTagging();

        $assetId = $request->get('asset_id');

        $asset = $this->assetRepository->find($assetId);

        if (! $asset) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.datagrid.not-found'),
            ], 404);
        }

        $this->damAuthorizeAsset((int) $assetId);

        $assetTag = $this->assetTagRepository->whereRaw('LOWER(name) = ?', [mb_strtolower(TagNameRule::normalize((string) $request->get('tag')))])->first();

        if ($assetTag) {
            // Recorded as a removal, so no re-index, AI accept, rule, import or bulk add puts it back (P4 D8).
            $this->tagWriter->remove(asset: $asset, tag: $assetTag, actorId: auth('admin')->id());
        }

        return response()->json([
            'success' => true,
            'file'    => $asset->load('tags'),
            'message' => trans('dam::app.admin.dam.asset.edit.tag-detach-success'),
        ], 201);
    }

    protected function massAssignTags(Request $request)
    {
        $request->validate([
            'indices'         => 'nullable|array',
            'indices.*'       => 'integer',
            'directory_ids'   => 'nullable|array',
            'directory_ids.*' => 'integer',
            'tags'            => 'required|array',
            'tags.*'          => ['required', 'string', new TagNameRule],
        ]);

        $this->authorizeTagging();
        $this->skippedForeign = 0;

        if (empty($request->input('indices')) && empty($request->input('directory_ids'))) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.tag.mass-action.no-items'),
            ], 422);
        }

        $tagIds = $this->tagWriter->resolve(array_values((array) $request->input('tags')), attribute: 'tags')->pluck('id')->all();

        if ($tagIds === []) {
            return response()->json([
                'success' => false,
                'message' => trans('dam::app.admin.dam.tag.mass-action.no-tags'),
            ], 422);
        }

        $updated = $this->tagSelectedAssets($request->input('indices', []), $tagIds);

        $updated += $this->tagAssetsInDirectories($request->input('directory_ids', []), $tagIds);

        // A role limited to its own items tags only the files it added; the rest were skipped, and it is told so.
        if ($this->skippedForeign > 0 && $updated === 0) {
            return response()->json([
                'success' => false,
                'skipped' => $this->skippedForeign,
                'message' => trans('dam::app.admin.permissions.own-only-mass-details-none', ['skipped' => $this->skippedForeign]),
            ], 403);
        }

        return response()->json(array_filter([
            'success' => true,
            'message' => $this->skippedForeign > 0
                ? trans('dam::app.admin.permissions.own-only-mass-details', ['updated' => $updated, 'skipped' => $this->skippedForeign])
                : trans_choice('dam::app.admin.dam.tag.mass-action.assign-success', (int) $updated, ['count' => $updated]),
            'count'   => $updated,
            'skipped' => $this->skippedForeign ?: null,
        ], fn ($value) => $value !== null));
    }

    /** Adding or removing a tag on an asset is its own permission (P4 D8), apart from editing the asset. */
    protected function authorizeTagging(): void
    {
        abort_unless(bouncer()->hasPermission('dam.asset.tag'), 403, trans('dam::app.admin.permissions.unauthorized'));
    }

    protected function tagSelectedAssets(array $assetIds, array $tagIds): int
    {
        $visible = array_values(array_filter(
            array_map('intval', $assetIds),
            fn (int $assetId) => $this->damCanAccessAsset($assetId)
        ));
        $existing = $this->assetRepository->getModel()->newQuery()->whereIn('id', $visible)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $roles = app(RoleBuilder::class);
        $owned = $roles->ownedAssetIds($existing);
        $this->skippedForeign += count($existing) - count($owned);
        $existing = $owned;

        // A bulk add never undoes a person's removal of a tag from one of these assets (P4 D8).
        $this->tagWriter->addRespectingRemovals(assetIds: $existing, tagIds: $tagIds, source: AssetTagWriter::SOURCE_MANUAL);

        return count($existing);
    }

    protected function tagAssetsInDirectories(array $directoryIds, array $tagIds): int
    {
        $service = $this->damPermissionService();
        $bypass = $service->bypass();

        $directoryIds = array_values(array_filter(
            array_unique(array_map('intval', $directoryIds)),
            fn (int $id) => $bypass || $service->canAccess($id)
        ));

        if (empty($directoryIds)) {
            return 0;
        }

        $grantedIds = $bypass ? null : $service->directlyGrantedIds();

        if ($grantedIds !== null && empty($grantedIds)) {
            return 0;
        }

        $roots = Directory::whereIn('id', $directoryIds)->get(['id', '_lft', '_rgt']);

        if ($roots->isEmpty()) {
            return 0;
        }

        $dirTable = (new Directory)->getTable();
        $now = now()->toDateTimeString();

        $subtreeDirIds = function ($query) use ($roots, $dirTable, $grantedIds) {
            $query->select('id')->from($dirTable)->where(function ($scope) use ($roots) {
                foreach ($roots as $i => $root) {
                    $scope->{$i === 0 ? 'where' : 'orWhere'}(function ($range) use ($root) {
                        $range->where('_lft', '>=', $root->_lft)
                            ->where('_rgt', '<=', $root->_rgt);
                    });
                }
            });

            if ($grantedIds !== null) {
                $query->whereIn('id', $grantedIds);
            }
        };

        // A trashed asset (P2 S4) is neither tagged nor counted: it comes back from the trash as it went in.
        $trashedIds = fn ($query) => $query->select('id')->from('dam_assets')->whereNotNull('deleted_at');

        // A role limited to its own items tags only the files it added; the others in these folders are counted as skipped.
        $roles = app(RoleBuilder::class);
        $onlyMine = $roles->restrictedToOwn() ? (int) $roles->actingAdmin()?->id : null;
        $mine = fn ($query) => $onlyMine === null ? $query : $query->whereIn('asset_id', fn ($owned) => $owned->select('id')->from('dam_assets')->where('created_by', $onlyMine));

        if ($onlyMine !== null) {
            // Only files this person can see are counted: under `lists` each file has its own access, and a hidden file must
            // change neither the number nor the sentence.
            $foreignFiles = DB::table('dam_assets')->whereNull('dam_assets.deleted_at')
                ->whereIn('dam_assets.id', fn ($in) => $in->select('asset_id')->from('dam_asset_directory')->whereIn('directory_id', $subtreeDirIds))
                ->where(fn ($q) => $q->whereNull('dam_assets.created_by')->orWhere('dam_assets.created_by', '!=', $onlyMine));
            app(AssetAccessDecision::class)->scopeListable(query: $foreignFiles, context: AccessContext::library(AccessContext::PURPOSE_LIST));
            $this->skippedForeign += $foreignFiles->count('dam_assets.id');
        }

        $nowLiteral = DB::getPdo()->quote($now);
        $nowExpr = DB::connection()->getDriverName() === 'pgsql'
            ? "CAST($nowLiteral AS timestamp)"
            : $nowLiteral;

        foreach ($tagIds as $tagId) {
            DB::table('dam_asset_tag')->insertOrIgnoreUsing(
                ['asset_id', 'tag_id', 'source', 'created_at', 'updated_at'],
                $mine(DB::table('dam_asset_directory')
                    ->whereIn('directory_id', $subtreeDirIds)
                    ->whereNotIn('asset_id', $trashedIds))
                    // A folder bulk add never undoes a person's removal of this tag from one asset (P4 D8).
                    ->whereNotExists(fn ($removed) => $removed->select(DB::raw(1))->from('dam_asset_tag_tombstones')
                        ->whereColumn('dam_asset_tag_tombstones.asset_id', 'dam_asset_directory.asset_id')
                        ->where('dam_asset_tag_tombstones.tag_id', (int) $tagId))
                    ->distinct()
                    ->select(
                        'asset_id',
                        DB::raw((int) $tagId.' as tag_id'),
                        DB::raw("'".AssetTagWriter::SOURCE_MANUAL."' as source"),
                        DB::raw($nowExpr.' as created_at'),
                        DB::raw($nowExpr.' as updated_at'),
                    )
            );
        }

        $affected = $mine(DB::table('dam_asset_directory')
            ->whereIn('directory_id', $subtreeDirIds)
            ->whereNotIn('asset_id', $trashedIds));

        // The raw insert bypasses the per-asset tag event, so the words of every asset it reached are re-rendered
        // here, 200 per queued job (P4 D8: a folder bulk-tag is searchable within one queue cycle).
        (clone $affected)->select('asset_id')->distinct()->orderBy('asset_id')
            ->chunk(1000, fn ($rows) => AiIndexDispatcher::lexical($rows->pluck('asset_id')->map(fn ($id) => (int) $id)->all()));

        return $affected->distinct()->count('asset_id');
    }
}
