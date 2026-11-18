<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Mothership\Admin\Auth\CodeTokenRepository;
use Mothership\Admin\Mail\Admin\InviteNotification;
use Mothership\Admin\Services\UserInvites;
use Mothership\Core\MailLinks;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

function codeUser(string $email, int $status = 1): Admin
{
    return Admin::factory()->create([
        'email'    => $email,
        'status'   => $status,
        'role_id'  => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id,
        'password' => Hash::make(Str::random(40)),
    ]);
}

function resetWith(string $code, string $email)
{
    return test()->postJson(route('admin.reset_password.store'), [
        'token'                 => $code,
        'email'                 => $email,
        'password'              => 'New-password-123',
        'password_confirmation' => 'New-password-123',
    ]);
}

function wrongCode(string $not): string
{
    return $not === '00000000' ? '11111111' : '00000000';
}

it('issues 8-digit codes through both brokers and stores only a hash and a zero attempt count', function (string $broker, string $table) {
    $admin = codeUser($broker.'@example.test', status: 0);

    $code = Password::broker($broker)->createToken($admin);
    $row = DB::table($table)->where('email', $admin->email)->sole();

    expect($code)->toMatch('/^\d{8}$/')
        ->and($row->token)->not->toBe($code)
        ->and(Hash::check($code, $row->token))->toBeTrue()
        ->and((int) $row->attempts)->toBe(0)
        ->and(CodeTokenRepository::display('00123456'))->toBe('0012 3456')
        ->and(CodeTokenRepository::normalize(" 1234-5678\n"))->toBe('12345678');
})->with([['admins', 'admin_password_resets'], ['admin_invites', 'admin_invites']]);

it('sends invite and reset mail as one text part with no link, image or HTML', function () {
    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    $admin = codeUser('textonly@example.test');

    Notification::sendNow($admin, new InviteNotification('12345678'));
    $admin->sendPasswordResetNotification('87654321');

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(2);

    foreach ($messages as $sent) {
        $email = $sent->getOriginalMessage();
        $text = (string) $email->getTextBody();

        expect($email->getHtmlBody())->toBeNull()
            ->and($email->getAttachments())->toBe([])
            ->and($text)->toContain(MailLinks::host())->toMatch('/\d{4} \d{4}/')
            ->and($text)->not->toContain('http')->not->toContain('<a')->not->toContain('<img')->not->toContain('&');
    }

    expect($messages[0]->getOriginalMessage()->getSubject())->toBe('Your '.config('documentation.name').' invitation code')
        ->and((string) $messages[0]->getOriginalMessage()->getTextBody())->toContain('1234 5678')->toContain('expires in 7 days');
});

it('locks a reset code after 5 wrong tries, even for the right code, and a new request starts a fresh count', function () {
    $admin = codeUser('capreset@example.test');
    $code = Password::broker('admins')->createToken($admin);

    foreach (range(1, CodeTokenRepository::MAX_ATTEMPTS) as $try) {
        resetWith(wrongCode($code), $admin->email)->assertStatus(422);
    }

    resetWith($code, $admin->email)->assertStatus(422);
    expect((int) DB::table('admin_password_resets')->value('attempts'))->toBe(CodeTokenRepository::MAX_ATTEMPTS);

    $fresh = Password::broker('admins')->createToken($admin);
    expect((int) DB::table('admin_password_resets')->value('attempts'))->toBe(0);
    resetWith($fresh, $admin->email)->assertOk();
});

