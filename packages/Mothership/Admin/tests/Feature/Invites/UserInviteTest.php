<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mothership\Admin\Mail\Admin\InviteNotification;
use Mothership\Admin\Mail\Admin\ResetPasswordNotification;
use Mothership\Admin\Services\UserInvites;
use Mothership\Core\MailLinks;
use Mothership\Core\Models\CoreConfig;
use Mothership\Core\RequestMemo;
use Mothership\Core\Rules\MailLinkBaseUrl;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

function inviteRole(): Role
{
    return Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
}

/** @return array{0: Admin, 1: string} a pending invitee and its live code */
function pendingInvitee(string $email = 'newcomer@example.test'): array
{
    $admin = Admin::factory()->create([
        'email'    => $email,
        'status'   => 0,
        'role_id'  => inviteRole()->id,
        'password' => Hash::make(Str::random(40)),
    ]);

    return [$admin, app(UserInvites::class)->issue($admin)];
}

function acceptInvite(string $token, string $email, array $overrides = [])
{
    $password = 'Chosen-pass-'.Str::random(6);

    return test()->postJson(route('admin.invite.accept.store'), array_merge([
        'token'                 => $token,
        'email'                 => $email,
        'name'                  => 'New Person',
        'password'              => $password,
        'password_confirmation' => $password,
    ], $overrides));
}

it('invites by email: inactive accounts, queued mail, skipped and invalid listed', function () {
    Notification::fake();
    $this->loginAsAdmin();
    $existing = Admin::factory()->create(['email' => 'already@example.test']);
    $role = inviteRole();

    $response = $this->postJson(route('admin.settings.users.invite'), [
        'emails'  => "one@example.test, Two@Example.test\nalready@example.test not-an-email",
        'role_id' => $role->id,
    ])->assertOk();

    expect($response->json('invited'))->toBe(['one@example.test', 'two@example.test'])
        ->and($response->json('skipped'))->toBe(['already@example.test'])
        ->and($response->json('invalid'))->toBe(['not-an-email']);

    $one = Admin::query()->where('email', 'one@example.test')->sole();
    expect((bool) $one->status)->toBeFalse()
        ->and((int) $one->role_id)->toBe($role->id)
        ->and($one->name)->toBe('One')
        ->and(Hash::info($one->password)['algo'])->not->toBeNull()
        ->and(app(UserInvites::class)->isPending($one))->toBeTrue()
        ->and(DB::table('admin_invites')->count())->toBe(2)
        ->and(Admin::query()->whereKey($existing->id)->value('status'))->not->toBe(0);

    Notification::assertSentTo($one, InviteNotification::class);
    Notification::assertCount(2);
    expect(new InviteNotification('t'))->toBeInstanceOf(ShouldQueue::class);
});

it('refuses more than 50 addresses in one submission', function () {
    Notification::fake();
    $this->loginAsAdmin();
    $emails = collect(range(1, 51))->map(fn ($i) => "p{$i}@example.test")->implode(' ');

    $this->postJson(route('admin.settings.users.invite'), ['emails' => $emails, 'role_id' => inviteRole()->id])
        ->assertStatus(422)->assertJsonValidationErrors(['emails']);

    Notification::assertNothingSent();
});

