<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\DerivativeImages;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

function registerFileTestAsset(string $path, array $attributes = []): Asset
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $asset = Asset::create(array_merge([
        'file_name' => basename($path),
        'file_type' => in_array($extension, ['jpg', 'jpeg', 'png'], true) ? 'image' : 'document',
        'file_size' => 1,
        'mime_type' => match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'pdf'         => 'application/pdf',
            default       => 'application/octet-stream',
        },
        'extension' => $extension,
        'path'      => $path,
    ], $attributes));

    $root = Directory::whereNull('parent_id')->firstOrFail();
    $asset->directories()->attach($root->id);

    return $asset;
}

it('should fetch an existing file with the correct mime type', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/sample.png';
    Storage::disk($disk)->put($path, 'binary-data');
    registerFileTestAsset($path);

    $response = $this->get(route('admin.dam.file.fetch', ['path' => $path]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/');
});

it('should return 404 when fetching a non-existent file', function () {
    $response = $this->getJson(route('admin.dam.file.fetch', ['path' => 'assets/none.png']));

    $response->assertNotFound();
});

it('should return 404 when fetching a stored path that no asset owns', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/unowned.png';
    Storage::disk($disk)->put($path, 'binary-data');

    $this->getJson(route('admin.dam.file.fetch', ['path' => $path]))
        ->assertNotFound();
});

it('should serve a default thumbnail when the file is not an image', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/document.pdf';
    Storage::disk($disk)->put($path, 'pdf-content');
    registerFileTestAsset($path);

    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/file.svg', '<svg/>');

    $response = $this->call(
        'GET',
        route('admin.dam.file.thumbnail'),
        ['path' => $path],
        [],
        [],
        ['HTTP_ACCEPT' => 'image/*']
    );

    $response->assertOk();
});

it('gives the details-page PDF card a server-built thumbnail URL that serves a path containing + or %HH', function (string $name) {
    // `+` and `%HH` survive only the server's urlencode()-inside-route() form:
    // FileController::thumbnail() urldecodes a path the request already decoded.
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/'.$name;
    Storage::disk($disk)->put($path, 'pdf-content');
    Storage::disk($disk)->put('thumbnails/pdf/draft-poster.jpg', 'poster-bytes');
    $asset = registerFileTestAsset($path, ['meta_data' => ['thumbnail_path' => 'thumbnails/pdf/draft-poster.jpg']]);

    $url = AssetHelper::pdfThumbnailUrl($asset);
    // @json escapes slashes and hex-encodes `&`; match the form the page carries.
    $baked = json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->assertSee('"pdfThumbnailUrl":'.$baked, false);
    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->assertJsonPath('pdfThumbnailUrl', $url);

    $response = $this->get($url, ['Accept' => 'image/*'])->assertOk();
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('poster-bytes');
})->with(['50+draft.pdf', '50%20draft.pdf']);

it('serves the 3D model placeholder for an STL without a capture, even when placeholders were never published', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/bracket.stl';
    Storage::disk($disk)->put($path, 'stl-bytes');
    registerFileTestAsset($path);

    // Production deploys never run vendor:publish, so the public disk may hold no dam/grid/*.svg at all.
    Storage::fake('public');

    $response = $this->call('GET', route('admin.dam.file.thumbnail'), ['path' => $path], [], [], ['HTTP_ACCEPT' => 'image/*']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml');
    expect($response->getContent())->toBe(file_get_contents(__DIR__.'/../../src/Resources/assets/images/grid/model.svg'));
});

it('serves the application mark for an Adobe file that has no picture of its own, and draws an .ai from its PDF bytes', function () {
    $disk = Directory::getAssetDisk();
    Storage::disk($disk)->put('assets/Root/Layout.indd', 'indd-bytes');
    registerFileTestAsset('assets/Root/Layout.indd');
    Storage::fake('public');

    $tile = $this->call('GET', route('admin.dam.file.thumbnail'), ['path' => 'assets/Root/Layout.indd'], [], [], ['HTTP_ACCEPT' => 'image/*']);

    $tile->assertOk();
    expect($tile->headers->get('Content-Type'))->toContain('image/svg+xml')
        ->and($tile->getContent())->toContain('Adobe InDesign file');

    if (trim((string) shell_exec('command -v pdftoppm')) === '') {
        return;
    }

    $page = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 120 80]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n";
    Storage::disk($disk)->put('assets/Root/Banner.ai', $page);
    registerFileTestAsset('assets/Root/Banner.ai', ['mime_type' => 'application/pdf']);

    $art = $this->call('GET', route('admin.dam.file.thumbnail'), ['path' => 'assets/Root/Banner.ai'], [], [], ['HTTP_ACCEPT' => 'image/*']);

    // The PDF renderer's first page, not the generic document glyph.
    expect($art->assertOk()->headers->get('Content-Type'))->toContain('image/jpeg');
});

