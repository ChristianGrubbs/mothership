<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\ContentStore;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

/** Attaches a published 1080p preview proxy and its done rendition. */
function attachPreviewProxy(Asset $asset, bool $writeFile = true, string $label = 'Preview (1080p)'): AssetFile
{
    $path = app(ContentStore::class)->objectPath(hash('sha256', 'preview|'.$asset->id), 'mp4');

    if ($writeFile) {
        Storage::disk(Directory::getAssetDisk())->put($path, 'proxy-bytes');
    }

    $object = FileObject::factory()->create([
        'sha256'    => hash('sha256', 'preview|'.$asset->id),
        'size'      => strlen('proxy-bytes'),
        'disk'      => Directory::getAssetDisk(),
        'path'      => $path,
        'mime_type' => 'video/mp4',
        'technical' => ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264'],
    ]);

    $file = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $object->id,
        'role'           => AssetFile::ROLE_PROXY,
        'label'          => $label,
        'sort'           => 900,
    ]);

    Rendition::factory()->create([
        'dam_asset_id'          => $asset->id,
        'recipe'                => Rendition::RECIPE_PREVIEW_1080P,
        'status'                => Rendition::STATUS_DONE,
        'output_file_object_id' => $object->id,
        'finished_at'           => now(),
    ]);

    return $file;
}

function makeBigVideo(): Asset
{
    $asset = Asset::factory()->create([
        'file_name' => 'master.mp4',
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'path'      => 'assets/Root/master.mp4',
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 2160],
    ]);

    return putAssetContent($asset, 'original-video');
}

it('prefers a published preview proxy over a browser-playable master', function () {
    $asset = makeBigVideo();
    $proxy = attachPreviewProxy($asset);

    $source = $asset->playbackSource();

    expect($source['is_proxy'])->toBeTrue()
        ->and($source['kind'])->toBe('video')
        ->and($source['label'])->toBe('Preview (1080p)')
        ->and($source['url'])->toBe(route('admin.dam.file.asset-file', [
            'asset'     => $asset->id,
            'assetFile' => $proxy->id,
        ]))
        ->and($source['original_url'])->not->toBeNull()
        ->and($source['original_url'])->not->toBe($source['url']);
});

it('plays the master when no preview proxy exists', function () {
    $asset = makeBigVideo();

    $source = $asset->playbackSource();

    expect($source['is_proxy'])->toBeFalse()
        ->and($source['label'])->toBe('Original')
        ->and($source['kind'])->toBe('video');
});

it('falls back to the master when the proxy row exists but its file is gone', function () {
    $asset = makeBigVideo();
    attachPreviewProxy($asset, writeFile: false);

    $source = $asset->playbackSource();

    expect($source['is_proxy'])->toBeFalse()
        ->and($source['label'])->toBe('Original');
});

it('leaves panorama playback untouched even with a competing published preview proxy', function () {
    $asset = Asset::factory()->create([
        'file_name'  => 'flight.osv',
        'file_type'  => 'document',
        'mime_type'  => 'video/mp4',
        'extension'  => 'osv',
        'path'       => 'assets/Root/flight.osv',
        'asset_kind' => 'pano360',
    ]);

    $stitched = attachPreviewFile(
        $asset,
        'assets/Root/flight-stitched.mp4',
        'video/mp4',
        AssetFile::ROLE_PROXY,
        'Stitched 360 Preview (equirectangular)',
        10,
        ['codec_name' => 'h264'],
    );

    // A competing DONE RECIPE_PREVIEW_1080P rendition, with its proxy file
    // present on disk, must not outrank the panorama's own stitched proxy —
    // the panorama exclusion in publishedPreviewProxy() must actually fire.
    attachPreviewProxy($asset);

    $source = $asset->playbackSource();

    expect($source['kind'])->toBe('pano360-video')
        ->and($source['label'])->toBe('Stitched 360 Preview (equirectangular)')
        ->and($source['url'])->toBe(route('admin.dam.file.asset-file', [
            'asset'     => $asset->id,
            'assetFile' => $stitched->id,
        ]));
});

