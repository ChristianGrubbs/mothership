<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Mothership\User\Models\Admin;

it('serves public PWA resources without creating or renewing a session cookie', function (string $route) {
    $this->get(route($route))->assertOk()->assertHeaderMissing('Set-Cookie');
    $this->withCookie(config('session.cookie'), 'qa-existing-session')
        ->get(route($route))->assertOk()->assertHeaderMissing('Set-Cookie');
})->with(['pwa.manifest', 'pwa.offline']);

it('offers the Home PWA on admin login but not unrelated anonymous portals', function () {
    $this->get(route('admin.session.create'))->assertOk()->assertSee('rel="manifest"', false);
    Route::middleware('web')->get('/qa-pwa-guest', fn () => Blade::render('<x-admin::layouts.anonymous>QA portal</x-admin::layouts.anonymous>'));
    $this->get('/qa-pwa-guest')->assertOk()->assertDontSee('rel="manifest"', false)->assertDontSee('pwa/register.js', false);
});

it('gives each device fit file an address that follows its content', function () {
    $html = $this->get(route('admin.session.create'))->assertOk()->getContent();
    foreach (['pwa/device.css', 'pwa/device.js'] as $file) {
        expect($html)->toContain(asset($file).'?v='.substr(md5_file(public_path($file)), 0, 8).'"');
    }

    // A changed file gets a new address and the other keeps its own; a missing file still lets the page render.
    $original = public_path();
    $public = sys_get_temp_dir().'/device-fit-'.bin2hex(random_bytes(4));
    mkdir($public.'/pwa', recursive: true);
    app()->usePublicPath($public);
    try {
        $addresses = function (): array {
            preg_match_all('~pwa/device\.(?:css|js)\?v=[^"]+~', Blade::render('<x-admin::layouts.device />'), $found);

            return $found[0];
        };
        expect($addresses())->toHaveCount(2);
        file_put_contents($public.'/pwa/device.css', 'a { color: red; }');
        file_put_contents($public.'/pwa/device.js', 'void 0;');
        [$css, $js] = $addresses();
        file_put_contents($public.'/pwa/device.css', 'a { color: blue; }');
        expect($addresses())->not->toContain($css)->toContain($js);
    } finally {
        app()->usePublicPath($original);
        array_map('unlink', glob($public.'/pwa/*'));
        rmdir($public.'/pwa');
        rmdir($public);
    }
});

it('offers standalone metadata with real sized icons and the configured home route', function () {
    $response = $this->get(route('pwa.manifest'))->assertOk();
    $response->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('start_url', route('admin.home.index', absolute: false))
        ->assertJsonPath('display', 'standalone');
    foreach ($response->json('icons') as $icon) {
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect($icon['sizes'])->toBe($width.'x'.$height);
    }
});

it('keeps the cacheable offline help identical across accounts and sign-out', function () {
    $public = $this->get(route('pwa.offline'))->assertOk()->getContent();
    $this->loginAsAdmin(Admin::factory()->create(['name' => 'Private fixture identity']));
    $private = $this->get(route('pwa.offline'))->assertOk()->getContent();
    expect($private)->toBe($public)->not->toContain('Private fixture identity');
    auth('admin')->logout();
    expect($this->get(route('pwa.offline'))->assertOk()->getContent())->toBe($public);
});
