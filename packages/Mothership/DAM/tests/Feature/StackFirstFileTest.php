<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\ComputeAssetPhash;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\StackMemberState;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Queue::fake();
    $this->loginAsAdmin();
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'first.png', 'path' => 'assets/Arrange/first.png', 'file_type' => 'image',
        'extension' => 'png', 'mime_type' => 'image/png',
    ]));
    $this->asset->directories()->attach(Directory::factory()->create()->id);
    $this->first = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'original']);
    $this->base = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id;
    $this->second = $this->postJson($this->base.'/files', [
        'file' => UploadedFile::fake()->image('second.png', 19, 13), 'role' => 'alternate',
    ])->assertCreated()->json();
    $this->third = $this->postJson($this->base.'/files', [
        'file' => UploadedFile::fake()->image('third.png', 23, 17), 'role' => 'alternate',
    ])->assertCreated()->json();
});

it('changes the first file atomically and restores its exact saved state on a round trip', function () {
    $meta = ['glb_primary_view' => ['sha256' => $this->first->fileObject->sha256, 'source_file_id' => $this->first->id,
        'revision'                           => 'saved-revision', 'view' => ['distance' => 4.5, 'aspect' => 1.2]],
        'custom_thumbnail' => ['path' => 'custom-thumbnails/'.$this->asset->id.'/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.png', 'revision' => 'original-art'],
        'artist_note'      => 'Keep this metadata'];
    $this->asset->update(['meta_data' => $meta]);
    $before = AssetFile::where('dam_asset_id', $this->asset->id)->orderBy('id')->get()->mapWithKeys(fn ($f) => [$f->id => $f->file_object_id])->all();
    $folders = $this->asset->directories()->pluck('dam_directories.id')->all();
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $this->third['id']],
    ])->assertOk()->assertJsonPath('files.0.id', $this->second['id'])->assertJsonPath('files.0.is_representative', true);
    expect(StackMemberState::remembered(asset: $this->asset->fresh(), file: $this->first))->toBe($meta)
        ->and($this->asset->fresh()->meta_data['custom_thumbnail'] ?? null)->toBeNull();
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$this->first->attachment_id, $this->second['attachment_id'], $this->third['attachment_id']],
        'expected_files' => [$this->second['id'], $this->first->id, $this->third['id']],
    ])->assertOk()->assertJsonPath('files.0.id', $this->first->id)->assertJsonPath('files.0.is_representative', true);
    $after = $this->asset->fresh()->meta_data;
    unset($after[StackMemberState::KEY]);
    expect($after)->toBe($meta)
        ->and(AssetFile::where('dam_asset_id', $this->asset->id)->orderBy('id')->get()->mapWithKeys(fn ($f) => [$f->id => $f->file_object_id])->all())->toBe($before)
        ->and($this->asset->directories()->pluck('dam_directories.id')->all())->toBe($folders);
    Queue::assertPushed(ProcessAssetUpload::class);
});

it('keeps the representative metadata and previews untouched for a non-first move', function () {
    $meta = ['glb_primary_view' => ['revision' => 'unchanged'], 'thumbnail_path' => 'keep.jpg'];
    $this->asset->update(['meta_data' => $meta]);
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$this->first->attachment_id, $this->third['attachment_id'], $this->second['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $this->third['id']],
    ])->assertOk()->assertJsonPath('files.0.id', $this->first->id);
    expect($this->asset->fresh()->meta_data)->toBe($meta);
    Queue::assertNotPushed(ProcessAssetUpload::class);
});

