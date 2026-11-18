<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\HeifPreviewService;
use Mothership\DAM\Support\DerivativeImages;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
 * HEIC/HEIF is decoded by libvips only (GD cannot read it). These cases run the real `vips` against committed synthetic
 * HEICs (a 64x48 left-red/right-blue one and a 640x480 one with a 90-degree `irot` and four coloured quadrants) so a mock
 * never stands in for the decoder. CI fails, not skips, when libvips has no HEIF reader.
 */

beforeEach(function () {
    // CI installs libvips before this runs and must prove the decoder: a missing HEIF reader fails there, and only a
    // developer machine without one skips.
    if ((int) shell_exec('vips -l 2>/dev/null | grep -c heifload') < 1) {
        getenv('GITHUB_ACTIONS') === 'true' || getenv('CI') === 'true'
            ? $this->fail('libvips has no HEIF reader (heifload) on this CI runner.')
            : $this->markTestSkipped('libvips with HEIF support is not available.');
    }

    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    $this->heic = file_get_contents(__DIR__.'/../Fixtures/heic/two-tone-64x48.heic');
    // 640x480 sensor pixels carrying an `irot` 90-degree-clockwise transform, so it displays as 480x640 (what a phone writes).
    // A genuine 10-bit HEVC Main 10 still, written by libvips `heifsave --bitdepth 10` (major brand heix).
    $this->main10 = file_get_contents(__DIR__.'/../Fixtures/heic/main10-320x240-heix.heic');
    $this->rotated = file_get_contents(__DIR__.'/../Fixtures/heic/rotated-640x480-irot90.heic');
});

function heifPendingFiles(): array
{
    return array_values(array_filter(
        Storage::disk(Directory::getAssetDisk())->allFiles(),
        fn (string $file): bool => str_contains($file, '.pending.'),
    ));
}

function heifTypedAsset(string $path, string $bytes, string $mime = 'image/heic'): array
{
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);
    $asset = registerServedAssetPath($path);
    $object = app(ContentStore::class)->current($asset);
    $object->update(['mime_type' => $mime]);
    $asset->update(['mime_type' => $mime]);

    return [$asset, $object->fresh()];
}

it('recognises a HEIC or HEIF by its bytes and leaves AVIF and other files alone', function () {
    $dir = sys_get_temp_dir().'/heif-brands-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    $isHeif = function (string $bytes) use ($dir): bool {
        file_put_contents($dir.'/f', $bytes);

        return HeifPreviewService::isHeif($dir.'/f');
    };

    expect($isHeif($this->heic))->toBeTrue()
        ->and($isHeif("\0\0\0\x18ftypmif1\0\0\0\0mif1heic"))->toBeTrue()
        ->and($isHeif("\0\0\0\x18ftypheix\0\0\0\0mif1heix"))->toBeTrue()
        // Image sequences are not stills: they keep the ordinary path.
        ->and($isHeif("\0\0\0\x18ftypmsf1\0\0\0\0msf1heic"))->toBeFalse()
        ->and($isHeif("\0\0\0\x18ftyphevc\0\0\0\0hevcmsf1"))->toBeFalse()
        ->and($isHeif("\0\0\0\x18ftypmif1\0\0\0\0mif1avif"))->toBeFalse()
        ->and($isHeif("\0\0\0\x18ftypavif\0\0\0\0mif1avif"))->toBeFalse()
        ->and($isHeif("\x89PNG\r\n\x1a\n and some more bytes"))->toBeFalse();

    unlink($dir.'/f');
    rmdir($dir);
});

