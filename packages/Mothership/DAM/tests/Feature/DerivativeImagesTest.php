<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\DerivativeImages;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function derivativeFixture(string $format, array $rgb = [20, 80, 140]): string
{
    $image = imagecreatetruecolor(640, 480);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));

    ob_start();
    match ($format) {
        'gif'   => imagegif($image),
        'webp'  => imagewebp($image),
        'jpg'   => imagejpeg($image),
        default => imagepng($image),
    };

    return ob_get_clean();
}

/** Every mirrored derivative shape an image asset can have left on disk. */
function seedMirroredDerivatives(string $path): array
{
    $derivatives = [
        'thumbnails/'.$path,
        'thumbnails/'.$path.'.webp',
        'thumbnails/'.$path.'.jpg',
        'preview/300/'.$path,
        'preview/1356/'.$path.'.webp',
    ];

    foreach ($derivatives as $derivative) {
        Storage::disk(Directory::getAssetDisk())->put($derivative, 'derivative');
    }

    return $derivatives;
}

/** An asset whose bytes are already at its Library path, ingested as its original; returns [asset, sha256]. */
function derivativeAsset(string $path, string $bytes): array
{
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);
    $asset = registerServedAssetPath($path);

    return [$asset, app(ContentStore::class)->current($asset)->sha256];
}

it('leaves path-keyed mirrored thumbnails and previews in place when an asset is deleted', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $asset = registerServedAssetPath('AbCdEfGhIj/files/deleted.png');
    $kept = registerServedAssetPath('KlMnOpQrSt/files/kept.png');
    $storage->put($asset->path, derivativeFixture('png'));
    $derivatives = seedMirroredDerivatives($asset->path);
    $keptDerivatives = seedMirroredDerivatives($kept->path);

    app(AssetDeletionService::class)->delete($asset, force: true);

    $storage->assertMissing($asset->path);
    // Path-keyed mirrors are no longer purged on deletion (ADR 0009 slice 2, AssetDeletionService);
    // they are read by nothing and reclaimed only in slice 4.
    foreach ($derivatives as $derivative) {
        $storage->assertExists($derivative);
    }
    foreach ($keptDerivatives as $derivative) {
        $storage->assertExists($derivative);
    }
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

it('purges a blob\'s sha-keyed derivatives once deleting the asset reclaims it', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    [$asset, $sha] = derivativeAsset('assets/Root/gone.png', derivativeFixture('png'));
    $thumbnail = DerivativeImages::thumbnail($sha, 'png');
    $storage->put($thumbnail, 'thumb');

    app(AssetDeletionService::class)->delete($asset, force: true);

    $storage->assertMissing($asset->path);
    $storage->assertMissing($thumbnail);
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

it('keeps a shared blob\'s sha-keyed derivatives when only one of its assets is deleted', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $bytes = derivativeFixture('png');
    [$first, $sha] = derivativeAsset('assets/Root/shared-a.png', $bytes);
    [, $copySha] = derivativeAsset('assets/Root/shared-b.png', $bytes);
    $thumbnail = DerivativeImages::thumbnail($sha, 'png');
    $storage->put($thumbnail, 'thumb');

    app(AssetDeletionService::class)->delete($first, force: true);

    expect($copySha)->toBe($sha);
    $storage->assertExists($thumbnail);
});

/*
 * clearAssetCache() (AssetController) must never purge sha-keyed derivatives itself: reclaim stays
 * with AssetDeletionService's reference-counted blob deletion, which already calls purgeContent() only
 * once a blob is actually unlinked (the test above, via the service directly). These three go through
 * the HTTP routes that call clearAssetCache(), so a regression there is caught even though the service
 * call itself is correct.
 */

it('keeps a shared blob\'s sha-keyed derivatives when the HTTP delete route removes only one asset', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $bytes = derivativeFixture('png');
    [$first, $sha] = derivativeAsset('assets/Root/http-shared-a.png', $bytes);
    [, $copySha] = derivativeAsset('assets/Root/http-shared-b.png', $bytes);
    $thumbnail = DerivativeImages::thumbnail($sha, 'png');
    $storage->put($thumbnail, 'thumb');

    $this->delete(route('admin.dam.assets.destroy', $first->id))->assertOk();

    expect($copySha)->toBe($sha);
    $storage->assertExists($thumbnail);
});