it('rejects an old order and a replaced revision instead of applying a stale drag', function () {
    $payload = ['order'  => [$this->first->attachment_id, $this->third['attachment_id'], $this->second['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $this->third['id']]];
    $this->putJson($this->base.'/attachments/order', $payload)->assertOk();
    $this->putJson($this->base.'/attachments/order', $payload)->assertStatus(409);
    AssetFile::whereKey($this->second['id'])->update(['is_current' => false]);
    $payload['expected_files'] = [$this->first->id, $this->third['id'], $this->second['id']];
    $this->putJson($this->base.'/attachments/order', $payload)->assertStatus(409);
});

it('converts the exact selected member and rejects foreign stale or malformed identity', function () {
    $url = route('admin.dam.assets.custom_download', ['id' => $this->asset->id]);
    $response = $this->get($url.'?file='.$this->second['id'].'&format=png&width=7&height=5')->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $size = getimagesize($path);
    expect($size[0])->toBe(7)->and($size[1])->toBe(5);
    @unlink($path);
    foreach (['bogus', '-1', '0', '99999999'] as $id) {
        $this->getJson($url.'?file='.$id.'&format=png')->assertNotFound();
    }
    $foreign = AssetFile::factory()->create();
    $this->getJson($url.'?file='.$foreign->id.'&format=png')->assertNotFound();
    AssetFile::whereKey($this->second['id'])->update(['is_current' => false]);
    $this->getJson($url.'?file='.$this->second['id'].'&format=png')->assertNotFound();
});

it('does not apply one revision saved view to another member with identical bytes', function () {
    AssetFile::whereKey($this->second['id'])->update(['file_object_id' => $this->first->file_object_id]);
    $meta = ['glb_primary_view' => ['sha256' => $this->first->fileObject->sha256, 'source_file_id' => $this->first->id, 'revision' => 'first-only']];
    $this->asset->update(['meta_data' => $meta]);
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $this->third['id']],
    ])->assertOk();
    expect($this->asset->fresh()->meta_data['glb_primary_view'] ?? null)->toBeNull()
        ->and(StackMemberState::remembered(asset: $this->asset->fresh(), file: $this->first))->toBe($meta);
});

it('refuses hidden and read-only stacks before changing their order or cover', function () {
    $payload = ['order'  => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $this->third['id']]];
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index', 'dam.asset.edit', 'dam.asset.download']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->putJson($this->base.'/attachments/order', $payload)->assertNotFound();
    $this->getJson(route('admin.dam.assets.custom_download', ['id' => $this->asset->id, 'file' => $this->second['id'], 'format' => 'png']))->assertNotFound();
    $viewer = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->asset->directories()->first()->id, 'role_id' => $viewer->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $viewer->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $this->putJson($this->base.'/attachments/order', $payload)->assertForbidden();
    $this->getJson(route('admin.dam.assets.custom_download', ['id' => $this->asset->id, 'file' => $this->second['id'], 'format' => 'png']))->assertForbidden();
    expect($this->first->fresh()->attachment->is_representative)->toBeTrue();
});

it('retains artwork named by an archived member when its former asset is deleted', function () {
    $source = Asset::withoutEvents(fn () => Asset::factory()->create(['file_type' => 'document', 'extension' => 'glb']));
    $path = 'thumbnails/models/'.$source->id.'-123456789abc.jpg';
    Storage::disk(Directory::getAssetDisk())->put($path, 'retained-picture');
    $source->update(['meta_data' => ['thumbnail_path' => $path]]);
    $this->asset->update(['meta_data' => [StackMemberState::KEY => [StackMemberState::key($this->first) => ['thumbnail_path' => $path]]]]);
    app(AssetDeletionService::class)->delete(asset: $source, force: true);
    Storage::disk(Directory::getAssetDisk())->assertExists($path);
});

