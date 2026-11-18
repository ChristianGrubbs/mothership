<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\GenerateDngPreview;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DngPreviewService;
use Mothership\DAM\Support\DerivativeImages;

it('recognizes DNG images even when the upload MIME is generic', function () {
    expect(AssetHelper::getFileType(UploadedFile::fake()->create('photo.DNG', 1, 'application/octet-stream')))->toBe('image');
    expect(AssetHelper::getFileTypeUsingExtension('DNG'))->toBe('image');
    expect((new Asset(['extension' => 'DNG', 'file_type' => 'document']))->file_type)->toBe('image');
});

it('extracts an oriented JPEG preview without changing the original and reuses its cache', function () {
    Storage::fake(Directory::getAssetDisk());
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, imagecolorallocate($image, 180, 90, 30));
    ob_start();
    imagejpeg($image);
    $jpeg = ob_get_clean();
    imagedestroy($image);
    // Minimal TIFF/DNG with an embedded JPEG and a 90-degree orientation.
    $tags = [
        [256, 4, 1, pack('V', 800)], [257, 4, 1, pack('V', 600)],
        [259, 3, 1, pack('v2', 6, 0)], [274, 3, 1, pack('v2', 6, 0)],
        [513, 4, 1, pack('V', 8 + 2 + 7 * 12 + 4)],
        [514, 4, 1, pack('V', strlen($jpeg))], [50706, 1, 4, "\x01\x04\x00\x00"],
    ];
    $dng = 'II'.pack('vVv', 42, 8, count($tags));
    foreach ($tags as [$tag, $type, $count, $value]) {
        $dng .= pack('vvV', $tag, $type, $count).$value;
    }
    $dng .= pack('V', 0).$jpeg;
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put('assets/photo.dng', $dng);
    $asset = Asset::factory()->create(['path' => 'assets/photo.dng', 'extension' => 'dng', 'file_type' => 'image']);
    $service = app(DngPreviewService::class);
    expect($service->ensure($asset, cachedOnly: true))->toBeNull();
    $preview = $service->ensure($asset);
    expect($preview)->not->toBeNull();
    $dimensions = getimagesizefromstring($disk->get($preview));
    expect($dimensions[0])->toBe(600)->and($dimensions[1])->toBe(800);
    expect($dimensions['mime'])->toBe('image/jpeg');
    expect($disk->get($asset->path))->toBe($dng);
    expect($service->ensure($asset->fresh()))->toBe($preview);
    expect($asset->playbackSource())->toMatchArray([
        'mime'         => 'image/jpeg',
        'kind'         => 'image',
        'original_url' => null,
        'is_proxy'     => false,
    ]);

    $this->loginAsAdmin();
    $this->get(route('admin.dam.file.preview', ['path' => urlencode($asset->path)]))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $thumbnail = $service->ensure($asset->fresh(), thumbnail: true);
    $thumbSize = getimagesizefromstring($disk->get($thumbnail));
    expect(max($thumbSize[0], $thumbSize[1]))->toBe(300);
    $share = Share::factory()->forAsset($asset->id)->create();
    $sharedPreview = $this->get(route('dam.share.download', ['token' => $share->urlToken(), 'disposition' => 'inline']))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($sharedPreview->headers->get('Cache-Control'))->toContain('private', 'no-cache')->not->toContain('public')->not->toContain('no-store');
    $sharedThumbnail = $this->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($sharedThumbnail->headers->get('Cache-Control'))->toContain('private', 'no-cache')->not->toContain('public')->not->toContain('no-store');
    $this->get(route('dam.share.download', $share->urlToken()))
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="'.$asset->file_name.'"');
    $share->update(['expires_at' => now()->subDay()]);
    $this->get(route('dam.share.download', ['token' => $share->urlToken(), 'disposition' => 'inline']))->assertStatus(410);
    Queue::fake();
    $this->artisan('dam:backfill-thumbnails')
        ->expectsOutput('Found 0 videos, 0 PDFs and 1 DNGs.')
        ->expectsOutput('Done. 0 queued, 1 skipped (already had thumbnails).')
        ->assertSuccessful();
    Queue::assertNotPushed(GenerateDngPreview::class);

    $disk->put($asset->path, $dng.'updated');
    $asset->update(['file_size' => strlen($dng.'updated')]);
    $replacement = $service->ensure($asset->fresh());
    expect($replacement)->not->toBeNull()->not->toBe($preview);
});

