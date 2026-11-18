<?php

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Http\Middleware\SignalDownloadStart;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Symfony\Component\HttpFoundation\Cookie;

/*
 * The "Preparing download…" card (components/download/preparing.blade.php) waits for a `dam_dl_<token>` cookie that
 * the download route sets with its response headers, so the card knows the download really began. The cookie is
 * plain (the page reads it from document.cookie) and is never set for another token, a preflight or an error.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $this->asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => 'menu.png', 'path' => 'assets/Browse/menu.png']));
    $this->zip = '/'.config('app.admin_url').'/dam/file/asset/'.$this->asset->id.'/files-zip';
    $this->postJson('/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files', [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'first notes'), 'role' => 'sidecar',
    ])->assertCreated();
});

/** @return list<Cookie> */
$startCookies = fn ($response): array => array_values(array_filter(
    $response->headers->getCookies(),
    fn (Cookie $cookie) => str_starts_with($cookie->getName(), 'dam_dl_'),
));

it('sets a plain dam_dl cookie for a valid token, with the lifetime, path and SameSite the card expects', function () use ($startCookies) {
    $token = 'Ab3dEf6hIj9kLm';

    $cookies = $startCookies($this->get($this->zip.'?dl='.$token)->assertOk());

    expect($cookies)->toHaveCount(1);
    [$cookie] = $cookies;
    expect($cookie->getName())->toBe('dam_dl_'.$token)
        // Not encrypted: the page reads it from document.cookie.
        ->and($cookie->getValue())->toBe('1')
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getPath())->toBe('/')
        ->and(strtolower((string) $cookie->getSameSite()))->toBe('lax')
        ->and($cookie->getExpiresTime() - time())->toBeGreaterThan(110)->toBeLessThanOrEqual(120);
});

it('ignores a token that is too short, too long or not alphanumeric', function (string $token) use ($startCookies) {
    expect($startCookies($this->get($this->zip.'?dl='.urlencode($token))->assertOk()))->toBe([]);
})->with([
    'too short'          => 'abc123',
    'too long'           => str_repeat('a', 41),
    'a path escape'      => '../../etc/passwd',
    'a cookie injection' => 'abcdefghijkl; Path=/x',
    'an empty token'     => '',
]);

it('sets nothing without a token, and nothing for a token sent as an array', function () use ($startCookies) {
    expect($startCookies($this->get($this->zip)->assertOk()))->toBe([])
        ->and($startCookies($this->get($this->zip.'?dl[]=Ab3dEf6hIj9kLm')->assertOk()))->toBe([]);
});

it('sets nothing for a preflight', function () use ($startCookies) {
    $response = $this->get($this->zip.'?dl=Ab3dEf6hIj9kLm', ['X-Zip-Preflight' => '1']);

    expect($startCookies($response))->toBe([]);
});

it('sets nothing for an error response', function () use ($startCookies) {
    $response = $this->get($this->zip.'?dl=Ab3dEf6hIj9kLm&files[]=999999')->assertNotFound();

    expect($startCookies($response))->toBe([]);
});

it('sets nothing for a redirect that stays on this host, and a cookie for one that leaves it', function (string $location, bool $expected) use ($startCookies) {
    $request = Request::create('https://dam.example.test/share/file?dl=Ab3dEf6hIj9kLm');
    $response = (new SignalDownloadStart)->handle($request, fn () => new RedirectResponse($location));

    expect($startCookies($response))->toHaveCount($expected ? 1 : 0);
})->with([
    'a relative path'                         => ['/admin/dam/assets', false],
    'the same host'                           => ['https://dam.example.test/admin/dam/assets', false],
    'the same host, any case'                 => ['https://DAM.example.test/admin', false],
    'the same host, default port spelled out' => ['https://dam.example.test:443/admin', false],
    'the same host on another port (MinIO)'   => ['https://dam.example.test:9000/x', true],
    'the storage host'                        => ['https://bucket.s3.amazonaws.com/menu.png?X-Amz-Signature=abc', true],
]);