it('resolves archived metadata from the locked asset rather than a stale caller snapshot', function () {
    $stale = $this->asset->fresh();
    $second = AssetFile::findOrFail($this->second['id']);
    $latest = ['artist_note' => 'newer saved member state'];
    $this->asset->update(['meta_data' => [StackMemberState::KEY => [StackMemberState::key($second) => $latest]]]);
    app(AttachmentLedger::class)->reorder(asset: $stale,
        attachmentIds: [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
        expectedFiles: [$this->first->id, $this->second['id'], $this->third['id']]);
    expect($this->asset->fresh()->meta_data['artist_note'])->toBe('newer saved member state');
});

it('retains borrowed custom artwork through replacement and deferred deletion', function () {
    $custom = app(CustomThumbnailService::class);
    $old = $custom->store(asset: $this->asset, upload: UploadedFile::fake()->image('old.png'));
    $other = Asset::withoutEvents(fn () => Asset::factory()->create(['meta_data' => ['stack_cover' => [
        'path' => $old['path'], 'mime' => $old['mime_type'], 'file_id' => 99, 'sha256' => str_repeat('c', 64),
    ]]]));
    $new = $custom->store(asset: $this->asset, upload: UploadedFile::fake()->image('new.png'));
    expect($new['path'])->not->toBe($old['path']);
    Storage::disk(Directory::getAssetDisk())->assertExists($old['path']);
    app(AssetDeletionService::class)->processPendingFileDeletions();
    Storage::disk(Directory::getAssetDisk())->assertExists($old['path']);
    expect($other->fresh()->meta_data['stack_cover']['path'])->toBe($old['path']);
});

it('finalizes a newly first member while retaining its member archive', function (string $mime, string $extension) {
    $third = AssetFile::findOrFail($this->third['id']);
    $third->fileObject->update(['mime_type' => $mime]);
    $third->update(['label' => 'third.'.$extension]);
    $third->attachment->update(['label' => 'third.'.$extension]);
    $view = ['sha256' => $this->first->fileObject->sha256, 'source_file_id' => $this->first->id, 'view' => ['distance' => 3.0]];
    $this->asset->update(['meta_data' => ['glb_primary_view' => $view]]);
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$third->attachment_id, $this->first->attachment_id, $this->second['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $third->id],
    ])->assertOk();
    Queue::assertPushed(ProcessAssetUpload::class);
    $current = $this->asset->fresh();
    $archive = $current->meta_data[StackMemberState::KEY];
    $job = new ProcessAssetUpload($current->id);
    $store = new ReflectionMethod($job, 'storeMetadata');
    $store->invoke($job, $current, ['extracted' => 'new member'], $third->fileObject->sha256);
    expect($current->fresh()->meta_data[StackMemberState::KEY])->toBe($archive);
    $store->invoke($job, $current, ['extracted' => 'stale bytes'], str_repeat('a', 64));
    expect($current->fresh()->meta_data['extracted'])->toBe('new member');
})->with([['application/pdf', 'pdf'], ['video/mp4', 'mp4'], ['model/gltf-binary', 'glb'], ['audio/mpeg', 'mp3'], ['image/png', 'png']]);

it('uses the current files listing order even when a derived-role attachment exists', function () {
    AssetFile::whereKey($this->third['id'])->update(['role' => AssetFile::ROLE_PROXY]);
    $files = $this->getJson($this->base.'/files')->assertOk()->json('files');
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$this->first->attachment_id, $this->third['attachment_id'], $this->second['attachment_id']],
        'expected_files' => array_column($files, 'id'),
    ])->assertOk();
});

it('converts the selected member through the existing JPEG and WebP encoders', function (string $format, int $type) {
    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $this->asset->id,
        'file'                                                             => $this->second['id'], 'format' => $format, 'width' => 8, 'height' => 6]))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $size = getimagesize($path);
    expect([$size[0], $size[1], $size[2]])->toBe([8, 5, $type]);
    @unlink($path);
})->with([['jpg', IMAGETYPE_JPEG], ['webp', IMAGETYPE_WEBP]]);

it('downloads the exact selected SVG unchanged when original format is requested', function () {
    $bytes = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>';
    $file = $this->postJson($this->base.'/files', ['file' => UploadedFile::fake()->createWithContent('art.svg', $bytes), 'role' => 'alternate'])->assertCreated()->json();
    $response = $this->get(route('admin.dam.assets.custom_download', ['id' => $this->asset->id, 'file' => $file['id'], 'format' => 'svg']))->assertOk();
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($bytes);
});

