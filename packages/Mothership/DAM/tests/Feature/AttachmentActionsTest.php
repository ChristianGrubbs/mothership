<?php

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * P3a S3: promote, edit, reorder and remove an entry's attachments, through the admin UI's endpoints and
 * REST alike. Acceptance lines 4 (any attachment can be promoted and the card follows it) and 5 (the UI and
 * REST behave identically).
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Arrange '.uniqid()]);
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'poster.png', 'path' => 'assets/Arrange/poster.png', 'file_type' => 'image',
        'extension' => 'png', 'mime_type' => 'image/png',
    ]));
    $this->asset->directories()->attach($this->folder->id);
    $this->original = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $this->base = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id;
});

$attach = function (object $test, string $name, string $content, string $role = 'alternate'): array {
    return $test->postJson($test->base.'/files', ['file' => UploadedFile::fake()->createWithContent($name, $content), 'role' => $role])
        ->assertCreated()->json();
};

$slots = fn (int $assetId) => Attachment::where('dam_asset_id', $assetId)->orderBy('position')->get();

it('promotes an attachment: roles and places swap, the entry is re-described, and no revision is added', function () use ($attach, $slots) {
    Queue::fake();
    $pdf = $attach($this, 'brochure.pdf', "%PDF-1.4\n%".str_repeat('x', 64));
    $sidecar = $attach($this, 'notes.txt', 'notes', 'sidecar');
    $versionsBefore = AssetVersion::where('dam_asset_id', $this->asset->id)->count();
    $revisionsBefore = AssetFile::where('dam_asset_id', $this->asset->id)->count();

    $response = $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();

    $asset = $this->asset->fresh();
    expect($asset->only(['file_name', 'path', 'extension', 'mime_type', 'file_type']))->toBe([
        'file_name' => 'brochure.pdf', 'path' => 'assets/Arrange/brochure.pdf', 'extension' => 'pdf',
        'mime_type' => 'application/pdf', 'file_type' => 'document',
    ])->and(AssetVersion::where('dam_asset_id', $this->asset->id)->count())->toBe($versionsBefore)
        ->and(AssetFile::where('dam_asset_id', $this->asset->id)->count())->toBe($revisionsBefore);

    // The promoted slot is the representative at the old representative's place; the old one took the alternate's.
    expect($slots($this->asset->id)->map->only(['id', 'role', 'is_representative'])->all())->toBe([
        ['id' => $pdf['attachment_id'], 'role' => 'original', 'is_representative' => true],
        ['id' => $this->original->attachment_id, 'role' => 'alternate', 'is_representative' => false],
        ['id' => $sidecar['attachment_id'], 'role' => 'sidecar', 'is_representative' => false],
    ]);

    // The old original keeps reading as itself, and the one-original rule still holds.
    expect($this->original->fresh()->only(['role', 'label', 'is_current']))->toBe(['role' => 'alternate', 'label' => 'poster.png', 'is_current' => true])
        ->and(AssetFile::where('dam_asset_id', $this->asset->id)->where('role', 'original')->sole()->id)->toBe($pdf['id'])
        ->and(collect($response->json('files'))->firstWhere('is_representative', true)['id'])->toBe($pdf['id']);

    Queue::assertPushed(ProcessAssetUpload::class, fn ($job) => true);

    // Promoting the representative again changes nothing.
    $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();
    expect($this->asset->fresh()->file_name)->toBe('brochure.pdf');
});

it('keeps the custom thumbnail when another file becomes the representative, and drops what described the old file', function () use ($attach) {
    Queue::fake();
    $pdf = $attach($this, 'brochure.pdf', "%PDF-1.4\n%".str_repeat('x', 64));
    $custom = ['path' => 'custom-thumbnails/'.$this->asset->id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()];
    $this->asset->update(['meta_data' => ['custom_thumbnail' => $custom, 'thumbnail_path' => 'thumbnails/models/old.jpg', 'cover_art_path' => 'covers/old.jpg', 'Make' => 'Old camera']]);

    $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();

    expect($this->asset->fresh()->meta_data)->toBe(['custom_thumbnail' => $custom]);
});

$uploadPng = function (object $test, int $width, int $height) {
    $id = $test->postJson(route('admin.dam.assets.upload'), ['files' => [UploadedFile::fake()->image('shot.png', $width, $height)], 'directory_id' => $test->folder->id])
        ->assertStatus(201)->json('files.0.id');

    return Asset::findOrFail($id);
};
$customThumbnail = fn (int $id) => ['path' => 'custom-thumbnails/'.$id.'/'.Str::uuid().'.png', 'mime_type' => 'image/png', 'revision' => (string) Str::uuid()];
$runJob = fn (int $id, bool $keep = false) => (new ProcessAssetUpload($id, null, $keep))->handle(app(MetadataExtractionService::class));

it('keeps a capture that landed while the upload job waited, and a custom thumbnail while the bytes are unchanged', function () use ($uploadPng, $customThumbnail, $runJob) {
    $asset = $uploadPng($this, 40, 30);
    $sha = app(ContentStore::class)->current($asset)->sha256;
    $custom = $customThumbnail($asset->id);
    $capture = 'thumbnails/models/'.$asset->id.'-'.substr($sha, 0, 12).'.jpg';

    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data ?? [], ['custom_thumbnail' => $custom, 'thumbnail_path' => $capture, 'thumbnail_revision' => 'abc123'])]);
    $runJob($asset->id);

    expect($asset->fresh()->meta_data)->toMatchArray(['custom_thumbnail' => $custom, 'thumbnail_path' => $capture, 'thumbnail_revision' => 'abc123']);

    // A capture of other bytes still goes, as before.
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data, ['thumbnail_path' => 'thumbnails/models/'.$asset->id.'-000000000000.jpg'])]);
    $runJob($asset->id);

    expect($asset->fresh()->meta_data)->not->toHaveKey('thumbnail_path')->toHaveKey('custom_thumbnail');
});