/*
 * The public share pages show the same card: the page marks its download links, includes the partial once, and the
 * share download routes set the same cookie for a visitor who is not signed in. Preview-only links get neither.
 */

/** A visitor who is not signed in, with the test asset's bytes on the faked disk. */
$asVisitor = function (object $test): void {
    Storage::disk(Directory::getAssetDisk())->put($test->asset->path, 'MENU-PNG-BYTES');
    $test->asset->forceFill(['extension' => 'png', 'mime_type' => 'image/png', 'file_type' => 'image'])->markGraphContentChanged()->save();
    Auth::guard('admin')->logout();
};

it('sets the dam_dl cookie on the share asset download for a valid token, and not for a bad one', function () use ($startCookies, $asVisitor) {
    $share = Share::factory()->forAsset($this->asset->id)->create();
    $asVisitor($this);
    $token = 'Zq8xWv2nBm5kRt';

    $response = $this->get(route('dam.share.download', ['token' => $share->urlToken(), 'dl' => $token]))->assertOk();

    $cookies = $startCookies($response);
    expect($cookies)->toHaveCount(1)
        ->and($cookies[0]->getName())->toBe('dam_dl_'.$token)
        ->and($cookies[0]->getValue())->toBe('1')
        ->and($cookies[0]->isHttpOnly())->toBeFalse();
    expect($startCookies($this->get(route('dam.share.download', ['token' => $share->urlToken(), 'dl' => 'short']))->assertOk()))->toBe([]);
});

it('sets the dam_dl cookie on the share folder zip', function () use ($startCookies, $asVisitor) {
    $folder = Directory::create(['name' => 'Share folder '.uniqid()]);
    $this->asset->directories()->attach($folder->id);
    $share = Share::factory()->forDirectory($folder->id)->create();
    $asVisitor($this);

    $response = $this->get(route('dam.share.download_zip', ['token' => $share->urlToken(), 'dl' => 'Zq8xWv2nBm5kRt']))->assertOk();

    expect($startCookies($response))->toHaveCount(1);
});

it('marks the share asset page download links for the card and includes it once', function () use ($asVisitor) {
    $share = Share::factory()->forAsset($this->asset->id)->create();
    $asVisitor($this);

    $page = $this->get(route('dam.share.show', $share->urlToken()))->assertOk();

    $page->assertSee('data-dam-download-name="menu.png"', false)
        ->assertSee('data-dam-download-thumb="'.e(route('dam.share.thumbnail', ['token' => $share->urlToken(), 'assetId' => $this->asset->id])).'"', false);
    expect(substr_count($page->getContent(), 'window.damDownload = { start }'))->toBe(1);
});

it('marks the share folder zip link with the folder name and no thumbnail, and includes the card', function () use ($asVisitor) {
    $folder = Directory::create(['name' => 'Press kit '.uniqid()]);
    $this->asset->directories()->attach($folder->id);
    $share = Share::factory()->forDirectory($folder->id)->create();
    $asVisitor($this);

    $page = $this->get(route('dam.share.show', $share->urlToken()))->assertOk();

    preg_match('~<a\s[^>]*download-zip[^>]*>~', $page->getContent(), $zipLink);
    expect($zipLink)->toHaveCount(1)
        ->and($zipLink[0])->toContain('data-dam-download', 'data-dam-download-name="'.e($folder->name).'"')
        ->and($zipLink[0])->not->toContain('data-dam-download-thumb')
        ->and(substr_count($page->getContent(), 'window.damDownload = { start }'))->toBe(1);
});

it('leaves a preview-only share page without the card', function () use ($asVisitor) {
    $share = Share::factory()->forAsset($this->asset->id)->create(['allow_download' => false]);
    $asVisitor($this);

    $this->get(route('dam.share.show', $share->urlToken()))->assertOk()
        ->assertDontSee('data-dam-download-name="menu.png"', false)
        ->assertDontSee('window.damDownload', false);
});