it('serves only the current member borrowed cover and falls back for stale or missing artwork', function () {
    $this->putJson($this->base.'/attachments/order', [
        'order'          => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
        'expected_files' => [$this->first->id, $this->second['id'], $this->third['id']],
    ])->assertOk();
    $asset = $this->asset->fresh();
    $file = AssetFile::findOrFail($this->second['id']);
    $path = 'covers/'.$asset->id.'-123456789abc.png';
    $upload = UploadedFile::fake()->image('cover.png', 11, 9);
    $art = file_get_contents($upload->getPathname());
    Storage::disk(Directory::getAssetDisk())->put($path, $art);
    $cover = ['file_id' => $file->id, 'sha256' => $file->fileObject->sha256, 'path' => $path, 'mime' => 'image/png'];
    $asset->update(['meta_data' => ['stack_cover' => $cover]]);
    $url = route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]);
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($art);
    $asset->update(['meta_data' => ['stack_cover' => [...$cover, 'sha256' => str_repeat('a', 64)]]]);
    $stale = $this->get($url)->assertOk();
    expect($stale->getContent())->not->toBe($art);
    $asset->update(['meta_data' => ['stack_cover' => $cover]]);
    Storage::disk(Directory::getAssetDisk())->delete($path);
    $missing = $this->get($url)->assertOk();
    expect($missing->getContent())->not->toBe($art);
});

it('promotes the first member for legacy reorder callers without expected files', function () {
    $this->putJson($this->base.'/attachments/order', [
        'order' => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
    ])->assertOk()->assertJsonPath('files.0.id', $this->second['id'])->assertJsonPath('files.0.is_representative', true);
    Queue::assertPushed(ProcessAssetUpload::class);
});

it('rejects a derived proxy as first without changing the representative', function () {
    $third = AssetFile::findOrFail($this->third['id']);
    $third->update(['role' => AssetFile::ROLE_PROXY]);
    $third->attachment->update(['role' => AssetFile::ROLE_PROXY]);
    $this->putJson($this->base.'/attachments/order', [
        'order' => [$third->attachment_id, $this->first->attachment_id, $this->second['attachment_id']],
    ])->assertUnprocessable();
    expect($this->first->fresh()->attachment->is_representative)->toBeTrue();
});

it('prunes removed and foreign archive identities but preserves a saved member', function () {
    $meta = [StackMemberState::KEY => [StackMemberState::key($this->first) => ['artist_note' => 'retained'], '999999:missing' => ['thumbnail_path' => 'dead.jpg']]];
    $this->asset->update(['meta_data' => $meta]);
    expect(StackMemberState::retainedStates($this->asset))->toBe([StackMemberState::key($this->first) => ['artist_note' => 'retained']]);
    $this->first->update(['is_current' => false]);
    expect(StackMemberState::retainedStates($this->asset))->toBe([StackMemberState::key($this->first) => ['artist_note' => 'retained']]);
});

it('extracts promoted image metadata and schedules its perceptual hash without losing the archive', function () {
    $this->putJson($this->base.'/attachments/order', [
        'order' => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
    ])->assertOk();
    $archive = $this->asset->fresh()->meta_data[StackMemberState::KEY];
    $service = Mockery::mock(MetadataExtractionService::class)->makePartial();
    $service->shouldReceive('extractMetadata')->once()->andReturn(['Make' => 'Selected member camera']);
    (new ProcessAssetUpload($this->asset->id, null, true))->handle($service);
    expect($this->asset->fresh()->meta_data)->toMatchArray(['Make' => 'Selected member camera', StackMemberState::KEY => $archive]);
    Queue::assertPushed(ComputeAssetPhash::class);
});

it('rejects extracted metadata if another member becomes first during extraction', function () {
    $this->putJson($this->base.'/attachments/order', [
        'order' => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']],
    ])->assertOk();
    $service = Mockery::mock(MetadataExtractionService::class)->makePartial();
    $service->shouldReceive('extractMetadata')->once()->andReturnUsing(function () {
        app(AttachmentLedger::class)->reorder(asset: $this->asset->fresh(),
            attachmentIds: [$this->third['attachment_id'], $this->first->attachment_id, $this->second['attachment_id']]);

        return ['Make' => 'Stale second member'];
    });
    (new ProcessAssetUpload($this->asset->id, null, true))->handle($service);
    expect($this->asset->fresh()->meta_data['Make'] ?? null)->toBeNull()
        ->and(AssetFile::find($this->third['id'])->attachment->is_representative)->toBeTrue();
});

