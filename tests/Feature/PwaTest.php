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
