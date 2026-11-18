<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
});

function apiThumbnailModel(string $bytes = 'synthetic model'): Asset
{
    $path = 'assets/'.Str::uuid().'.glb';
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);

    return Asset::factory()->create([
        'path'      => $path, 'file_name' => 'example.glb', 'extension' => 'glb',
        'file_type' => 'document', 'mime_type' => 'model/gltf-binary', 'file_size' => strlen($bytes),
    ]);
}

function apiThumbnailFile(Asset $asset): AssetFile
{
    return $asset->files()->with('fileObject')->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
}

function apiThumbnailRoute(Asset $asset, string $action = 'show', ?AssetFile $file = null): string
{
    return route('admin.api.dam.assets.'.($file === null ? '' : 'files.').'model-thumbnail.'.$action,
        array_filter(['asset' => $asset->id, 'file' => $file?->id]));
}

function apiThumbnailCapture(int $size = 200): UploadedFile
{
    return UploadedFile::fake()->image('capture.png', $size, $size);
}

it('requires bearer authentication and separates capture read write and download scopes', function (bool $compound) {
    $asset = apiThumbnailModel();
    $file = $compound ? apiThumbnailFile($asset) : null;
    $this->getJson(apiThumbnailRoute($asset, file: $file))->assertUnauthorized();
    $none = $this->getAuthenticationHeaders('custom', []);
    auth()->forgetGuards();
    $this->withHeaders($none)->getJson(apiThumbnailRoute($asset, file: $file))->assertForbidden();
    $read = $this->getAuthenticationHeaders('custom', ['api.dam.assets.model-thumbnails.read']);
    auth()->forgetGuards();
    $state = $this->withHeaders($read)->getJson(apiThumbnailRoute($asset, file: $file))->assertOk()->assertJsonPath('can_capture', false)->json();
    $this->postJson(apiThumbnailRoute($asset, 'store', $file), [])->assertForbidden();
    $this->getJson($state['source_url'])->assertForbidden();
    $this->getJson($state['thumbnail_url'])->assertNotFound();
})->with([false, true]);

it('round trips checksum pinned model bytes and a capture while preserving saved views', function (bool $legacyEncoded) {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $file = apiThumbnailFile($asset);
    $view = ['sha256' => $file->fileObject->sha256, 'view' => ['distance' => 2.5, 'aspect' => 1.6], 'revision' => 'saved-view'];
    $metadata = ['glb_primary_view' => $view, 'keep' => 'metadata', 'FileName' => 'example.glb', 'exif' => ['FileSize' => '10 MB']];
    $asset->update(['meta_data' => $legacyEncoded ? json_encode($metadata) : $metadata]);
    $state = $this->getJson(apiThumbnailRoute($asset))->assertOk()
        ->assertJsonPath('primary_view.sha256', $view['sha256'])
        ->assertJsonPath('primary_view.view.distance', 2.5)
        ->assertJsonPath('primary_view.view.aspect', 1.6)
        ->assertJsonPath('primary_view.revision', 'saved-view')->json();
    $source = $this->get($state['source_url'])->assertOk();
    expect($source->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect(file_get_contents($source->baseResponse->getFile()->getPathname()))->toBe('synthetic model');
    $stored = $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(), 'checksum' => $state['checksum'],
    ])->assertOk()->assertJsonPath('stored', true)->json();
    $readback = $this->get($stored['thumbnail_url'])->assertOk()->streamedContent();
    expect(getimagesizefromstring($readback)[2])->toBe(IMAGETYPE_JPEG);
    $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(300), 'checksum' => $state['checksum'],
    ])->assertOk()->assertJsonPath('skipped', 'exists');
    expect($this->get($stored['thumbnail_url'])->streamedContent())->toBe($readback);
    $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(300), 'checksum' => $state['checksum'], 'replace' => 1,
    ])->assertOk()->assertJsonPath('stored', true);
    expect($this->get($stored['thumbnail_url'])->streamedContent())->not->toBe($readback);
    expect($asset->fresh()->meta_data['glb_primary_view'])->toEqual($view)
        ->and($asset->fresh()->meta_data['keep'])->toBe('metadata')
        ->and(apiThumbnailFile($asset)->file_object_id)->toBe($file->file_object_id);
    expect(array_diff_key($asset->fresh()->meta_data, array_flip(['thumbnail_path', 'thumbnail_revision'])))->toBe($metadata);
})->with([false, true]);