it('reports a null original_url when a proxy is published over a browser-unplayable ProRes master', function () {
    $asset = Asset::factory()->create([
        'file_name' => 'master.mov',
        'file_type' => 'video',
        'mime_type' => 'video/quicktime',
        'extension' => 'mov',
        'path'      => 'assets/Root/master.mov',
        'meta_data' => [
            'codec_name'  => 'prores',
            'ImageWidth'  => 1920,
            'ImageHeight' => 1080,
        ],
    ]);

    putAssetContent($asset, 'original-prores-video');

    attachPreviewProxy($asset);

    $source = $asset->playbackSource();

    expect($source['is_proxy'])->toBeTrue()
        ->and($source['original_url'])->toBeNull();
});

it('serves the older proxy when the newest DONE rendition file is missing from disk', function () {
    $asset = makeBigVideo();

    // Older DONE rendition: its proxy file is present on disk.
    $olderPath = app(ContentStore::class)->objectPath(hash('sha256', 'preview-older|'.$asset->id), 'mp4');
    Storage::disk(Directory::getAssetDisk())->put($olderPath, 'older-proxy-bytes');

    $olderObject = FileObject::factory()->create([
        'sha256'    => hash('sha256', 'preview-older|'.$asset->id),
        'size'      => strlen('older-proxy-bytes'),
        'disk'      => Directory::getAssetDisk(),
        'path'      => $olderPath,
        'mime_type' => 'video/mp4',
        'technical' => ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264'],
    ]);

    $olderFile = AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $olderObject->id,
        'role'           => AssetFile::ROLE_PROXY,
        'label'          => 'Preview (1080p) v1',
        'sort'           => 900,
    ]);

    Rendition::factory()->create([
        'dam_asset_id'          => $asset->id,
        'recipe'                => Rendition::RECIPE_PREVIEW_1080P,
        'status'                => Rendition::STATUS_DONE,
        'output_file_object_id' => $olderObject->id,
        'finished_at'           => now()->subMinute(),
    ]);

    // Newer DONE rendition (forced regeneration): its object row exists, its
    // AssetFile row exists, but the bytes were never written to disk — the
    // stale-newest-output case.
    $newerPath = app(ContentStore::class)->objectPath(hash('sha256', 'preview-newer|'.$asset->id), 'mp4');

    $newerObject = FileObject::factory()->create([
        'sha256'    => hash('sha256', 'preview-newer|'.$asset->id),
        'disk'      => Directory::getAssetDisk(),
        'path'      => $newerPath,
        'mime_type' => 'video/mp4',
        'technical' => ['width' => 1920, 'height' => 1080, 'codec_name' => 'h264'],
    ]);

    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $newerObject->id,
        'role'           => AssetFile::ROLE_PROXY,
        'label'          => 'Preview (1080p) v2',
        'sort'           => 900,
    ]);

    Rendition::factory()->create([
        'dam_asset_id'          => $asset->id,
        'recipe'                => Rendition::RECIPE_PREVIEW_1080P,
        'status'                => Rendition::STATUS_DONE,
        'output_file_object_id' => $newerObject->id,
        'finished_at'           => now(),
    ]);

    $source = $asset->playbackSource();

    expect($source['is_proxy'])->toBeTrue()
        ->and($source['label'])->toBe('Preview (1080p) v1')
        ->and($source['url'])->toBe(route('admin.dam.file.asset-file', [
            'asset'     => $asset->id,
            'assetFile' => $olderFile->id,
        ]));
});

it('treats a published preview as the proxy even when its AssetFile label has been customized', function () {
    $asset = makeBigVideo();
    attachPreviewProxy($asset, label: 'Custom name');

    $source = $asset->playbackSource();

    expect($source['is_proxy'])->toBeTrue()
        ->and($source['label'])->toBe('Custom name')
        ->and($source['original_url'])->not->toBeNull();
});

it('exposes the master url and proxy flag to the preview modal', function () {
    $asset = makeBigVideo();
    attachPreviewProxy($asset);

    $response = $this->get(route('admin.dam.assets.edit', $asset->id));

    $response->assertOk();

    $source = $asset->playbackSource();
    $expectedOriginalUrl = json_encode($source['original_url'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

    $response
        ->assertSee('"playbackIsProxy":true', escape: false)
        ->assertSee('"originalUrl":'.$expectedOriginalUrl, escape: false);
});

it('exposes null original_url and false proxy flag when no proxy exists', function () {
    $asset = makeBigVideo();

    $response = $this->get(route('admin.dam.assets.edit', $asset->id));

    $response->assertOk()
        ->assertSee('"playbackIsProxy":false', escape: false)
        ->assertSee('"originalUrl":null', escape: false);
});
