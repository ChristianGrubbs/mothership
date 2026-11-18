<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Mothership\Core\PublicOrigins;

beforeEach(function () {
    $this->canonical = rtrim((string) config('app.url'), '/');
    Route::get('/_public-origin-probe', fn () => response()->json([
        'url'    => url('/x'),
        'route'  => route('admin.session.create'),
        'asset'  => asset('a.css'),
        'app'    => config('app.url'),
        'signed' => URL::signedRoute('public-origin.signed'),
    ]));
    Route::get('/_public-origin-signed', fn () => 'ok')->middleware('signed')->name('public-origin.signed');
    Route::getRoutes()->refreshNameLookups();
});

it('builds URLs, redirects and the login page on an allowlisted public origin', function () {
    config(['app.public_origins' => 'https://alias.example, https://other.example']);

    $probe = $this->getJson('https://alias.example/_public-origin-probe')->assertOk();
    $probe->assertJsonPath('url', 'https://alias.example/x')
        ->assertJsonPath('route', 'https://alias.example/admin/login')
        ->assertJsonPath('asset', 'https://alias.example/a.css')
        ->assertJsonPath('app', 'https://alias.example');

    // A signed URL validates on the host that generated it, not on another one.
    $signed = $probe->json('signed');
    expect($signed)->toStartWith('https://alias.example/_public-origin-signed?');
    $this->get($signed)->assertOk();
    $this->get(str_replace('https://alias.example', 'https://other.example', $signed))->assertForbidden();

    $this->get('https://alias.example/admin/dashboard')->assertRedirect('https://alias.example/admin/login');
    $this->get('https://alias.example/admin/login')->assertOk()
        ->assertSee('<meta name="base-url" content="https://alias.example">', false)
        ->assertSee('action="https://alias.example/admin/login"', false);

    // The request's origin never leaks into the next request or into code outside a request.
    expect(url('/x'))->toBe($this->canonical.'/x')->and(config('app.url'))->toBe($this->canonical);
});

it('keeps APP_URL for a Host that is not allowlisted', function () {
    config(['app.public_origins' => 'https://alias.example,http://plain.example,https://pathed.example/sub']);

    foreach (['https://evil.example', 'http://plain.example', 'https://pathed.example'] as $origin) {
        $this->getJson($origin.'/_public-origin-probe')->assertOk()
            ->assertJsonPath('url', $this->canonical.'/x')
            ->assertJsonPath('app', $this->canonical);
        $this->get($origin.'/admin/dashboard')->assertRedirect($this->canonical.'/admin/login');
    }
});

it('changes nothing when APP_PUBLIC_ORIGINS is unset', function () {
    expect(config('app.public_origins'))->toBeNull();

    $this->getJson('https://alias.example/_public-origin-probe')->assertOk()
        ->assertJsonPath('url', $this->canonical.'/x')
        ->assertJsonPath('route', $this->canonical.'/admin/login');
});

it('keeps console and queue work outside a request on APP_URL', function () {
    config(['app.public_origins' => 'https://alias.example']);

    expect(url('/x'))->toBe($this->canonical.'/x');
    dispatch(function (): void {
        cache()->put('public-origin-job-url', url('/x'));
    });
    expect(cache()->get('public-origin-job-url'))->toBe($this->canonical.'/x');
    $this->artisan('tinker', ['--execute' => 'echo url("/x");'])->expectsOutputToContain($this->canonical.'/x');
});

it('parses and matches origins by host and non-default port only', function () {
    $origins = PublicOrigins::list(canonical: 'https://space.example/', extra: 'https://a.example, https://b.example:8443,http://c.example,https://d.example/p,,https://a.example');

    expect($origins)->toBe(['https://space.example', 'https://a.example', 'https://b.example:8443']);
    $request = fn (string $url) => Request::create($url);
    expect(PublicOrigins::match(request: $request('http://a.example/x'), origins: $origins))->toBe('https://a.example')
        ->and(PublicOrigins::match(request: $request('https://b.example:8443/'), origins: $origins))->toBe('https://b.example:8443')
        ->and(PublicOrigins::match(request: $request('https://b.example/'), origins: $origins))->toBeNull()
        ->and(PublicOrigins::match(request: $request('https://A.EXAMPLE.evil/'), origins: $origins))->toBeNull();
});