it('serves a standalone HEIC as a WebP thumbnail and preview made by libvips, leaving the original HEIC untouched', function (string $action, int $size) {
    $disk = Directory::getAssetDisk();
    [$asset, $object] = heifTypedAsset('assets/Root/IMG_2345.HEIC', $this->heic);

    request()->merge(['path' => 'assets/Root/IMG_2345.HEIC', 'size' => (string) $size]);
    $response = (new FileController)->{$action}();

    $destination = $action === 'thumbnail'
        ? DerivativeImages::thumbnail($object->sha256, 'heic')
        : DerivativeImages::preview($object->sha256, $size, 'heic');
    expect($destination)->toEndWith('.webp')
        ->and($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('image/webp');

    $derivative = Storage::disk($disk)->get($destination);
    $info = getimagesizefromstring($derivative);
    expect($info['mime'])->toBe('image/webp')
        ->and([$info[0], $info[1]])->toBe([64, 48]);

    // The decoder really ran: the left of the picture is red and the right is blue.
    $decoded = imagecreatefromstring($derivative);
    $left = imagecolorat($decoded, 8, 24);
    $right = imagecolorat($decoded, 56, 24);
    imagedestroy($decoded);
    expect(($left >> 16) & 0xFF)->toBeGreaterThan(150)
        ->and($left & 0xFF)->toBeLessThan(100)
        ->and($right & 0xFF)->toBeGreaterThan(150)
        ->and(($right >> 16) & 0xFF)->toBeLessThan(100);

    expect(Storage::disk($disk)->get('assets/Root/IMG_2345.HEIC'))->toBe($this->heic)
        ->and(hash('sha256', $this->heic))->toBe($object->sha256)
        ->and(heifPendingFiles())->toBe([]);
})->with([
    'thumbnail300' => ['thumbnail', 300],
    'preview1920'  => ['preview', 1920],
]);

it('bounds the HEIC derivative to the requested size', function () {
    $disk = Directory::getAssetDisk();
    $local = sys_get_temp_dir().'/heif-bound-'.bin2hex(random_bytes(4)).'.heic';
    file_put_contents($local, $this->heic);

    try {
        expect(app(HeifPreviewService::class)->generateIfHeif(disk: $disk, localSource: $local, destinationPath: 'derivatives/aa/x/thumbnail.webp', maxSize: 32))->toBeTrue();
    } finally {
        unlink($local);
    }

    $info = getimagesizefromstring(Storage::disk($disk)->get('derivatives/aa/x/thumbnail.webp'));
    expect($info[0])->toBe(32)
        ->and($info[1])->toBe(24);
});

it('answers a corrupt HEIC with the placeholder, writes no derivative and leaves no staging file behind', function () {
    $disk = Directory::getAssetDisk();
    [$asset, $object] = heifTypedAsset('assets/Root/broken.heic', "\0\0\0\x1cftypheic\0\0\0\0mif1heicmiaf".str_repeat("\x07", 64));

    request()->merge(['path' => 'assets/Root/broken.heic']);
    request()->headers->set('Accept', 'image/*');
    $response = (new FileController)->thumbnail();

    expect($response->headers->get('Content-Type'))->not->toBe('image/webp')
        ->and($response->getContent())->toContain('<svg');
    Storage::disk($disk)->assertMissing(DerivativeImages::thumbnail($object->sha256, 'heic'));
    expect(heifPendingFiles())->toBe([]);
});

it('refuses a derivative when vips exits cleanly but reports tile decode errors, as a missing HEVC plugin does', function () {
    $disk = Directory::getAssetDisk();
    Process::fake(function ($process) {
        $output = preg_replace('/\[.*\]$/', '', $process->command[3]);
        $webp = imagecreatetruecolor(8, 8);
        imagewebp($webp, $output);

        return Process::result(output: '', errorOutput: "(vips:1): VIPS-WARNING **: error in tile 0 x 0\n");
    });
    $destination = DerivativeImages::thumbnail(str_repeat('c', 64), 'heic');

    expect(fn () => app(HeifPreviewService::class)->generateIfHeif(
        disk: $disk,
        localSource: base_path('packages/Mothership/DAM/tests/Fixtures/heic/two-tone-64x48.heic'),
        destinationPath: $destination,
        maxSize: 300,
    ))->toThrow(RuntimeException::class, 'decode errors');

    Storage::disk($disk)->assertMissing($destination);
    expect(heifPendingFiles())->toBe([]);
});

it('publishes the derivative when vips only warns about an ignored colour profile', function () {
    $disk = Directory::getAssetDisk();
    Process::fake(function ($process) {
        imagewebp(imagecreatetruecolor(8, 8), preg_replace('/\[.*\]$/', '', $process->command[3]));

        return Process::result(output: '', errorOutput: "(vips:1): VIPS-WARNING **: heifload: ignoring nclx profile\n");
    });
    $destination = DerivativeImages::thumbnail(str_repeat('d', 64), 'heic');

    expect(app(HeifPreviewService::class)->generateIfHeif(
        disk: $disk,
        localSource: base_path('packages/Mothership/DAM/tests/Fixtures/heic/two-tone-64x48.heic'),
        destinationPath: $destination,
        maxSize: 300,
    ))->toBeTrue();

    Storage::disk($disk)->assertExists($destination);
    expect(heifPendingFiles())->toBe([]);
});

it('keeps a HEIC-typed non-HEIF file off the libvips path', function () {
    Process::fake();
    $disk = Directory::getAssetDisk();
    $local = sys_get_temp_dir().'/heif-not-'.bin2hex(random_bytes(4)).'.heic';
    file_put_contents($local, 'not a heif at all, just text');

    try {
        expect(app(HeifPreviewService::class)->generateIfHeif(disk: $disk, localSource: $local, destinationPath: 'derivatives/aa/y/thumbnail.webp', maxSize: 300))->toBeFalse();
    } finally {
        unlink($local);
    }

    Process::assertNothingRan();
});

it('shows a selected non-cover HEIC member its own thumbnail and screen-sized preview, with the original still downloadable', function () {
    $cover = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'cover.png', 'path' => 'assets/Heif/cover.png']));
    $folder = Directory::create(['name' => 'Heif '.uniqid()]);
    $cover->directories()->attach($folder->id);
    $base = '/'.config('app.admin_url').'/dam/assets/'.$cover->id;

    $member = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('IMG_2345.HEIC', $this->heic), 'role' => 'sidecar', 'label' => 'IMG_2345.HEIC',
    ])->assertCreated()->json();
    $object = FileObject::query()->whereKey(AssetFile::query()->whereKey($member['id'])->value('file_object_id'))->first();
    $object->update(['mime_type' => 'image/heic']);

    $payload = collect($this->getJson(route('admin.dam.assets.files.index', $cover->id))->assertOk()->json('files'))->keyBy('id')[$member['id']];
    $thumbUrl = route('admin.dam.file.asset-file', ['asset' => $cover->id, 'assetFile' => $member['id'], 'thumb' => 1]);
    $previewUrl = route('admin.dam.file.asset-file', ['asset' => $cover->id, 'assetFile' => $member['id'], 'preview' => 1]);

    // Offered before the first request: the derivative is made on demand, for this member's own bytes.
    expect($payload['thumbnail_url'])->toStartWith($thumbUrl.'&v=')
        ->and($payload['preview_url'])->toBe($previewUrl);

    $disk = Storage::disk(Directory::getAssetDisk());
    foreach ([[$thumbUrl, DerivativeImages::thumbnail($object->sha256, 'heic')], [$previewUrl, DerivativeImages::preview($object->sha256, 1920, 'heic')]] as [$url, $destination]) {
        $answer = $this->get($url)->assertOk();
        expect($answer->headers->get('Content-Type'))->toBe('image/webp');
        $disk->assertExists($destination);
        expect(getimagesizefromstring($disk->get($destination))['mime'])->toBe('image/webp');
    }

    // The thumbnail now exists, so a later payload stops going through the on-request path.
    $again = collect($this->getJson(route('admin.dam.assets.files.index', $cover->id))->assertOk()->json('files'))->keyBy('id')[$member['id']];
    expect($again['thumbnail_url'])->toStartWith($thumbUrl.'&v=');

    $download = $this->get(route('admin.dam.file.asset-file', ['asset' => $cover->id, 'assetFile' => $member['id'], 'download' => 1]))->assertOk();
    $bytes = $download->baseResponse instanceof BinaryFileResponse ? file_get_contents($download->baseResponse->getFile()->getPathname()) : $download->streamedContent();
    expect($bytes)->toBe($this->heic)
        ->and(heifPendingFiles())->toBe([]);
});

