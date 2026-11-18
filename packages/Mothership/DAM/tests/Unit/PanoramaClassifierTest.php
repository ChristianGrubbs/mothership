<?php

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\PanoramaClassifier;

it('classifies the approved panorama matrix', function (
    string $extension,
    string $mime,
    string $fileType,
    int $width,
    int $height,
    bool $expected,
) {
    $asset = Asset::factory()->make([
        'extension'     => $extension,
        'mime_type'     => $mime,
        'file_type'     => $fileType,
        'panorama_mode' => Asset::PANORAMA_AUTO,
        'meta_data'     => ['ImageWidth' => $width, 'ImageHeight' => $height],
    ]);

    expect(app(PanoramaClassifier::class)->classify($asset)['is_panorama'])->toBe($expected);
})->with([
    'jpeg 2:1' => ['jpeg', 'image/jpeg', 'image', 4000, 2000, true],
    'png 2:1'  => ['png', 'image/png', 'image', 3840, 1920, true],
    'webp 2:1' => ['webp', 'image/webp', 'image', 2048, 1024, true],
    'mp4 2:1'  => ['mp4', 'video/mp4', 'video', 3840, 1920, true],
    'mov 2:1'  => ['mov', 'video/quicktime', 'video', 3840, 1920, true],
    'webm 2:1' => ['webm', 'video/webm', 'video', 3840, 1920, true],
    'flat'     => ['jpg', 'image/jpeg', 'image', 4000, 3000, false],
]);

it('honors both panorama overrides', function () {
    $flat = Asset::factory()->make([
        'panorama_mode' => Asset::PANORAMA_FORCE_360,
        'file_type'     => 'image', 'extension' => 'jpg',
        'meta_data'     => ['ImageWidth' => 4000, 'ImageHeight' => 3000],
    ]);
    $wide = Asset::factory()->make([
        'panorama_mode' => Asset::PANORAMA_FORCE_FLAT,
        'file_type'     => 'image', 'extension' => 'jpg',
        'meta_data'     => ['ImageWidth' => 4000, 'ImageHeight' => 2000],
    ]);

    expect(app(PanoramaClassifier::class)->classify($flat)['is_panorama'])->toBeTrue()
        ->and(app(PanoramaClassifier::class)->classify($wide)['is_panorama'])->toBeFalse();
});

it('detects imported panorama images from their resolution field', function (string $extension, string $resolution, bool $expected) {
    $asset = Asset::factory()->make([
        'extension'     => $extension,
        'mime_type'     => $extension === 'png' ? 'image/png' : 'image/jpeg',
        'file_type'     => 'image',
        'panorama_mode' => Asset::PANORAMA_AUTO,
        'meta_data'     => ['legacy' => ['fields' => ['tech_resolution' => $resolution]]],
    ]);

    $classification = app(PanoramaClassifier::class)->classify($asset);

    expect($classification['is_panorama'])->toBe($expected)
        ->and($classification['direct_candidate'])->toBe($expected)
        ->and($classification['requires_proxy'])->toBeFalse();
})->with([
    'imported png'       => ['png', '3840x1920', true],
    'imported jpeg'      => ['jpeg', '4000x2000', true],
    'imported jpg'       => ['jpg', '4000 × 2000', true],
    'flat image'         => ['png', '4000x3000', false],
    'invalid dimensions' => ['jpeg', '4000x0', false],
    'unstructured text'  => ['png', 'panorama 3840x1920 draft', false],
]);

it('prefers current dimensions and explicit flat mode over imported resolution', function () {
    $asset = Asset::factory()->make([
        'extension'     => 'png',
        'file_type'     => 'image',
        'panorama_mode' => Asset::PANORAMA_AUTO,
        'meta_data'     => [
            'width'  => 4000,
            'height' => 3000,
            'legacy' => ['fields' => ['tech_resolution' => '4000x2000']],
        ],
    ]);

    expect(app(PanoramaClassifier::class)->classify($asset)['is_panorama'])->toBeFalse();

    $asset->meta_data = ['legacy' => ['fields' => ['tech_resolution' => '4000x2000']]];
    $asset->panorama_mode = Asset::PANORAMA_FORCE_FLAT;

    expect(app(PanoramaClassifier::class)->classify($asset)['is_panorama'])->toBeFalse();
});

it('does not replace a partial current dimension with stale imported dimensions', function (array $current) {
    $asset = Asset::factory()->make([
        'extension'     => 'png',
        'file_type'     => 'image',
        'panorama_mode' => Asset::PANORAMA_AUTO,
        'meta_data'     => array_merge($current, ['legacy' => ['fields' => ['tech_resolution' => '4000x2000']]]),
    ]);

    expect(app(PanoramaClassifier::class)->classify($asset)['is_panorama'])->toBeFalse();
})->with([
    'only current width'  => [['width' => 3000]],
    'only current height' => [['height' => 3000]],
]);
