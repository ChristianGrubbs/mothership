<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Exceptions\WebCollectionException;
use Mothership\DAM\Http\Requests\WebCollectionStoreRequest;
use Mothership\DAM\Http\Requests\WebCollectionUpdateRequest;
use Mothership\DAM\Http\Requests\WebPageMoveRequest;
use Mothership\DAM\Models\WebCollection;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\WebCollectionService;

/** Collection writes for the Web Pages area (ADR 0008); every route requires dam.pages.organize. */
class WebCollectionController extends Controller
{
    public function __construct(protected WebCollectionService $collections) {}

    public function store(WebCollectionStoreRequest $request): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;

        try {
            $collection = $this->collections->create(name: (string) $request->input('name'), parentId: $parentId, adminId: auth('admin')->id());
        } catch (WebCollectionException $e) {
            return $this->fail(field: $e->reason === 'name-taken' || $e->reason === 'name-invalid' ? 'name' : 'parent_id', e: $e);
        }

        return response()->json(['collection' => $this->present($collection)], 201);
    }

    public function update(WebCollectionUpdateRequest $request, int $id): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $collection = WebCollection::query()->findOrFail($id);

        try {
            if ($request->has('name') && $request->exists('parent_id')) {
                $this->collections->renameAndMove($collection, (string) $request->input('name'), $request->filled('parent_id') ? (int) $request->input('parent_id') : null);
            } elseif ($request->has('name')) {
                $this->collections->rename($collection, (string) $request->input('name'));
            } else {
                $this->collections->move($collection, $request->filled('parent_id') ? (int) $request->input('parent_id') : null);
            }
        } catch (WebCollectionException $e) {
            return $this->fail(field: in_array($e->reason, ['name-taken', 'name-invalid'], true) ? 'name' : 'parent_id', e: $e);
        }

        return response()->json(['collection' => $this->present($collection->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $collection = WebCollection::query()->findOrFail($id);

        try {
            return response()->json($this->collections->delete($collection));
        } catch (WebCollectionException $e) {
            return $this->fail(field: 'collection_id', e: $e);
        }
    }

    public function move(WebPageMoveRequest $request, DirectoryPermissionService $permissions): JsonResponse
    {
        abort_unless(config('dam.web_pages.enabled', true), 404);
        $collectionId = $request->filled('collection_id') ? (int) $request->input('collection_id') : null;

        try {
            $result = $this->collections->placePages((array) $request->input('asset_ids'), $collectionId, $permissions);
        } catch (WebCollectionException $e) {
            return $this->fail(field: 'collection_id', e: $e);
        }

        $refusedAsForeign = collect($result['rejected'])->contains(fn (array $row) => $row['reason'] === 'not-yours');

        return response()->json(
            $refusedAsForeign ? $result + ['message' => trans('dam::app.admin.permissions.own-only-pages')] : $result,
            $result['placed'] === [] ? 403 : 200,
        );
    }

    protected function present(WebCollection $collection): array
    {
        return ['id' => $collection->id, 'name' => $collection->name, 'parent_id' => $collection->parent_id, 'path' => $collection->pathString()];
    }

    protected function fail(string $field, WebCollectionException $e): JsonResponse
    {
        $message = trans('dam::app.admin.dam.web-pages.collections.'.$e->reason, ['limit' => $e->limit]);

        // The own-items refusals are the person's rights, not a mistyped field: 403, in plain words.
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], in_array($e->reason, ['not-yours', 'holds-others'], true) ? 403 : 422);
    }
}
