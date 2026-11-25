<?php

use Dom\HTMLDocument;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Mothership\Admin\Sso\SsoManager;
use Mothership\User\Models\Admin;

function enableGoogleButton(): void
{
    config()->set('services.google', [
        'enabled'       => true,
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect'      => null,
    ]);

    app()->forgetInstance(SsoManager::class);
}

it('shows the password form and Google button together by default', function () {
    enableGoogleButton();

    $page = $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee('name="password"', false)
        ->assertSee(route('admin.forget_password.create'), false)
        ->assertSee(route('admin.session.sso.redirect', ['provider' => 'google']), false);

    $document = HTMLDocument::createFromString($page->getContent(), LIBXML_NOERROR);
    expect($document->getElementById('password-signin'))->not->toBeNull()
        ->and($document->querySelector('input[name="remember"][checked][form="provider-signin"]'))->not->toBeNull();
});

it('hides the password form and its routes when password sign-in is off', function () {
    enableGoogleButton();
    config()->set('admin.auth.password_login', false);

    Admin::factory()->create(['email' => 'pw@example.test', 'password' => Hash::make('password-123'), 'status' => 1]);

    $page = $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertDontSee('name="password"', false)
        ->assertDontSee(route('admin.forget_password.create'), false)
        ->assertSee(route('admin.session.sso.redirect', ['provider' => 'google']), false);

    $document = HTMLDocument::createFromString($page->getContent(), LIBXML_NOERROR);
    expect($document->querySelector('input[name="remember"][checked][form="provider-signin"]'))->not->toBeNull()
        ->and($document->querySelector('form#provider-signin button[type="submit"]'))->not->toBeNull();

    $this->post(route('admin.session.store'), ['email' => 'pw@example.test', 'password' => 'password-123'])->assertNotFound();
    $this->assertGuest('admin');

    $this->get(route('admin.forget_password.create'))->assertNotFound();
    $this->get(route('admin.reset_password.create'))->assertNotFound();
    $this->get(route('admin.invite.accept.create'))->assertNotFound();
});

it('keeps password sign-in on while no provider is enabled', function () {
    config()->set('services.google.enabled', false);
    config()->set('admin.auth.password_login', false);
    app()->forgetInstance(SsoManager::class);

    Admin::factory()->create(['email' => 'pw@example.test', 'password' => Hash::make('password-123'), 'status' => 1]);

    $this->get(route('admin.session.create'))->assertOk()->assertSee('name="password"', false);

    $this->post(route('admin.session.store'), ['email' => 'pw@example.test', 'password' => 'password-123'])->assertRedirect();
    $this->assertAuthenticated('admin');
});

it('has no self-service sign-up', function () {
    $this->get('/'.config('app.admin_url').'/register')->assertNotFound();

    expect(Route::has('admin.register.create'))->toBeFalse();

    $this->get(route('admin.session.create'))->assertOk()->assertDontSee('/admin/register', false);
});
