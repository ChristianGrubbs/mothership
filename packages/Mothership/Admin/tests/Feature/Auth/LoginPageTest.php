<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Mothership\User\Models\Admin;

it('renders the login page with remember-me, subtitle and autofill attributes', function () {
    $response = $this->get(route('admin.session.create'));

    $response->assertOk();
    $response->assertSee('name="remember"', false);
    $response->assertSee(trans('admin::app.users.sessions.remember-me'));
    $response->assertSee(trans('admin::app.users.sessions.subtitle', ['name' => config('documentation.name')]));
    $response->assertSee('autocomplete="username"', false);
    $response->assertSee('autocomplete="current-password"', false);
});

it('renders the login in light mode when the dark_mode cookie is light (does not force dark)', function () {
    $this->withUnencryptedCookie('dark_mode', 'light')
        ->get(route('admin.session.create'))
        ->assertOk()
        ->assertDontSee('class="dark"', false);
});

it('renders the login in dark mode when the dark_mode cookie is dark', function () {
    $this->withUnencryptedCookie('dark_mode', 'dark')
        ->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee('class="dark"', false);
});

it('includes both the light and dark default logos so they swap with the theme', function () {
    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee('dark_logo', false)
        ->assertSee('dark:hidden', false);
});

it('renders the Mothership lockup in the login logo slot', function () {
    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee('data-mothership-logo', false)
        ->assertSee('title="The Mothership"', false)
        ->assertSee('alt="The Mothership"', false)
        ->assertDontSee('alt="Mothership"', false);
});

it('keeps the decorative hero panel beside the sign-in form inert, with no photo or video', function () {
    $html = $this->get(route('admin.session.create'))->assertOk()->getContent();

    expect($html)
        ->toMatch('/<div[^>]*aria-hidden="true"[^>]*\binert\b[^>]*data-login-backdrop/s')
        ->not->toContain('<video')
        ->not->toContain('login-poster')
        ->not->toContain('login-loop');

    // The backdrop sits outside the Vue root so mounting never recreates it.
    expect(strpos($html, 'data-login-backdrop'))->toBeLessThan(strpos($html, 'id="app"'));
});

it('keeps the other anonymous pages free of the login backdrop', function () {
    $this->get(route('admin.forget_password.create'))
        ->assertOk()
        ->assertDontSee('data-login-backdrop', false);
});

it('hides the Google sign-in option when Google is not configured', function () {
    config()->set('services.google.enabled', false);

    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertDontSee(route('admin.session.sso.redirect', ['provider' => 'google']));
});

it('shows the Google sign-in option when Google is configured', function () {
    config()->set('services.google.enabled', true);
    config()->set('services.google.client_id', 'client-id');
    config()->set('services.google.client_secret', 'client-secret');

    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee(route('admin.session.sso.redirect', ['provider' => 'google']), false)
        ->assertSeeText('Sign in with Google');
});

it('keeps the admin logged in across the remember cookie when remember is checked', function () {
    $admin = Admin::factory()->create([
        'email'    => 'remember@example.com',
        'password' => Hash::make('password-123'),
        'status'   => 1,
    ]);

    $response = $this->post(route('admin.session.store'), [
        'email'    => 'remember@example.com',
        'password' => 'password-123',
        'remember' => '1',
    ]);

    $this->assertAuthenticatedAs($admin, 'admin');
    $response->assertCookie(auth()->guard('admin')->getRecallerName());
});

it('returns the friendly throttle message as json on too many login attempts', function () {
    // The styled 429 body is produced by the exception handler, which only
    // registers its renderable when debug is off (production behaviour).
    config(['app.debug' => false]);

    // The throttle is cache-backed; clear it so prior tests can't trip it early.
    Cache::flush();

    $response = null;

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $response = $this->postJson(route('admin.session.store'), [
            'email'    => 'flood@example.com',
            'password' => 'wrong-'.$attempt,
        ]);
    }

    $response->assertStatus(429);
    $response->assertJson([
        'error'       => trans('admin::app.errors.429.title'),
        'description' => trans('admin::app.errors.429.description'),
    ]);
});
