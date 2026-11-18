<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

it('previews SVG originals as flat images including wide artwork', function (int $width) {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    $asset = Asset::factory()->create([
        'file_name' => 'art.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml',
        'file_type' => 'image', 'path' => 'assets/Root/art.svg',
        'meta_data' => ['width' => $width, 'height' => 100],
    ]);
    putAssetContent($asset, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><path d="M0 0H200V100Z"/></svg>');
    expect($asset->playbackSource())->not->toBeNull();
    expect($asset->playbackSource()['kind'])->toBe('image');
    $response = $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()
        ->assertJsonPath('playbackSource.kind', 'image');
    $this->get($response->json('previewPath'))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get($asset->playbackSource()['url'])->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
})->with([100, 200]);

it('streams the original for vectorization even with an AWS asset disk', function () {
    $this->loginAsAdmin();
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS]);
    Storage::fake(Directory::ASSETS_DISK_AWS);
    $asset = Asset::factory()->create([
        'file_name' => 'art.png', 'extension' => 'png', 'mime_type' => 'image/png',
        'file_type' => 'image', 'path' => 'assets/Root/art.png',
    ]);
    // Ingested as the asset's current original, as an upload's would be (ADR 0009): reads go through it.
    putAssetContent($asset, 'original-bytes', Directory::ASSETS_DISK_AWS);
    $response = $this->get(route('admin.dam.assets.custom_download', $asset->id))->assertOk();
    expect($response->streamedContent())->toBe('original-bytes');
    expect($response->headers->has('Location'))->toBeFalse();
});
