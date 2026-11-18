<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Models\Directory;

it('rejects HTML and other executable/markup uploads by extension', function (string $extension) {
    expect(AssetHelper::isForbiddenFile($extension, null, "evil.{$extension}", null))->toBeTrue();
})->with(['xhtml', 'shtml', 'hta', 'php', 'phtml', 'phar']);

it('allows html and htm uploads only while web pages are enabled', function () {
    config(['dam.web_pages.enabled' => true]);
    expect(AssetHelper::isForbiddenFile('html', 'text/html', 'page.html', null))->toBeFalse();
    config(['dam.web_pages.enabled' => false]);
    expect(AssetHelper::isForbiddenFile('html', 'text/html', 'page.html', null))->toBeTrue();
});

it('keeps an html upload forbidden when its MIME type is executable', function () {
    config(['dam.web_pages.enabled' => true]);
    expect(AssetHelper::isForbiddenFile('html', 'application/x-php', 'page.html', null))->toBeTrue()
        ->and(AssetHelper::isForbiddenFile('htm', 'application/javascript', 'page.htm', null))->toBeTrue();
});

it('exempts an html upload only for an allow-listed MIME type', function () {
    config(['dam.web_pages.enabled' => true]);

    expect(AssetHelper::isForbiddenFile('html', 'application/vnd.unlisted', 'page.html', null))->toBeTrue()
        ->and(AssetHelper::isForbiddenFile('html', 'application/octet-stream', 'page.html', null))->toBeFalse()
        ->and(AssetHelper::isForbiddenFile('html', 'inode/x-empty', 'page.html', null))->toBeFalse();
});

it('refuses an html upload whose real bytes are a program', function () {
    config(['dam.web_pages.enabled' => true]);
    $script = tempnam(sys_get_temp_dir(), 'hard').'.html';
    $markup = tempnam(sys_get_temp_dir(), 'hard').'.html';
    file_put_contents($script, '<?php echo 1;');
    file_put_contents($markup, '<!doctype html><p>x</p>');

    // The claimed type is markup in both cases; only the bytes on disk tell them apart.
    expect(AssetHelper::isForbiddenFile('html', 'text/html', 'page.html', $script))->toBeTrue()
        ->and(AssetHelper::isForbiddenFile('html', 'text/html', 'page.html', $markup))->toBeFalse();
});

it('rejects HTML uploads by MIME type even with an innocent extension', function () {
    expect(AssetHelper::isForbiddenFile('txt', 'text/html', 'evil.txt', null))->toBeTrue();
    expect(AssetHelper::isForbiddenFile(null, 'application/xhtml+xml', 'evil', null))->toBeTrue();
});

it('still allows genuine media uploads', function () {
    expect(AssetHelper::isForbiddenFile('png', 'image/png', 'ok.png', null))->toBeFalse();
    expect(AssetHelper::isForbiddenFile('mp4', 'video/mp4', 'ok.mp4', null))->toBeFalse();
    expect(AssetHelper::isForbiddenFile('pdf', 'application/pdf', 'ok.pdf', null))->toBeFalse();
});

it('treats only real media MIME types as inline-safe', function () {
    expect(AssetHelper::isInlineSafeMime('image/png'))->toBeTrue();
    expect(AssetHelper::isInlineSafeMime('video/mp4'))->toBeTrue();
    expect(AssetHelper::isInlineSafeMime('audio/mpeg'))->toBeTrue();
    expect(AssetHelper::isInlineSafeMime('application/pdf'))->toBeTrue();
    expect(AssetHelper::isInlineSafeMime('image/svg+xml'))->toBeTrue();

    expect(AssetHelper::isInlineSafeMime('text/html'))->toBeFalse();
    expect(AssetHelper::isInlineSafeMime('application/xml'))->toBeFalse();
    expect(AssetHelper::isInlineSafeMime('text/plain'))->toBeFalse();
    expect(AssetHelper::isInlineSafeMime(''))->toBeFalse();
    expect(AssetHelper::isInlineSafeMime(null))->toBeFalse();
});

it('exposes hardening headers that block script execution and MIME sniffing', function () {
    $headers = AssetHelper::assetResponseHeaders();

    expect($headers['X-Content-Type-Options'])->toBe('nosniff');
    expect($headers['Content-Security-Policy'])->toContain("default-src 'none'");
});

it('fetchFile serves an HTML file as an attachment with hardening headers', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    $this->loginAsAdmin();

    $path = 'assets/Root/evil.html';
    Storage::disk(Directory::ASSETS_DISK_PRIVATE)->put($path, '<script>alert(document.cookie)</script>');
    registerServedAssetPath($path);

    $response = (new FileController)->fetchFile($path);

    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('Content-Security-Policy'))->not->toBeNull();
});

it('fetchFile serves a genuine image inline (no forced download)', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_PRIVATE]);
    Storage::fake(Directory::ASSETS_DISK_PRIVATE);
    $this->loginAsAdmin();

    $path = 'assets/Root/photo.jpg';
    $image = UploadedFile::fake()->image('photo.jpg', 8, 8);
    Storage::disk(Directory::ASSETS_DISK_PRIVATE)->put($path, file_get_contents($image->getRealPath()));
    registerServedAssetPath($path);

    $response = (new FileController)->fetchFile($path);

    expect($response->headers->get('Content-Disposition'))->toBe('inline; filename=photo.jpg');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});
