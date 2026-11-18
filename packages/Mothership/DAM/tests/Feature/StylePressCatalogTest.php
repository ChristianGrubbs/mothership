<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\StylePressCatalog;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    config(['stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);
    $this->stylePressRoot = sys_get_temp_dir().'/mothership-stylepress-'.Str::uuid();
    mkdir($this->stylePressRoot.'/stylepress', 0777, true);
    file_put_contents($this->stylePressRoot.'/stylepress/appended-style.jpg', 'thumbnail-fixture');
    $this->stylePressPath = $this->stylePressRoot.'/stylepress-catalog.json';
    file_put_contents($this->stylePressPath, json_encode([
        'version'    => 'fixture-v2',
        'count'      => 2,
        'categories' => ['Editorial', 'Product'],
        'presets'    => [
            ['slug'=>'original-style', 'title'=>'Original Style', 'categoryLabel'=>'Editorial', 'aspectRatio'=>'1:1', 'look'=>'Paper texture', 'template'=>'ORIGINAL TEMPLATE', 'slots'=>'', 'image'=>'stylepress/original.jpg'],
            ['slug'=> 'appended-style', 'title'=>'Appended Style', 'categoryLabel'=>'Product', 'aspectRatio'=>'4:5', 'look'=>'Quiet studio light', 'template'=>'APPENDED TEMPLATE', 'slots'=>'SLOT CONTRACT', 'image'=>'stylepress/appended-style.jpg'],
        ],
    ], JSON_THROW_ON_ERROR));
    config(['stylepress.catalog_path' => $this->stylePressPath]);
});

afterEach(function () {
    @unlink($this->stylePressRoot.'/stylepress/appended-style.jpg');
    @rmdir($this->stylePressRoot.'/stylepress');
    @unlink($this->stylePressPath);
    @rmdir($this->stylePressRoot);
});

it('serves appended catalog styles and the compiler output on Home', function () {
    $catalog = app(StylePressCatalog::class);
    $style = $catalog->find('appended-style');
    $compiled = $catalog->compile($style, 'Keep the product centered.', 'restyle');

    expect($catalog->data()['count'])->toBe(2)
        ->and($style['title'])->toBe('Appended Style')
        ->and($compiled)->toContain('STYLEPRESS HOUSE STYLE — LOCKED PRODUCTION INSTRUCTION')
        ->and($compiled)->toContain('APPENDED TEMPLATE')
        ->and($compiled)->toContain('SLOT CONTRACT')
        ->and($compiled)->toContain('RESTYLE MODE: preserve the source image subject, identity, product geometry, composition, readable text, logos, and spatial relationships.')
        ->and($compiled)->toContain("USER REQUEST:\n\nKeep the product centered.");

    $home = $this->getJson('/admin/home/stylepress/catalog')->assertOk()->json();

    expect(collect($home['presets'])->pluck('slug')->all())->toContain('appended-style');

    $updated = $catalog->data();
    $updated['version'] = 'fixture-v3';
    $updated['count'] = 3;
    $updated['presets'][] = ['slug'=>'future-style', 'title'=>'Future Style', 'categoryLabel'=>'Editorial', 'aspectRatio'=>'1:1', 'look'=>'New catalog entry', 'template'=>'FUTURE TEMPLATE', 'slots'=>'', 'image'=>'stylepress/future.jpg'];
    file_put_contents($this->stylePressPath, json_encode($updated, JSON_THROW_ON_ERROR));

    expect($catalog->find('future-style')['title'])->toBe('Future Style');
    $this->getJson('/admin/home/stylepress/catalog')->assertOk()->assertJsonPath('version', 'fixture-v3')->assertJsonCount(3, 'presets');
});

it('parses the StylePress catalog file only once across repeated data() calls, in one instance and across instances', function () {
    $root = sys_get_temp_dir().'/mothership-stylepress-memo-'.Str::uuid();
    mkdir($root, 0777, true);
    $path = $root.'/catalog.json';
    file_put_contents($path, json_encode([
        'version' => 'memo-v1', 'count' => 1, 'categories' => [],
        'presets' => [['slug' => 'memo-style', 'title' => 'Memo Style', 'image' => null]],
    ], JSON_THROW_ON_ERROR));

    // Exactly one real read for three data() calls: two on the same instance (the instance
    // memo) and one on a fresh instance with the same file fingerprint (the Cache::remember).
    File::partialMock()->shouldReceive('get')->once()->passthru();

    $catalog = new StylePressCatalog($path);
    $first = $catalog->data();
    $second = $catalog->data();
    $third = (new StylePressCatalog($path))->data();

    expect($first['version'])->toBe('memo-v1')
        ->and($second)->toBe($first)
        ->and($third['version'])->toBe('memo-v1');

    @unlink($path);
    @rmdir($root);
});