it('gives a public share link the same WebP thumbnail of a HEIC', function () {
    [$asset, $object] = heifTypedAsset('assets/Root/shared.heic', $this->heic);
    $share = Share::factory()->forAsset($asset->id)->create();

    $answer = $this->get(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $asset->id]))->assertOk();

    expect(getimagesizefromstring($answer->baseResponse instanceof BinaryFileResponse ? file_get_contents($answer->baseResponse->getFile()->getPathname()) : $answer->getContent())['mime'])->toBe('image/webp');
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::shareStill($object->sha256, 'thumbnail', 'heic'));
});

/** A pixel of a decoded WebP as [r, g, b]. */
function heifPixel(string $webp, int $x, int $y): array
{
    $image = imagecreatefromstring($webp);
    $color = imagecolorat($image, $x, $y);
    imagedestroy($image);

    return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
}

it('displays a phone-style HEIC upright at thumbnail and preview size: the declared rotation is applied once', function (string $action, int $size, array $expected) {
    $disk = Directory::getAssetDisk();
    [$asset, $object] = heifTypedAsset('assets/Root/IMG_ROT.HEIC', $this->rotated);

    request()->merge(['path' => 'assets/Root/IMG_ROT.HEIC', 'size' => (string) $size]);
    (new FileController)->{$action}();

    $destination = $action === 'thumbnail'
        ? DerivativeImages::thumbnail($object->sha256, 'heic')
        : DerivativeImages::preview($object->sha256, $size, 'heic');
    $webp = Storage::disk($disk)->get($destination);
    $info = getimagesizefromstring($webp);

    // Sensor quadrants are red (top-left), blue (top-right), green (bottom-left), yellow (bottom-right), with a black
    // stamp in the sensor's top-left corner. Rotated 90 degrees clockwise: green top-left, red top-right (stamp in that
    // corner), yellow bottom-left, blue bottom-right, in a portrait frame.
    expect($info['mime'])->toBe('image/webp')
        ->and([$info[0], $info[1]])->toBe($expected['dimensions']);
    foreach (['top-left' => [0.15, 0.1], 'top-right' => [0.6, 0.1], 'bottom-left' => [0.15, 0.9], 'bottom-right' => [0.85, 0.9]] as $corner => [$fx, $fy]) {
        [$r, $g, $b] = heifPixel($webp, (int) ($info[0] * $fx), (int) ($info[1] * $fy));
        $want = $expected['colors'][$corner];
        expect([$r > 150, $g > 150, $b > 150])->toBe($want, $corner);
    }

    expect(Storage::disk($disk)->get('assets/Root/IMG_ROT.HEIC'))->toBe($this->rotated)
        ->and(hash('sha256', $this->rotated))->toBe($object->sha256)
        ->and(heifPendingFiles())->toBe([]);
})->with([
    'thumbnail300' => ['thumbnail', 300, ['dimensions' => [225, 300], 'colors' => [
        'top-left'    => [false, true, false], 'top-right' => [true, false, false],
        'bottom-left' => [true, true, false], 'bottom-right' => [false, false, true],
    ]]],
    'preview1920' => ['preview', 1920, ['dimensions' => [480, 640], 'colors' => [
        'top-left'    => [false, true, false], 'top-right' => [true, false, false],
        'bottom-left' => [true, true, false], 'bottom-right' => [false, false, true],
    ]]],
]);

