<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mothership\DAM\Jobs\BuildWebPageBundle;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetRename;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->headers = $this->getAuthenticationHeaders();
});

it('lists assets via the api index endpoint', function () {
    Asset::factory()->count(2)->create();

    $response = $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.assets.index'));

    $response->assertOk();
});

it('shows an asset via the api show endpoint', function () {
    $asset = Asset::factory()->create();

    $response = $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.assets.show', $asset->id));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.asset.id', $asset->id);
});

it('returns 404 when showing a non-existent asset via api', function () {
    $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.assets.show', 999999))
        ->assertStatus(404)
        ->assertJsonPath('success', false);
});

it('returns edit payload for an asset via api', function () {
    $asset = Asset::factory()->create();

    $response = $this->withHeaders($this->headers)
        ->putJson(route('admin.api.dam.assets.edit', $asset->id));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.asset.id', $asset->id);
});

it('returns 404 when editing a non-existent asset via api', function () {
    $this->withHeaders($this->headers)
        ->putJson(route('admin.api.dam.assets.edit', 999999))
        ->assertStatus(404);
});

it('updates an asset via the api update endpoint', function () {
    $asset = Asset::factory()->create(['file_name' => 'original.png', 'extension' => 'png', 'path' => 'assets/Root/original.png']);

    $response = $this->withHeaders($this->headers)
        ->putJson(route('admin.api.dam.assets.update', $asset->id), [
            'file_name' => 'renamed.png',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas($this->getFullTableName(Asset::class), [
        'id'        => $asset->id,
        'file_name' => 'renamed.png',
        'path'      => 'assets/Root/renamed.png',
    ]);
});

it('never lets the api update rewrite what describes the bytes, and renames under the Library rule (UX eval R5)', function () {
    $asset = Asset::factory()->create(['file_name' => 'notes.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'file_type' => 'document', 'file_size' => 16, 'path' => 'assets/Root/notes.txt']);
    $url = route('admin.api.dam.assets.update', $asset->id);
    $descriptors = fn () => $asset->fresh()->only(['extension', 'mime_type', 'file_type', 'file_size']);
    $before = $descriptors();

    // Byte-derived fields are ignored; a new extension in the name does not retype the file, and the path follows.
    $this->withHeaders($this->headers)->putJson($url, [
        'file_name' => 'report.html', 'extension' => 'html', 'mime_type' => 'text/html', 'file_type' => 'web', 'file_size' => 999,
    ])->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['success', 'message', 'data']);

    expect($descriptors())->toBe($before)
        ->and($asset->fresh()->only(['file_name', 'path']))->toBe(['file_name' => 'report.txt', 'path' => 'assets/Root/report.txt']);

    // A name another asset holds, or one the Library rule refuses, answers 422 under file_name and changes nothing.
    Asset::factory()->create(['file_name' => 'taken.txt', 'path' => 'assets/Root/taken.txt']);
    $this->withHeaders($this->headers)->putJson($url, ['file_name' => 'taken.txt'])->assertUnprocessable()->assertJsonValidationErrors('file_name');
    $this->withHeaders($this->headers)->putJson($url, ['file_name' => '.hidden'])->assertUnprocessable()->assertJsonValidationErrors('file_name');

    expect($asset->fresh()->only(['file_name', 'path']))->toBe(['file_name' => 'report.txt', 'path' => 'assets/Root/report.txt']);
});

it('renames from the asset as it is now, not as the caller loaded it, when a re-upload lands in between (rev-B 1)', function () {
    $asset = Asset::factory()->create(['file_name' => 'report.png', 'extension' => 'png', 'mime_type' => 'image/png', 'file_type' => 'image', 'path' => 'assets/Root/report.png']);
    $stale = Asset::query()->findOrFail($asset->id);

    // A re-upload replaces the bytes (and with them the extension) after the caller loaded the asset.
    DB::table('dam_assets')->where('id', $asset->id)->update([
        'file_name' => 'report.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_type' => 'document', 'path' => 'assets/Root/report.pdf',
    ]);

    app(AssetRename::class)->rename($stale, 'final.png');

    expect($asset->fresh()->only(['file_name', 'path', 'extension', 'mime_type', 'file_type']))->toBe([
        'file_name' => 'final.pdf', 'path' => 'assets/Root/final.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_type' => 'document',
    ])->and($stale->file_name)->toBe('final.pdf');
});

it('returns 404 when updating a non-existent asset via api', function () {
    $this->withHeaders($this->headers)
        ->putJson(route('admin.api.dam.assets.update', 999999), ['file_name' => 'x.png'])
        ->assertStatus(404);
});

it('uploads an asset via the api upload endpoint', function () {
    $disk = Directory::getAssetDisk();
    $metadata = ['FileName' => 'api-upload.png', 'exif' => ['ImageWidth' => 200]];
    $this->mock(MetadataExtractionService::class, function ($mock) use ($metadata) {
        $mock->shouldReceive('extractMetadata')->once()->andReturn($metadata);
    });

    // Folders are metadata (ADR 0009 slice 3): no directory on disk, and none is written.
    $directory = Directory::factory()->create(['name' => 'New', 'parent_id' => null]);

    $file = UploadedFile::fake()->image('api-upload.png', 200, 200);
    $bytes = $file->get();

    $response = $this->withHeaders($this->headers)
        ->post(route('admin.api.dam.assets.upload'), [
            'files'        => [$file],
            'directory_id' => $directory->id,
        ]);

    $response->assertStatus(201)->assertJsonPath('success', true);

    // The model casts arrays itself: encoding before assignment creates a JSON string in the column.
    $uploaded = Asset::findOrFail($response->json('files.0.id'));
    expect(json_decode($uploaded->getRawOriginal('meta_data'), true))->toBe($metadata);

    $object = app(ContentStore::class)->current(Asset::findOrFail($response->json('files.0.id')));
    expect(Storage::disk($disk)->get($object->path))->toBe($bytes)
        ->and(Storage::disk($disk)->allFiles('assets/New'))->toBe([])
        ->and(Storage::disk($disk)->allFiles(ContentStore::STAGING))->toBe([])
        ->and(Asset::findOrFail($response->json('files.0.id'))->needs_sorting_at)->toBeNull();
});

// "New user uploads": an API upload made by a user whose role is not `all` waits to be sorted.
it('flags an api upload made by a role-User for sorting', function () {
    $directory = Directory::factory()->create(['name' => 'ApiSort', 'parent_id' => null]);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset']]);
    $uploader = Admin::query()->orderByDesc('id')->first();
    $uploader->update(['role_id' => $role->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'lists']);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $uploader->id]);

    $response = $this->withHeaders($this->headers)->post(route('admin.api.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('api-sort.png', 50, 50)],
        'directory_id' => $directory->id,
    ]);

    $response->assertStatus(201);
    expect(Asset::findOrFail($response->json('files.0.id'))->needs_sorting_at)->not->toBeNull();
});

it('validates directory_id on api upload', function () {
    $response = $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.assets.upload'), []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['directory_id']);
});

it('deletes an asset via the api destroy endpoint', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/api-destroy.png';
    Storage::disk($disk)->put($path, 'x');

    $asset = Asset::factory()->create(['path' => $path]);

    $this->withHeaders($this->headers)
        ->deleteJson(route('admin.api.dam.assets.destroy', $asset->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    // P2 S4: a delete moves the asset to the trash.
    $this->assertSoftDeleted($this->getFullTableName(Asset::class), ['id' => $asset->id]);
});

it('returns 404 when destroying a non-existent asset via api', function () {
    $this->withHeaders($this->headers)
        ->deleteJson(route('admin.api.dam.assets.destroy', 999999))
        ->assertStatus(404);
});

it('returns 404 when downloading a missing asset via api', function () {
    $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.assets.download', 999999))
        ->assertStatus(404);
});

it('returns a temporary signed download_url for non-s3 disks', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/download-url.png';
    Storage::disk($disk)->put($path, 'binary-bytes');

    $asset = Asset::factory()->create([
        'file_name' => 'download-url.png',
        'path'      => $path,
    ]);

    $response = $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.assets.download', $asset->id));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['download_url']]);

    $downloadUrl = $response->json('data.download_url');

    expect($downloadUrl)
        ->toContain('signature=')
        ->toContain('expires=')
        ->toContain('signUrlDownload/'.$asset->id);
});

it('returns a 404 when the stored file is missing for download', function () {
    $asset = Asset::factory()->create(['path' => 'assets/Root/missing.png']);

    $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.assets.download', $asset->id))
        ->assertStatus(404)
        ->assertJsonPath('success', false);
});

it('streams the file via the signed download URL without bearer auth', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/signed-stream.png';
    Storage::disk($disk)->put($path, 'binary-bytes');

    $asset = Asset::factory()->create([
        'file_name' => 'signed-stream.png',
        'path'      => $path,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'admin.api.dam.assets.private.download',
        now()->addMinutes(10),
        ['id' => $asset->id]
    );

    $relative = parse_url($signedUrl, PHP_URL_PATH).'?'.parse_url($signedUrl, PHP_URL_QUERY);

    $response = $this->get($relative);

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('signed-stream.png');
});

it('rejects the signed download URL when signature is invalid', function () {
    $asset = Asset::factory()->create(['path' => 'assets/Root/x.png']);

    Storage::disk(Directory::getAssetDisk())->put($asset->path, 'x');

    $this->get(route('admin.api.dam.assets.private.download', $asset->id).'?expires=9999999999&signature=deadbeef')
        ->assertForbidden();
});

it('rejects the signed-url download route without a valid signature', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/download.png';
    Storage::disk($disk)->put($path, 'content');

    $asset = Asset::factory()->create(['path' => $path]);

    $this->get(route('admin.api.dam.assets.private.download', $asset->id))
        ->assertForbidden();
});

