<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Services\Organization\LabelService;

/**
 * P4 (D4, D13): shared labels and personal boards from the Library. Both are labels; LibraryBoardController serves
 * boards. A shared label's changes need dam.labels.manage and what it holds dam.labels.assign (the route ACL); a board
 * is personal, so only its owner ever finds it, and anyone else gets the 404 a missing id gets.
 */
class LibraryLabelController extends OrganizationController
{
    public function __construct(protected LabelService $labels) {}

    /** A board is called a board in what it says back (Label created. for a board read as the wrong thing). */
    private function say(string $kind, string $key): string
    {
        return trans('dam::app.admin.organize.labels.'.$key.($kind === 'board' ? '-board' : ''));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeLibrary();
        $kind = $this->kind($request);
        $attributes = $request->validate(['name' => 'required|string|max:191', 'parent_id' => 'nullable|integer|min:1']);
        $label = $this->labels->create(kind: $kind, attributes: $attributes, viewerId: $this->viewerId());

        return new JsonResponse(['data' => $this->present($label), 'message' => $this->say($kind, 'created')], 201);
    }

    public function update(Request $request, int $label): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->find(request: $request, id: $label);
        $attributes = $request->validate([
            'name'      => 'sometimes|required|string|max:191',
            'parent_id' => 'sometimes|nullable|integer|min:1',
            'position'  => 'sometimes|integer|min:0',
        ]);
        $model = $this->labels->update(label: $model, attributes: $attributes, viewerId: $this->viewerId());

        return new JsonResponse(['data' => $this->present($model), 'message' => $this->say($model->kind, 'updated')]);
    }

    public function destroy(Request $request, int $label): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->find(request: $request, id: $label);
        $removed = $this->labels->delete(label: $model, viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['deleted' => $removed], 'message' => $this->say($model->kind, 'deleted')]);
    }

    public function addAssets(Request $request, int $label): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->find(request: $request, id: $label);
        $request->validate(self::assetIdsRules());
        $added = $this->labels->addAssets(label: $model, assetIds: $this->assetIds($request), context: $this->context(), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['added' => count($added)], 'message' => trans('dam::app.admin.organize.labels.assets-added', ['count' => count($added)])]);
    }

    public function removeAssets(Request $request, int $label): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->find(request: $request, id: $label);
        $request->validate(self::assetIdsRules());
        $removed = $this->labels->removeAssets(label: $model, assetIds: $this->assetIds($request), context: $this->context(), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['removed' => $removed], 'message' => trans('dam::app.admin.organize.labels.assets-removed', ['count' => $removed])]);
    }

    public function moveAsset(Request $request, int $label, int $asset): JsonResponse
    {
        $this->authorizeLibrary();
        $model = $this->find(request: $request, id: $label);
        $request->validate(['position' => 'required|integer|min:0']);
        $this->labels->moveAsset(label: $model, assetId: $asset, position: $request->integer('position'), context: $this->context(), viewerId: $this->viewerId());

        return new JsonResponse(['data' => ['moved' => true]]);
    }

    protected function kind(Request $request): string
    {
        return Label::KIND_SHARED;
    }

    protected function find(Request $request, int $id): Label
    {
        return $this->labels->findVisible(id: $id, viewerId: $this->viewerId(), kind: $this->kind($request));
    }

    /** @return array<string, mixed> */
    protected function present(Label $label): array
    {
        return [
            'id'        => (int) $label->id,
            'parent_id' => $label->parent_id === null ? null : (int) $label->parent_id,
            'name'      => $label->name,
            'kind'      => $label->kind,
            'position'  => (int) $label->position,
        ];
    }
}