it('clears the custom thumbnail when the entry\'s bytes are replaced by different ones', function () use ($uploadPng, $customThumbnail) {
    $asset = $uploadPng($this, 40, 30);
    $custom = $customThumbnail($asset->id);
    $derivative = CustomThumbnailService::derivativePath($custom);
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put($custom['path'], 'raw');
    $disk->put($derivative, 'webp');
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data ?? [], ['custom_thumbnail' => $custom])]);

    // The same name in the same folder is a new revision; the queue runs the finalizer on its different bytes.
    $replaced = $uploadPng($this, 80, 60);

    expect($replaced->id)->toBe($asset->id)
        ->and($replaced->fresh()->meta_data)->not->toHaveKey('custom_thumbnail');
    // The dropped thumbnail's files (raw upload and 300 px copy) are cleaned up, not leaked.
    $disk->assertMissing($custom['path']);
    $disk->assertMissing($derivative);
});

it('keeps the custom thumbnail through Make representative, even though the finalizer runs on the new file', function () use ($uploadPng, $customThumbnail) {
    $asset = $uploadPng($this, 40, 30);
    $custom = $customThumbnail($asset->id);
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data ?? [], ['custom_thumbnail' => $custom])]);
    $other = $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/files', ['file' => UploadedFile::fake()->image('second.png', 50, 50), 'role' => 'alternate'])->assertCreated()->json();

    $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$asset->id.'/attachments/'.$other['attachment_id'].'/promote')->assertOk();

    expect($asset->fresh()->meta_data)->toHaveKey('custom_thumbnail')
        ->and($asset->fresh()->meta_data['custom_thumbnail'])->toBe($custom);
});

it('skips the metadata write when the original changed after the extraction read it', function () use ($uploadPng) {
    $asset = $uploadPng($this, 40, 30);
    $before = $asset->fresh()->meta_data;
    $job = new ProcessAssetUpload($asset->id);

    // The extraction read other bytes than the ones the row holds under its lock now.
    $stored = (new ReflectionMethod($job, 'storeMetadata'))->invoke($job, $asset, ['Make' => 'stale'], str_repeat('a', 64));

    expect($stored->fresh()->meta_data)->toBe($before)
        ->and($stored->fresh()->meta_data)->not->toHaveKey('Make');
});

$audioAsset = function (object $test, callable $uploadPng, callable $customThumbnail): array {
    $asset = $uploadPng($test, 40, 30);
    $asset->update(['mime_type' => 'audio/mpeg', 'extension' => 'png']);
    $sha = app(ContentStore::class)->current($asset)->sha256;
    $custom = $customThumbnail($asset->id);
    $capture = 'thumbnails/models/'.$asset->id.'-'.substr($sha, 0, 12).'.jpg';
    $asset->update(['meta_data' => ['custom_thumbnail' => $custom, 'thumbnail_path' => $capture]]);

    return [$asset->fresh(), $sha, $custom, $capture];
};
$coverService = function (string $art) {
    $service = Mockery::mock(MetadataExtractionService::class)->makePartial();
    $service->shouldReceive('extractMetadata')->andReturn(['Title' => 'Talk']);
    $service->shouldReceive('extractCoverArtData')->andReturn($art);

    return $service;
};
$png = function (): string {
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);

    return ob_get_clean();
};

$coverFile = fn (int $assetId, string $bytes, string $ext = 'png') => CoverArt::path($assetId, $bytes, $ext);

it('adds an audio file\'s cover art without erasing a capture or custom thumbnail that landed meanwhile', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png, $coverFile) {
    [$asset, , $custom, $capture] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $cover = $coverFile($asset->id, $png());

    (new ProcessAssetUpload($asset->id, null, true))->handle($coverService($png()));

    // The cover sits under a name that carries its bytes, and nothing else is left in the folder.
    expect($asset->fresh()->meta_data)->toMatchArray(['Title' => 'Talk', 'cover_art_path' => $cover, 'custom_thumbnail' => $custom, 'thumbnail_path' => $capture])
        ->and($disk->get($cover))->toBe($png())
        ->and(collect($disk->files('covers'))->all())->toBe([$cover]);
});

it('points at the new cover and deletes no cover file when it replaces a legacy one', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png, $coverFile) {
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put('covers/'.$asset->id.'.jpg', 'legacy-cover');
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data, ['cover_art_path' => 'covers/'.$asset->id.'.jpg'])]);

    (new ProcessAssetUpload($asset->id, null, true))->handle($coverService($png()));

    // The previous file is left where it is: nothing on this path deletes a cover.
    expect($asset->fresh()->meta_data['cover_art_path'])->toBe($coverFile($asset->id, $png()))
        ->and($disk->exists($coverFile($asset->id, $png())))->toBeTrue()
        ->and($disk->get('covers/'.$asset->id.'.jpg'))->toBe('legacy-cover');
});

it('keeps the existing cover file and pointer when the pointer swap fails after the new art was published', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png, $coverFile) {
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $existing = $coverFile($asset->id, 'valid-previous-cover', 'png');
    $disk->put($existing, 'valid-previous-cover');
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data, ['cover_art_path' => $existing])]);
    // The save of the new pointer fails inside the transaction: SQL rolls back, and bytes cannot.
    Asset::updating(function (Asset $row) use ($existing) {
        if (($row->meta_data['cover_art_path'] ?? $existing) !== $existing) {
            throw new RuntimeException('commit failed');
        }
    });

    (new ProcessAssetUpload($asset->id, null, true))->handle($coverService($png()));

    expect($disk->get($existing))->toBe('valid-previous-cover')
        ->and($asset->fresh()->meta_data['cover_art_path'])->toBe($existing);
});

