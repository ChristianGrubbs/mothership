<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\StackFileThumbnail;
use Mothership\DAM\Traits\AssetAccessControl;

/**
 * Browser-captured thumbnails of the 3D files inside a stack (ADR 0006), one per asset and file blob (PERM-01).
 *
 * ModelThumbnailController is the asset's own picture and the stack's cover; this is the same handshake for a
 * stacked file, so opening one in the viewer can never overwrite the cover. The rights are the asset
 * controller's: the file's owning asset must be visible (a hidden one answers like a missing file), writing
 * needs edit rights on it, and a capture is bound to the sha256 of the bytes the browser rendered.
 */
class ModelFileThumbnailController
{
    use AssetAccessControl;

    public function show(int $assetFile): JsonResponse
    {
        [$file, $asset] = $this->model($assetFile);

        return response()->json([
            'checksum'      => $file->fileObject->sha256,
            'has_thumbnail' => ModelFileThumbnailService::exists($file->fileObject->sha256, $asset->id),
            'can_edit'      => $this->damCanEditAsset($asset->id),
            // A role limited to its own files captures and replaces pictures only on files it added.
            'can_capture'   => app(RoleBuilder::class)->refusal(model: $asset) === null,
            'can_replace'   => app(RoleBuilder::class)->refusal(model: $asset) === null,
        ]);
    }

    public function store(Request $request, int $assetFile): JsonResponse
    {
        [$file, $asset] = $this->model($assetFile, write: true);

        // Every write, the first capture included, is for the person who added the file (or an unrestricted role).
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset, reason: 'own-only-thumbnail');

        $request->validate([
            'image'    => 'required|file|mimetypes:image/png,image/jpeg|max:2048|dimensions:max_width=1024,max_height=1024',
            'checksum' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/i',
            'replace'  => 'sometimes|boolean',
        ]);

        $sha256 = $file->fileObject->sha256;

        abort_unless(
            hash_equals($sha256, strtolower($request->string('checksum')->toString())),
            409,
            'This capture belongs to an older version of the file. Reopen the preview and try again.'
        );

        $result = app(ModelFileThumbnailService::class)->store(
            object: $file->fileObject,
            upload: $request->file('image'),
            assetId: $asset->id,
            replace: $request->boolean('replace'),
        );

        return response()->json([
            'stored'        => $result['stored'],
            'skipped'       => $result['skipped'],
            'thumbnail_url' => StackFileThumbnail::url($asset->id, $file->id),
        ]);
    }

    /** @return array{0: AssetFile, 1: Asset} the file with its blob, and the asset that owns it */
    private function model(int $id, bool $write = false): array
    {
        $file = AssetFile::query()->with('fileObject')->find($id);
        $asset = $file ? $this->damVisibleAssetOrFail($file->dam_asset_id) : abort(404);

        abort_if($write && ! $this->damCanEditAsset($asset->id), 403);
        abort_unless($file->fileObject && ModelFileThumbnailService::supports($file, $asset), 422, 'Generated thumbnails cover GLB, 3MF and STL models only.');

        return [$file, $asset];
    }
}
