<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Organization\SavedSearchService;

/**
 * P4 (D12): saved searches from the Library. Anyone who uses the Library saves their own (private unless they share
 * it); only the owner changes or deletes one. The Library re-runs one by loading its definition into the explorer,
 * whose listing applies the viewer's access at that moment.
 */
class LibrarySavedSearchController extends OrganizationController
{
    public function __construct(protected SavedSearchService $searches) {}

    public static function rules(bool $creating): array
    {
        return [
            'name'       => ($creating ? 'required' : 'sometimes|required').'|string|max:191',
            'definition' => ($creating ? 'required' : 'sometimes|required').'|array',
            'pinned'     => 'sometimes|boolean',
            'visibility' => 'sometimes|in:'.implode(',', SavedSearch::VISIBILITIES),
            'position'   => 'sometimes|integer|min:0',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeLibrary();
        $attributes = $request->validate(self::rules(creating: true));
        $search = $this->searches->create(attributes: $attributes, viewerId: $this->viewerId());

        return new JsonResponse(['data' => $this->searches->present(search: $search, viewerId: $this->viewerId()), 'message' => trans('dam::app.admin.organize.saved-searches.created')], 201);
    }

    public function update(Request $request, int $search): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->searches->findOwned(id: $search, viewerId: $this->viewerId());
        $attributes = $request->validate(self::rules(creating: false));
        $model = $this->searches->update(search: $model, attributes: $attributes, viewerId: $this->viewerId());

        return new JsonResponse(['data' => $this->searches->present(search: $model, viewerId: $this->viewerId()), 'message' => trans('dam::app.admin.organize.saved-searches.updated')]);
    }

    public function destroy(int $search): JsonResponse
    {
        $this->authorizeLibrary();
        $this->searches->delete(search: $this->searches->findOwned(id: $search, viewerId: $this->viewerId()), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['deleted' => true], 'message' => trans('dam::app.admin.organize.saved-searches.deleted')]);
    }
}