it('never lets a job for older bytes replace a newer cover, and leaves no private file behind', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png) {
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put('covers/'.$asset->id.'.png', 'newer-cover');
    $job = new ProcessAssetUpload($asset->id);

    // The job read other bytes than the ones the row holds under its lock now.
    (new ReflectionMethod($job, 'attachAudioCoverArt'))->invoke($job, $coverService($png()), $asset, Directory::getAssetDisk(), str_repeat('a', 64));

    expect($disk->get('covers/'.$asset->id.'.png'))->toBe('newer-cover')
        ->and($asset->fresh()->meta_data)->not->toHaveKey('cover_art_path');
});

it('leaves the file a committed pointer names alone when a rejected job published the same art', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png, $coverFile) {
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $job = new ProcessAssetUpload($asset->id);
    $attach = new ReflectionMethod($job, 'attachAudioCoverArt');
    $current = app(ContentStore::class)->current($asset)->sha256;

    // Job B, for the bytes the row holds, commits its pointer; job A, for older bytes, then publishes the same art and is refused.
    $attach->invoke($job, $coverService($png()), $asset, Directory::getAssetDisk(), $current);
    $attach->invoke($job, $coverService($png()), $asset, Directory::getAssetDisk(), str_repeat('a', 64));

    expect($asset->fresh()->meta_data['cover_art_path'])->toBe($coverFile($asset->id, $png()))
        ->and($disk->get($coverFile($asset->id, $png())))->toBe($png())
        ->and($disk->files('covers'))->toBe([$coverFile($asset->id, $png())]);
});

it('reclaims every hashed cover an entry made when it is purged, after repeated cover replacements and a promotion to another kind', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png, $attach) {
    Queue::fake();
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $second = imagecreatetruecolor(5, 5);
    ob_start();
    imagepng($second);
    $secondArt = ob_get_clean();

    // Two covers over the entry's life: the first is left behind when the second replaces it.
    (new ProcessAssetUpload($asset->id, null, true))->handle($coverService($png()));
    (new ProcessAssetUpload($asset->id, null, true))->handle($coverService($secondArt));
    expect($disk->files('covers'))->toHaveCount(2);

    // The entry stops being audio: a PDF becomes its representative.
    $this->base = '/'.config('app.admin_url').'/dam/assets/'.$asset->id;
    $pdf = $attach($this, 'brochure.pdf', "%PDF-1.4\n%".str_repeat('x', 64));
    $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();
    expect($asset->fresh()->mime_type)->not->toStartWith('audio/');

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();
    $this->deleteJson(route('admin.dam.trash.destroy', $asset->id))->assertOk();

    expect($disk->files('covers'))->toBe([]);
});

it('does not delete the previous cover of another entry that a copy inherited', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png) {
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $foreign = 'covers/'.($asset->id + 1000).'-0123456789abcdef.png';
    $disk->put($foreign, 'source-cover');
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data, ['cover_art_path' => $foreign])]);

    (new ProcessAssetUpload($asset->id, null, true))->handle($coverService($png()));

    expect($disk->exists($foreign))->toBeTrue();
});

it('reports no cover path, and leaves nothing of its own, for a write that failed, stored less, or threw', function (callable $arrange) {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturn(false);
    $arrange($disk);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    Storage::set('cover-test', $disk);

    $path = app(MetadataExtractionService::class)->storeCoverArt('0123456789', 5, 'cover-test', immutable: true);

    expect($path)->toBeNull();
})->with([
    'the disk refused the write' => [function ($disk) {
        $disk->shouldReceive('put')->once()->andReturn(false);
    }],
    'the disk kept part of it' => [function ($disk) {
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('size')->andReturn(4);
    }],
    'the size check threw' => [function ($disk) {
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('size')->andThrow(new RuntimeException('disk gone'));
    }],
]);

it('keeps the previous cover and its pointer when the new cover cannot be written', function () use ($uploadPng, $customThumbnail, $audioAsset, $coverService, $png) {
    [$asset] = $audioAsset($this, $uploadPng, $customThumbnail);
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put('covers/'.$asset->id.'.png', 'previous-cover');
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data, ['cover_art_path' => 'covers/'.$asset->id.'.png'])]);
    $service = $coverService($png());
    $service->shouldReceive('storeCoverArt')->andReturn(null);

    (new ProcessAssetUpload($asset->id, null, true))->handle($service);

    expect($disk->get('covers/'.$asset->id.'.png'))->toBe('previous-cover')
        ->and($asset->fresh()->meta_data['cover_art_path'])->toBe('covers/'.$asset->id.'.png')
        ->and($disk->files('covers'))->toBe(['covers/'.$asset->id.'.png']);
});

// The exact bytes the 7eaf6ee7 class (two promoted properties, no flags) serializes to; `%d` is the asset id.
$productionPayload = fn (int $assetId, ?int $batchId) => sprintf(
    "O:38:\"Mothership\\DAM\\Jobs\\ProcessAssetUpload\":2:{s:10:\"\0*\0assetId\";i:%d;s:10:\"\0*\0batchId\";%s}",
    $assetId, $batchId === null ? 'N;' : 'i:'.$batchId.';',
);