it('locks an invite code after 5 wrong tries, and a resend yields a working code', function () {
    $admin = codeUser('capinvite@example.test', status: 0);
    $invites = app(UserInvites::class);
    $code = $invites->issue($admin);
    $accept = fn (string $c) => $this->postJson(route('admin.invite.accept.store'), [
        'token'    => $c, 'email' => $admin->email, 'name' => 'New Person',
        'password' => 'Chosen-pass-1', 'password_confirmation' => 'Chosen-pass-1',
    ]);

    foreach (range(1, CodeTokenRepository::MAX_ATTEMPTS) as $try) {
        $accept(wrongCode($code))->assertStatus(422);
    }

    $accept($code)->assertStatus(422);
    expect((bool) $admin->refresh()->status)->toBeFalse();

    $accept($invites->issue($admin))->assertOk();
    expect((bool) $admin->refresh()->status)->toBeTrue();
});

it('spends at most one attempt per hash check, atomically', function () {
    $admin = codeUser('atomic@example.test');
    $repository = Password::broker('admins');
    $code = $repository->createToken($admin);
    $hash = DB::table('admin_password_resets')->value('token');
    $tokens = new CodeTokenRepository(DB::connection(), app('hash'), 'admin_password_resets', 'k');

    $spent = collect(range(1, 8))->filter(fn () => $tokens->spendAttempt(email: $admin->email, storedHash: $hash))->count();

    expect($spent)->toBe(CodeTokenRepository::MAX_ATTEMPTS)
        ->and($tokens->exists($admin, $code))->toBeFalse();
});

it('ignores spaces and hyphens in a typed code', function (string $format) {
    $admin = codeUser('format@example.test');
    $code = Password::broker('admins')->createToken($admin);

    resetWith(sprintf($format, substr($code, 0, 4), substr($code, 4)), $admin->email)->assertOk();
})->with(['plain' => ['%s%s'], 'space' => ['%s %s'], 'hyphen' => ['%s-%s'], 'padded' => [' %s  %s ']]);

it('renders the invite and reset code forms and sends old token addresses to them', function () {
    $this->get(route('admin.invite.accept.create'))->assertOk()
        ->assertSee('autocomplete="one-time-code"', false)->assertSee('inputmode="numeric"', false)
        ->assertSee(trans('admin::app.invites.accept.code'));
    $this->get(route('admin.reset_password.create'))->assertOk()
        ->assertSee('autocomplete="one-time-code"', false)->assertSee(trans('admin::app.users.reset-password.code'));

    $this->get('/admin/invite/12345678')->assertRedirect(route('admin.invite.accept.create'));
    $this->get('/admin/reset-password/12345678')->assertRedirect(route('admin.reset_password.create'));
});

it('links the code forms from the sign-in and forgot-password pages', function () {
    $this->get(route('admin.session.create'))->assertOk()
        ->assertSee(route('admin.invite.accept.create'), false)->assertSee(trans('admin::app.users.sessions.invite-code-link'));
    $this->get(route('admin.forget_password.create'))->assertOk()
        ->assertSee(route('admin.reset_password.create'), false)->assertSee(trans('admin::app.users.forget-password.create.have-code-link'));
});

it('answers forgot-password alike for a known and an unknown address and carries the email in the session', function () {
    Mail::fake();
    codeUser('known@example.test');
    $answers = [];

    foreach (['known@example.test', 'unknown@example.test', 'known@example.test'] as $email) {
        $response = $this->postJson(route('admin.forget_password.store'), ['email' => $email])->assertOk();
        $answers[] = $response->json();
        $response->assertSessionHas('reset_email', $email);
    }

    expect($answers[0])->toBe($answers[1])->toBe($answers[2])
        ->and($answers[0]['redirect_url'])->toBe(route('admin.reset_password.create'))
        ->and($answers[0]['redirect_url'])->not->toContain('example.test');

    $this->withSession(['reset_email' => 'known@example.test'])->get(route('admin.reset_password.create'))
        ->assertSee('known@example.test');
});

