<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\ImageEditing;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function capabilityAsset(string $name, string $type, string $mime, string $bytes = 'bytes'): Asset
{
    $path = 'assets/Root/'.uniqid().'-'.$name;
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);

    return Asset::factory()->create(['path' => $path, 'file_name' => $name, 'file_type' => $type,
        'extension'                         => pathinfo($name, PATHINFO_EXTENSION), 'mime_type' => $mime]);
}

function capabilityEditorUrl(Asset $asset): string
{
    return '/'.config('app.admin_url').'/dam/assets/editor/'.$asset->id;
}

it('offers the original editor for PNG, JPEG and WebP only', function (string $name, string $mime, bool $offered) {
    $asset = capabilityAsset($name, 'image', $mime);
    expect(ImageEditing::originalEditable($asset))->toBe($offered);
    expect(ImageEditing::previewFields($asset)['canEditOriginal'])->toBe($offered);
})->with([
    ['a.png', 'image/png', true], ['a.jpg', 'image/jpeg', true], ['a.jpeg', 'image/jpeg', true], ['a.webp', 'image/webp', true],
    ['a.gif', 'image/gif', false], ['a.svg', 'image/svg+xml', false], ['a.tiff', 'image/tiff', false], ['a.psd', 'image/vnd.adobe.photoshop', false],
]);

it('refuses the editor endpoints for an unsupported image before touching any document or file', function (string $name, string $mime) {
    $asset = capabilityAsset($name, 'image', $mime, 'original');
    $before = ImageEditorDocument::count();
    $this->getJson(capabilityEditorUrl($asset))->assertStatus(422);
    $this->post(capabilityEditorUrl($asset).'/save', ['revision' => 'x', 'document' => '{}', 'mode' => 'version',
        'render'                                                 => UploadedFile::fake()->image('r.png')], ['Accept' => 'application/json'])->assertStatus(422);
    expect(ImageEditorDocument::count())->toBe($before);
    expect(Storage::disk(Directory::getAssetDisk())->get($asset->path))->toBe('original');
})->with([['a.gif', 'image/gif'], ['a.svg', 'image/svg+xml']]);

it('offers a thumbnail edit only for a non-image asset that has a real thumbnail', function () {
    $model = capabilityAsset('m.glb', 'document', 'model/gltf-binary');
    $none = ImageEditing::previewFields($model);
    expect($none)->toMatchArray(['canEditOriginal' => false, 'canEditThumbnail' => false, 'thumbnailEditUrl' => null]);

    $url = '/'.config('app.admin_url').'/dam/assets/'.$model->id.'/custom-thumbnail';
    $this->postJson($url, ['thumbnail' => UploadedFile::fake()->image('c.png')])->assertCreated();
    $fields = ImageEditing::previewFields($model->fresh());
    expect($fields['canEditThumbnail'])->toBeTrue()->and($fields['canEditOriginal'])->toBeFalse();
    expect($fields['thumbnailEditUrl'])->toContain('/dam/')->and($fields['thumbnailEditUrl'])->toContain('v=');

    // An animated GIF thumbnail would be flattened by an edit, so none is offered.
    $this->postJson($url, ['thumbnail' => UploadedFile::fake()->createWithContent('m.gif', hex2bin('47494638396101000100800000000000ffffff21ff0b4e45545343415045322e300301000000'
        .'21f904000a0000002c0000000001000100000202440100'.'21f904000a0000002c00000000010001000002024c01003b'))])->assertCreated();
    expect(ImageEditing::previewFields($model->fresh())['canEditThumbnail'])->toBeFalse();
});

it('never offers a thumbnail edit on an image asset', function () {
    $image = capabilityAsset('p.png', 'image', 'image/png');
    expect(ImageEditing::takesCustomThumbnail($image))->toBeFalse();
    expect(ImageEditing::previewFields($image)['canEditThumbnail'])->toBeFalse();
});

it('keeps the thumbnail edit from a role without the editor permission or, when own-only, another person\'s asset', function (array $permissions, bool $ownOnly, bool $mine, bool $offered) {
    $model = capabilityAsset('m.glb', 'document', 'model/gltf-binary');
    $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$model->id.'/custom-thumbnail', ['thumbnail' => UploadedFile::fake()->image('c.png')])->assertCreated();
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    if ($ownOnly) {
        DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'delete_own_only' => 1]);
    }
    $member = Admin::factory()->create(['role_id' => $role->id]);
    if ($mine) {
        $model->forceFill(['created_by' => $member->id])->save();
    }
    $this->actingAs($member, 'admin');
    expect(ImageEditing::previewFields($model->fresh())['canEditThumbnail'])->toBe($offered);
})->with([
    'viewer without editor'        => [['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit'], false, false, false],
    'editor'                       => [['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.editor'], false, false, true],
    'own-only on another\'s asset' => [['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.editor'], true, false, false],
    'own-only on own asset'        => [['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.editor'], true, true, true],
]);

it('saves a thumbnail without touching the original or adding assets', function () {
    $model = capabilityAsset('m.glb', 'document', 'model/gltf-binary', 'glb-bytes');
    $count = Asset::count();
    $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$model->id.'/custom-thumbnail', ['thumbnail' => UploadedFile::fake()->image('c.png')])->assertCreated();
    expect(Storage::disk(Directory::getAssetDisk())->get($model->path))->toBe('glb-bytes');
    expect(Asset::count())->toBe($count);
});