it('keeps a shared blob\'s sha-keyed derivatives when the HTTP bulk-delete route removes only one asset', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $bytes = derivativeFixture('png');
    [$first, $sha] = derivativeAsset('assets/Root/bulk-shared-a.png', $bytes);
    [, $copySha] = derivativeAsset('assets/Root/bulk-shared-b.png', $bytes);
    $thumbnail = DerivativeImages::thumbnail($sha, 'png');
    $storage->put($thumbnail, 'thumb');

    $this->post(route('admin.dam.assets.mass_delete'), ['indices' => [$first->id]])->assertOk();

    expect($copySha)->toBe($sha);
    $storage->assertExists($thumbnail);
});

it('keeps a shared blob\'s sha-keyed derivatives when re-uploading a different asset that shared it', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $bytes = derivativeFixture('png');
    $directory = Directory::factory()->create(['name' => 'Root']);
    [$first, $sha] = derivativeAsset('assets/Root/reupload-shared-a.png', $bytes);
    [, $copySha] = derivativeAsset('assets/Root/reupload-shared-b.png', $bytes);
    $first->directories()->attach($directory->id);
    $thumbnail = DerivativeImages::thumbnail($sha, 'png');
    $storage->put($thumbnail, 'thumb');

    $newFile = UploadedFile::fake()->image('replacement.png', 20, 20);
    $this->postJson(route('admin.dam.assets.re_upload'), [
        'file'     => $newFile,
        'asset_id' => $first->id,
    ])->assertStatus(201);

    // Re-upload produces a new sha for $first; the OLD shared sha's derivatives (still serving the
    // untouched copy) must survive — re-upload needs no purge of its own, the new sha is a fresh key.
    expect($copySha)->toBe($sha);
    $storage->assertExists($thumbnail);
});

it('keys derivatives by content under derivatives/<sha[0:2]>/<sha>/<recipe>.<ext>', function () {
    $sha = hash('sha256', 'layout');
    $base = 'derivatives/'.substr($sha, 0, 2).'/'.$sha.'/';

    expect(DerivativeImages::thumbnail($sha, 'PNG'))->toBe($base.'thumbnail.webp')
        ->and(DerivativeImages::thumbnail($sha, 'gif'))->toBe($base.'thumbnail.gif')
        ->and(DerivativeImages::preview($sha, 300, 'avif'))->toBe($base.'preview-300.avif')
        ->and(DerivativeImages::preview($sha, 300, 'jpg'))->toBe($base.'preview-300.webp')
        ->and(DerivativeImages::poster($sha))->toBe($base.'poster.jpg')
        ->and(DerivativeImages::contentType('webp'))->toBe('image/webp')
        ->and(DerivativeImages::contentType('tiff'))->toBe('image/webp');
});

it('generates WebP thumbnails and previews at the sha-keyed path', function (string $action, string $format = 'png') {
    $storage = Storage::disk(Directory::getAssetDisk());
    $path = 'assets/Root/photo.'.$format;
    [, $sha] = derivativeAsset($path, derivativeFixture($format));
    $destination = $action === 'thumbnail'
        ? DerivativeImages::thumbnail($sha, $format)
        : DerivativeImages::preview($sha, 300, $format);

    request()->merge(['path' => $path, 'size' => '300']);
    $first = (new FileController)->{$action}();
    // The cached hit is a BinaryFileResponse, which sniffs its type in prepare() as the kernel does.
    $cached = (new FileController)->{$action}()->prepare(request());

    expect($destination)->toEndWith('.webp');
    $storage->assertExists($destination);
    expect($storage->allFiles('thumbnails'))->toBe([])
        ->and($storage->allFiles('preview'))->toBe([])
        ->and(getimagesizefromstring($storage->get($destination))['mime'])->toBe('image/webp')
        ->and($first->headers->get('Content-Type'))->toBe('image/webp')
        ->and($cached->headers->get('Content-Type'))->toBe('image/webp');
})->with([
    'thumbnail'      => ['thumbnail'],
    'preview'        => ['preview'],
    'JPEG thumbnail' => ['thumbnail', 'jpg'],
]);

