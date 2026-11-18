<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetRelationshipService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * A 3D file inside a stack is captured by the browser that opens it (ADR 0006), but never against the stack's own
 * thumbnail: the picture is stored beside the file's blob, per asset, `derivatives/<sha[0:2]>/<sha>/model-capture-a<id>.jpg`
 * (ADR 0009, PERM-01), behind
 * its own pair of routes with the asset capture's rights. The files payload names it once it exists.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Stacked models '.uniqid()]);
    $this->coverBytes = 'cover-thumbnail-bytes';
    $this->coverPath = 'thumbnails/models/keep-me.jpg';
    Storage::disk(Directory::getAssetDisk())->put($this->coverPath, $this->coverBytes);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'stack.glb', 'path' => 'assets/Stacked/stack.glb', 'extension' => 'glb',
        'meta_data' => ['thumbnail_path' => $this->coverPath, 'thumbnail_revision' => 'cover-revision'],
    ]));
    $this->asset->directories()->attach($this->folder->id);
    $this->base = '/'.config('app.admin_url').'/dam/assets';
});

$bytes = fn (string $seed = 'a'): string => "glTF\x02\x00\x00\x00".str_repeat($seed, 64);

$attach = fn (object $test, string $name, string $content, ?Asset $asset = null) => $test->postJson(
    $test->base.'/'.($asset ?? $test->asset)->id.'/files',
    ['file' => UploadedFile::fake()->createWithContent($name, $content), 'role' => 'sidecar', 'label' => $name],
)->assertCreated()->json();

$endpoint = fn (object $test, array $file) => $test->base.'/files/'.$file['id'].'/model-thumbnail';

$capture = fn (int $width = 512, int $height = 512) => UploadedFile::fake()->image('capture.png', $width, $height);

$body = fn ($response) => $response->baseResponse instanceof BinaryFileResponse
    ? file_get_contents($response->baseResponse->getFile()->getPathname())
    : $response->getContent();

it('stores a stacked file’s capture beside its blob for its asset, a JPEG of at most 512px, and leaves the stack’s own thumbnail alone', function () use ($attach, $bytes, $endpoint, $capture) {
    $file = $attach($this, 'part.glb', $bytes());
    $meta = $this->asset->fresh()->meta_data;

    $this->postJson($endpoint($this, $file), ['image' => $capture(1024, 768), 'checksum' => $file['sha256']])
        ->assertOk()->assertJsonPath('stored', true)->assertJsonPath('skipped', null)
        ->assertJsonPath('thumbnail_url', route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'thumb' => 1]));

    $disk = Storage::disk(Directory::getAssetDisk());
    $path = DerivativeImages::modelCapture($file['sha256'], $this->asset->id);
    $info = getimagesizefromstring($disk->get($path));

    expect($path)->toBe('derivatives/'.substr($file['sha256'], 0, 2).'/'.$file['sha256'].'/model-capture-a'.$this->asset->id.'.jpg')
        ->and($info[2])->toBe(IMAGETYPE_JPEG)
        ->and([$info[0], $info[1]])->toBe([512, 384])
        // The cover's thumbnail flow (ADR 0006) is untouched: same metadata, same bytes, no per-asset capture written.
        ->and($this->asset->fresh()->meta_data)->toBe($meta)
        ->and($disk->get($this->coverPath))->toBe($this->coverBytes)
        ->and($disk->allFiles('thumbnails/models'))->toBe([$this->coverPath]);
});