it('extracts and stores audio cover art when uploading audio via the api', function () {
    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/AudioApi');

    $directory = Directory::factory()->create(['name' => 'AudioApi', 'parent_id' => null]);

    $mock = Mockery::mock(MetadataExtractionService::class);
    $mock->shouldReceive('extractMetadata')->andReturn(['Title' => 'Sample Track']);
    $mock->shouldReceive('extractCoverArtData')->andReturn('fake-binary-cover-bytes');
    $mock->shouldReceive('storeCoverArt')
        ->andReturnUsing(function ($data, $assetId, $diskName) {
            return 'covers/'.$assetId.'.jpg';
        });
    app()->instance(MetadataExtractionService::class, $mock);

    $file = UploadedFile::fake()->create('cover-track.mp3', 100, 'audio/mpeg');

    $response = $this->withHeaders($this->headers)
        ->post(route('admin.api.dam.assets.upload'), [
            'files'        => [$file],
            'directory_id' => $directory->id,
        ]);

    $response->assertStatus(201)->assertJsonPath('success', true);

    $assetId = $response->json('files.0.id');
    $asset = Asset::find($assetId);

    expect($asset->meta_data)->toHaveKey('cover_art_path')
        ->and($asset->meta_data['cover_art_path'])->toBe('covers/'.$asset->id.'.jpg');
});