it('decodes a HEIF still whose major brand is mif1', function () {
    $disk = Directory::getAssetDisk();
    $generic = preg_replace('/^(.{4}ftyp)heic/s', '$1mif1', $this->heic, 1);
    expect($generic)->not->toBe($this->heic);
    [$asset, $object] = heifTypedAsset('assets/Root/generic.heif', $generic, 'image/heif');

    request()->merge(['path' => 'assets/Root/generic.heif']);
    (new FileController)->thumbnail();

    $info = getimagesizefromstring(Storage::disk($disk)->get(DerivativeImages::thumbnail($object->sha256, 'heif')));
    expect([$info[0], $info[1], $info['mime']])->toBe([64, 48, 'image/webp']);
});

/** An asset in a folder whose original is a HEIC, as a link would share it. */
function heifShared(object $test): array
{
    [$asset, $object] = heifTypedAsset('assets/Root/shared-full.heic', $test->heic);
    $folder = Directory::create(['name' => 'Heif share '.uniqid(), 'parent_id' => null]);
    $asset->directories()->attach($folder->id);

    return [$asset, $object, $folder];
}

it('shows a shared HEIC inline as the bounded WebP on asset, container and preview-only links, and keeps the download the original', function () {
    [$asset, $object, $folder] = heifShared($this);
    $assetLink = Share::factory()->forAsset($asset->id)->create();
    $folderLink = Share::factory()->forDirectory($folder->id)->create();
    $previewOnly = Share::factory()->forAsset($asset->id)->create(['allow_download' => false]);
    $previewOnlyFolder = Share::factory()->forDirectory($folder->id)->create(['allow_download' => false]);

    $body = fn ($response) => $response->baseResponse instanceof BinaryFileResponse
        ? file_get_contents($response->baseResponse->getFile()->getPathname())
        : $response->streamedContent();

    foreach ([
        'asset link'              => route('dam.share.preview', $assetLink->urlToken()),
        'container member'        => route('dam.share.asset_preview', ['token' => $folderLink->urlToken(), 'assetId' => $asset->id]),
        'preview-only asset'      => route('dam.share.preview', $previewOnly->urlToken()),
        'preview-only container'  => route('dam.share.asset_preview', ['token' => $previewOnlyFolder->urlToken(), 'assetId' => $asset->id]),
        'inline download route'   => route('dam.share.download', ['token' => $assetLink->urlToken(), 'disposition' => 'inline']),
    ] as $name => $url) {
        $answer = $this->get($url)->assertOk();
        $info = getimagesizefromstring($body($answer));
        expect($answer->headers->get('Content-Type'))->toBe('image/webp', $name)
            ->and($info['mime'])->toBe('image/webp', $name)
            ->and([$info[0], $info[1]])->toBe([64, 48], $name);
    }
    Storage::disk(Directory::getAssetDisk())->assertExists(DerivativeImages::shareStill($object->sha256, 'preview-1920', 'heic'));

    // The download stays the original HEIC, counted as before; a preview-only link still refuses it.
    $download = $this->get(route('dam.share.download', $assetLink->urlToken()))->assertOk();
    expect($body($download))->toBe($this->heic)
        ->and($download->headers->get('Content-Disposition'))->toContain('attachment');
    $this->get(route('dam.share.download', $previewOnly->urlToken()))->assertForbidden();
    $this->get(route('dam.share.asset_download', ['token' => $previewOnlyFolder->urlToken(), 'assetId' => $asset->id]))->assertForbidden();
    expect(heifPendingFiles())->toBe([]);
});