it('reports the binding checksum and whether the file has a capture, then skips a repeat and replaces on request', function () use ($attach, $bytes, $endpoint, $capture) {
    $file = $attach($this, 'part.glb', $bytes());
    $url = $endpoint($this, $file);
    $disk = Storage::disk(Directory::getAssetDisk());
    $path = DerivativeImages::modelCapture($file['sha256'], $this->asset->id);

    $this->getJson($url)->assertOk()
        ->assertExactJson(['checksum' => $file['sha256'], 'has_thumbnail' => false, 'can_edit' => true, 'can_capture' => true, 'can_replace' => true]);

    $this->postJson($url, ['image' => $capture(), 'checksum' => $file['sha256']])->assertOk()->assertJsonPath('stored', true);
    $first = $disk->get($path);

    $this->getJson($url)->assertOk()->assertJsonPath('has_thumbnail', true);

    $this->postJson($url, ['image' => $capture(200, 200), 'checksum' => $file['sha256']])
        ->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'exists');
    expect($disk->get($path))->toBe($first);

    $this->postJson($url, ['image' => $capture(200, 200), 'checksum' => $file['sha256'], 'replace' => 1])
        ->assertOk()->assertJsonPath('stored', true);
    expect($disk->get($path))->not->toBe($first);
});

it('rejects a capture bound to bytes the file no longer holds', function () use ($attach, $bytes, $endpoint, $capture) {
    $file = $attach($this, 'part.glb', $bytes());

    $this->postJson($endpoint($this, $file), ['image' => $capture(), 'checksum' => str_repeat('a', 64)])->assertStatus(409);

    expect(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::modelCapture($file['sha256'], $this->asset->id)))->toBeFalse()
        ->and(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::modelCapture(str_repeat('a', 64))))->toBeFalse();
});

it('refuses files the viewer does not render, and captures that are not bounded images', function () use ($attach, $bytes, $endpoint, $capture) {
    $notes = $attach($this, 'notes.txt', 'plain notes');
    $obj = $attach($this, 'part.obj', 'v 0 0 0');

    foreach ([$notes, $obj] as $file) {
        $this->getJson($endpoint($this, $file))->assertUnprocessable();
        $this->postJson($endpoint($this, $file), ['image' => $capture(), 'checksum' => $file['sha256']])->assertUnprocessable();
    }

    $model = $attach($this, 'part.glb', $bytes());
    $url = $endpoint($this, $model);

    $this->postJson($url, ['image' => $capture(2048, 2048), 'checksum' => $model['sha256']])->assertUnprocessable();
    $this->postJson($url, ['image' => UploadedFile::fake()->createWithContent('x.png', 'not an image'), 'checksum' => $model['sha256']])->assertUnprocessable();
    $this->postJson($url, ['image' => $capture()])->assertUnprocessable();
    $this->postJson($url, ['checksum' => $model['sha256']])->assertUnprocessable();

    expect(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::modelCapture($model['sha256'], $this->asset->id)))->toBeFalse();
});

it('needs edit rights on the owning asset, and a viewer without folder access cannot tell the file exists', function (bool $edit, bool $access) use ($attach, $bytes, $endpoint, $capture) {
    $file = $attach($this, 'part.glb', $bytes());

    $permissions = ['dam', 'dam.asset', 'dam.asset.view'];
    if ($edit) {
        $permissions[] = 'dam.asset.edit';
    }
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    if ($access) {
        DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $role->id]);
    }
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);

    $store = $this->postJson($endpoint($this, $file), ['image' => $capture(), 'checksum' => $file['sha256']]);
    $show = $this->getJson($endpoint($this, $file));

    if ($access) {
        $store->assertForbidden();
        $show->assertOk()->assertJsonPath('can_edit', false);
    } else {
        // Exactly the answer an id nothing holds gets.
        $missing = $this->getJson($this->base.'/files/'.(DB::table('dam_asset_files')->max('id') + 1000).'/model-thumbnail');
        $store->assertNotFound();
        $show->assertNotFound();
        expect($show->getContent())->toBe($missing->getContent());
    }

    expect(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::modelCapture($file['sha256'], $this->asset->id)))->toBeFalse();
})->with([[false, true], [true, false]]);

