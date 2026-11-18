<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\WebPageBundler;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
});

function zipBytes(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'wpb').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();

    return file_get_contents($path);
}

function webAsset(string $name, string $bytes, string $extension = 'zip'): Asset
{
    $path = 'assets/Root/'.$name;
    // Written before the row is created so the graph observer ingests it as the original (ADR 0009).
    Storage::disk(Directory::getAssetDisk())->put($path, $bytes);

    return Asset::factory()->create(['file_name' => $name, 'file_type' => 'web', 'extension' => $extension, 'mime_type' => $extension === 'zip' ? 'application/zip' : 'text/html', 'path' => $path]);
}

function buildBundle(Asset $asset): array
{
    return app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));
}

it('builds a bundle from a root-level zip', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => '<h1>hi</h1>', 'css/a.css' => 'h1{}']));

    $manifest = buildBundle($asset);

    expect($manifest)->toMatchArray(['version' => 0, 'root' => "bundles/{$asset->id}/0", 'entry' => 'index.html', 'capture_entry' => 'index.html', 'file_count' => 2]);
    Storage::disk(Directory::getAssetDisk())->assertExists("bundles/{$asset->id}/0/index.html");
    Storage::disk(Directory::getAssetDisk())->assertExists("bundles/{$asset->id}/0/css/a.css");
    expect($asset->fresh()->meta_data['web_bundle']['file_count'])->toBe(2);
});

it('strips a single top-level folder', function () {
    $asset = webAsset('site.zip', zipBytes(['site/index.html' => 'x', 'site/js/app.js' => '1']));

    buildBundle($asset);

    Storage::disk(Directory::getAssetDisk())->assertExists("bundles/{$asset->id}/0/js/app.js");
    Storage::disk(Directory::getAssetDisk())->assertMissing("bundles/{$asset->id}/0/site/index.html");
});

it('serves a lone non-index entry as index.html beside its site folder', function () {
    $asset = webAsset('export.zip', zipBytes(['Export/OPEN ME.html' => 'open', 'Export/README.txt' => 'r', 'Export/site/index.html' => 'inner']));

    $manifest = buildBundle($asset);

    $disk = Storage::disk(Directory::getAssetDisk());
    expect($manifest['entry'])->toBe('index.html')
        ->and($disk->get("bundles/{$asset->id}/0/index.html"))->toBe('open')
        ->and($disk->get("bundles/{$asset->id}/0/site/index.html"))->toBe('inner');
});

it('records where the entry meta-refreshes to as the capture entry', function (string $refresh, string $expected) {
    $asset = webAsset('export.zip', zipBytes([
        'Export/OPEN ME.html'         => '<!doctype html><!-- <meta http-equiv="refresh" content="0; url=site/other.html"> --><meta http-equiv="refresh" content="'.$refresh.'">',
        'Export/site/live/index.html' => 'live',
        'Export/site/My Page.html'    => 'mine',
        'Export/site/other.html'      => 'other',
        'Export/site/app.js'          => '1',
    ]));

    expect(buildBundle($asset)['capture_entry'])->toBe($expected)
        ->and($asset->fresh()->meta_data['web_bundle']['capture_entry'])->toBe($expected);
})->with([
    'a file'                  => ['0; url=site/live/index.html', 'site/live/index.html'],
    'quoted, query, fragment' => ["0;URL='./site/live/?v=2#top'", 'site/live/index.html'],
    'a directory'             => ['0; url=site/live', 'site/live/index.html'],
    'percent-encoded'         => ['0; url=site/My%20Page.html', 'site/My Page.html'],
    'backslashes'             => ['0; url=site\\live\\index.html', 'site/live/index.html'],
    'backslash-rooted'        => ['0; url=\\site\\live\\index.html', 'index.html'],
    'backslash traversal'     => ['0; url=..\\Export\\site\\live\\index.html', 'index.html'],
    'not html'                => ['0; url=site/app.js', 'index.html'],
    'off-site'                => ['0; url=https://example.com/site/live/index.html', 'index.html'],
    'origin-rooted'           => ['0; url=/site/live/index.html', 'index.html'],
    'outside the bundle'      => ['0; url=../Export/site/live/index.html', 'index.html'],
    'missing'                 => ['0; url=site/gone.html', 'index.html'],
    'no url'                  => ['5', 'index.html'],
]);