it('does not attach cover art when uploading non-audio files via the api', function () {
    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/ImageApi');

    $directory = Directory::factory()->create(['name' => 'ImageApi', 'parent_id' => null]);

    $mock = Mockery::mock(MetadataExtractionService::class);
    $mock->shouldReceive('extractMetadata')->andReturn(['Width' => 200]);
    $mock->shouldNotReceive('extractCoverArtData');
    $mock->shouldNotReceive('storeCoverArt');
    app()->instance(MetadataExtractionService::class, $mock);

    $file = UploadedFile::fake()->image('image.png', 200, 200);

    $this->withHeaders($this->headers)
        ->post(route('admin.api.dam.assets.upload'), [
            'files'        => [$file],
            'directory_id' => $directory->id,
        ])->assertStatus(201);
});

it('extracts and stores audio cover art when reuploading audio via the api', function () {
    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/AudioReup');

    $directory = Directory::factory()->create(['name' => 'AudioReup', 'parent_id' => null]);

    $initialPath = 'assets/AudioReup/old.mp3';
    Storage::disk($disk)->put($initialPath, 'old audio bytes');
    $asset = Asset::factory()->create([
        'file_name' => 'old.mp3',
        'mime_type' => 'audio/mpeg',
        'extension' => 'mp3',
        'path'      => $initialPath,
    ]);
    $asset->directories()->attach($directory->id);

    $mock = Mockery::mock(MetadataExtractionService::class);
    $mock->shouldReceive('extractMetadata')->andReturn(['Title' => 'Updated Track']);
    $mock->shouldReceive('extractCoverArtData')->andReturn('fake-binary-cover-bytes');
    $mock->shouldReceive('storeCoverArt')
        ->andReturnUsing(function ($data, $assetId, $diskName) {
            return 'covers/'.$assetId.'.jpg';
        });
    app()->instance(MetadataExtractionService::class, $mock);

    $newFile = UploadedFile::fake()->create('new-track.mp3', 100, 'audio/mpeg');

    $response = $this->withHeaders($this->headers)
        ->post(route('admin.api.dam.assets.reUpload'), [
            'file'     => $newFile,
            'asset_id' => $asset->id,
        ]);

    $response->assertStatus(201)->assertJsonPath('success', true);

    $asset->refresh();

    expect($asset->meta_data)->toHaveKey('cover_art_path')
        ->and($asset->meta_data['cover_art_path'])->toBe('covers/'.$asset->id.'.jpg');
});

it('only lists assets from granted directories for a custom-role api user', function () {

    $grantedDir = Directory::factory()->create();
    $grantedAsset = Asset::factory()->create();
    $grantedAsset->directories()->attach($grantedDir->id);

    $deniedDir = Directory::factory()->create();
    $deniedAsset = Asset::factory()->create();
    $deniedAsset->directories()->attach($deniedDir->id);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets.index']]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $grantedDir->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $headers = $this->getAuthenticationHeaders();

    $admin = Admin::latest('id')->first();
    $admin->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    $response = $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.assets.index'));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->toArray();
    expect($ids)->toContain($grantedAsset->id);
    expect($ids)->not->toContain($deniedAsset->id);
});