it('keeps the existing share checks in front of the HEIC preview: a revoked link and an unreadable original answer as before', function () {
    [$asset, $object] = heifShared($this);
    $revoked = Share::factory()->forAsset($asset->id)->revoked()->create();
    $live = Share::factory()->forAsset($asset->id)->create();

    $this->get(route('dam.share.preview', $revoked->urlToken()))->assertStatus(410);

    Storage::disk((string) $object->disk)->delete((string) $object->path);
    $this->get(route('dam.share.preview', $live->urlToken()))->assertNotFound();
    Storage::disk(Directory::getAssetDisk())->assertMissing(DerivativeImages::shareStill($object->sha256, 'preview-1920', 'heic'));
});

it('serves the original HEIC inline when the decoder cannot make the copy, as a share did before', function () {
    $broken = "\0\0\0\x1cftypheic\0\0\0\0mif1heicmiaf".str_repeat("\x07", 64);
    [$asset, $object] = heifTypedAsset('assets/Root/broken-share.heic', $broken);
    $link = Share::factory()->forAsset($asset->id)->create();

    $answer = $this->get(route('dam.share.preview', $link->urlToken()))->assertOk();

    expect($answer->headers->get('Content-Type'))->toBe('image/heic');
    Storage::disk(Directory::getAssetDisk())->assertMissing(DerivativeImages::shareStill($object->sha256, 'preview-1920', 'heic'));
    expect(heifPendingFiles())->toBe([]);
});

