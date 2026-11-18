<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\LargeRasterPreviewService;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    Process::preventStrayProcesses();
});

function largeRasterFixture(string $extension, int $width = 800, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    if ($extension === 'png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 20, 80, 140, 64));
    } else {
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 80, 140));
    }

    ob_start();
    $extension === 'png' ? imagepng($image) : imagejpeg($image, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/**
 * A real but tiny JPEG whose SOF marker declares an arbitrary geometry. getimagesize()
 * reads only that marker, so this exercises the oversized-raster decision without
 * allocating the bitmap the decision exists to avoid.
 */
function jpegDeclaringDimensions(int $width, int $height): string
{
    $bytes = largeRasterFixture('jpg', 16, 16);
    $offset = 2;

    while ($offset < strlen($bytes) - 1) {
        if ($bytes[$offset] !== "\xFF") {
            $offset++;

            continue;
        }

        $marker = ord($bytes[$offset + 1]);

        if ($marker === 0xC0 || $marker === 0xC2) {
            // SOF: marker(2) length(2) precision(1) height(2) width(2)
            $bytes = substr_replace($bytes, pack('n', $height), $offset + 5, 2);

            return substr_replace($bytes, pack('n', $width), $offset + 7, 2);
        }

        $offset += 2 + unpack('n', substr($bytes, $offset + 2, 2))[1];
    }

    throw new RuntimeException('JPEG fixture has no SOF marker to rewrite.');
}

it('uses libvips before GD for oversized thumbnail and preview requests', function (
    string $extension,
    string $mime,
    string $action,
    int $size,
) {
    config(['mothership.large_raster_preview_pixels' => 1]);
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/oversized.'.$extension;
    $source = largeRasterFixture($extension);
    Storage::disk($disk)->put($path, $source);
    $sha = app(ContentStore::class)->current(registerServedAssetPath($path))->sha256;

    Process::fake(function ($process) use ($mime) {
        $command = $process->command;
        $outputPath = $command[3];
        $output = imagecreatetruecolor(120, 80);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagefill($output, 0, 0, $mime === 'image/png'
            ? imagecolorallocatealpha($output, 40, 100, 160, 64)
            : imagecolorallocate($output, 40, 100, 160));
        // Lossless keeps the alpha channel exact for the assertion below.
        imagewebp($output, $outputPath, IMG_WEBP_LOSSLESS);
        imagedestroy($output);

        return Process::result();
    });

    request()->merge(['path' => $path, 'size' => (string) $size]);
    $response = (new FileController)->{$action}();
    // Generated derivatives are WebP, whatever the source format, keyed by the original's sha.
    $destination = $action === 'thumbnail'
        ? DerivativeImages::thumbnail($sha, $extension)
        : DerivativeImages::preview($sha, $size, $extension);
    expect($destination)->toEndWith('.webp');

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('image/webp');
    Storage::disk($disk)->assertExists($destination);
    expect(Storage::disk($disk)->get($path))->toBe($source);
    expect(array_filter(
        Storage::disk($disk)->allFiles(),
        fn (string $file): bool => str_contains($file, '.pending.')
    ))->toBe([]);

    $derivative = Storage::disk($disk)->get($destination);
    $info = getimagesizefromstring($derivative);
    expect($info['mime'])->toBe('image/webp')
        ->and(max($info[0], $info[1]))->toBeLessThanOrEqual($size);
    if ($mime === 'image/png') {
        $decoded = imagecreatefromstring($derivative);
        expect((imagecolorat($decoded, 0, 0) >> 24) & 0x7F)->toBe(64);
        imagedestroy($decoded);
    }

    Process::assertRan(function ($process) use ($size) {
        $command = $process->command;

        return $command[0] === 'vips'
            && $command[1] === 'thumbnail'
            && $command[4] === (string) $size
            && in_array('--size', $command, true)
            && in_array('down', $command, true)
            && ! in_array('--no-rotate', $command, true)
            && str_ends_with($command[3], '.webp');
    });
})->with([
    'PNG thumbnail with alpha'               => ['png', 'image/png', 'thumbnail', 300],
    'JPEG preview with autorotation enabled' => ['jpg', 'image/jpeg', 'preview', 192],
]);

it('leaves small and unsupported rasters on the ordinary image path', function () {
    config(['mothership.large_raster_preview_pixels' => 1_000_000]);
    Process::fake();
    $disk = Directory::getAssetDisk();
    $storage = Storage::disk($disk);
    $service = app(LargeRasterPreviewService::class);

    $storage->put('assets/small.png', largeRasterFixture('png', 100, 100));
    expect($service->generateIfNeeded(
        disk: $disk,
        sourcePath: 'assets/small.png',
        destinationPath: 'preview/300/assets/small.png',
        maxSize: 300,
    ))->toBeFalse();

    $storage->put('assets/unsupported.gif', 'GIF89a');
    expect($service->generateIfNeeded(
        disk: $disk,
        sourcePath: 'assets/unsupported.gif',
        destinationPath: 'preview/300/assets/unsupported.gif',
        maxSize: 300,
    ))->toBeFalse();

    Process::assertNothingRan();
    $storage->assertMissing('preview/300/assets/small.png');
    $storage->assertMissing('preview/300/assets/unsupported.gif');
});

it('does not fall back to GD when libvips fails for an oversized raster', function () {
    config(['mothership.large_raster_preview_pixels' => 1]);
    Process::fake(fn () => Process::result(errorOutput: 'fixture failure', exitCode: 1));
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/vips-failure.png';
    $source = largeRasterFixture('png');
    Storage::disk($disk)->put($path, $source);
    $sha = app(ContentStore::class)->current(registerServedAssetPath($path))->sha256;
    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/image.svg', '<svg id="large-raster-placeholder"/>');

    request()->merge(['path' => $path]);
    request()->headers->set('Accept', 'image/*');
    $response = (new FileController)->thumbnail();

    expect($response->getContent())->toContain('large-raster-placeholder');
    Storage::disk($disk)->assertMissing(DerivativeImages::thumbnail($sha, 'png'));
    expect(Storage::disk($disk)->get($path))->toBe($source);
    Process::assertRan(fn ($process): bool => $process->command[0] === 'vips');
});

it('sends the 131 MP geometry behind the production 500s to libvips at the shipped default threshold', function () {
    // Field evidence, windows-gpu, read-only 2026-09-19: every one of the 18 HTTP 500s on
    // /admin/dam/file/thumbnail and /admin/dam/file/preview came from a single 14016x9344
    // JPEG (130.97 MP, 48.9 MB). That runtime predates this service, so GD decoded the
    // raster in process - about 524 MB of bitmap against memory_limit=512M - and PHP died
    // on a fatal memory error. A fatal is not a Throwable, which is why it escaped the
    // catch in FileController::thumbnail() and wrote nothing to laravel.log.
    // This case pins the SHIPPED DEFAULT deliberately, with no config override: raising
    // that default past this geometry would reopen the fault just as silently.
    $productionPixels = 14016 * 9344;
    expect((int) config('mothership.large_raster_preview_pixels', 32_000_000))
        ->toBeLessThanOrEqual($productionPixels);

    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/production-oversized.jpg';
    Storage::disk($disk)->put($path, jpegDeclaringDimensions(14016, 9344));

    Process::fake(function ($process) {
        $output = imagecreatetruecolor(300, 200);
        imagefill($output, 0, 0, imagecolorallocate($output, 10, 20, 30));
        imagejpeg($output, $process->command[3], 90);
        imagedestroy($output);

        return Process::result();
    });

    expect(app(LargeRasterPreviewService::class)->generateIfNeeded(
        disk: $disk,
        sourcePath: $path,
        destinationPath: 'thumbnails/'.$path,
        maxSize: 300,
    ))->toBeTrue();

    Storage::disk($disk)->assertExists('thumbnails/'.$path);
    Process::assertRan(
        fn ($process): bool => $process->command[0] === 'vips' && $process->command[1] === 'thumbnail'
    );
});