it('runs a payload queued by the production class, and keeps the custom thumbnail of unchanged bytes', function () use ($uploadPng, $customThumbnail, $productionPayload) {
    $asset = $uploadPng($this, 40, 30);
    $sha = app(ContentStore::class)->current($asset)->sha256;
    $custom = $customThumbnail($asset->id);
    $asset->update(['meta_data' => array_merge($asset->fresh()->meta_data, ['custom_thumbnail' => $custom, 'processed_sha256' => $sha])]);

    $job = unserialize($productionPayload($asset->id, null));

    expect($job)->toBeInstanceOf(ProcessAssetUpload::class);
    foreach (['keepCustomThumbnail', 'bytesChanged'] as $name) {
        $property = new ReflectionProperty(ProcessAssetUpload::class, $name);
        expect($property->isInitialized($job))->toBeTrue()->and($property->getValue($job))->toBeFalse();
    }

    $job->handle(app(MetadataExtractionService::class));

    expect($asset->fresh()->meta_data['custom_thumbnail'])->toBe($custom);
});

it('serializes a new payload so the production class can still read who to process, for every flag value', function (array $arguments) use ($productionPayload) {
    $payload = serialize(new ProcessAssetUpload(...$arguments));

    // The production worker has no class for the flags: it reads the two properties it knows and ignores the rest.
    $incomplete = (array) unserialize($payload, ['allowed_classes' => false]);
    expect($incomplete["\0*\0assetId"])->toBe($arguments[0])
        ->and($incomplete["\0*\0batchId"])->toBe($arguments[1] ?? null)
        // A flag at its default is not serialized at all, so a default payload is byte for byte the production one.
        ->and(array_key_exists("\0*\0keepCustomThumbnail", $incomplete))->toBe($arguments[2] ?? false)
        ->and(array_key_exists("\0*\0bytesChanged", $incomplete))->toBe($arguments[3] ?? false);

    if (! ($arguments[2] ?? false) && ! ($arguments[3] ?? false)) {
        expect($payload)->toBe($productionPayload($arguments[0], $arguments[1]));
    }

    // And the flags survive the round trip into this class, default or not.
    $job = unserialize($payload);
    expect((new ReflectionProperty(ProcessAssetUpload::class, 'keepCustomThumbnail'))->getValue($job))->toBe($arguments[2] ?? false)
        ->and((new ReflectionProperty(ProcessAssetUpload::class, 'bytesChanged'))->getValue($job))->toBe($arguments[3] ?? false);
})->with([
    'defaults'            => [[7, 3]],
    'keep custom art'     => [[7, 3, true]],
    'bytes replaced'      => [[7, 3, false, true]],
    'no batch, both set'  => [[7, null, true, true]],
]);

it('keeps the custom thumbnail of an entry that was never processed unless the dispatcher says the bytes changed', function (bool $bytesChanged) use ($uploadPng, $customThumbnail) {
    $asset = $uploadPng($this, 40, 30);
    $custom = $customThumbnail($asset->id);
    $meta = $asset->fresh()->meta_data;
    unset($meta['processed_sha256']);
    $asset->update(['meta_data' => array_merge($meta, ['custom_thumbnail' => $custom])]);

    (new ProcessAssetUpload($asset->id, null, false, $bytesChanged))->handle(app(MetadataExtractionService::class));

    $kept = $asset->fresh()->meta_data['custom_thumbnail'] ?? null;
    expect($kept)->toBe($bytesChanged ? null : $custom)
        ->and($asset->fresh()->meta_data)->toHaveKey('processed_sha256');
})->with([
    'bytes not known to have changed' => [false],
    'bytes replaced'                  => [true],
]);

it('drops art of other bytes even when nothing can be extracted from the file', function (string $marker, bool $expectKept) use ($uploadPng, $customThumbnail) {
    $asset = $uploadPng($this, 40, 30);
    $sha = app(ContentStore::class)->current($asset)->sha256;
    $custom = $customThumbnail($asset->id);
    $asset->update(['meta_data' => ['custom_thumbnail' => $custom, 'processed_sha256' => $marker === 'same' ? $sha : str_repeat('b', 64), 'Title' => 'Kept']]);
    $service = Mockery::mock(MetadataExtractionService::class)->makePartial();
    $service->shouldReceive('extractMetadata')->andReturn([]);

    (new ProcessAssetUpload($asset->id))->handle($service);

    $meta = $asset->fresh()->meta_data;
    expect(array_key_exists('custom_thumbnail', $meta))->toBe($expectKept)
        ->and($meta['Title'])->toBe('Kept')
        ->and($meta['processed_sha256'])->toBe($sha);
})->with([
    'same bytes'      => ['same', true],
    'replaced bytes'  => ['other', false],
]);

it('answers false, and publishes nothing, when the disk fails to store a copied capture', function () {
    $sha = str_repeat('c', 64);
    $from = 'thumbnails/models/7-cccccccccccc.jpg';
    $jpeg = UploadedFile::fake()->image('capture.jpg', 16, 16)->get();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $path) => $path === $from);
    $disk->shouldReceive('size')->with($from)->andReturn(strlen($jpeg));
    $disk->shouldReceive('readStream')->with($from)->andReturnUsing(fn () => fopen('data://image/jpeg;base64,'.base64_encode($jpeg), 'r'));
    $disk->shouldReceive('get')->with($from)->andReturn($jpeg);
    $disk->shouldReceive('put')->once()->andReturn(false);
    $disk->shouldReceive('delete')->andReturn(true);
    $disk->shouldNotReceive('move');
    Storage::set(Directory::getAssetDisk(), $disk);

    expect(ModelFileThumbnailService::copyIfAbsent($from, $sha, 9))->toBeFalse();
});