it('names the capture in the files payload only once it exists, and serves it as the file’s small picture', function () use ($attach, $bytes, $endpoint, $capture, $body) {
    $file = $attach($this, 'part.glb', $bytes());
    $thumb = fn (array $query = ['thumb' => 1]) => $this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id']] + $query));
    $listed = fn () => collect($this->getJson(route('admin.dam.assets.files.index', $this->asset->id))->assertOk()->json('files'))->keyBy('id')[$file['id']];

    expect($file['thumbnail_url'])->toBeNull()
        ->and($listed()['thumbnail_url'])->toBeNull();
    // Nothing to serve yet, and the file's own bytes are still what a plain fetch returns.
    $thumb()->assertNotFound();
    expect($body($thumb([])->assertOk()))->toBe($bytes());

    $this->postJson($endpoint($this, $file), ['image' => $capture(), 'checksum' => $file['sha256']])->assertOk();

    $picture = $body($thumb()->assertOk());
    expect($listed()['thumbnail_url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $file['id'], 'thumb' => 1]))
        ->and(getimagesizefromstring($picture)[2])->toBe(IMAGETYPE_JPEG)
        ->and($picture)->toBe(Storage::disk(Directory::getAssetDisk())->get(DerivativeImages::modelCapture($file['sha256'], $this->asset->id)))
        // ?download=1 still wins: a download is always the original.
        ->and($body($thumb(['thumb' => 1, 'download' => 1])->assertOk()))->toBe($bytes());
});

it('keeps a capture to its own stack, and shows a capture written before per-asset captures to every stack holding the blob', function () use ($attach, $bytes, $endpoint, $capture, $body) {
    $other = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'other.glb', 'path' => 'assets/Stacked/other.glb', 'extension' => 'glb']));
    $other->directories()->attach($this->folder->id);

    $first = $attach($this, 'part.glb', $bytes());
    $second = $attach($this, 'copy.glb', $bytes(), $other);
    $secondThumb = fn () => $this->get(route('admin.dam.file.asset-file', ['asset' => $other->id, 'assetFile' => $second['id'], 'thumb' => 1]));

    expect($second['sha256'])->toBe($first['sha256'])->and($second['thumbnail_url'])->toBeNull();

    $this->postJson($endpoint($this, $first), ['image' => $capture(), 'checksum' => $first['sha256']])->assertOk()->assertJsonPath('stored', true);

    // PERM-01: the other stack holding the same bytes gets no picture from it.
    $this->getJson($endpoint($this, $second))->assertOk()->assertJsonPath('has_thumbnail', false);
    $secondThumb()->assertNotFound();

    // A capture keyed by the blob alone, from before, is every holder's fallback until it has its own.
    $legacy = UploadedFile::fake()->image('legacy.jpg', 64, 64)->get();
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::modelCapture($second['sha256']), $legacy);

    $listed = collect($this->getJson(route('admin.dam.assets.files.index', $other->id))->assertOk()->json('files'))->keyBy('id');
    expect($listed[$second['id']]['thumbnail_url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $other->id, 'assetFile' => $second['id'], 'thumb' => 1]))
        ->and($body($secondThumb()->assertOk()))->toBe($legacy);
    $this->postJson($endpoint($this, $second), ['image' => $capture(), 'checksum' => $second['sha256']])->assertOk()->assertJsonPath('skipped', 'exists');
});

it('lists a file that has no blob without a picture instead of failing the whole list', function () {
    $file = new AssetFile(['label' => 'orphan.glb']);
    $file->id = 4242;

    $payload = app(AssetRelationshipService::class)->formatFile($file, $this->asset);

    expect($payload['thumbnail_url'])->toBeNull()->and($payload['sha256'])->toBeNull();
});

it('lists a stacked file\'s own recorded pixel size, and null where the upload recorded none', function () {
    $measured = new AssetFile(['label' => 'shot.png']);
    $measured->id = 4243;
    $measured->setRelation('fileObject', new FileObject(['technical' => ['width' => 800, 'height' => '600']]));
    $bare = new AssetFile(['label' => 'part.glb']);
    $bare->id = 4244;
    $bare->setRelation('fileObject', new FileObject(['technical' => null]));
    $service = app(AssetRelationshipService::class);

    expect($service->formatFile($measured, $this->asset))->toMatchArray(['width' => 800, 'height' => 600])
        ->and($service->formatFile($bare, $this->asset))->toMatchArray(['width' => null, 'height' => null]);
});