it('preserves a custom card while keeping a readable generated capture', function (bool $legacyEncoded) {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $meta = ['custom_thumbnail' => ['path' => 'custom-thumbnails/keep.jpg', 'revision' => 'keep']];
    $asset->update(['meta_data' => $legacyEncoded ? json_encode($meta) : $meta]);
    $rawBefore = $asset->fresh()->getRawOriginal('meta_data');
    $stored = $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(), 'checksum' => apiThumbnailFile($asset)->fileObject->sha256, 'replace' => 1,
    ])->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'custom')->json();
    $this->get($stored['thumbnail_url'])->assertOk();
    expect($asset->fresh()->meta_data)->toEqual($meta);
    expect($asset->fresh()->getRawOriginal('meta_data'))->toBe($rawBefore);
})->with([false, true]);

it('validates images and rejects stale capture and bytes requests', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $checksum = apiThumbnailFile($asset)->fileObject->sha256;
    foreach ([UploadedFile::fake()->create('bad.txt', 1, 'text/plain'), apiThumbnailCapture(1025)] as $image) {
        $this->postJson(apiThumbnailRoute($asset, 'store'), ['image' => $image, 'checksum' => $checksum])->assertUnprocessable();
    }
    $this->postJson(apiThumbnailRoute($asset, 'store'), ['image' => apiThumbnailCapture(), 'checksum' => 'bad'])->assertUnprocessable();
    $stale = str_repeat('0', 64);
    $this->postJson(apiThumbnailRoute($asset, 'store'), ['image' => apiThumbnailCapture(), 'checksum' => $stale])->assertConflict();
    foreach (['source', 'image'] as $action) {
        $this->getJson(apiThumbnailRoute($asset, $action).'?checksum='.$stale)->assertConflict();
    }
});

it('merges a first capture into legacy encoded metadata without losing extracted fields', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $metadata = ['FileName' => 'example.glb', 'FileSize' => '10 MB', 'FileModifyDate' => '2026:10:21 10:44:04+00:00', 'exif' => ['FileName' => 'example.glb']];
    $asset->update(['meta_data' => json_encode($metadata)]);
    $file = apiThumbnailFile($asset);
    $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(), 'checksum' => $file->fileObject->sha256,
    ])->assertOk()->assertJsonPath('stored', true);
    $persisted = json_decode($asset->fresh()->getRawOriginal('meta_data'), true);
    expect(array_diff_key($persisted, array_flip(['thumbnail_path', 'thumbnail_revision'])))->toBe($metadata)
        ->and(apiThumbnailFile($asset)->file_object_id)->toBe($file->file_object_id);
});

it('reads and serializes legacy metadata without changing stored bytes', function ($raw, $expected) {
    $asset = apiThumbnailModel();
    DB::table('dam_assets')->where('id', $asset->id)->update(['meta_data' => $raw]);
    $storedBefore = DB::table('dam_assets')->where('id', $asset->id)->value('meta_data');
    $loaded = $asset->fresh();
    expect($loaded->meta_data)->toBe($expected)
        ->and($loaded->toArray()['meta_data'])->toBe($expected)
        ->and(DB::table('dam_assets')->where('id', $asset->id)->value('meta_data'))->toBe($storedBefore);
})->with([
    [null, null],
    [json_encode(['FileName' => 'example.glb']), ['FileName'              => 'example.glb']],
    [json_encode(json_encode(['FileName' => 'example.glb'])), ['FileName' => 'example.glb']],
    [json_encode('[]'), []],
]);

it('preserves legacy custom stack artwork when capturing beside it', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $meta = ['stack_cover' => ['path' => 'custom-thumbnails/'.$asset->id.'/'.Str::uuid().'.jpg'], 'FileName' => 'example.glb'];
    $asset->update(['meta_data' => json_encode($meta)]);
    $rawBefore = $asset->fresh()->getRawOriginal('meta_data');
    $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(), 'checksum' => apiThumbnailFile($asset)->fileObject->sha256,
    ])->assertOk()->assertJsonPath('stored', false)->assertJsonPath('skipped', 'custom')->assertJsonPath('captured', true);
    expect($asset->fresh()->meta_data)->toBe($meta)
        ->and($asset->fresh()->getRawOriginal('meta_data'))->toBe($rawBefore);
});

it('binds compound captures to their owner and blob without changing the cover', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $other = apiThumbnailModel('different model');
    $original = apiThumbnailFile($other);
    $member = $asset->files()->create(['file_object_id' => $original->file_object_id, 'role' => 'sidecar', 'label' => 'part.glb']);
    $meta = $asset->meta_data;
    $state = $this->getJson(apiThumbnailRoute($asset, file: $member))->assertOk()->assertJsonPath('primary_view', null)->json();
    expect($state['checksum'])->toBe($original->fileObject->sha256);
    $this->getJson(apiThumbnailRoute($other, file: $member))->assertNotFound();
    $this->postJson(apiThumbnailRoute($asset, 'store', $member), [
        'image' => apiThumbnailCapture(), 'checksum' => $state['checksum'],
    ])->assertOk()->assertJsonPath('stored', true);
    $this->get($state['thumbnail_url'])->assertOk();
    $source = $this->get($state['source_url'])->assertOk();
    expect(file_get_contents($source->baseResponse->getFile()->getPathname()))->toBe('different model');
    expect($asset->fresh()->meta_data)->toEqual($meta);
});

