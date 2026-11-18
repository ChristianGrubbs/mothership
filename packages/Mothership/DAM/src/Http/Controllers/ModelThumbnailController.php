<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * The server half of client-side 3D thumbnail capture (ADR 0006).
 *
 * `show` hands a browser the binding checksum and whether a thumbnail already
 * exists, `store` accepts the rendered frame, and `missing` enumerates the
 * models a backfill still has to visit.
 */
class ModelThumbnailController
{
    use AssetAccessControl;

    private const MAX_IDS = 200;

    public function show(int $id): JsonResponse
    {
        $asset = $this->model($id);

        return response()->json([
            'checksum'      => ModelThumbnailService::checksum($asset),
            // True only when the card has a picture AND the AI has a capture of the current bytes: a custom or stale card
            // thumbnail alone never stops the viewer from capturing.
            'has_thumbnail' => ModelThumbnailService::hasCapture($asset),
            'can_edit'      => $this->damCanEditAsset($id),
            // A role limited to its own files captures and replaces pictures only on files it added.
            'can_capture'   => $this->mayWrite(asset: $asset),
            'can_replace'   => $this->mayWrite(asset: $asset),
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $asset = $this->model($id, write: true);
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset, reason: 'own-only-thumbnail');

        $request->validate([
            'image'    => 'required|file|mimetypes:image/png,image/jpeg|max:2048|dimensions:max_width=1024,max_height=1024',
            'checksum' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/i',
            'replace'  => 'sometimes|boolean',
        ]);

        $checksum = strtolower($request->string('checksum')->toString());
        $current = ModelThumbnailService::checksum($asset);

        abort_if(
            $current === null || ! hash_equals($current, $checksum),
            409,
            'This capture belongs to an older version of the model. Reopen the preview and try again.'
        );

        $result = app(ModelThumbnailService::class)->store(
            asset: $asset,
            upload: $request->file('image'),
            checksum: $checksum,
            replace: $request->boolean('replace'),
        );

        return response()->json([
            'stored'         => $result['stored'],
            'skipped'        => $result['skipped'],
            'thumbnail_path' => $result['thumbnail_path'],
            'captured'       => $result['captured'],
            'thumbnail_url'  => $this->thumbnailUrl($asset->fresh() ?? $asset, $result['revision']),
        ]);
    }

    public function missing(Request $request): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('dam.asset.view'), 403, trans('dam::app.admin.permissions.unauthorized'));

        $request->validate([
            'directory_id' => ['nullable', 'integer', 'min:1', new VisibleDirectory],
            'limit'        => 'nullable|integer|min:1|max:250',
            'cursor'       => 'nullable|integer|min:0',
            'ids'          => 'nullable|array|max:'.self::MAX_IDS,
            'ids.*'        => 'integer|min:1',
        ]);

        $permissions = app(DirectoryPermissionService::class);
        $directoryId = $request->integer('directory_id') ?: null;

        if ($directoryId !== null) {
            abort_unless($permissions->bypass() || $permissions->canAccess($directoryId), 403, trans('dam::app.admin.explorer.access-denied'));
        }

        $limit = $request->integer('limit') ?: 100;
        $cursor = $request->integer('cursor');

        $query = Asset::query()->visibleCaptures();
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        // One folder when the operator backfills a folder, every directly
        // granted folder when they backfill the library. directlyGrantedIds()
        // rather than viewableIds(): the latter adds ancestors so the tree can
        // be drawn, and a role that may only see a folder's name must not be
        // handed the models sitting in it.
        $directoryIds = $directoryId !== null
            ? [$directoryId]
            : ($permissions->bypass() ? null : $permissions->directlyGrantedIds());

        $query->whereExists(function ($sub) use ($directoryIds) {
            $sub->select(DB::raw(1))
                ->from('dam_asset_directory')
                ->whereColumn('dam_asset_directory.asset_id', 'dam_assets.id');

            if ($directoryIds !== null) {
                $sub->whereIn('dam_asset_directory.directory_id', $directoryIds);
            }
        });

        $prefix = DB::getTablePrefix();
        $placeholders = implode(', ', array_fill(0, count(ModelThumbnailService::EXTENSIONS), '?'));

        $query->whereRaw("LOWER({$prefix}dam_assets.extension) in ({$placeholders})", ModelThumbnailService::EXTENSIONS)
            ->whereNull('dam_assets.meta_data->thumbnail_path')
            ->whereNull('dam_assets.meta_data->custom_thumbnail');

        if ($request->filled('ids')) {
            $query->whereIn('dam_assets.id', array_slice($request->input('ids'), 0, self::MAX_IDS));
        }

        if ($cursor > 0) {
            $query->where('dam_assets.id', '>', $cursor);
        }

        $assets = $query->orderBy('dam_assets.id')
            ->limit($limit)
            ->select(['dam_assets.id', 'dam_assets.extension', 'dam_assets.file_size'])
            ->addSelect(['checksum' => ModelThumbnailService::checksumSubquery()])
            ->get()
            ->map(fn (Asset $asset) => [
                'id'        => (int) $asset->id,
                'extension' => strtolower((string) $asset->extension),
                'checksum'  => $asset->checksum,
                'size'      => (int) $asset->file_size,
                'url'       => route('admin.dam.assets.download', array_filter(['id' => $asset->id, 'sha256' => $asset->checksum])),
            ]);

        return response()->json([
            'items'       => $assets->values(),
            'next_cursor' => $assets->count() === $limit ? (int) $assets->last()['id'] : null,
        ]);
    }

    /** Every write of a model's picture, the first capture included, is for the person who added the file (or an unrestricted role). */
    private function mayWrite(Asset $asset): bool
    {
        return app(RoleBuilder::class)->refusal(model: $asset) === null;
    }

    private function model(int $id, bool $write = false): Asset
    {
        $asset = $this->damVisibleAssetOrFail($id);
        abort_if($write && ! $this->damCanEditAsset($id), 403);
        abort_unless(ModelThumbnailService::supports($asset), 422, 'Generated thumbnails cover GLB, 3MF and STL models only.');

        return $asset;
    }

    private function thumbnailUrl(Asset $asset, ?string $revision): ?string
    {
        if (! $asset->path) {
            return null;
        }

        return route('admin.dam.file.thumbnail', array_filter([
            'path' => urlencode($asset->path),
            'v'    => $revision,
        ]));
    }
}
