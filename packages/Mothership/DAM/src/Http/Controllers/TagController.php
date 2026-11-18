<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\DataGrids\Tag\TagDataGrid;
use Mothership\DAM\Http\Requests\TagRequest;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Repositories\AssetTagRepository;
use Mothership\DAM\Rules\TagNameRule;
use Mothership\DAM\Services\Tags\TagVocabulary;

class TagController extends Controller
{
    public function __construct(
        protected AssetTagRepository $tagRepository,
        protected TagVocabulary $vocabulary,
    ) {}

    public function index()
    {
        if (request()->ajax()) {
            return app(TagDataGrid::class)->toJson();
        }

        return view('dam::tag.index');
    }

    public function store(TagRequest $request): JsonResponse
    {
        if (! bouncer()->hasPermission('dam.tags.create')) {
            return $this->unauthorized();
        }

        $tag = $this->tagRepository->create(['name' => TagNameRule::normalize((string) $request->input('name'))]);

        return response()->json([
            'success' => true,
            'tag'     => ['id' => $tag->id, 'name' => $tag->name],
            'message' => trans('dam::app.admin.dam.tag.create-success'),
        ]);
    }

    public function update(TagRequest $request, int $id): JsonResponse
    {
        if (! bouncer()->hasPermission('dam.tags.update')) {
            return $this->unauthorized();
        }

        $tag = $this->tagRepository->find($id);

        if (! $tag) {
            return $this->notFound();
        }

        // Re-indexes every asset that carries it, so search finds the new name (P4 D8).
        $this->vocabulary->rename(tag: $tag, name: TagNameRule::normalize((string) $request->input('name')));

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.tag.update-success'),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if (! bouncer()->hasPermission('dam.tags.delete')) {
            return $this->unauthorized();
        }

        $tag = $this->tagRepository->find($id);

        if (! $tag) {
            return $this->notFound();
        }

        // Re-indexes every asset that carried it, so search stops finding the name (P4 D8).
        $this->vocabulary->delete(tagIds: [(int) $id]);

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.tag.delete-success'),
        ]);
    }

    public function massDestroy(Request $request): JsonResponse
    {
        if (! bouncer()->hasPermission('dam.tags.delete')) {
            return $this->unauthorized();
        }

        $request->validate([
            'indices'   => 'required|array',
            'indices.*' => 'integer',
        ]);

        $this->vocabulary->delete(tagIds: array_map('intval', (array) $request->input('indices')));

        return response()->json([
            'success' => true,
            'message' => trans('dam::app.admin.dam.tag.mass-delete-success'),
        ]);
    }

    public function list(Request $request): JsonResponse
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
            'data' => $paginator->getCollection()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                ->values(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'has_more'     => $paginator->hasMorePages(),
        ]);
    }

    protected function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => trans('dam::app.admin.permissions.unauthorized'),
        ], 403);
    }

    protected function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => trans('dam::app.admin.dam.tag.not-found'),
        ], 404);
    }
}