it('decodes a genuine 10-bit HEVC Main 10 still (major brand heix) to bounded WebP and leaves the original bytes alone', function (string $action, int $size, array $dimensions) {
    $disk = Directory::getAssetDisk();
    $path = __DIR__.'/../Fixtures/heic/main10-320x240-heix.heic';
    // The fixture really is 10-bit heix: libvips loads it as 16-bit samples, which an 8-bit HEVC image never is.
    expect(substr($this->main10, 4, 8))->toBe('ftypheix')
        ->and((string) shell_exec('vipsheader '.escapeshellarg($path)))->toContain('ushort');
    [$asset, $object] = heifTypedAsset('assets/Root/MAIN10.HEIC', $this->main10);

    request()->merge(['path' => 'assets/Root/MAIN10.HEIC', 'size' => (string) $size]);
    (new FileController)->{$action}();

    $destination = $action === 'thumbnail'
        ? DerivativeImages::thumbnail($object->sha256, 'heic')
        : DerivativeImages::preview($object->sha256, $size, 'heic');
    $webp = Storage::disk($disk)->get($destination);
    $info = getimagesizefromstring($webp);
    expect($info['mime'])->toBe('image/webp')
        ->and([$info[0], $info[1]])->toBe($dimensions);

    // Red top-left, blue top-right, green bottom-left (the fixture's own quadrants).
    [$r, $g, $b] = heifPixel($webp, (int) ($info[0] * 0.2), (int) ($info[1] * 0.2));
    expect([$r > 150, $g > 150, $b > 150])->toBe([true, false, false]);
    [$r, $g, $b] = heifPixel($webp, (int) ($info[0] * 0.8), (int) ($info[1] * 0.2));
    expect([$r > 150, $g > 150, $b > 150])->toBe([false, false, true]);
    [$r, $g, $b] = heifPixel($webp, (int) ($info[0] * 0.2), (int) ($info[1] * 0.8));
    expect([$r > 150, $g > 150, $b > 150])->toBe([false, true, false]);

    expect(Storage::disk($disk)->get('assets/Root/MAIN10.HEIC'))->toBe($this->main10)
        ->and(hash('sha256', $this->main10))->toBe($object->sha256)
        ->and(heifPendingFiles())->toBe([]);
})->with([
    'thumbnail300' => ['thumbnail', 300, [300, 225]],
    'preview1920'  => ['preview', 1920, [320, 240]],
]);

it('serves a 10-bit heix stack member its own WebP copies while its download stays the original', function () {
    $cover = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'cover.png', 'path' => 'assets/Heix/cover.png']));
    $folder = Directory::create(['name' => 'Heix '.uniqid()]);
    $cover->directories()->attach($folder->id);
    $base = '/'.config('app.admin_url').'/dam/assets/'.$cover->id;
    $member = $this->postJson($base.'/files', [
        'file' => UploadedFile::fake()->createWithContent('MAIN10.HEIC', $this->main10), 'role' => 'sidecar', 'label' => 'MAIN10.HEIC',
    ])->assertCreated()->json();
    FileObject::query()->whereKey(AssetFile::query()->whereKey($member['id'])->value('file_object_id'))->update(['mime_type' => 'image/heic']);

    foreach (['thumb', 'preview'] as $query) {
        $answer = $this->get(route('admin.dam.file.asset-file', ['asset' => $cover->id, 'assetFile' => $member['id'], $query => 1]))->assertOk();
        expect($answer->headers->get('Content-Type'))->toBe('image/webp');
    }

    $download = $this->get(route('admin.dam.file.asset-file', ['asset' => $cover->id, 'assetFile' => $member['id'], 'download' => 1]))->assertOk();
    $bytes = $download->baseResponse instanceof BinaryFileResponse ? file_get_contents($download->baseResponse->getFile()->getPathname()) : $download->streamedContent();
    expect($bytes)->toBe($this->main10);
});