it('finalizes a promoted audio member without regenerating its retained artwork', function () {
    $second = AssetFile::findOrFail($this->second['id']);
    $second->fileObject->update(['mime_type' => 'audio/mpeg']);
    $second->update(['label' => 'talk.mp3']);
    $second->attachment->update(['label' => 'talk.mp3']);
    $cover = ['file_id' => $second->id, 'sha256' => $second->fileObject->sha256, 'path' => 'covers/held.jpg', 'mime' => 'image/jpeg'];
    $this->asset->update(['meta_data' => [StackMemberState::KEY => [StackMemberState::key($second) => ['stack_cover' => $cover]]]]);
    $this->putJson($this->base.'/attachments/order', ['order' => [$second->attachment_id, $this->first->attachment_id, $this->third['attachment_id']]])->assertOk();
    $service = Mockery::mock(MetadataExtractionService::class)->makePartial();
    $service->shouldReceive('extractMetadata')->once()->andReturn(['Title' => 'Talk']);
    $service->shouldNotReceive('extractCoverArtData');
    Queue::assertPushed(ProcessAssetUpload::class, function ($job) use ($service) {
        $job->handle($service);

        return true;
    });
    expect($this->asset->fresh()->meta_data)->toMatchArray(['Title' => 'Talk', 'stack_cover' => $cover]);
});

it('leaves a legacy non-first representative untouched when the first slot does not change', function () {
    $fourth = $this->postJson($this->base.'/files', ['file' => UploadedFile::fake()->image('fourth.png'), 'role' => 'alternate'])->assertCreated()->json();
    foreach ([$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id'], $fourth['attachment_id']] as $sort => $slot) {
        Attachment::whereKey($slot)->update(['position' => $sort]);
        AssetFile::where('attachment_id', $slot)->update(['sort' => $sort]);
    }
    $before = $this->asset->fresh()->only(['file_name', 'meta_data']);
    $this->putJson($this->base.'/attachments/order', ['order' => [$this->second['attachment_id'], $this->first->attachment_id, $fourth['attachment_id'], $this->third['attachment_id']]])->assertOk();
    expect($this->asset->fresh()->only(['file_name', 'meta_data']))->toBe($before)
        ->and($this->first->fresh()->attachment->is_representative)->toBeTrue();
    Queue::assertNotPushed(ProcessAssetUpload::class);
});

it('prunes a deleted member archive through the endpoint while retaining historical state', function () {
    $second = AssetFile::findOrFail($this->second['id']);
    $historical = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'is_current' => false, 'role' => 'version']);
    $saved = [StackMemberState::key($historical) => ['glb_primary_view' => ['revision' => 'keep-history']]];
    $this->asset->update(['meta_data' => [StackMemberState::KEY => [...$saved, StackMemberState::key($second) => ['artist_note' => 'remove-member']]]]);
    $this->deleteJson($this->base.'/files/'.$second->id)->assertOk();
    expect($this->asset->fresh()->meta_data[StackMemberState::KEY])->toBe($saved);
});

it('retains singleton converter access while requiring download permission for an explicit member', function () {
    $this->putJson($this->base.'/attachments/order', ['order' => [$this->second['attachment_id'], $this->first->attachment_id, $this->third['attachment_id']]])->assertOk();
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->asset->directories()->first()->id, 'role_id' => $role->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();
    $url = route('admin.dam.assets.custom_download', ['id' => $this->asset->id, 'format' => 'png']);
    $this->get($url)->assertOk();
    $this->getJson($url.'&file='.$this->second['id'])->assertForbidden();
});
