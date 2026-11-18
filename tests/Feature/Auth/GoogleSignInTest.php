<?php

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mothership\Admin\Sso\SsoManager;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminAccessRequest;
use Mothership\User\Models\AdminIdentity;

/** Switch the Google provider on with placeholder credentials and answer its callback with this account. */
function fakeGoogleAccount(string $subject, string $email, bool $verified = true): void
{
    config()->set('services.google', [
        'enabled'       => true,
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'redirect'      => null,
    ]);

    app()->forgetInstance(SsoManager::class);

    Socialite::fake('google', (new SocialiteUser)->map([
        'id'    => $subject,
        'email' => $email,
        'name'  => 'Google Person',
    ])->setRaw(['sub' => $subject, 'email' => $email, 'email_verified' => $verified]));
}

function googleCallback($test)
{
    return $test->get(route('admin.session.sso.callback', ['provider' => 'google', 'state' => 's', 'code' => 'c']));
}

beforeEach(function () {
    config()->set('admin.auth.access_log', false);
});

it('sends the visitor to Google from the sign-in button', function () {
    fakeGoogleAccount(subject: 'g-1', email: 'person@example.test');

    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee(route('admin.session.sso.redirect', ['provider' => 'google']), false);

    $this->get(route('admin.session.sso.redirect', ['provider' => 'google']))
        ->assertRedirect('https://socialite.fake/google/authorize');
});

it('logs in the admin whose Google account is linked', function () {
    fakeGoogleAccount(subject: 'g-linked', email: 'linked@example.test');

    $admin = Admin::factory()->create(['email' => 'someone-else@example.test', 'status' => 1]);

    AdminIdentity::query()->create(['admin_id' => $admin->id, 'provider' => 'google', 'subject' => 'g-linked']);

    googleCallback($this)->assertRedirect();

    $this->assertAuthenticatedAs($admin, 'admin');

    expect(AdminIdentity::query()->sole()->last_used_at)->not->toBeNull()
        ->and(AdminAccessRequest::query()->count())->toBe(0);
});

it('never links by email: an unlinked account matching an admin address waits for access', function () {
    fakeGoogleAccount(subject: 'g-stranger', email: 'existing@example.test');

    Admin::factory()->create(['email' => 'existing@example.test', 'status' => 1]);

    googleCallback($this)->assertRedirect(route('admin.session.sso.pending'));

    $this->assertGuest('admin');

    $request = AdminAccessRequest::query()->sole();

    expect($request->provider)->toBe('google')
        ->and($request->subject)->toBe('g-stranger')
        ->and($request->email_verified)->toBeTrue()
        ->and(AdminIdentity::query()->count())->toBe(0);

    $this->get(route('admin.session.sso.pending'))
        ->assertOk()
        ->assertSee("data-access-request-id=\"{$request->id}\"", false)
        ->assertDontSee('existing@example.test');
});

it('shows a waiting person their request number and no grant command when password sign-in is off', function () {
    config()->set('admin.auth.password_login', false);

    fakeGoogleAccount(subject: 'g-waiting', email: 'waiting@example.test');

    googleCallback($this)->assertRedirect(route('admin.session.sso.pending'));

    $request = AdminAccessRequest::query()->sole();

    $this->get(route('admin.session.sso.pending'))
        ->assertOk()
        ->assertSee('Waiting for access')
        ->assertSee("aria-label=\"Request {$request->id}\"", false)
        ->assertSee('Send the request number above to the owner of this Mothership so they can approve it.')
        ->assertSee(route('admin.session.create'), false)
        ->assertDontSee('mothership:access', false)
        ->assertDontSee('artisan', false)
        ->assertDontSee('data-grant-command', false)
        ->assertDontSee('data-copy-command', false)
        ->assertDontSee('waiting@example.test');

    $this->get(route('admin.session.create'))
        ->assertOk()
        ->assertSee(route('admin.session.sso.redirect', ['provider' => 'google']), false);
});

it('keeps one request per Google account and refreshes it on a repeat sign-in', function () {
    fakeGoogleAccount(subject: 'g-repeat', email: 'repeat@example.test');

    googleCallback($this);
    googleCallback($this);

    expect(AdminAccessRequest::query()->count())->toBe(1);
});

it('caps open requests, dropping the oldest', function () {
    config()->set('admin.auth.access_requests.max_pending', 2);

    foreach (['a', 'b', 'c'] as $index => $suffix) {
        $this->travel($index)->minutes();

        fakeGoogleAccount(subject: "g-cap-{$suffix}", email: "{$suffix}@example.test");

        googleCallback($this);
    }

    expect(AdminAccessRequest::query()->orderBy('subject')->pluck('subject')->all())->toBe(['g-cap-b', 'g-cap-c']);
});

it('refuses an inactive linked admin', function () {
    fakeGoogleAccount(subject: 'g-off', email: 'off@example.test');

    $admin = Admin::factory()->create(['status' => 0]);

    AdminIdentity::query()->create(['admin_id' => $admin->id, 'provider' => 'google', 'subject' => 'g-off']);

    googleCallback($this)->assertRedirect(route('admin.session.create'));

    $this->assertGuest('admin');
});

it('sends the pending page back to sign-in without a request in the session', function () {
    $this->get(route('admin.session.sso.pending'))->assertRedirect(route('admin.session.create'));
});

it('shows the whole request number on the pending board', function (int $requestId) {
    $html = view('admin::users.sessions.pending', [
        'requestId' => $requestId,
        'ttlHours'  => 24,
    ])->render();

    preg_match('/<div class="bridge-frow">(.*?)<\/div>/s', $html, $row);
    preg_match_all('/<i>(.*?)<\/i>/s', $row[1], $cells);

    expect(implode('', $cells[1]))->toBe("REQUEST{$requestId}");
})->with([99, 100, 1000]);
