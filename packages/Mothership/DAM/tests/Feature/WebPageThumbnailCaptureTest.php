<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Http\Controllers\WebPageController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\PageGrant;
use Mothership\DAM\Services\WebPageBundler;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE, 'mothership.media_accel_prefix' => null]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
});

function capturableAsset(): Asset
{
    $assetPath = 'assets/Root/p.html';
    // Written before the row is created so the graph observer ingests it as the original (ADR 0009).
    Storage::disk(Directory::getAssetDisk())->put($assetPath, '<html><head><title>x</title></head><body>hi</body></html>');
    $asset = Asset::factory()->create(['file_name' => 'p.html', 'file_type' => 'web', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => $assetPath]);
    $asset->directories()->attach(1);
    app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));

    return $asset->fresh();
}

it('injects the capture script on the admin route until a thumbnail exists', function () {
    $this->loginAsAdmin();
    $asset = capturableAsset();

    getRawUrl(PageGrant::baseUrl($asset, 1).'/')->assertOk()->assertSee(WebPageController::CAPTURE_MARKER, false);

    $this->post(route('admin.dam.assets.custom_thumbnail.store', ['id' => $asset->id]), ['thumbnail' => UploadedFile::fake()->image('t.jpg', 64, 36)])->assertCreated();

    getRawUrl(PageGrant::baseUrl($asset->fresh(), 1).'/')->assertOk()->assertDontSee(WebPageController::CAPTURE_MARKER, false);
});

it('never injects on the share route', function () {
    $asset = capturableAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    getRawUrl(route('dam.share.page', ['token' => $share->urlToken()]).'/')->assertOk()->assertDontSee(WebPageController::CAPTURE_MARKER, false);
});

/** The exporter shape (PR #30): an "OPEN ME" launcher beside site/, served as index.html, that meta-refreshes to the real page. */
function launcherAsset(string $target = 'site/tickets/live/index.html'): Asset
{
    $zipPath = tempnam(sys_get_temp_dir(), 'wpl');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('Export/OPEN ME - Live.html', '<!doctype html><meta charset="utf-8"><title>Live</title><meta http-equiv="refresh" content="0; url='.$target.'"><p>Opening <a href="'.$target.'">Live</a></p>');
    $zip->addFromString('Export/README.txt', 'r');
    $zip->addFromString('Export/site/tickets/live/index.html', '<html><head><title>live</title></head><body>real page</body></html>');
    $zip->close();

    $assetPath = 'assets/Root/launcher.zip';
    Storage::disk(Directory::getAssetDisk())->put($assetPath, file_get_contents($zipPath));
    unlink($zipPath);
    $asset = Asset::factory()->create(['file_name' => 'launcher.zip', 'file_type' => 'web', 'extension' => 'zip', 'mime_type' => 'application/zip', 'path' => $assetPath]);
    $asset->directories()->attach(1);
    app(WebPageBundler::class)->build($asset, WebPageBundler::versionNumber($asset));

    return $asset->fresh();
}

it('captures the page a launcher entry refreshes to, never the launcher itself', function () {
    $this->loginAsAdmin();
    $asset = launcherAsset();
    $base = PageGrant::baseUrl($asset, 1);

    // The launcher navigates away at once, so a capture injected there would never fire.
    getRawUrl($base.'/')->assertOk()->assertDontSee(WebPageController::CAPTURE_MARKER, false);

    // Its bytes depend on the thumbnail state, so a cached injected copy must not outlive the capture.
    getRawUrl($base.'/site/tickets/live/index.html')->assertOk()
        ->assertSee(WebPageController::CAPTURE_MARKER, false)
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->post(route('admin.dam.assets.custom_thumbnail.store', ['id' => $asset->id]), ['thumbnail' => UploadedFile::fake()->image('t.jpg', 64, 36)])->assertCreated();

    getRawUrl(PageGrant::baseUrl($asset->fresh(), 1).'/site/tickets/live/index.html')->assertOk()->assertDontSee(WebPageController::CAPTURE_MARKER, false);
});

it('captures a launcher directory target at the index.html the browser lands on', function () {
    $this->loginAsAdmin();
    $asset = launcherAsset('site/tickets/live');
    $base = PageGrant::baseUrl($asset, 1);

    // `site/tickets/live` redirects to the slashed directory, which serves its index.html.
    getRawUrl($base.'/site/tickets/live')->assertRedirect();
    getRawUrl($base.'/site/tickets/live/')->assertOk()
        ->assertSee(WebPageController::CAPTURE_MARKER, false)
        ->assertHeader('Cache-Control', 'no-store, private');
    getRawUrl($base.'/')->assertOk()->assertDontSee(WebPageController::CAPTURE_MARKER, false);
});

it('keeps capturing the entry of a bundle built before capture entries were recorded', function () {
    $this->loginAsAdmin();
    $asset = launcherAsset();
    $meta = $asset->meta_data;
    unset($meta['web_bundle']['capture_entry']);
    $asset->update(['meta_data' => $meta]);
    $base = PageGrant::baseUrl($asset->fresh(), 1);

    getRawUrl($base.'/')->assertOk()->assertSee(WebPageController::CAPTURE_MARKER, false);
    getRawUrl($base.'/site/tickets/live/index.html')->assertOk()
        ->assertDontSee(WebPageController::CAPTURE_MARKER, false)
        ->assertHeader('Cache-Control', 'max-age=300, private');
});

it('never injects into a launcher target on the share route', function () {
    $asset = launcherAsset();
    $share = Share::factory()->forAsset($asset->id)->create();

    getRawUrl(route('dam.share.page', ['token' => $share->urlToken()]).'/site/tickets/live/index.html')->assertOk()
        ->assertDontSee(WebPageController::CAPTURE_MARKER, false)
        ->assertHeader('Cache-Control', 'max-age=300, public');
});

it('only accepts a base64 jpeg data url in the parent listener', function () {
    $source = file_get_contents(base_path('packages/Mothership/DAM/src/Resources/views/asset/preview-modal/web/frame-script.blade.php'));

    expect($source)->toContain('data:image\/jpeg;base64,')->not->toContain('fetch(data.jpeg');
});

function injectIntoHead(string $html, string $script): string
{
    $method = new ReflectionMethod(WebPageController::class, 'injectIntoHead');
    $method->setAccessible(true);

    return $method->invoke(app(WebPageController::class), $html, $script);
}

it('injects after a real head start tag even when an attribute value contains a bare >', function () {
    expect(injectIntoHead('<head data-x=">">', 'MARK'))->toBe('<head data-x=">">MARK');
});

it('skips a head tag written inside an html comment and injects after the real one', function () {
    expect(injectIntoHead('<!-- <head> --><head>', 'MARK'))->toBe('<!-- <head> --><head>MARK');
});

it('keeps a leading doctype first and injects right after it when there is no head', function () {
    expect(injectIntoHead('<!doctype html><p>x</p>', 'MARK'))->toBe('<!doctype html>MARK<p>x</p>');
});

it('never lands inside a script string that merely contains head-shaped text', function () {
    expect(injectIntoHead('<script>const s="<head>";</script>', 'MARK'))
        ->toBe('MARK<script>const s="<head>";</script>');

    expect(injectIntoHead('<html><script>const s="<head>";</script></html>', 'MARK'))
        ->toBe('<html>MARK<script>const s="<head>";</script></html>');
});

it('injects after the html tag when a quoted attribute value contains head-shaped text', function () {
    expect(injectIntoHead('<html data-x="<head>">', 'MARK'))->toBe('<html data-x="<head>">MARK');
});