it('keeps the stack\'s name when another file becomes its representative, and a title someone set', function () use ($attach) {
    Queue::fake();
    $pdf = $attach($this, 'brochure.pdf', "%PDF-1.4\n%".str_repeat('x', 64));

    $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();

    // The file is named for the bytes it holds, so a download never carries another file's name; the Library still shows the stack's.
    expect($this->asset->fresh()->only(['file_name', 'display_name']))->toBe(['file_name' => 'brochure.pdf', 'display_name' => 'poster.png']);
    $served = collect($this->getJson($this->base.'/files')->assertOk()->json('files'))->firstWhere('is_representative', true);
    $download = $this->get($served['download_url'])->assertOk();
    expect($served['file_name'])->toBe('brochure.pdf')
        ->and($download->headers->get('content-disposition'))->toContain('brochure.pdf')->not->toContain('poster');

    $this->asset->update(['title' => 'Bistro menu']);
    $png = $attach($this, 'second.png', 'second-bytes');
    $this->postJson($this->base.'/attachments/'.$png['attachment_id'].'/promote')->assertOk();

    expect($this->asset->fresh()->display_name)->toBe('Bistro menu');
});

it('shows the capture that already exists for a 3D file the moment it becomes the representative', function () use ($attach) {
    Queue::fake();
    $glb = $attach($this, 'part.glb', 'glb-bytes');
    $sha = AssetFile::findOrFail($glb['id'])->fileObject->sha256;
    Storage::disk(Directory::getAssetDisk())->put(DerivativeImages::modelCapture($sha, $this->asset->id), UploadedFile::fake()->image('capture.jpg', 16, 16)->get());

    $this->postJson($this->base.'/attachments/'.$glb['attachment_id'].'/promote')->assertOk();

    expect($this->asset->fresh()->meta_data)->toMatchArray(['thumbnail_path' => DerivativeImages::modelCapture($sha, $this->asset->id)]);
});

it('brings back the asset-level capture of a 3D file when it becomes the representative again', function () use ($attach) {
    Queue::fake();
    $glb = $attach($this, 'part.glb', 'glb-bytes');
    $sha = AssetFile::findOrFail($glb['id'])->fileObject->sha256;
    $assetLevel = ModelThumbnailService::DIRECTORY.$this->asset->id.'-'.substr($sha, 0, 12).'.jpg';
    Storage::disk(Directory::getAssetDisk())->put($assetLevel, UploadedFile::fake()->image('capture.jpg', 16, 16)->get());
    $promote = fn (int $attachmentId) => $this->postJson($this->base.'/attachments/'.$attachmentId.'/promote')->assertOk();

    $promote($glb['attachment_id']);
    expect($this->asset->fresh()->meta_data)->toMatchArray(['thumbnail_path' => $assetLevel]);

    // Another file takes over, and the 3D picture goes with the file it described.
    $promote($this->original->attachment_id);
    expect($this->asset->fresh()->meta_data)->not->toHaveKey('thumbnail_path');

    $promote($glb['attachment_id']);
    expect($this->asset->fresh()->meta_data)->toMatchArray(['thumbnail_path' => $assetLevel]);
});

it('treats an empty or unreadable stored capture as missing, and replaces it with a copy', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $sha = str_repeat('d', 64);
    $own = DerivativeImages::modelCapture($sha, 9);
    $from = ModelThumbnailService::DIRECTORY.'3-dddddddddddd.jpg';
    $jpeg = UploadedFile::fake()->image('capture.jpg', 16, 16)->get();
    $disk->put($from, $jpeg);

    $image = imagecreatetruecolor(64, 64);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    $oversized = imagecreatetruecolor(2500, 2500);
    ob_start();
    imagejpeg($oversized);
    $huge = ob_get_clean();

    // Each of these begins like a picture; none is one a viewer can show.
    $remainsOfAWrite = [
        'zero bytes'                    => '',
        'not a picture'                 => 'half a pict',
        'a JPEG signature and a null'   => "\xFF\xD8\xFF\x00",
        'a JPEG cut off part way'       => substr($jpeg, 0, (int) (strlen($jpeg) / 2)),
        'a JPEG without its end marker' => substr($jpeg, 0, -2),
        'a PNG cut off part way'        => substr($png, 0, (int) (strlen($png) / 2)),
        'a PNG without its end chunk'   => substr($png, 0, -12),
        'a picture past the pixel cap'  => $huge,
    ];

    foreach ($remainsOfAWrite as $label => $remains) {
        $disk->put($own, $remains);
        expect(ModelFileThumbnailService::exists($sha, 9))->toBeFalse($label);
    }

    // And a complete JPEG and a complete PNG are accepted.
    foreach (['jpeg' => $jpeg, 'png' => $png] as $label => $complete) {
        $disk->put($own, $complete);
        expect(ModelFileThumbnailService::exists($sha, 9))->toBeTrue($label);
    }

    $disk->put($own, 'half a pict');

    expect(ModelFileThumbnailService::copyIfAbsent($from, $sha, 9))->toBeTrue()
        ->and($disk->get($own))->toBe($jpeg)
        ->and(collect($disk->allFiles(dirname($own)))->filter(fn ($file) => str_ends_with($file, '.tmp')))->toBeEmpty();
});

it('does not publish a capture whose stored size is not what was written', function () {
    $sha = str_repeat('e', 64);
    $from = ModelThumbnailService::DIRECTORY.'3-eeeeeeeeeeee.jpg';
    $jpeg = UploadedFile::fake()->image('capture.jpg', 16, 16)->get();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturnUsing(fn (string $path) => $path === $from);
    $disk->shouldReceive('size')->with($from)->andReturn(strlen($jpeg));
    $disk->shouldReceive('size')->withArgs(fn (string $path) => str_ends_with($path, '.tmp'))->andReturn(strlen($jpeg) - 5);
    $disk->shouldReceive('readStream')->andReturnUsing(fn () => fopen('data://image/jpeg;base64,'.base64_encode($jpeg), 'r'));
    $disk->shouldReceive('get')->with($from)->andReturn($jpeg);
    $disk->shouldReceive('put')->once()->andReturn(true);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    $disk->shouldNotReceive('move');
    Storage::set(Directory::getAssetDisk(), $disk);

    expect(ModelFileThumbnailService::copyIfAbsent($from, $sha, 9))->toBeFalse();
});