it('hides inaccessible models on every capture endpoint', function () {
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => Role::factory()->create([
        'permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ])->id]);
    $asset = apiThumbnailModel();
    $file = apiThumbnailFile($asset);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);
    $this->withHeaders($headers);
    $routes = [
        'admin.api.dam.assets.model-thumbnail.show',
        'admin.api.dam.assets.model-thumbnail.source',
        'admin.api.dam.assets.model-thumbnail.image',
        'admin.api.dam.assets.files.model-thumbnail.show',
        'admin.api.dam.assets.files.model-thumbnail.source',
        'admin.api.dam.assets.files.model-thumbnail.image',
    ];
    foreach ($routes as $route) {
        $hidden = $this->getJson(route($route, ['asset' => $asset->id, 'file' => $file->id]))->assertNotFound();
        $missing = $this->getJson(route($route, ['asset' => $asset->id + 1000000, 'file' => $file->id]))->assertNotFound();
        expect($hidden->getContent())->toBe($missing->getContent());
    }
    foreach ([null, $file] as $selected) {
        $this->postJson(apiThumbnailRoute($asset, 'store', $selected), [])->assertNotFound();
    }
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $state = $this->getJson(apiThumbnailRoute($asset))->assertOk()->json();
    $this->get($state['source_url'])->assertOk();
});

it('enforces edit and own-items rules even when the integration has capture write scope', function (bool $compound) {
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->reject(fn ($key) => $key === 'dam.asset.edit')->values()->all(),
    ]);
    $member->update(['role_id' => $role->id]);
    $asset = apiThumbnailModel();
    $file = $compound ? apiThumbnailFile($asset) : null;
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->withHeaders($headers)->getJson(apiThumbnailRoute($asset, file: $file))->assertOk()->assertJsonPath('can_capture', false);
    $this->postJson(apiThumbnailRoute($asset, 'store', $file), [])->assertForbidden();

    $role->update(['permissions' => collect(config('acl'))->pluck('key')->unique()->values()->all()]);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $role->id], ['delete_own_only' => true]);
    $member->unsetRelation('role');
    auth()->forgetGuards();
    app(DirectoryPermissionService::class)->flush();
    $this->postJson(apiThumbnailRoute($asset, 'store', $file), [])->assertForbidden();
    $asset->update(['created_by' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->postJson(apiThumbnailRoute($asset, 'store', $file), [
        'image' => apiThumbnailCapture(), 'checksum' => apiThumbnailFile($asset)->fileObject->sha256,
    ])->assertOk();
})->with([false, true]);

it('serves the new card capture even when an older derivative exists', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $file = apiThumbnailFile($asset);
    $checksum = $file->fileObject->sha256;
    app(ModelFileThumbnailService::class)->store(object: $file->fileObject, upload: apiThumbnailCapture(100), assetId: $asset->id);
    $this->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('has_thumbnail', false);
    $stored = $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(300), 'checksum' => $checksum, 'replace' => 1,
    ])->assertOk()->assertJsonPath('stored', true)->json();
    $disk = Storage::disk(Directory::getAssetDisk());
    $card = $disk->get(ModelThumbnailService::generatedPath($asset, $checksum));
    $old = $disk->get(DerivativeImages::modelCapture($checksum, $asset->id));
    expect($this->get($stored['thumbnail_url'])->assertOk()->streamedContent())->toBe($card)->not->toBe($old);
});

it('reads an adopted current-byte derivative card', function (bool $legacy) {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $file = apiThumbnailFile($asset);
    $checksum = $file->fileObject->sha256;
    app(ModelFileThumbnailService::class)->store(object: $file->fileObject, upload: apiThumbnailCapture(), assetId: $asset->id);
    $disk = Storage::disk(Directory::getAssetDisk());
    $storedPath = DerivativeImages::modelCapture($checksum, $asset->id);
    $path = $legacy ? DerivativeImages::modelCapture($checksum) : $storedPath;
    $bytes = $disk->get($storedPath);
    $disk->put($path, $bytes);
    $asset->update(['meta_data' => ['thumbnail_path' => $path]]);
    $state = $this->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('has_thumbnail', true)->json();
    expect($this->get($state['thumbnail_url'])->assertOk()->streamedContent())->toBe($bytes);
})->with([false, true]);