it('reads only the refresh a browser would act on', function (string $entry, string $expected) {
    $asset = webAsset('export.zip', zipBytes([
        'Export/OPEN ME.html'         => '<!doctype html>'.str_replace('REFRESH', '<meta http-equiv="refresh" content="0; url=site/live/index.html">', $entry),
        'Export/site/live/index.html' => 'live',
    ]));

    expect(buildBundle($asset)['capture_entry'])->toBe($expected);
})->with([
    'a plain refresh'                => ['REFRESH', 'site/live/index.html'],
    'after a --!> closed comment'    => ['<!-- x --!>REFRESH', 'site/live/index.html'],
    'after an unparseable one'       => ['<meta http-equiv="refresh" content="soon">REFRESH', 'site/live/index.html'],
    'after a bare-time self refresh' => ['<meta http-equiv="refresh" content="5">REFRESH', 'index.html'],
    'in an unclosed comment'         => ['<!-- REFRESH', 'index.html'],
    'in an unclosed script'          => ['<script>const s = \'REFRESH\';', 'index.html'],
    'in an unclosed style'           => ['<style>REFRESH', 'index.html'],
    'in an unclosed textarea'        => ['<body><textarea>REFRESH', 'index.html'],
    'in an unclosed title'           => ['<title>REFRESH', 'index.html'],
    'in an unclosed iframe'          => ['<body><iframe>REFRESH', 'index.html'],
    'in an unclosed xmp'             => ['<body><xmp>REFRESH', 'index.html'],
    'in an unclosed noembed'         => ['<body><noembed>REFRESH', 'index.html'],
    'in noscript'                    => ['<noscript>REFRESH</noscript>', 'index.html'],
    'in head noscript after a div'   => ['<head><noscript><div>REFRESH</noscript>', 'index.html'],
    'after a noscript'               => ['<NoScript><div>x</div></NoScript>REFRESH', 'site/live/index.html'],
    'after a noscript beside an xmp' => ['<body><xmp>a</xmp><noscript>b</noscript>REFRESH', 'site/live/index.html'],
    'in an xmp holding </noscript>'  => ['<body><xmp></noscript>REFRESH', 'index.html'],
    'in a noscript holding </xmp>'   => ['<noscript></xmp>REFRESH</noscript>', 'index.html'],
    'after a noframes stand-in'      => ['<body><xmp>a</xmp><noembed>b</noembed><noscript>c</noscript>REFRESH', 'site/live/index.html'],
    'noframes when xmp and noembed'  => ['<body><xmp>a</xmp><noembed>b</noembed><noscript></xmp></noembed>REFRESH</noscript>', 'index.html'],
    'in an XMP holding </noscript>'  => ['<body><XMP></noscript>REFRESH', 'index.html'],
    'every stand-in already named'   => ['<body><xmp>a</xmp><noembed>b</noembed><noframes>c</noframes><noscript>d</noscript>REFRESH', 'index.html'],
    'in a template'                  => ['<template>REFRESH</template>', 'index.html'],
]);

it('wraps a single html upload as index.html', function () {
    $asset = webAsset('page.html', '<p>solo</p>', 'html');

    buildBundle($asset);

    expect(Storage::disk(Directory::getAssetDisk())->get("bundles/{$asset->id}/0/index.html"))->toBe('<p>solo</p>');
});

it('records an error and stores nothing for a forbidden entry', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => 'x', 'x.php' => '<?php']));

    $manifest = buildBundle($asset);

    expect($manifest['error'])->toBe('forbidden_entry:x.php');
    Storage::disk(Directory::getAssetDisk())->assertMissing("bundles/{$asset->id}/0/index.html");
    expect(WebPageBundler::rootFor($asset->fresh()))->toBeNull();
});

it('fails a single html upload larger than the bundle limit', function () {
    config(['dam.web_pages.max_bundle_size' => 8]);
    $asset = webAsset('page.html', str_repeat('x', 64), 'html');

    expect(buildBundle($asset)['error'])->toBe('too_large');
    Storage::disk(Directory::getAssetDisk())->assertMissing("bundles/{$asset->id}/0/index.html");
});

it('builds the newest version and leaves the older bundle with its version', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => 'v0']));
    buildBundle($asset);
    // Re-ingests the new bytes as the current original (ADR 0009), matching a real re-upload; the
    // graph observer bumps AssetVersion to 1 itself (a prior original already existed).
    putAssetContent($asset, zipBytes(['index.html' => 'v1']));

    $manifest = buildBundle($asset->fresh());

    expect($manifest['root'])->toBe("bundles/{$asset->id}/1");
    Storage::disk(Directory::getAssetDisk())->assertExists("bundles/{$asset->id}/0/index.html");
    expect(Storage::disk(Directory::getAssetDisk())->get("bundles/{$asset->id}/1/index.html"))->toBe('v1');
});

it('publishes nothing when a newer version landed during the build', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => 'v0']));
    AssetVersion::create(['dam_asset_id' => $asset->id, 'number' => 3]);

    $manifest = app(WebPageBundler::class)->build($asset->fresh(), 2);

    expect($manifest)->toMatchArray(['version' => 2, 'error' => 'stale']);
    Storage::disk(Directory::getAssetDisk())->assertMissing("bundles/{$asset->id}/2/index.html");
    expect(WebPageBundler::rootFor($asset->fresh()))->toBeNull();
});

it('refuses the s3 disk', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => 'x']));
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS]);

    expect(buildBundle($asset)['error'])->toBe('disk_unsupported');
});

it('purge removes every bundle of the asset', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => 'x']));
    buildBundle($asset);

    app(WebPageBundler::class)->purge($asset->id);

    Storage::disk(Directory::getAssetDisk())->assertMissing("bundles/{$asset->id}/0/index.html");
});

it('leaves a published manifest alone when an older version fails late', function () {
    $asset = webAsset('site.zip', zipBytes(['index.html' => 'v1']));
    AssetVersion::create(['dam_asset_id' => $asset->id, 'number' => 1]);
    $published = app(WebPageBundler::class)->build($asset->fresh(), 1);
    expect($published['root'])->toBe("bundles/{$asset->id}/1");

    // Version 0's build only now gets around to failing: the original is already gone.
    Storage::disk(Directory::getAssetDisk())->delete($asset->path);
    $late = app(WebPageBundler::class)->build($asset->fresh(), 0);

    expect($late)->toMatchArray(['version' => 0, 'error' => 'stale'])
        ->and($asset->fresh()->meta_data['web_bundle'])->toMatchArray(['version' => 1, 'root' => "bundles/{$asset->id}/1"]);
    Storage::disk(Directory::getAssetDisk())->assertExists("bundles/{$asset->id}/1/index.html");
});
