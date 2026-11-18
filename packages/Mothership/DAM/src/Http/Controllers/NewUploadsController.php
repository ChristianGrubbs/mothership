<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\NewUserUploads;

/** The admin-only "New user uploads" page (routes carry ManageFolderAccess, so every action is admin-checked server-side). */
class NewUploadsController extends Controller
{
    private const PER_PAGE = 100;

    public function __construct(protected NewUserUploads $uploads) {}

    public function index()
    {
        return view('dam::new-uploads.index');
    }

    public function list(Request $request): JsonResponse
    {
        $page = Asset::query()
            ->whereNotNull('needs_sorting_at')
            ->with(['creator:id,name', 'directories:id,name'])
            ->orderByDesc('needs_sorting_at')
            ->orderByDesc('id')
            ->paginate(perPage: self::PER_PAGE, page: $request->integer('page', 1));

        return response()->json([
            'assets' => $page->getCollection()->map(fn (Asset $asset) => [
                'id'        => $asset->id,
                'name'      => $asset->display_name ?? $asset->file_name,
                'thumbnail' => $asset->path ? route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]) : null,
                'owner'     => $asset->creator?->name,
                'guest'     => $asset->upload_submission_id !== null,
                'folder'    => $asset->directories->first()?->name,
                'added_at'  => $asset->needs_sorting_at?->toIso8601String(),
            ])->values(),
            'meta'   => ['total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage()],
        ]);
    }

    public function move(Request $request): JsonResponse
    {
        $ids = $this->ids($request);
        $result = $this->uploads->moveToSortFolder(assetIds: $ids, adminId: (int) auth('admin')->id());

        return response()->json($result + ['message' => trans_choice('dam::app.admin.dam.new-uploads.moved', (int) $result['moved'], ['count' => $result['moved']])]);
    }

    public function markSorted(Request $request): JsonResponse
    {
        $result = $this->uploads->markSorted(assetIds: $this->ids($request));

        return response()->json($result + ['message' => trans_choice('dam::app.admin.dam.new-uploads.marked', (int) $result['cleared'], ['count' => $result['cleared']])]);
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $request->validate(['asset_ids' => 'required|array|min:1|max:1000', 'asset_ids.*' => 'integer']);

        return array_map('intval', $request->input('asset_ids'));
    }
}
