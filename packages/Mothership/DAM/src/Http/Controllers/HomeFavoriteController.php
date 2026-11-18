<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\AssetFavoriteService;

class HomeFavoriteController extends Controller
{
    public function index(Request $request, AssetFavoriteService $favorites)
    {
        abort_unless(bouncer()->hasPermission('dam.assets'), 403);
        $assets = $favorites->list($request->integer('limit', 24))->map(fn ($asset) => [
            'id'        => $asset->id, 'name' => $asset->display_name, 'type' => $asset->file_type, 'type_label' => HomeController::typeLabel($asset),
            'url'       => route('admin.dam.assets.edit', $asset->id),
            'thumbnail' => route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]),
        ]);

        return response()->json(['assets' => $assets])->header('Cache-Control', 'private, no-store');
    }

    public function store(int $asset, AssetFavoriteService $favorites)
    {
        abort_unless(bouncer()->hasPermission('dam.assets'), 403);
        $favorites->favorite($asset);

        return response()->json(['favorite' => true]);
    }

    public function show(int $asset, AssetFavoriteService $favorites)
    {
        abort_unless(bouncer()->hasPermission('dam.assets'), 403);

        return response()->json(['favorite' => $favorites->isFavorite($asset)])->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, AssetFavoriteService $favorites)
    {
        abort_unless(bouncer()->hasPermission('dam.assets'), 403);
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        return response()->json(['favorites' => $favorites->statuses($data['ids'])])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(int $asset, AssetFavoriteService $favorites)
    {
        abort_unless(bouncer()->hasPermission('dam.assets'), 403);
        $favorites->unfavorite($asset);

        return response()->json(['favorite' => false]);
    }
}