it('should serve a placeholder thumbnail when an image file is undecodable instead of 500', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/corrupt.png';

    Storage::disk($disk)->put($path, "\x89PNG\r\n\x1a\n".str_repeat('x', 128));
    registerFileTestAsset($path);

    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/image.svg', '<svg/>');

    $response = $this->call(
        'GET',
        route('admin.dam.file.thumbnail'),
        ['path' => $path],
        [],
        [],
        ['HTTP_ACCEPT' => 'image/*']
    );

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml');
});

it('should serve a placeholder preview when an image file is undecodable instead of 500', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/corrupt-preview.png';

    Storage::disk($disk)->put($path, "\x89PNG\r\n\x1a\n".str_repeat('x', 128));
    registerFileTestAsset($path);

    Storage::fake('public');
    Storage::disk('public')->put('dam/preview/image.svg', '<svg/>');

    $response = $this->call(
        'GET',
        route('admin.dam.file.preview'),
        ['path' => $path, 'size' => 300],
        [],
        [],
        ['HTTP_ACCEPT' => 'image/*']
    );

    $response->assertOk();
});

it('serves a sized GIF preview as stored, so an animation keeps its frames and a large one never 500s', function () {
    $path = 'assets/Root/pulse.gif';
    // A valid 1x1 GIF: resizing would re-encode it to a single frame.
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    Storage::disk(Directory::getAssetDisk())->put($path, $gif);
    registerFileTestAsset($path, ['file_type' => 'image', 'mime_type' => 'image/gif']);

    $response = $this->get(route('admin.dam.file.preview', ['path' => $path, 'size' => 1356]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/gif')
        ->and($response->streamedContent() ?: $response->getContent())->toBe($gif);
});

it('refuses an anonymous fetch of an existing, owned file before sending any of it', function () {
    // admin.dam.file.fetch drops the admin middleware and authenticates in the controller, so the route inventory
    // cannot see its guard: this is the probe that does.
    $path = 'assets/Root/anonymous-probe.png';
    Storage::disk(Directory::getAssetDisk())->put($path, 'secret-bytes');
    registerFileTestAsset($path);
    $this->get(route('admin.dam.file.fetch', ['path' => $path]))->assertOk();

    auth()->guard('admin')->logout();
    $anonymous = $this->get(route('admin.dam.file.fetch', ['path' => $path]));

    expect($anonymous->getStatusCode())->toBe(403)
        ->and($anonymous->getContent())->not->toContain('secret-bytes')
        ->and($anonymous->headers->get('X-Accel-Redirect'))->toBeNull();
});

it('should redirect thumbnail requests from unauthenticated users', function () {
    auth()->guard('admin')->logout();

    $response = $this->call('GET', route('admin.dam.file.thumbnail'), ['path' => 'assets/Root/x.png']);

    expect($response->getStatusCode())->toBe(302);
});

it('should redirect preview requests from unauthenticated users', function () {
    auth()->guard('admin')->logout();

    $response = $this->call('GET', route('admin.dam.file.preview'), ['path' => 'assets/Root/x.png']);

    expect($response->getStatusCode())->toBe(302);
});

it('should answer 404, not a placeholder, when previewing an asset whose file does not exist', function () {
    // No bytes were ever ingested, so the asset has no original: a preview is a 404 (ADR 0009 slice 2),
    // recorded to content-store.jsonl, never a placeholder that looks like success.
    registerFileTestAsset('assets/Root/missing.pdf');

    Storage::fake('public');
    Storage::disk('public')->put('dam/preview/file.svg', '<svg/>');

    $response = $this->call('GET', route('admin.dam.file.preview'), ['path' => 'assets/Root/missing.pdf']);

    $response->assertNotFound();
});

/** A tiny real PNG, so the grid thumbnail is generated rather than replaced by a placeholder. */
function versionedThumbnailPng(): string
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function versionedThumbnailAsset(string $path = 'assets/Root/versioned.png'): array
{
    $asset = registerServedAssetPath($path);
    putAssetContent($asset, versionedThumbnailPng());
    $sha = app(ContentStore::class)->current($asset->fresh())->sha256;

    return [$asset, $sha];
}

function thumbnailGet(string $path, ?string $version = null, array $headers = []): TestResponse
{
    $query = array_filter(['path' => urlencode($path), 'v' => $version]);

    return test()->call('GET', route('admin.dam.file.thumbnail'), $query, [], [], array_merge(['HTTP_ACCEPT' => 'image/*'], $headers));
}

it('serves a raster thumbnail immutably, privately, only under the version its URL names', function () {
    [$asset, $sha] = versionedThumbnailAsset();

    $unversioned = thumbnailGet($asset->path);
    $stale = thumbnailGet($asset->path, 'not-this-content');
    $versioned = thumbnailGet($asset->path, $sha);

    $unversioned->assertOk();
    $versioned->assertOk();

    $cache = $versioned->headers->get('Cache-Control');
    expect($cache)->toContain('private')->toContain('max-age=31536000')->toContain('immutable')->not->toContain('public')
        ->and($versioned->headers->get('ETag'))->not->toBeNull();

    // A URL whose `v` does not name these bytes is never called immutable.
    foreach ([$unversioned, $stale] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-cache')->not->toContain('immutable')->not->toContain('max-age=31536000');
    }
});

it('answers a versioned thumbnail revalidation 304 without a body, even before the derivative exists', function () {
    [$asset, $sha] = versionedThumbnailAsset('assets/Root/revalidate.png');

    $first = thumbnailGet($asset->path, $sha)->assertOk();
    $etag = $first->headers->get('ETag');

    $again = thumbnailGet($asset->path, $sha, ['HTTP_IF_NONE_MATCH' => $etag]);

    expect($again->getStatusCode())->toBe(304)
        ->and($again->getContent())->toBe('')
        ->and($again->headers->get('Cache-Control'))->toContain('immutable');

    // The derivative being purged does not turn a client that holds the bytes into a decode: still 304.
    Storage::disk(Directory::getAssetDisk())->deleteDirectory(DerivativeImages::directory($sha));
    expect(thumbnailGet($asset->path, $sha, ['HTTP_IF_NONE_MATCH' => $etag])->getStatusCode())->toBe(304);

    // A validator for another version never matches.
    expect(thumbnailGet($asset->path, $sha, ['HTTP_IF_NONE_MATCH' => '"other"'])->getStatusCode())->toBe(200);
});

it('keeps the access check on the versioned and conditional thumbnail paths', function () {
    [$asset, $sha] = versionedThumbnailAsset('assets/Root/guarded.png');
    $etag = thumbnailGet($asset->path, $sha)->assertOk()->headers->get('ETag');

    // A member of a folder that does not hold the asset has the same URL, the same version and the same validator.
    $elsewhere = Directory::create(['name' => 'Elsewhere', 'parent_id' => Directory::whereNull('parent_id')->firstOrFail()->id]);
    actAsPlaybackRestrictedAdmin($elsewhere, ['dam', 'dam.asset', 'dam.asset.view']);

    $denied = thumbnailGet($asset->path, $sha, ['HTTP_IF_NONE_MATCH' => $etag]);
    $missing = thumbnailGet('assets/Root/nothing-here.png', $sha, ['HTTP_IF_NONE_MATCH' => $etag]);

    expect($denied->getStatusCode())->toBe(404)
        ->and($denied->getStatusCode())->toBe($missing->getStatusCode())
        ->and($denied->headers->get('Cache-Control'))->not->toContain('immutable');

    auth()->guard('admin')->logout();
    expect(thumbnailGet($asset->path, $sha, ['HTTP_IF_NONE_MATCH' => $etag])->getStatusCode())->toBe(302);
});

it('never calls a placeholder immutable, even for a request that carries a version', function () {
    $path = 'assets/Root/also-corrupt.png';
    Storage::disk(Directory::getAssetDisk())->put($path, "\x89PNG\r\n\x1a\n".str_repeat('x', 128));
    $asset = registerFileTestAsset($path);
    $sha = app(ContentStore::class)->current($asset->fresh())->sha256;

    Storage::fake('public');
    Storage::disk('public')->put('dam/grid/image.svg', '<svg/>');

    $response = thumbnailGet($path, $sha)->assertOk();

    expect($response->headers->get('Cache-Control'))->not->toContain('immutable');
});

it('gives the Library card a thumbnail URL versioned by the original sha', function () {
    [$asset, $sha] = versionedThumbnailAsset('assets/Root/card.png');
    $asset->directories()->attach(Directory::whereNull('parent_id')->firstOrFail()->id);

    $card = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'per_page' => 100]))->assertOk()->json('assets'))->firstWhere('id', $asset->id);

    expect($card)->not->toBeNull();
    parse_str((string) parse_url($card['path'], PHP_URL_QUERY), $query);
    expect($query['v'] ?? null)->toBe($sha);
});