it('publishes nothing when the capture lock was lost before the move, and leaves no temporary file', function () {
    $real = Storage::disk(Directory::getAssetDisk());
    $sha = str_repeat('c', 64);
    $own = DerivativeImages::modelCapture($sha, 9);
    $from = ModelThumbnailService::DIRECTORY.'3-cccccccccccc.jpg';
    $real->put($from, UploadedFile::fake()->image('capture.jpg', 16, 16)->get());
    $lockKey = 'model-capture:'.sha1(Directory::getAssetDisk().':'.$own);

    // The lease runs out while the temporary copy is being written: someone else may hold the lock by the time of the move.
    $disk = Mockery::mock($real)->makePartial();
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, $contents) use ($real, $lockKey) {
        $written = $real->put($path, $contents);
        Cache::lock($lockKey)->forceRelease();

        return $written;
    });
    $disk->shouldNotReceive('move');
    Storage::set(Directory::getAssetDisk(), $disk);

    expect(ModelFileThumbnailService::copyIfAbsent($from, $sha, 9))->toBeFalse()
        ->and($real->exists($own))->toBeFalse()
        ->and(collect($real->allFiles(dirname($own)))->filter(fn ($file) => str_ends_with($file, '.tmp')))->toBeEmpty();
});

it('serializes a browser capture and a merge copy on one lock: neither writes while the other holds it', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    config(['dam.model_capture_lock_wait' => 0]);
    $sha = str_repeat('f', 64);
    $own = DerivativeImages::modelCapture($sha, 9);
    $from = ModelThumbnailService::DIRECTORY.'3-ffffffffffff.jpg';
    $disk->put($from, UploadedFile::fake()->image('capture.jpg', 16, 16)->get());
    $held = Cache::lock('model-capture:'.sha1(Directory::getAssetDisk().':'.$own), 60);
    $held->get();

    // A copy that cannot get the lock is skipped, never written unlocked.
    expect(ModelFileThumbnailService::copyIfAbsent($from, $sha, 9))->toBeFalse()
        ->and($disk->exists($own))->toBeFalse();

    // And the browser capture asks to be retried instead of writing over whatever the holder is about to publish.
    $object = new FileObject(['sha256' => $sha]);
    try {
        app(ModelFileThumbnailService::class)->store($object, UploadedFile::fake()->image('browser.png', 16, 16), 9);
        $status = 200;
    } catch (HttpException $exception) {
        $status = $exception->getStatusCode();
        $headers = $exception->getHeaders();
    }
    expect($status)->toBe(503)->and($disk->exists($own))->toBeFalse()
        // The browser is told when to come back.
        ->and($headers['Retry-After'] ?? null)->toBe((string) ModelFileThumbnailService::RETRY_AFTER);

    $held->release();
    expect(ModelFileThumbnailService::copyIfAbsent($from, $sha, 9))->toBeTrue();
});

it('answers 503 with Retry-After, and writes nothing, when the cache that holds the capture lock is down', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $sha = str_repeat('9', 64);
    $own = DerivativeImages::modelCapture($sha, 9);
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('redis is down'));

    try {
        app(ModelFileThumbnailService::class)->store(new FileObject(['sha256' => $sha]), UploadedFile::fake()->image('browser.png', 16, 16), 9);
        $exception = null;
    } catch (HttpException $exception) {
    }

    // Not a 500, and never an unlocked write.
    expect($exception?->getStatusCode())->toBe(503)
        ->and($exception->getHeaders())->toHaveKey('Retry-After')
        ->and($disk->allFiles(dirname($own)))->toBe([]);
});

it('answers 503, and publishes nothing, when the cache stops answering while the lock ownership is checked', function () {
    $disk = Storage::disk(Directory::getAssetDisk());
    $sha = str_repeat('8', 64);
    $own = DerivativeImages::modelCapture($sha, 9);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andReturn(true);
    $lock->shouldReceive('isOwnedByCurrentProcess')->andThrow(new RuntimeException('redis went away'));
    $lock->shouldReceive('release')->andReturn(true);
    Cache::shouldReceive('lock')->andReturn($lock);

    try {
        app(ModelFileThumbnailService::class)->store(new FileObject(['sha256' => $sha]), UploadedFile::fake()->image('browser.png', 16, 16), 9);
        $exception = null;
    } catch (HttpException $exception) {
    }

    expect($exception?->getStatusCode())->toBe(503)
        ->and($disk->allFiles(dirname($own)))->toBe([]);
});

it('names the download for the promoted file exactly as the stack listed it', function (string $name) use ($attach) {
    Queue::fake();
    $file = $attach($this, $name, 'plain bytes of '.$name);
    $listed = collect($this->getJson($this->base.'/files')->assertOk()->json('files'))->firstWhere('attachment_id', $file['attachment_id']);

    $this->postJson($this->base.'/attachments/'.$file['attachment_id'].'/promote')->assertOk();

    // What the confirm showed is what the entry downloads as; nothing is added to or taken from a name someone chose.
    expect($listed['file_name'])->toBe($name)
        ->and($this->asset->fresh()->file_name)->toBe($name);
})->with(['.gitignore', 'report.customext', 'archive.001', 'v1.2.pdf', 'Rev 2.0.pdf']);