it('keeps GIF and already-WebP sources in their own format', function (string $format, string $mime) {
    $storage = Storage::disk(Directory::getAssetDisk());
    $path = 'assets/Root/kept.'.$format;
    [, $sha] = derivativeAsset($path, derivativeFixture($format));

    request()->merge(['path' => $path]);
    $response = (new FileController)->thumbnail();

    $destination = DerivativeImages::thumbnail($sha, $format);
    expect($destination)->toEndWith('/thumbnail.'.$format);
    $storage->assertExists($destination);
    expect(getimagesizefromstring($storage->get($destination))['mime'])->toBe($mime)
        ->and($response->headers->get('Content-Type'))->toBe($mime);
})->with([
    'gif'  => ['gif', 'image/gif'],
    'webp' => ['webp', 'image/webp'],
]);

it('shares one derivative between assets that share a blob', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $bytes = derivativeFixture('png');
    [, $sha] = derivativeAsset('assets/Root/first.png', $bytes);
    [, $copySha] = derivativeAsset('assets/Copies/first copy.png', $bytes);

    request()->merge(['path' => 'assets/Root/first.png']);
    (new FileController)->thumbnail();
    request()->merge(['path' => 'assets/Copies/first copy.png']);
    $copy = (new FileController)->thumbnail();

    expect($copySha)->toBe($sha)
        ->and($storage->allFiles(DerivativeImages::ROOT))->toBe([DerivativeImages::thumbnail($sha, 'png')])
        ->and($copy->getFile()->getPathname())->toEndWith(DerivativeImages::thumbnail($sha, 'png'));
});

it('never serves a moved asset\'s thumbnail to a new asset that reuses its old Library path', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $path = 'assets/Root/reused.png';
    [$moved, $movedSha] = derivativeAsset($path, derivativeFixture('png', [200, 0, 0]));

    request()->merge(['path' => $path]);
    (new FileController)->thumbnail();
    $storage->assertExists(DerivativeImages::thumbnail($movedSha, 'png'));
    // What the path-keyed cache held for the old asset: never read again.
    $storage->put('thumbnails/'.$path.'.webp', $storage->get(DerivativeImages::thumbnail($movedSha, 'png')));

    $moved->update(['path' => 'assets/Elsewhere/reused.png']);
    [, $newSha] = derivativeAsset($path, derivativeFixture('png', [0, 0, 200]));

    request()->merge(['path' => $path]);
    $response = (new FileController)->thumbnail();

    $decoded = imagecreatefromstring($response->getContent());
    $blue = imagecolorat($decoded, 10, 10) & 0xFF;
    expect($newSha)->not->toBe($movedSha)
        ->and($response->getContent())->toBe($storage->get(DerivativeImages::thumbnail($newSha, 'png')))
        ->and($blue)->toBeGreaterThan(150);
});

it('hands public share downloads to the web server when an accel prefix is configured', function () {
    config(['mothership.media_accel_prefix' => '/__dam_media/']);
    DB::table('dam_shares')->delete();
    $asset = Asset::factory()->create(['path' => 'assets/Shared Folder/big file.png', 'file_name' => 'big file.png']);
    // Ingested as the asset's current original, as an upload's would be (ADR 0009): reads go through it.
    putAssetContent($asset, derivativeFixture('png'));
    $share = Share::factory()->forAsset($asset->id)->create();

    // deliver() builds X-Accel-Redirect from the FileObject's own content-addressed locator (ADR
    // 0009), never the Library path, so nginx's /__dam_media/ alias resolves the blob directly.
    $objectPath = app(ContentStore::class)->current($asset)->path;
    $encodedObjectPath = implode('/', array_map('rawurlencode', explode('/', $objectPath)));

    $response = $this->get(route('dam.share.download', $share->urlToken()))->assertOk();

    expect($response->headers->get('X-Accel-Redirect'))->toBe('/__dam_media/'.$encodedObjectPath)
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="big file.png"')
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('X-Accel-Buffering'))->toBeNull()
        ->and($response->getContent())->toBe('')
        ->and($share->fresh()->download_count)->toBe(1);

    $expired = Share::factory()->forAsset($asset->id)->create(['expires_at' => now()->subDay()]);
    $refused = $this->get(route('dam.share.download', $expired->urlToken()));

    expect($refused->headers->get('X-Accel-Redirect'))->toBeNull();
});