it('refuses a role the inviting admin outranks', function () {
    Notification::fake();
    $this->loginWithPermissions('custom', ['dashboard', 'settings.users.users.create']);
    $powerRole = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard', 'settings.roles']]);

    $this->postJson(route('admin.settings.users.invite'), ['emails' => 'x@example.test', 'role_id' => $powerRole->id])
        ->assertForbidden();

    expect(Admin::query()->where('email', 'x@example.test')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('refuses an admin without the create permission', function () {
    $this->loginWithPermissions('custom', ['dashboard']);

    $this->postJson(route('admin.settings.users.invite'), ['emails' => 'x@example.test', 'role_id' => inviteRole()->id])
        ->assertForbidden();
});

it('accepts an invite: activates the account, consumes the token and signs the person in', function () {
    [$admin, $token] = pendingInvitee();

    acceptInvite($token, $admin->email, ['password' => 'Chosen-pass-1', 'password_confirmation' => 'Chosen-pass-1'])->assertOk();

    $admin->refresh();
    expect((bool) $admin->status)->toBeTrue()
        ->and($admin->name)->toBe('New Person')
        ->and(Hash::check('Chosen-pass-1', $admin->password))->toBeTrue()
        ->and(DB::table('admin_invites')->where('email', $admin->email)->exists())->toBeFalse();
    $this->assertAuthenticatedAs($admin, 'admin');
});

it('answers every bad code with one generic message', function () {
    [$admin, $token] = pendingInvitee();
    [$other] = pendingInvitee('other@example.test');
    $generic = trans('admin::app.invites.invalid-code');

    // wrong email for the token
    acceptInvite($token, $other->email)->assertStatus(422)->assertJsonPath('errors.email.0', $generic);
    // unknown address, unknown token
    acceptInvite($token, 'nobody@example.test')->assertStatus(422)->assertJsonPath('errors.email.0', $generic);
    acceptInvite('not-a-token', $admin->email)->assertStatus(422)->assertJsonPath('errors.email.0', $generic);

    // reused
    acceptInvite($token, $admin->email)->assertOk();
    auth('admin')->logout();
    acceptInvite($token, $admin->email)->assertStatus(422)->assertJsonPath('errors.email.0', $generic);

    // an ordinary active account is never a pending invite
    $active = Admin::factory()->create(['email' => 'active@example.test', 'status' => 1]);
    $resetToken = Password::broker('admins')->createToken($active);
    acceptInvite($resetToken, $active->email)->assertStatus(422)->assertJsonPath('errors.email.0', $generic);
});

it('refuses an expired code, and a password-reset code does not open an invite', function () {
    [$admin, $token] = pendingInvitee();
    $resetToken = Password::broker('admins')->createToken($admin);

    acceptInvite($resetToken, $admin->email)->assertStatus(422);

    $this->travel(7)->days();
    $this->travel(1)->minutes();

    acceptInvite($token, $admin->email)->assertStatus(422)
        ->assertJsonPath('errors.email.0', trans('admin::app.invites.invalid-code'));
    expect((bool) $admin->refresh()->status)->toBeFalse();
});

it('resend issues a new code and the old one stops working', function () {
    Notification::fake();
    $this->loginAsAdmin();
    [$admin, $oldToken] = pendingInvitee();

    $this->postJson(route('admin.settings.users.invite.resend', $admin->id))->assertOk();

    Notification::assertSentTo($admin, InviteNotification::class, function (InviteNotification $n) use ($oldToken) {
        return $n->token !== $oldToken;
    });
    acceptInvite($oldToken, $admin->email)->assertStatus(422);
    expect((bool) $admin->refresh()->status)->toBeFalse();

    $newToken = collect(Notification::sent($admin, InviteNotification::class))->sole()->token;
    acceptInvite($newToken, $admin->email)->assertOk();
});

it('does not resend to an account that is not a pending invite', function () {
    $this->loginAsAdmin();
    $active = Admin::factory()->create(['status' => 1]);

    $this->postJson(route('admin.settings.users.invite.resend', $active->id))->assertNotFound();
});

it('keeps an invitee from signing in before accepting, and deleting them removes the token', function () {
    [$admin] = pendingInvitee();
    // Even holding a password that matches, an account that has not accepted is refused.
    $admin->forceFill(['password' => Hash::make('guessed-pass-123')])->save();

    $this->postJson(route('admin.session.store'), ['email' => $admin->email, 'password' => 'guessed-pass-123'])->assertForbidden();
    $this->assertGuest('admin');

    $admin->delete();
    expect(DB::table('admin_invites')->where('email', $admin->email)->exists())->toBeFalse();
});

it('records invite sent, resent and accepted as security events', function () {
    Notification::fake();
    $actor = $this->loginAsAdmin();

    $this->postJson(route('admin.settings.users.invite'), ['emails' => 'ev@example.test', 'role_id' => inviteRole()->id])->assertOk();
    $admin = Admin::query()->where('email', 'ev@example.test')->sole();
    $this->postJson(route('admin.settings.users.invite.resend', $admin->id))->assertOk();
    $token = collect(Notification::sent($admin, InviteNotification::class))->last()->token;
    acceptInvite($token, $admin->email)->assertOk();

    foreach ([SecurityEvent::INVITE_SENT => $actor->id, SecurityEvent::INVITE_RESENT => $actor->id, SecurityEvent::INVITE_ACCEPTED => $admin->id] as $event => $actorId) {
        $row = DB::table(SecurityEventRecorder::TABLE)->where('event', $event)->sole();
        expect((int) $row->actor_admin_id)->toBe((int) $actorId);
    }
});

it('names the mail link base URL host in invite and reset mail when set, and the app host when empty', function () {
    $admin = Admin::factory()->create(['email' => 'link@example.test']);
    config(['app.url' => 'https://canonical.example.test']);

    expect(MailLinks::host())->toBe('canonical.example.test')
        ->and((string) (new InviteNotification('12345678'))->toMail($admin)->render())->toContain('Open canonical.example.test in');

    config(['app.public_origins' => 'https://links.example.test']);
    CoreConfig::query()->updateOrCreate(
        ['code' => MailLinks::CONFIG_KEY, 'channel_code' => null, 'locale_code' => null],
        ['value' => 'https://links.example.test'],
    );
    app(RequestMemo::class)->forget('core_config.');

    $invite = (string) (new InviteNotification('12345678'))->toMail($admin)->render();
    $reset = (string) (new ResetPasswordNotification('12345678'))->toMail($admin)->render();

    expect(MailLinks::host())->toBe('links.example.test')
        ->and($invite)->toContain('Open links.example.test in')->toContain('1234 5678')->not->toContain('canonical.example.test')
        ->and($reset)->toContain('open links.example.test,')
        ->and($invite)->toContain('expires in 7 days');
});

it('accepts a mail link base URL only when it is a configured origin', function (string $value, bool $ok) {
    config([
        'app.url'            => 'https://app.example.test',
        'app.public_origins' => 'https://alias.example.test',
        'app.trusted_hosts'  => '^pattern\\.example\\.test$',
    ]);

    $passes = Validator::make(['v' => $value], ['v' => ['nullable', new MailLinkBaseUrl]])->passes();

    expect($passes)->toBe($ok);
})->with([
    'app origin'                   => ['https://app.example.test', true],
    'public origin'                => ['https://alias.example.test', true],
    'public origin trailing /'     => ['https://alias.example.test/', true],
    'public origin upper case'     => ['https://ALIAS.Example.Test', true],
    'empty'                        => ['', true],
    'unlisted subdomain of app'    => ['https://sub.app.example.test', false],
    'unlisted host'                => ['https://evil.example.net', false],
    'only a trusted-hosts pattern' => ['https://pattern.example.test', false],
    'http'                         => ['http://app.example.test', false],
    'with path'                    => ['https://app.example.test/x', false],
    'with credentials'             => ['https://u:p@app.example.test', false],
    'with port'                    => ['https://app.example.test:8443', false],
]);

it('accepts a host listed in the public origins even when trusted hosts holds only anchored patterns', function () {
    config(['app.url' => 'https://app.example.test', 'app.public_origins' => 'https://links.example.test', 'app.trusted_hosts' => '^links\\.example\\.test$']);

    expect(Validator::make(['v' => 'https://links.example.test'], ['v' => [new MailLinkBaseUrl]])->passes())->toBeTrue();
});

it('wires the mail link base URL rule into the Email settings group', function () {
    $group = collect(config('core'))->firstWhere('key', 'emails.configure.email_settings');
    $field = collect($group['fields'])->firstWhere('name', 'mail_link_base_url');

    expect($field['validation'])->toContain(MailLinkBaseUrl::class);
});

it('marks pending invites as Invited in the Users list and offers resend only for them', function () {
    $this->loginAsAdmin();
    [$pending] = pendingInvitee('listed@example.test');

    $records = collect($this->getJson(route('admin.settings.users.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json('records'));
    $row = $records->firstWhere('email', 'listed@example.test');

    expect($row['status'])->toContain(trans('admin::app.invites.status'))
        ->and(collect($row['actions'])->pluck('index')->all())->toContain('resend');
    expect(collect($records->firstWhere('email', '!=', 'listed@example.test')['actions'])->pluck('index')->all())->not->toContain('resend');
});

it('flags only the pending invitee in the Users datagrid, and filtering and sorting by email still work', function () {
    $this->loginAsAdmin();
    [$pending] = pendingInvitee('pending-flag@example.test');
    $active = Admin::factory()->create(['email' => 'active-flag@example.test', 'status' => 1]);
    $headers = ['X-Requested-With' => 'XMLHttpRequest'];

    $records = collect($this->getJson(route('admin.settings.users.index'), $headers)->assertOk()->json('records'));
    $invited = trans('admin::app.invites.status');

    expect($records->firstWhere('email', $pending->email)['status'])->toContain($invited)
        ->and($records->firstWhere('email', $active->email)['status'])->not->toContain($invited)
        ->and(collect($records->firstWhere('email', $active->email)['actions'])->pluck('index')->all())->not->toContain('resend')
        ->and(collect($records->firstWhere('email', $pending->email)['actions'])->pluck('index')->all())->toContain('resend');

    $filtered = $this->getJson(route('admin.settings.users.index').'?'.http_build_query([
        'filters' => ['email' => ['pending-flag']],
        'sort'    => ['column' => 'email', 'order' => 'asc'],
    ]), $headers)->assertOk()->json('records');

    expect(collect($filtered)->pluck('email')->all())->toBe([$pending->email]);
});

it('drops the invite row when a pending invitee is activated or re-addressed by an edit', function () {
    [$activated] = pendingInvitee('edit-a@example.test');
    [$moved] = pendingInvitee('edit-b@example.test');

    $activated->forceFill(['status' => 1])->save();
    $moved->forceFill(['email' => 'edit-b2@example.test'])->save();

    expect(DB::table('admin_invites')->whereIn('email', ['edit-a@example.test', 'edit-b@example.test', 'edit-b2@example.test'])->count())->toBe(0);
});

it('takes the same minimum time to refuse an unknown, active, non-invited and pending address', function () {
    [$pending] = pendingInvitee('timed-pending@example.test');
    Admin::factory()->create(['email' => 'timed-active@example.test', 'status' => 1]);
    Admin::factory()->create(['email' => 'timed-inactive@example.test', 'status' => 0]);

    foreach (['timed-unknown@example.test', 'timed-active@example.test', 'timed-inactive@example.test', $pending->email] as $email) {
        $start = microtime(true);
        acceptInvite('invalid-token', $email)->assertStatus(422);

        // Lower bound only: the 500 ms timebox, with a little slack for clock granularity.
        expect(microtime(true) - $start)->toBeGreaterThanOrEqual(0.49);
    }
});

/**
 * Binds a UserInvites whose verify() runs $after once the token has been verified and before the account lock:
 * the seam where a competing change lands in a real race.
 */
function afterVerify(Closure $after): void
{
    app()->bind(UserInvites::class, fn () => new class($after) extends UserInvites
    {
        public function __construct(private readonly Closure $after) {}

        public function verify(string $email, string $plainToken): ?object
        {
            $row = parent::verify($email, $plainToken);

            if ($row) {
                ($this->after)();
            }

            return $row;
        }
    });
}

it('refuses a redemption when a resend lands between the token check and the lock', function () {
    [$admin, $oldToken] = pendingInvitee('race-resend@example.test');
    $newToken = null;
    afterVerify(function () use ($admin, &$newToken) {
        $newToken = (new UserInvites)->issue($admin);
    });

    acceptInvite($oldToken, $admin->email, ['password' => 'Chosen-pass-1', 'password_confirmation' => 'Chosen-pass-1'])
        ->assertStatus(422)->assertJsonPath('errors.email.0', trans('admin::app.invites.invalid-code'));
    expect((bool) $admin->refresh()->status)->toBeFalse();

    app()->bind(UserInvites::class, fn () => new UserInvites);
    acceptInvite($newToken, $admin->email)->assertOk();
    expect((bool) $admin->refresh()->status)->toBeTrue();
});

it('refuses a redemption when the address is changed between the token check and the lock', function () {
    [$admin, $token] = pendingInvitee('race-email@example.test');
    afterVerify(fn () => $admin->forceFill(['email' => 'race-email-new@example.test'])->save());

    acceptInvite($token, 'race-email@example.test')->assertStatus(422)
        ->assertJsonPath('errors.email.0', trans('admin::app.invites.invalid-code'));
    expect((bool) $admin->refresh()->status)->toBeFalse()->and($admin->name)->not->toBe('New Person');
});

it('refuses a redemption when an admin activates the account between the token check and the lock', function () {
    [$admin, $token] = pendingInvitee('race-active@example.test');
    $originalHash = $admin->password;
    afterVerify(fn () => $admin->forceFill(['status' => 1])->save());

    acceptInvite($token, $admin->email, ['password' => 'Chosen-pass-1', 'password_confirmation' => 'Chosen-pass-1'])
        ->assertStatus(422)->assertJsonPath('errors.email.0', trans('admin::app.invites.invalid-code'));
    expect($admin->refresh()->password)->toBe($originalHash);
});

it('refuses a redemption when the invite expires between the token check and the lock', function () {
    [$admin, $token] = pendingInvitee('race-expiry@example.test');
    $originalHash = $admin->password;
    afterVerify(fn () => Carbon::setTestNow(now()->addMinutes((int) config('auth.passwords.admin_invites.expire') + 1)));

    try {
        acceptInvite($token, $admin->email, ['password' => 'Chosen-pass-1', 'password_confirmation' => 'Chosen-pass-1'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', trans('admin::app.invites.invalid-code'));
    } finally {
        Carbon::setTestNow();
    }

    expect((bool) $admin->refresh()->status)->toBeFalse()
        ->and($admin->password)->toBe($originalHash)
        ->and(DB::table('admin_invites')->where('email', $admin->email)->exists())->toBeTrue();
});