it('refuses to promote a derived preview and answers 404 for another asset\'s slot', function () use ($attach) {
    $thumb = $attach($this, 'thumb.png', 'thumb-bytes', 'thumbnail');
    $this->postJson($this->base.'/attachments/'.$thumb['attachment_id'].'/promote')->assertUnprocessable();

    $other = Asset::withoutEvents(fn () => Asset::factory()->create());
    $foreign = AssetFile::factory()->create(['dam_asset_id' => $other->id, 'role' => 'alternate']);
    $this->postJson($this->base.'/attachments/'.$foreign->attachment_id.'/promote')->assertNotFound();
    expect($this->asset->fresh()->file_name)->toBe('poster.png');
});

it('classifies a promoted web zip from its bytes and takes no digit-only extension from a label', function () use ($attach) {
    Queue::fake();
    $path = tempnam(sys_get_temp_dir(), 'web-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '<html><body>menu</body></html>');
    $zip->close();
    $site = $attach($this, 'site.zip', file_get_contents($path));
    unlink($path);

    $this->postJson($this->base.'/attachments/'.$site['attachment_id'].'/promote')->assertOk();
    expect($this->asset->fresh()->only(['extension', 'file_type']))->toBe(['extension' => 'zip', 'file_type' => 'web']);

    $pdf = $attach($this, 'brochure.pdf', "%PDF-1.4\n%".str_repeat('x', 64));
    $this->patchJson($this->base.'/attachments/'.$pdf['attachment_id'], ['label' => 'Rev 2.0'])->assertOk();
    $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();
    expect($this->asset->fresh()->only(['file_name', 'extension', 'file_type']))->toBe(['file_name' => 'Rev 2.0', 'extension' => 'pdf', 'file_type' => 'document']);
});

it('refuses to promote a web zip the upload rule would refuse, changing nothing', function () use ($attach, $slots) {
    Queue::fake();
    $path = tempnam(sys_get_temp_dir(), 'web-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', '<html><body>menu</body></html>');
    $zip->addFromString('shell.php', '<?php echo 1;');
    $zip->close();
    // Attaching skips bundle inspection, so the zip is accepted as a plain file.
    $site = $attach($this, 'site.zip', file_get_contents($path));
    unlink($path);
    $before = $slots($this->asset->id)->map->only(['id', 'role', 'is_representative', 'position'])->all();

    $this->postJson($this->base.'/attachments/'.$site['attachment_id'].'/promote')
        ->assertUnprocessable()->assertJsonValidationErrors('attachment');

    expect($this->asset->fresh()->only(['file_name', 'extension', 'file_type']))->toBe(['file_name' => 'poster.png', 'extension' => 'png', 'file_type' => 'image'])
        ->and($slots($this->asset->id)->map->only(['id', 'role', 'is_representative', 'position'])->all())->toBe($before)
        ->and(AssetFile::find($site['id'])->role)->toBe('alternate');
    Queue::assertNotPushed(ProcessAssetUpload::class);
});

it('edits a label and role, keeps the representative\'s role fixed, and moves a slot', function () use ($attach, $slots) {
    $a = $attach($this, 'a.txt', 'a', 'sidecar');
    $b = $attach($this, 'b.txt', 'b', 'sidecar');

    $this->patchJson($this->base.'/attachments/'.$a['attachment_id'], ['label' => '  Back cover ', 'role' => 'source'])->assertOk();
    expect(Attachment::find($a['attachment_id'])->only(['role', 'label']))->toBe(['role' => 'source', 'label' => 'Back cover'])
        ->and(AssetFile::find($a['id'])->only(['role', 'label']))->toBe(['role' => 'source', 'label' => 'Back cover']);

    $this->patchJson($this->base.'/attachments/'.$this->original->attachment_id, ['role' => 'alternate'])->assertUnprocessable();
    $this->patchJson($this->base.'/attachments/'.$a['attachment_id'], ['role' => 'version'])->assertUnprocessable();

    $this->patchJson($this->base.'/attachments/'.$b['attachment_id'], ['position' => 0])->assertOk();
    expect($slots($this->asset->id)->pluck('id')->all())->toBe([$b['attachment_id'], $this->original->attachment_id, $a['attachment_id']])
        ->and(AssetFile::find($b['id'])->sort)->toBe(0);
});

it('saves a full order and refuses a stale or partial one', function () use ($attach, $slots) {
    $a = $attach($this, 'a.txt', 'a', 'sidecar');
    $b = $attach($this, 'b.txt', 'b', 'sidecar');
    $order = [$b['attachment_id'], $a['attachment_id'], $this->original->attachment_id];

    $files = $this->putJson($this->base.'/attachments/order', ['order' => $order])->assertOk()->json('files');
    expect($slots($this->asset->id)->pluck('id')->all())->toBe($order)
        ->and(array_column($files, 'attachment_id'))->toBe($order);

    $this->putJson($this->base.'/attachments/order', ['order' => [$a['attachment_id'], $b['attachment_id']]])->assertUnprocessable();
    $this->putJson($this->base.'/attachments/order', ['order' => [$a['attachment_id'], $a['attachment_id'], $b['attachment_id']]])->assertUnprocessable();
    expect($slots($this->asset->id)->pluck('id')->all())->toBe($order);
});

it('keeps a demoted representative that carries history, and removes a plain attachment', function () use ($attach) {
    $pdf = $attach($this, 'brochure.pdf', "%PDF-1.4\n%pdf");
    $plain = $attach($this, 'notes.txt', 'notes', 'sidecar');
    // A replacement gives the representative slot a superseded revision; promoting another slot demotes it with that history.
    AssetFile::create(['dam_asset_id' => $this->asset->id, 'file_object_id' => $this->original->file_object_id, 'role' => 'version', 'attachment_id' => $this->original->attachment_id, 'is_current' => false]);
    Queue::fake();
    $this->postJson($this->base.'/attachments/'.$pdf['attachment_id'].'/promote')->assertOk();

    $this->deleteJson($this->base.'/files/'.$this->original->id)->assertUnprocessable();
    $this->deleteJson($this->base.'/files/'.$plain['id'])->assertOk();
    expect(Attachment::find($plain['attachment_id']))->toBeNull()
        ->and(Attachment::find($this->original->attachment_id))->not->toBeNull();
});

it('hides a hidden asset as missing and refuses a viewer without edit rights', function () use ($attach) {
    $alt = $attach($this, 'alt.png', 'alt-bytes');
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index', 'dam.asset.edit']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson($this->base.'/attachments/'.$alt['attachment_id'].'/promote')->assertNotFound();

    // A viewer who can open the folder but has no edit permission.
    $viewer = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $viewer->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $viewer->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson($this->base.'/attachments/'.$alt['attachment_id'].'/promote')->assertForbidden();
    $this->putJson($this->base.'/attachments/order', ['order' => [$this->original->attachment_id, $alt['attachment_id']]])->assertForbidden();
    expect($this->asset->fresh()->file_name)->toBe('poster.png');
});

it('refuses before writing when the entry\'s first folder is not granted, in the UI and through REST', function () use ($attach) {
    $alt = $attach($this, 'alt.png', 'alt-bytes');
    $second = Directory::create(['name' => 'Second '.uniqid()]);
    $this->asset->directories()->attach($second->id);
    // Granted the asset's second folder only: it may see the entry, but the files list's first-folder rule refuses it.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index', 'dam.asset.edit']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $second->id, 'role_id' => $role->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->patchJson($this->base.'/attachments/'.$alt['attachment_id'], ['label' => 'Renamed'])->assertForbidden();
    expect(Attachment::find($alt['attachment_id'])->label)->toBe('alt.png');

    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.files.update']);
    Admin::query()->latest('id')->firstOrFail()->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->withHeaders($headers)->patchJson(route('admin.api.dam.assets.attachments.update', ['asset' => $this->asset->id, 'attachment' => $alt['attachment_id']]), ['label' => 'Renamed'])
        ->assertForbidden();
    expect(Attachment::find($alt['attachment_id'])->label)->toBe('alt.png');
});

it('does the same through REST: attach, promote, edit, reorder, stack and remove', function () {
    Queue::fake();
    $headers = $this->getAuthenticationHeaders('custom', [
        'api.dam.assets.files.store', 'api.dam.assets.files.destroy', 'api.dam.assets.files.promote',
        'api.dam.assets.files.update', 'api.dam.assets.files.reorder', 'api.dam.assets.stack',
    ]);
    $id = $this->asset->id;

    $pdf = $this->withHeaders($headers)->post(route('admin.api.dam.assets.files.store', $id), [
        'file' => UploadedFile::fake()->createWithContent('brochure.pdf', "%PDF-1.4\n%rest"), 'role' => 'alternate',
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.file');
    $notes = $this->withHeaders($headers)->post(route('admin.api.dam.assets.files.store', $id), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'notes'), 'role' => 'sidecar',
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.file');

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.attachments.promote', ['asset' => $id, 'attachment' => $pdf['attachment_id']]))
        ->assertOk()->assertJsonPath('data.asset.file_name', 'brochure.pdf');
    $this->withHeaders($headers)->patchJson(route('admin.api.dam.assets.attachments.update', ['asset' => $id, 'attachment' => $notes['attachment_id']]), ['label' => 'Speaker notes'])
        ->assertOk();
    $order = [$notes['attachment_id'], $pdf['attachment_id'], $this->original->attachment_id];
    $expectedFiles = AssetFile::where('dam_asset_id', $id)->where('is_current', true)->orderBy('sort')->orderBy('id')->pluck('id')->all();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.attachments.order', $id), ['order' => $order, 'expected_files' => $expectedFiles])
        ->assertOk()->assertJsonPath('data.files.0.label', 'Speaker notes')->assertJsonPath('data.files.0.is_representative', true);
    $positions = Attachment::where('dam_asset_id', $id)->orderBy('position')->pluck('id')->all();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.attachments.order', $id), ['order' => array_reverse($order), 'expected_files' => $expectedFiles])
        ->assertStatus(409);
    expect(Attachment::where('dam_asset_id', $id)->orderBy('position')->pluck('id')->all())->toBe($positions)
        ->and(Attachment::find($notes['attachment_id'])->is_representative)->toBeTrue();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $id), ['basis' => 'product', 'key' => ' Bistro menu '])
        ->assertOk()->assertJsonPath('data.stack.key', 'Bistro menu');
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $id), ['basis' => 'product', 'key' => ' '])->assertUnprocessable();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.attachments.order', $id), [
        'order' => [$pdf['attachment_id'], $notes['attachment_id'], $this->original->attachment_id],
    ])->assertOk()->assertJsonPath('data.files.0.is_representative', true);
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.assets.files.destroy', ['asset' => $id, 'file' => $notes['id']]))->assertOk();

    expect(Attachment::where('dam_asset_id', $id)->orderBy('position')->pluck('id')->all())->toBe([$pdf['attachment_id'], $this->original->attachment_id])
        ->and($this->asset->fresh()->only(['file_name', 'stack_key']))->toBe(['file_name' => 'brochure.pdf', 'stack_key' => 'Bistro menu']);
});

it('refuses a REST key without the route\'s scope', function () {
    $headers = $this->getAuthenticationHeaders('custom', ['api.dam.assets.files.index']);

    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.attachments.order', $this->asset->id), ['order' => [$this->original->attachment_id]])->assertForbidden();
    $this->withHeaders($headers)->putJson(route('admin.api.dam.assets.stack.update', $this->asset->id), ['basis' => null])->assertForbidden();
});