it('keeps download-only and write-only keys separate on both resource families', function (bool $compound) {
    $asset = apiThumbnailModel();
    $original = apiThumbnailFile($asset);
    $file = $compound ? $original : null;
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.download']);
    auth()->forgetGuards();
    $this->withHeaders($headers)->get(apiThumbnailRoute($asset, 'source', $file).'?checksum='.$original->fileObject->sha256)->assertOk();
    $this->getJson(apiThumbnailRoute($asset, file: $file))->assertForbidden();
    $this->postJson(apiThumbnailRoute($asset, 'store', $file), [])->assertForbidden();
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.model-thumbnails.write']);
    auth()->forgetGuards();
    $this->withHeaders($headers)->postJson(apiThumbnailRoute($asset, 'store', $file), [
        'image' => apiThumbnailCapture(), 'checksum' => $original->fileObject->sha256,
    ])->assertOk();
    $this->getJson(apiThumbnailRoute($asset, file: $file))->assertForbidden();
    $this->getJson(apiThumbnailRoute($asset, 'source', $file).'?checksum='.$original->fileObject->sha256)->assertForbidden();
})->with([false, true]);

it('does not report or read a card capture belonging to other bytes or another asset', function (string $kind) {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $file = apiThumbnailFile($asset);
    $checksum = $file->fileObject->sha256;
    app(ModelFileThumbnailService::class)->store(object: $file->fileObject, upload: apiThumbnailCapture(), assetId: $asset->id);
    $disk = Storage::disk(Directory::getAssetDisk());
    $path = match ($kind) {
        'old-card'    => ModelThumbnailService::generatedPath($asset, hash('sha256', 'old bytes')),
        'other-bytes' => DerivativeImages::modelCapture(hash('sha256', 'other bytes'), $asset->id),
        'other-asset' => DerivativeImages::modelCapture($checksum, $asset->id + 1),
    };
    $disk->put($path, $disk->get(DerivativeImages::modelCapture($checksum, $asset->id)));
    $asset->update(['meta_data' => ['thumbnail_path' => $path]]);
    $state = $this->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('has_thumbnail', false)->json();
    $this->get($state['thumbnail_url'])->assertNotFound();
    $this->postJson(apiThumbnailRoute($asset, 'store'), [
        'image' => apiThumbnailCapture(), 'checksum' => $checksum,
    ])->assertOk()->assertJsonPath('stored', true);
    $this->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('has_thumbnail', true);
})->with(['old-card', 'other-bytes', 'other-asset']);

it('refuses superseded compound revisions and trashed assets', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    $file = apiThumbnailFile($asset);
    $file->update(['is_current' => false]);
    foreach (['show', 'source', 'image'] as $action) {
        $this->getJson(apiThumbnailRoute($asset, $action, $file))->assertNotFound();
    }
    $this->postJson(apiThumbnailRoute($asset, 'store', $file), [])->assertNotFound();
    $asset->delete();
    foreach (['show', 'source', 'image'] as $action) {
        $this->getJson(apiThumbnailRoute($asset, $action))->assertNotFound();
    }
    $this->postJson(apiThumbnailRoute($asset, 'store'), [])->assertNotFound();
});

it('reports model extensions and ignores a saved view for different bytes', function () {
    $this->withHeaders($this->getAuthenticationHeaders());
    $asset = apiThumbnailModel();
    apiThumbnailFile($asset)->update(['label' => null]);
    $asset->update(['file_name' => 'label without suffix', 'meta_data' => ['glb_primary_view' => ['sha256' => str_repeat('0', 64), 'view' => ['distance' => 9, 'aspect' => 1]]]]);
    $this->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('extension', 'glb')->assertJsonPath('primary_view', null);
    foreach (['stl', '3mf'] as $extension) {
        $asset->update(['extension' => $extension]);
        $this->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('extension', $extension);
    }
    $asset->update(['extension' => 'png']);
    $this->getJson(apiThumbnailRoute($asset))->assertUnprocessable();
});

it('keeps a visible grants-model first-folder refusal distinct from a hidden model', function () {
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => Role::factory()->create([
        'permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit'],
    ])->id]);
    $asset = apiThumbnailModel();
    $first = Directory::factory()->create();
    $second = Directory::factory()->create();
    $asset->directories()->attach([$first->id, $second->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $second->id, 'role_id' => $member->role_id]);
    app(DirectoryPermissionService::class)->flush();
    $this->withHeaders($headers)->getJson(apiThumbnailRoute($asset))->assertOk()->assertJsonPath('can_capture', false);
    $this->postJson(apiThumbnailRoute($asset, 'store'), [])->assertForbidden();
    DB::table('dam_directory_role')->where('role_id', $member->role_id)->delete();
    app(DirectoryPermissionService::class)->flush();
    $this->postJson(apiThumbnailRoute($asset, 'store'), [])->assertNotFound();
});