it('reads a stacked file\'s recorded pixel size from the nested shapes the dimension reader accepts', function () {
    $service = app(AssetRelationshipService::class);
    $sized = function (int $id, array $technical) {
        $file = new AssetFile(['label' => 'shot.png']);
        $file->id = $id;
        $file->setRelation('fileObject', new FileObject(['technical' => $technical]));

        return $file;
    };

    expect($service->formatFile($sized(4245, ['dimensions' => ['width' => 1024, 'height' => '768']]), $this->asset))->toMatchArray(['width' => 1024, 'height' => 768])
        ->and($service->formatFile($sized(4246, ['image' => ['width' => 640, 'height' => 480]]), $this->asset))->toMatchArray(['width' => 640, 'height' => 480])
        // Flat values win; a half-recorded or non-positive pair reads as nothing, and width/height never mix shapes.
        ->and($service->formatFile($sized(4247, ['width' => 10, 'height' => 20, 'image' => ['width' => 640, 'height' => 480]]), $this->asset))->toMatchArray(['width' => 10, 'height' => 20])
        ->and($service->formatFile($sized(4248, ['width' => 800, 'image' => ['width' => 640, 'height' => 480]]), $this->asset))->toMatchArray(['width' => 640, 'height' => 480])
        ->and($service->formatFile($sized(4249, ['dimensions' => ['width' => 0, 'height' => 480]]), $this->asset))->toMatchArray(['width' => null, 'height' => null]);
});

// PERM-01: the same bytes stacked in an asset the caller may not edit keep their picture. A caller who cannot change
// every asset holding the blob writes their own asset's capture, never the shared one, with or without replace.
it('never changes the picture of an asset the caller may not edit through a blob they share', function (bool $sharedFirst) use ($attach, $bytes, $endpoint, $capture, $body) {
    $hiddenFolder = Directory::create(['name' => 'Admins only '.uniqid()]);
    $hidden = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'hidden.glb', 'path' => 'assets/Hidden/hidden.glb', 'extension' => 'glb']));
    $hidden->directories()->attach($hiddenFolder->id);

    $mine = $attach($this, 'part.glb', $bytes());
    $theirs = $attach($this, 'part.glb', $bytes(), $hidden);
    $theirThumb = fn () => $this->get(route('admin.dam.file.asset-file', ['asset' => $hidden->id, 'assetFile' => $theirs['id'], 'thumb' => 1]));

    if ($sharedFirst) {
        // A capture keyed by the blob alone, written before captures were per asset: both assets show it.
        Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::modelCapture($mine['sha256']), UploadedFile::fake()->image('old.jpg', 64, 64)->get());
    }
    $before = $sharedFirst ? $body($theirThumb()->assertOk()) : null;

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $role->id]);
    $staff = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    $admin = auth('admin')->user();
    $this->actingAs($staff, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson($endpoint($this, $mine), ['image' => $capture(300, 200), 'checksum' => $mine['sha256'], 'replace' => 1])
        ->assertOk()->assertJsonPath('stored', true);
    $staffPicture = $body($this->get(route('admin.dam.file.asset-file', ['asset' => $this->asset->id, 'assetFile' => $mine['id'], 'thumb' => 1]))->assertOk());

    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(getimagesizefromstring($staffPicture)[0])->toBe(300)
        ->and(Storage::disk(Directory::getAssetDisk())->exists(DerivativeImages::modelCapture($mine['sha256'], $this->asset->id)))->toBeTrue();

    if ($sharedFirst) {
        expect($body($theirThumb()->assertOk()))->toBe($before)->not->toBe($staffPicture);
    } else {
        $theirThumb()->assertNotFound();
    }
})->with(['a capture shared by the blob exists' => [true], 'no capture yet' => [false]]);
