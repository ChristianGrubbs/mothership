<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Services\StylePressThumbnailDerivatives;

function stylePressWriteJpegFixture(string $path, int $width, int $height): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 120, 140, 200));
    imagejpeg($image, $path, 90);
    imagedestroy($image);
}

function stylePressWritePngFixture(string $path, int $width, int $height): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 150, 90));
    imagepng($image, $path);
    imagedestroy($image);
}

function stylePressWriteWebpFixture(string $path, int $width, int $height): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 90, 30));
    imagewebp($image, $path);
    imagedestroy($image);
}

function stylePressPngBytes(int $width = 8, int $height = 8): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 150, 90));
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return ob_get_clean();
}

function stylePressWebpBytes(int $width = 8, int $height = 8): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 90, 30));
    ob_start();
    imagewebp($image);
    imagedestroy($image);

    return ob_get_clean();
}

beforeEach(function () {
    // Sources live outside any disk (thumbnailPath() already returns a bare filesystem path);
    // only the rendered derivative goes through the 'local' disk, faked here so nothing writes
    // into a real storage/app/stylepress-thumbnails during the test run.
    $this->derivativeSourceRoot = sys_get_temp_dir().'/mothership-stylepress-derivative-src-'.Str::uuid();
    mkdir($this->derivativeSourceRoot, 0777, true);
    Storage::fake('local');
});

afterEach(function () {
    foreach (glob($this->derivativeSourceRoot.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->derivativeSourceRoot);
});

it('resizes a wide StylePress source down to a 480px-wide JPEG derivative under stylepress-thumbnails/{catalogVersion}/{slug}.jpg', function () {
    $source = $this->derivativeSourceRoot.'/wide.jpg';
    stylePressWriteJpegFixture($source, 800, 1000);

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'wide-style', catalogVersion: 'v1');

    expect($derivativePath)->not->toBe($source);
    Storage::disk('local')->assertExists('stylepress-thumbnails/v1/wide-style.jpg');
    $size = getimagesize($derivativePath);
    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(480)
        ->and(filesize($derivativePath))->toBeLessThan(filesize($source));
});

it('re-encodes a StylePress source that is already at or under the target width to JPEG without resizing it', function () {
    $source = $this->derivativeSourceRoot.'/small.jpg';
    stylePressWriteJpegFixture($source, 300, 375);

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'small-style', catalogVersion: 'v1');

    $size = getimagesize($derivativePath);
    expect($size)->not->toBeFalse()
        ->and($size[2])->toBe(IMAGETYPE_JPEG)
        ->and($size[0])->toBe(300)
        ->and($size[1])->toBe(375);
});

it('transcodes a PNG StylePress source to a real JPEG derivative, not PNG bytes under a .jpg name', function () {
    $source = $this->derivativeSourceRoot.'/small.png';
    stylePressWritePngFixture($source, 40, 40);

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'png-style', catalogVersion: 'v1');

    $size = getimagesize($derivativePath);
    expect($size)->not->toBeFalse()
        ->and($size[2])->toBe(IMAGETYPE_JPEG)
        ->and($size[0])->toBe(40)
        ->and($size[1])->toBe(40);
});

it('transcodes a WebP StylePress source to a real JPEG derivative, not WebP bytes under a .jpg name', function () {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('This GD build lacks WebP support.');
    }
    $source = $this->derivativeSourceRoot.'/small.webp';
    stylePressWriteWebpFixture($source, 40, 40);

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'webp-style', catalogVersion: 'v1');

    $size = getimagesize($derivativePath);
    expect($size)->not->toBeFalse()
        ->and($size[2])->toBe(IMAGETYPE_JPEG)
        ->and($size[0])->toBe(40)
        ->and($size[1])->toBe(40);
});

it('falls back to the original file, never a 500, when the source cannot be decoded', function () {
    $source = $this->derivativeSourceRoot.'/broken.jpg';
    file_put_contents($source, 'not a real image');

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'broken-style', catalogVersion: 'v1');

    expect($derivativePath)->toBe($source);
    Storage::disk('local')->assertMissing('stylepress-thumbnails/v1/broken-style.jpg');
});

it('regenerates a derivative path that already holds PNG bytes under the .jpg name (a stale pre-fix write)', function () {
    $source = $this->derivativeSourceRoot.'/wide.jpg';
    stylePressWriteJpegFixture($source, 800, 1000);

    // Simulate what the pre-fix code (before the "always transcode" change) could have left on
    // disk: real PNG bytes sitting at the .jpg derivative path.
    Storage::disk('local')->put('stylepress-thumbnails/v1/legacy-style.jpg', stylePressPngBytes());

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'legacy-style', catalogVersion: 'v1');

    $size = getimagesize($derivativePath);
    expect($size)->not->toBeFalse()->and($size[2])->toBe(IMAGETYPE_JPEG);
});

it('regenerates a derivative path that already holds WebP bytes under the .jpg name (a stale pre-fix write)', function () {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('This GD build lacks WebP support.');
    }
    $source = $this->derivativeSourceRoot.'/wide.jpg';
    stylePressWriteJpegFixture($source, 800, 1000);

    Storage::disk('local')->put('stylepress-thumbnails/v1/legacy-webp-style.jpg', stylePressWebpBytes());

    $derivativePath = app(StylePressThumbnailDerivatives::class)->path(originalPath: $source, slug: 'legacy-webp-style', catalogVersion: 'v1');

    $size = getimagesize($derivativePath);
    expect($size)->not->toBeFalse()->and($size[2])->toBe(IMAGETYPE_JPEG);
});

it('reuses an already-rendered derivative on a second request instead of re-rendering it', function () {
    $source = $this->derivativeSourceRoot.'/wide.jpg';
    stylePressWriteJpegFixture($source, 800, 1000);

    $service = app(StylePressThumbnailDerivatives::class);
    $first = $service->path(originalPath: $source, slug: 'repeat-style', catalogVersion: 'v1');
    $second = $service->path(originalPath: $source, slug: 'repeat-style', catalogVersion: 'v1');

    expect($second)->toBe($first)
        ->and(is_file($first))->toBeTrue();
});
