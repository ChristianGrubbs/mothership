<?php

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\VideoPreviewProfile;

it('skips a video that is already 1080p', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'meta_data' => ['ImageWidth' => 1920, 'ImageHeight' => 1080],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeFalse();
});

it('wants a proxy for a 4k video', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 2160],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
});

it('reads dimensions from extracted metadata when the columns are empty', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'meta_data' => ['ImageWidth' => 3840, 'ImageHeight' => 2160],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->sourceHeight($asset))->toBe(2160)
        ->and(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
});

it('never wants a proxy for a non-video asset', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'image',
        'mime_type' => 'image/png',
        'extension' => 'png',
        'meta_data' => ['ImageWidth' => 6000, 'ImageHeight' => 4000],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeFalse();
});

it('wants a proxy for a 1080p master the browser cannot play', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'video',
        'mime_type' => 'video/quicktime',
        'extension' => 'mov',
        'meta_data' => ['ImageWidth' => 1920, 'ImageHeight' => 1080, 'codec_name' => 'prores'],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
});

it('never wants a proxy for a panorama video even at 4k-class height', function () {
    $asset = Asset::factory()->make([
        'file_type'     => 'video',
        'mime_type'     => 'video/mp4',
        'extension'     => 'mp4',
        'panorama_mode' => Asset::PANORAMA_AUTO,
        // Ratio 2.0 falls inside PanoramaClassifier's 1.95-2.05 equirectangular
        // band, so this is classified as a panorama despite the 4K-class height.
        'meta_data'     => ['ImageWidth' => 4096, 'ImageHeight' => 2048],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeFalse();
});

it('wants a proxy for a non-panorama video with no height metadata at all', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'meta_data' => [],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->sourceHeight($asset))->toBeNull()
        ->and(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
});

it('wants a proxy when only SourceImageHeight is present in metadata', function () {
    $asset = Asset::factory()->make([
        'file_type' => 'video',
        'mime_type' => 'video/mp4',
        'extension' => 'mp4',
        'meta_data' => ['SourceImageHeight' => 2160],
    ]);
    $asset->setRelation('files', new EloquentCollection);

    expect(app(VideoPreviewProfile::class)->sourceHeight($asset))->toBe(2160)
        ->and(app(VideoPreviewProfile::class)->needsProxy($asset))->toBeTrue();
});