it('does not publish a preview for invalid DNG data and cleans temporary files', function () {
    Storage::fake(Directory::getAssetDisk());
    $disk = Storage::disk(Directory::getAssetDisk());
    $disk->put('assets/broken.dng', 'invalid raw');
    $asset = Asset::factory()->create(['path' => 'assets/broken.dng', 'extension' => 'dng']);
    // A private temp directory: CI runners share /tmp, so a global glob sees other jobs' files.
    $temp = sys_get_temp_dir().'/damdng-test-'.bin2hex(random_bytes(8));
    mkdir($temp);
    try {
        $service = new DngPreviewService(tempDirectory: $temp);
        expect($service->ensure($asset))->toBeNull();
        expect(glob($temp.'/damdng_*'))->toBe([]);
    } finally {
        array_map('unlink', glob($temp.'/*'));
        rmdir($temp);
    }
    expect($disk->get($asset->path))->toBe('invalid raw');
});

it('keeps a shared DNG blob\'s sha-keyed derivatives when only one of its assets is deleted', function () {
    Storage::fake(Directory::getAssetDisk());
    $disk = Storage::disk(Directory::getAssetDisk());
    $dng = minimalDngWithEmbeddedJpeg();
    $disk->put('assets/shared-a.dng', $dng);
    $first = Asset::factory()->create(['path' => 'assets/shared-a.dng', 'extension' => 'dng', 'file_type' => 'image']);
    $disk->put('assets/shared-b.dng', $dng);
    $second = Asset::factory()->create(['path' => 'assets/shared-b.dng', 'extension' => 'dng', 'file_type' => 'image']);

    $sha = app(ContentStore::class)->current($first)->sha256;
    // Same bytes hash to the same sha256, so both assets attach to one FileObject (ADR 0009).
    expect(app(ContentStore::class)->current($second)->sha256)->toBe($sha);

    $service = app(DngPreviewService::class);
    $preview = $service->ensure($first);
    $thumbnail = $service->ensure($first, thumbnail: true);
    expect($preview)->toBe(DerivativeImages::dngPreview($sha))
        ->and($thumbnail)->toBe(DerivativeImages::dngThumbnail($sha));

    app(AssetDeletionService::class)->delete($first, force: true);

    // $second's AssetFile still references the shared FileObject, so it was never reclaimed.
    $disk->assertExists($preview);
    $disk->assertExists($thumbnail);
});

it('purges a DNG blob\'s sha-keyed derivatives once deleting its last asset reclaims it', function () {
    Storage::fake(Directory::getAssetDisk());
    $disk = Storage::disk(Directory::getAssetDisk());
    $dng = minimalDngWithEmbeddedJpeg();
    $disk->put('assets/solo.dng', $dng);
    $asset = Asset::factory()->create(['path' => 'assets/solo.dng', 'extension' => 'dng', 'file_type' => 'image']);

    $sha = app(ContentStore::class)->current($asset)->sha256;
    $service = app(DngPreviewService::class);
    $preview = $service->ensure($asset);
    $thumbnail = $service->ensure($asset, thumbnail: true);
    expect($preview)->toBe(DerivativeImages::dngPreview($sha))
        ->and($thumbnail)->toBe(DerivativeImages::dngThumbnail($sha));
    $disk->assertExists($preview);
    $disk->assertExists($thumbnail);

    app(AssetDeletionService::class)->delete($asset, force: true);

    // The blob is now orphaned and unlinked; its sha-keyed derivatives (DNG included, ADR 0009
    // slice 2) live under the same directory purgeContent() reclaims with it.
    $disk->assertMissing($preview);
    $disk->assertMissing($thumbnail);
});

it('builds a thumbnail for a stacked file\'s blob, which no asset owns as its original', function () {
    Storage::fake(Directory::getAssetDisk());
    $disk = Storage::disk(Directory::getAssetDisk());
    $dng = minimalDngWithEmbeddedJpeg();
    $disk->put('assets/stacked.dng', $dng);
    $asset = Asset::factory()->create(['path' => 'assets/stacked.dng', 'extension' => 'dng', 'file_type' => 'image']);
    $object = app(ContentStore::class)->current($asset);

    $thumbnail = app(DngPreviewService::class)->ensureForObject($object);

    expect($thumbnail)->not->toBeNull();
    $size = getimagesizefromstring($disk->get($thumbnail));
    expect(max($size[0], $size[1]))->toBe(300)->and($disk->get($asset->path))->toBe($dng);
});