it('describes an ordinary or heix HEIC to the details view as an image served through the WebP preview route, with the original untouched', function (string $name, string $bytes) {
    [$asset, $object] = heifTypedAsset('assets/Root/'.$name, $this->{$bytes});
    $asset = $asset->fresh();

    $source = $asset->playbackSource();
    $expectedUrl = route('admin.dam.file.preview', ['path' => urlencode($asset->path), 'size' => 1920]);
    expect($source)->toMatchArray(['url' => $expectedUrl, 'mime' => 'image/webp', 'kind' => 'image', 'original_url' => null, 'is_proxy' => false])
        // The original itself is still not something a browser can play.
        ->and($asset->playbackFile())->toBeNull()
        ->and($asset->originalBrowserPlaybackType())->toBeNull();

    // What the details panel is told (the contract that picks the zoomable image): kind image, a bounded browser URL.
    $payload = $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->json();
    expect($payload['playbackSource']['kind'])->toBe('image')
        ->and($payload['playbackSource']['url'])->toBe($expectedUrl)
        ->and($payload['previewPath'])->toBe(route('admin.dam.file.preview', ['path' => urlencode($asset->path), 'size' => 1356]));

    // Both URLs really answer with a WebP, and the original download is still the HEIC bytes.
    foreach ([$payload['playbackSource']['url'], $payload['previewPath']] as $url) {
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    }
    $download = $this->get(route('admin.dam.assets.download', $asset->id))->assertOk();
    $body = $download->baseResponse instanceof BinaryFileResponse ? file_get_contents($download->baseResponse->getFile()->getPathname()) : $download->streamedContent();
    expect($body)->toBe($this->{$bytes})
        ->and(hash('sha256', $body))->toBe($object->sha256);
})->with([
    'ordinary HEIC' => ['IMG_1.HEIC', 'heic'],
    'heix HEIC'     => ['IMG_2.HEIC', 'main10'],
]);

it('leaves an unreadable HEIC original and other raster types on their existing playback description', function () {
    [$asset, $object] = heifTypedAsset('assets/Root/gone.heic', $this->heic);
    Storage::disk((string) $object->disk)->delete((string) $object->path);

    expect($asset->fresh()->playbackSource())->toBeNull();
});

it('never gives a HEIC the direct S3 shortcut, because the browser needs the derived WebP', function (string $mime, string $extension) {
    config(['filesystems.default' => 's3']);
    Storage::shouldReceive('disk')->never();

    $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'a.'.$extension, 'extension' => $extension, 'mime_type' => $mime, 'path' => 'assets/Root/s3.'.$extension,
    ]));
    $sha256 = hash('sha256', 'remote-'.$extension);
    $object = FileObject::factory()->create([
        'sha256' => $sha256, 'disk' => Directory::ASSETS_DISK_AWS, 'path' => app(ContentStore::class)->objectPath($sha256, $extension),
        'size'   => 12, 'mime_type' => $mime,
    ]);
    AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL]);

    expect(AssetHelper::getPreviewUrl($asset->path, 1356))
        ->toBe(route('admin.dam.file.preview', ['path' => urlencode($asset->path), 'size' => 1356]));
})->with([
    'heic' => ['image/heic', 'heic'],
    'heif' => ['image/heif', 'heif'],
]);

it('shows a shared HEIC in the zoomable image viewer fed by the WebP preview link', function () {
    [$asset] = heifShared($this);
    $link = Share::factory()->forAsset($asset->id)->create();

    $page = $this->get(route('dam.share.show', $link->urlToken()))->assertOk()->getContent();

    expect($page)->toContain('<v-zoomable-image')
        ->and($page)->toContain(route('dam.share.preview', ['token' => $link->urlToken()]));
});

it('keeps an intact stitched 360 proxy ahead of the HEIC WebP copy, and falls back to the WebP once the proxy is gone', function (string $mime, string $extension, string $kind, ?array $technical) {
    [$asset] = heifTypedAsset('assets/Root/sphere.heic', $this->heic);
    $proxy = attachPreviewFile($asset, 'assets/Root/sphere-stitched.'.$extension, $mime, AssetFile::ROLE_PROXY, 'Stitched 360 Preview', 10, $technical);
    $asset = $asset->fresh();

    $source = $asset->playbackSource();
    expect($source)->toMatchArray(['mime' => $mime, 'kind' => $kind, 'is_proxy' => false, 'original_url' => null])
        ->and($source['url'])->toBe(route('admin.dam.file.asset-file', ['asset' => $asset->id, 'assetFile' => $proxy->id]))
        ->and($source['url'])->not->toContain('size=');

    // Without the proxy the HEIC is described through its WebP copy again.
    $proxy->delete();
    expect($asset->fresh()->playbackSource())->toMatchArray(['kind' => 'image', 'mime' => 'image/webp']);
})->with([
    'stitched image' => ['image/jpeg', 'jpg', 'pano360-image', null],
    'stitched video' => ['video/mp4', 'mp4', 'pano360-video', ['codec_name' => 'h264']],
]);