it('offers no original edit when the recorded MIME says the bytes are not PNG, JPEG or WebP', function (string $mime, bool $offered) {
    $asset = capabilityAsset('renamed.png', 'image', $mime);
    expect(ImageEditing::originalEditable($asset))->toBe($offered);
})->with([['image/gif', false], ['image/svg+xml', false], ['image/png', true], ['', true], ['application/octet-stream', true]]);

it('offers a thumbnail edit for a stored generated thumbnail but not for a placeholder, a missing original or a lazy poster', function (string $name, string $type, string $mime, string $metaKey) {
    $asset = capabilityAsset($name, $type, $mime);
    $disk = Storage::disk(Directory::getAssetDisk());
    // No thumbnail on disk yet: the route would answer the type placeholder, so there is nothing to edit.
    expect(ImageEditing::previewFields($asset)['canEditThumbnail'])->toBeFalse();

    $thumb = 'thumbs/'.uniqid().'.jpg';
    $disk->put($thumb, UploadedFile::fake()->image('t.jpg')->getContent());
    $asset->update(['meta_data' => [$metaKey => $thumb]]);
    $fields = ImageEditing::previewFields($asset->fresh());
    expect($fields['canEditThumbnail'])->toBeTrue()->and($fields['canEditOriginal'])->toBeFalse();

    // The thumbnail is derived from the original: once its bytes are gone the route shows the placeholder.
    $disk->delete($asset->files()->where('role', 'original')->with('fileObject')->firstOrFail()->fileObject->path);
    expect(ImageEditing::previewFields($asset->fresh())['canEditThumbnail'])->toBeFalse();
})->with([
    'captured model view' => ['m.glb', 'document', 'model/gltf-binary', 'thumbnail_path'],
    'pdf poster'          => ['d.pdf', 'document', 'application/pdf', 'thumbnail_path'],
    'audio cover art'     => ['a.mp3', 'audio', 'audio/mpeg', 'cover_art_path'],
]);

it('lets a custom thumbnail win when the original is unavailable, as the route does', function () {
    $asset = capabilityAsset('m.glb', 'document', 'model/gltf-binary');
    $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/custom-thumbnail', ['thumbnail' => UploadedFile::fake()->image('c.png')])->assertCreated();
    Storage::disk(Directory::getAssetDisk())->delete($asset->files()->where('role', 'original')->with('fileObject')->firstOrFail()->fileObject->path);
    expect(ImageEditing::previewFields($asset->fresh())['canEditThumbnail'])->toBeTrue();
});

it('follows the thumbnail route: a recorded path that is gone is not rescued by a cached poster', function () {
    $asset = capabilityAsset('d.pdf', 'document', 'application/pdf');
    $disk = Storage::disk(Directory::getAssetDisk());
    $object = $asset->files()->where('role', 'original')->with('fileObject')->firstOrFail()->fileObject;
    $poster = DerivativeImages::poster($object->sha256);
    $disk->put($poster, UploadedFile::fake()->image('p.jpg')->getContent());

    // No recorded path: the poster keyed by the original's bytes is what the route serves.
    expect(ImageEditing::previewFields($asset->fresh())['canEditThumbnail'])->toBeTrue();

    // A recorded path that is missing on disk wins over the poster in the route (which then regenerates), so nothing is offered.
    $asset->update(['meta_data' => ['thumbnail_path' => 'thumbs/gone.jpg']]);
    expect(ImageEditing::previewFields($asset->fresh())['canEditThumbnail'])->toBeFalse();
});

it('offers a thumbnail edit for a video, 360 video, Blender or Unity package poster only once it exists on disk', function (string $name, string $type, string $mime, ?string $kind) {
    $asset = capabilityAsset($name, $type, $mime);
    $asset->update(['asset_kind' => $kind]);
    // The poster is made lazily on first view; until then the route would answer the type placeholder.
    expect(ImageEditing::previewFields($asset->fresh())['canEditThumbnail'])->toBeFalse();

    $object = $asset->files()->where('role', 'original')->with('fileObject')->firstOrFail()->fileObject;
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($object->sha256), UploadedFile::fake()->image('p.jpg')->getContent());
    $fields = ImageEditing::previewFields($asset->fresh());
    expect($fields['canEditThumbnail'])->toBeTrue()->and($fields['canEditOriginal'])->toBeFalse()
        ->and($fields['thumbnailEditUrl'])->toContain('path='.urlencode(urlencode($asset->path)));
})->with([
    'video poster'                      => ['v.mp4', 'video', 'video/mp4', null],
    '360 video poster'                  => ['pano.mp4', 'video', 'video/mp4', 'pano360'],
    'Blender embedded thumbnail'        => ['scene.blend', 'document', 'application/x-blender', null],
    'Unity package embedded thumbnail'  => ['kit.unitypackage', 'document', 'application/gzip', null],
]);

it('offers no thumbnail edit for a Unity YAML file, which has no embedded thumbnail, even with a poster-shaped file present', function () {
    $asset = capabilityAsset('Scene.unity', 'document', 'text/plain');
    $object = $asset->files()->where('role', 'original')->with('fileObject')->firstOrFail()->fileObject;
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::poster($object->sha256), UploadedFile::fake()->image('p.jpg')->getContent());
    expect(ImageEditing::previewFields($asset->fresh())['canEditThumbnail'])->toBeFalse();
});