it('consumes a reset code on first use: the same code through the broker resets once', function () {
    $admin = codeUser('once@example.test');
    $code = Password::broker('admins')->createToken($admin);
    $credentials = ['email' => $admin->email, 'token' => $code, 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'];

    $first = Password::broker('admins')->reset($credentials, fn () => null);
    $second = Password::broker('admins')->reset($credentials, fn () => null);

    expect($first)->toBe(Password::PASSWORD_RESET)->and($second)->toBe(Password::INVALID_TOKEN)
        ->and(DB::table('admin_password_resets')->where('email', $admin->email)->exists())->toBeFalse();
});

it('refuses an older code once a new one replaced it, and the new one works', function () {
    $admin = codeUser('replaced@example.test');
    $old = Password::broker('admins')->createToken($admin);
    $new = Password::broker('admins')->createToken($admin);

    resetWith($old, $admin->email)->assertStatus(422);
    resetWith($new, $admin->email)->assertOk();
});

it('refuses an expired reset code without consuming it or spending a try', function () {
    $admin = codeUser('expired@example.test');
    $code = Password::broker('admins')->createToken($admin);

    $this->travel((int) config('auth.passwords.admins.expire') + 1)->minutes();
    resetWith($code, $admin->email)->assertStatus(422);

    $row = DB::table('admin_password_resets')->where('email', $admin->email)->first();
    expect($row)->not->toBeNull()->and((int) $row->attempts)->toBe(0);
});

it('redeems a code with a leading zero', function () {
    $admin = codeUser('zero@example.test');
    DB::table('admin_password_resets')->insert([
        'email' => $admin->email, 'token' => Hash::make('00123456'), 'created_at' => now(), 'attempts' => 0,
    ]);

    resetWith('0012 3456', $admin->email)->assertOk();
});

it('leaves attempts at 0 for input that is not 8 digits, for resets and invites', function () {
    $user = codeUser('shape@example.test');
    Password::broker('admins')->createToken($user);
    resetWith('1234', $user->email)->assertStatus(422);
    resetWith('abcd efgh', $user->email)->assertStatus(422);

    $invitee = codeUser('shape-invite@example.test', status: 0);
    app(UserInvites::class)->issue($invitee);
    $this->postJson(route('admin.invite.accept.store'), [
        'token'    => '12', 'email' => $invitee->email, 'name' => 'New Person',
        'password' => 'Chosen-pass-1', 'password_confirmation' => 'Chosen-pass-1',
    ])->assertStatus(422);

    expect((int) DB::table('admin_password_resets')->where('email', $user->email)->value('attempts'))->toBe(0)
        ->and((int) DB::table('admin_invites')->where('email', $invitee->email)->value('attempts'))->toBe(0);
});

it('keeps the reset code usable when the password write throws', function () {
    $admin = codeUser('throws@example.test');
    $code = Password::broker('admins')->createToken($admin);
    Event::listen(PasswordReset::class, fn () => throw new RuntimeException('write failed'));

    resetWith($code, $admin->email)->assertStatus(500);

    $row = DB::table('admin_password_resets')->where('email', $admin->email)->first();
    expect($row)->not->toBeNull()->and((int) $row->attempts)->toBe(0);

    Event::forget(PasswordReset::class);
    resetWith($code, $admin->email)->assertOk();
});

it('lets only one of two overlapping resets with the same code write a password', function () {
    $admin = codeUser('overlap@example.test');
    $code = Password::broker('admins')->createToken($admin);
    $credentials = ['email' => $admin->email, 'token' => $code, 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'];
    $writes = 0;
    $innerRan = false;
    $inner = null;

    // The window between the framework's code check and its delete: a second request arrives with the same code.
    $outer = Password::broker('admins')->reset($credentials, function () use (&$writes, &$innerRan, &$inner, $credentials) {
        $writes++;
        $inner = Password::broker('admins')->reset($credentials, function () use (&$writes, &$innerRan) {
            $innerRan = true;
            $writes++;
        });
    });

    expect($outer)->toBe(Password::PASSWORD_RESET)
        ->and($inner)->toBe(Password::INVALID_TOKEN)
        ->and($innerRan)->toBeFalse()
        ->and($writes)->toBe(1);
});
