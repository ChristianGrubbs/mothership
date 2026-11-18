<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Services\Organization\LibraryOrganizationFilters;
use Mothership\DAM\Services\Organization\SectionService;

/**
 * P4 (D3): sections from the Library. Everyone who uses the Library browses them (through the explorer listing with
 * `section_id`); creating, changing, deleting and pinning need dam.sections.manage (the route ACL).
 */
class LibrarySectionController extends OrganizationController
{
    public function __construct(protected SectionService $sections) {}

    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';

        return [
            'name'                => $required.'|string|max:191',
            'rule'                => 'sometimes|array',
            'rule.media_types'    => 'sometimes|array|max:'.count(SectionService::MEDIA_TYPES),
            'rule.media_types.*'  => 'in:'.implode(',', SectionService::MEDIA_TYPES),
            'rule.extensions'     => 'sometimes|array|max:50',
            'rule.extensions.*'   => 'string|max:17',
            'default_sort'        => 'sometimes|nullable|in:'.implode(',', self::defaultSorts()),
            'position'            => 'sometimes|integer|min:0',
        ];
    }

    /** @return array<int, string> the default sorts a section may carry */
    public static function defaultSorts(): array
    {
        $sorts = [];

        foreach (['created_at', 'name', 'size', 'updated_at', ...LibraryOrganizationFilters::sortKeys()] as $column) {
            $sorts[] = $column.':asc';
            $sorts[] = $column.':desc';
        }

        return $sorts;
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeLibrary();
        $attributes = $request->validate(self::rules(creating: true));
        $section = $this->sections->create(attributes: $attributes, viewerId: $this->viewerId());

        return new JsonResponse(['data' => $this->sections->present($section), 'message' => trans('dam::app.admin.organize.sections.created')], 201);
    }

    public function update(Request $request, int $section): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->sections->find($section);
        $attributes = $request->validate(self::rules(creating: false));
        $model = $this->sections->update(section: $model, attributes: $attributes, viewerId: $this->viewerId());

        return new JsonResponse(['data' => $this->sections->present($model), 'message' => trans('dam::app.admin.organize.sections.updated')]);
    }

    public function destroy(int $section): JsonResponse
    {
        $this->authorizeLibrary();
        $this->sections->delete(section: $this->sections->find($section), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['deleted' => true], 'message' => trans('dam::app.admin.organize.sections.deleted')]);
    }

    public function pin(Request $request, int $section): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->sections->find($section);
        $request->validate(self::assetIdsRules());
        $added = $this->sections->pin(section: $model, assetIds: $this->assetIds($request), context: $this->context(), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['pinned' => count($added)], 'message' => trans('dam::app.admin.organize.sections.pinned', ['count' => count($added)])]);
    }

    public function unpin(Request $request, int $section): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->sections->find($section);
        $request->validate(self::assetIdsRules());
        $removed = $this->sections->unpin(section: $model, assetIds: $this->assetIds($request), context: $this->context(), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['unpinned' => $removed], 'message' => trans('dam::app.admin.organize.sections.unpinned', ['count' => $removed])]);
    }

    public function movePin(Request $request, int $section, int $asset): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->sections->find($section);
        $request->validate(['position' => 'required|integer|min:0']);
        $this->sections->movePin(section: $model, assetId: $asset, position: $request->integer('position'), context: $this->context(), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['moved' => true]]);
    }
}