function makeCustomAssetApiHeaders(Directory $grantedDir): array
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $grantedDir->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $headers = test()->getAuthenticationHeaders();
    Admin::latest('id')->first()->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    return $headers;
}

it('returns 404, as for a missing id, when showing an asset in a denied directory via api', function () {
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($denied->id);
    $granted = Directory::factory()->create();
    $headers = makeCustomAssetApiHeaders($granted);

    $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.assets.show', $asset->id))
        ->assertNotFound();
});

it('returns 404, as for a missing id, when editing an asset in a denied directory via api', function () {
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($denied->id);
    $granted = Directory::factory()->create();
    $headers = makeCustomAssetApiHeaders($granted);

    $this->withHeaders($headers)
        ->putJson(route('admin.api.dam.assets.edit', $asset->id))
        ->assertNotFound();
});

it('returns 404, as for a missing id, when updating an asset in a denied directory via api', function () {
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($denied->id);
    $granted = Directory::factory()->create();
    $headers = makeCustomAssetApiHeaders($granted);

    $this->withHeaders($headers)
        ->putJson(route('admin.api.dam.assets.update', $asset->id), ['file_name' => 'x.png'])
        ->assertNotFound();
});

it('returns 404, as for a missing id, when destroying an asset in a denied directory via api', function () {
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($denied->id);
    $granted = Directory::factory()->create();
    $headers = makeCustomAssetApiHeaders($granted);

    $this->withHeaders($headers)
        ->deleteJson(route('admin.api.dam.assets.destroy', $asset->id))
        ->assertNotFound();
});

it('returns 404, like a missing id, when downloading an asset in a denied directory via api', function () {
    Storage::fake(Directory::getAssetDisk());
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create(['path' => 'assets/test.jpg']);
    $asset->directories()->attach($denied->id);
    Storage::disk(Directory::getAssetDisk())->put('assets/test.jpg', 'content');
    $granted = Directory::factory()->create();
    $headers = makeCustomAssetApiHeaders($granted);

    $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.assets.download', $asset->id))
        ->assertStatus(404);
});

it('returns 403 when uploading to a denied directory via api', function () {
    Storage::fake(Directory::getAssetDisk());
    // The parent of a granted folder: in the role's tree, so refused with 403, not answered as missing.
    $denied = Directory::factory()->create();
    $granted = Directory::factory()->create(['parent_id' => $denied->id]);
    $headers = makeCustomAssetApiHeaders($granted);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.upload'), [
            'directory_id' => $denied->id,
            'files'        => [UploadedFile::fake()->image('test.jpg')],
        ])
        ->assertStatus(403);
});

it('skips cover art on reupload when the new file is not audio', function () {
    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->makeDirectory('assets/MixedReup');

    $directory = Directory::factory()->create(['name' => 'MixedReup', 'parent_id' => null]);

    $initialPath = 'assets/MixedReup/old.png';
    Storage::disk($disk)->put($initialPath, 'old image bytes');
    $asset = Asset::factory()->create([
        'file_name' => 'old.png',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'path'      => $initialPath,
    ]);
    $asset->directories()->attach($directory->id);

    $mock = Mockery::mock(MetadataExtractionService::class);
    $mock->shouldReceive('extractMetadata')->andReturn(['Width' => 100]);
    $mock->shouldNotReceive('extractCoverArtData');
    $mock->shouldNotReceive('storeCoverArt');
    app()->instance(MetadataExtractionService::class, $mock);

    $newFile = UploadedFile::fake()->image('new.png', 100, 100);

    $this->withHeaders($this->headers)
        ->post(route('admin.api.dam.assets.reUpload'), [
            'file'     => $newFile,
            'asset_id' => $asset->id,
        ])->assertStatus(201);
});

it('dispatches the web page bundler for an html upload through the api', function () {
    Bus::fake();
    $directory = Directory::factory()->create();

    $response = $this->withHeaders($this->headers)
        ->post(route('admin.api.dam.assets.upload'), [
            'directory_id' => $directory->id,
            'files'        => [UploadedFile::fake()->createWithContent('page.html', '<!doctype html><p>x</p>')],
        ]);

    $response->assertStatus(201);
    $asset = Asset::where('file_name', 'like', 'page%')->firstOrFail();
    expect($asset->file_type)->toBe('web');
    // This route never runs ProcessAssetUpload, so the bundle must be dispatched by the controller.
    Bus::assertDispatched(BuildWebPageBundle::class);
});

it('answers a non-numeric asset id with 404, not a 500 (FAULT-11)', function () {
    $this->withHeaders($this->headers)->getJson('/api/v1/rest/assets/abc')->assertNotFound();
    $this->withHeaders($this->headers)->getJson('/api/v1/rest/assets/999999999')->assertNotFound();
});
