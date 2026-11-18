<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\AssetAccessControl;

class CustomThumbnailController
{
    use AssetAccessControl;

    public function store(Request $request, int $id)
    {
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless($this->damCanEditAsset($id), 403);
        // A custom picture replaces what everyone sees on the item: only the person who added it may set it.
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset, reason: 'own-only-thumbnail');
        abort_if($asset->file_type === 'image' || str_starts_with(strtolower((string) $asset->mime_type), 'image/'), 422, 'Choose a non-image asset.');
        $request->validate(['thumbnail' => [
            'required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/gif', 'max:32768',
            // G1-17: a truncated image passes `image` and would show half grey; refuse it before anything is saved.
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value instanceof UploadedFile && ! CustomThumbnailService::isComplete($value)) {
                    $fail(trans('dam::app.admin.explorer.custom-thumbnail.damaged'));
                }
            },
        ]]);
        $thumbnail = app(CustomThumbnailService::class)->store(asset: $asset, upload: $request->file('thumbnail'));

        return response()->json(['thumbnail_url' => route('admin.dam.file.thumbnail', [
            'path' => urlencode($asset->path), 'v' => CustomThumbnailService::servedVersion($thumbnail),
        ])], 201);
    }
}