it('re-reads the StylePress catalog file after a re-import changes its mtime and size', function () {
    $root = sys_get_temp_dir().'/mothership-stylepress-memo-'.Str::uuid();
    mkdir($root, 0777, true);
    $path = $root.'/catalog.json';
    file_put_contents($path, json_encode([
        'version' => 'memo-v1', 'count' => 1, 'categories' => [],
        'presets' => [['slug' => 'memo-style', 'title' => 'Memo Style', 'image' => null]],
    ], JSON_THROW_ON_ERROR));

    File::partialMock()->shouldReceive('get')->twice()->passthru();

    $before = (new StylePressCatalog($path))->data();

    file_put_contents($path, json_encode([
        'version' => 'memo-v2', 'count' => 2, 'categories' => [],
        'presets' => [
            ['slug' => 'memo-style', 'title' => 'Memo Style', 'image' => null],
            ['slug' => 'memo-style-two', 'title' => 'Memo Style Two', 'image' => null],
        ],
    ], JSON_THROW_ON_ERROR));
    touch($path, time() + 5);
    clearstatcache(true, $path);

    $after = (new StylePressCatalog($path))->data();

    expect($before['version'])->toBe('memo-v1')->and($after['version'])->toBe('memo-v2');

    @unlink($path);
    @rmdir($root);
});

it('serves StylePress thumbnails with a long-lived immutable private cache header, an ETag, and 304 on a matching If-None-Match', function () {
    $response = $this->get('/admin/home/stylepress/thumbnail/appended-style?v=fixture-v2')->assertOk();
    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private')->toContain('max-age=31536000')->toContain('immutable');
    $etag = $response->headers->get('ETag');
    expect($etag)->not->toBeNull();

    // Per-request headers (not withHeaders(), which merges into $this->defaultHeaders and
    // persists onto every later request in this test method).
    $this->get('/admin/home/stylepress/thumbnail/appended-style?v=fixture-v2', ['If-None-Match' => $etag])
        ->assertStatus(304);
});

it('transcodes PNG and WebP StylePress sources at or under 480px to real JPEG bytes on the Home thumbnail route', function () {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('This GD build lacks WebP support.');
    }

    $root = sys_get_temp_dir().'/mothership-stylepress-formats-'.Str::uuid();
    mkdir($root.'/stylepress', 0777, true);

    $png = imagecreatetruecolor(40, 40);
    imagefilledrectangle($png, 0, 0, 39, 39, imagecolorallocate($png, 30, 150, 90));
    imagepng($png, $root.'/stylepress/png-style.png');
    imagedestroy($png);

    $webp = imagecreatetruecolor(40, 40);
    imagefilledrectangle($webp, 0, 0, 39, 39, imagecolorallocate($webp, 200, 90, 30));
    imagewebp($webp, $root.'/stylepress/webp-style.webp');
    imagedestroy($webp);

    $catalogPath = $root.'/catalog.json';
    file_put_contents($catalogPath, json_encode([
        'version' => 'formats-v1', 'count' => 2, 'categories' => ['Editorial'],
        'presets' => [
            ['slug'=>'png-style', 'title'=>'PNG Style', 'categoryLabel'=>'Editorial', 'aspectRatio'=>'1:1', 'look'=>'', 'template'=>'PNG TEMPLATE', 'slots'=>'', 'image'=>'stylepress/png-style.png'],
            ['slug'=> 'webp-style', 'title'=>'WebP Style', 'categoryLabel'=>'Editorial', 'aspectRatio'=>'1:1', 'look'=>'', 'template'=>'WEBP TEMPLATE', 'slots'=>'', 'image'=>'stylepress/webp-style.webp'],
        ],
    ], JSON_THROW_ON_ERROR));
    config(['stylepress.catalog_path' => $catalogPath, 'stylepress.import_root' => sys_get_temp_dir().'/stylepress-none-'.Str::uuid()]);

    // Seed each slug's derivative path with the WRONG format under the .jpg name -- exactly what
    // a since-fixed bug (or a future one) could leave on disk -- so the assertions below also
    // prove the route regenerates rather than trust an existing file at face value.
    $png = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($png);
    imagedestroy($png);
    Storage::disk('local')->put('stylepress-thumbnails/formats-v1/png-style.jpg', ob_get_clean());

    $webp = imagecreatetruecolor(4, 4);
    ob_start();
    imagewebp($webp);
    imagedestroy($webp);
    Storage::disk('local')->put('stylepress-thumbnails/formats-v1/webp-style.jpg', ob_get_clean());

    foreach (['png-style', 'webp-style'] as $slug) {
        $homeResponse = $this->get('/admin/home/stylepress/thumbnail/'.$slug.'?v=formats-v1')->assertOk();
        expect($homeResponse->headers->get('Content-Type'))->toBe('image/jpeg');
        $homeFile = $homeResponse->baseResponse->getFile()->getPathname();
        $homeSize = getimagesize($homeFile);
        expect($homeSize)->not->toBeFalse()->and($homeSize[2])->toBe(IMAGETYPE_JPEG)
            ->and(filesize($homeFile))->toBeGreaterThan(0);
        $homeEtag = $homeResponse->headers->get('ETag');
        $this->get('/admin/home/stylepress/thumbnail/'.$slug.'?v=formats-v1', ['If-None-Match' => $homeEtag])
            ->assertStatus(304);

    }

    Storage::disk('local')->deleteDirectory('stylepress-thumbnails/formats-v1');
    @unlink($root.'/stylepress/png-style.png');
    @unlink($root.'/stylepress/webp-style.webp');
    @unlink($catalogPath);
    @rmdir($root.'/stylepress');
    @rmdir($root);
});
