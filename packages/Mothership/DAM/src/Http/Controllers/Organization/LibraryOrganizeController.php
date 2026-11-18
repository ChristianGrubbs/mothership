<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Services\Organization\LabelService;
use Mothership\DAM\Services\Organization\LibraryPreferences;
use Mothership\DAM\Services\Organization\PopularityScore;
use Mothership\DAM\Services\Organization\SavedSearchService;
use Mothership\DAM\Services\Organization\SectionService;
use Mothership\DAM\Services\Organization\VisibleAssets;

/**
 * P4: the Library sidebar's organization panel in one read (shared labels, the viewer's boards, sections, saved
 * searches and their favorites count, every count over what the viewer may list now), plus the viewer's saved sort.
 * With `asset_ids[]` each label, board and section also reports how many of those assets it holds, for the
 * add-to-board and label pickers.
 */
class LibraryOrganizeController extends OrganizationController
{
    public function index(Request $request, LabelService $labels, SectionService $sections, SavedSearchService $searches, LibraryPreferences $preferences): JsonResponse
    {
        $this->authorizeLibrary();
        $request->validate(['asset_ids' => 'sometimes|array|max:500', 'asset_ids.*' => 'integer|min:1']);
        $viewerId = $this->viewerId();
        $context = $this->context();
        $assetIds = $this->assetIds($request);

        $favorites = $viewerId === null ? 0 : DB::table('dam_asset_favorites')->where('admin_id', $viewerId)
            ->whereIn('dam_asset_id', VisibleAssets::query($context))->count();

        return (new JsonResponse([
            'labels'         => $labels->tree(viewerId: $viewerId, kind: Label::KIND_SHARED, context: $context, assetIds: $assetIds),
            'boards'         => $labels->tree(viewerId: $viewerId, kind: Label::KIND_BOARD, context: $context, assetIds: $assetIds),
            'sections'       => $sections->list(context: $context, assetIds: $assetIds),
            'saved_searches' => $searches->list($viewerId),
            'favorites'      => $favorites,
            'sort'           => $viewerId === null ? null : $preferences->sort($viewerId),
            'popularity'     => PopularityScore::enabled(),
            'can'            => [
                'manage_labels'   => bouncer()->hasPermission('dam.labels.manage'),
                'assign_labels'   => bouncer()->hasPermission('dam.labels.assign'),
                'manage_sections' => bouncer()->hasPermission('dam.sections.manage'),
            ],
        ]))->header('Cache-Control', 'private, no-store');
    }

    public function updatePreferences(Request $request, LibraryPreferences $preferences): JsonResponse
    {
        $this->authorizeLibrary();
        $request->validate(['sort' => 'present|nullable|in:'.implode(',', LibraryPreferences::sorts())]);
        $viewerId = $this->viewerId();
        abort_if($viewerId === null, 403, trans('dam::app.admin.permissions.unauthorized'));
        $preferences->setSort(adminId: $viewerId, sort: $request->input('sort'));

        return new JsonResponse(['data' => ['sort' => $preferences->sort($viewerId)]]);
    }
}
