<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Mothership\Admin\Sso\SsoManager;
use Mothership\Admin\Tests\Support\FakeSsoProvider;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Services\Security\SecurityReports;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminIdentity;

/** P1a: every auth outcome is one dam_security_events row, minimized at the source (§3.6.7). */
function securityEvents(string $event)
{
    return DB::table(SecurityEventRecorder::TABLE)->where('event', $event)->orderBy('id')->get();
}

/** An insert into the events table, however the connection quotes its name. */
function securityEventInsert(string $sql): bool
{
    return str_starts_with(strtolower(ltrim($sql)), 'insert') && str_contains($sql, SecurityEventRecorder::TABLE);
}

beforeEach(function () {
    $this->admin = Admin::factory()->create(['email' => 'staff.member@example.test', 'password' => Hash::make('right-password')]);
});

it('records a successful login with the actor, hashed identifier, session hash and request id', function () {
    $response = $this->withHeader('X-Request-Id', 'login-trace-0001')
        ->post(route('admin.session.store'), ['email' => 'staff.member@example.test', 'password' => 'right-password', 'remember' => '1'])
        ->assertRedirect();

    $row = securityEvents(SecurityEvent::LOGIN_SUCCEEDED)->sole();

    expect((int) $row->actor_admin_id)->toBe($this->admin->id)
        ->and($row->guard)->toBe('admin')
        ->and($row->identifier_hash)->toBe(hash_hmac('sha256', 'staff.member@example.test', (string) config('app.key')))
        ->and($row->request_id)->toBe('login-trace-0001')
        ->and($row->session_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->ip)->toBe('127.0.0.1')
        ->and($row->ip_truncated)->toBe('127.0.0.0')
        ->and(json_decode($row->ctx, true))->toBe(['method' => 'password', 'remember' => true]);

    $recaller = auth('admin')->getRecallerName();
    $cookie = $response->getCookie($recaller, decrypt: false);
    auth()->forgetGuards();
    session()->invalidate();
    $this->withCredentials()->withUnencryptedCookie($recaller, $cookie->getValue())
        ->getJson(route('admin.session.token'))->assertOk();
    expect(json_decode(securityEvents(SecurityEvent::LOGIN_SUCCEEDED)->last()->ctx, true))
        ->toBe(['method' => 'remember', 'remember' => true]);
});

it('records a failed login by HMAC alone: the typed email appears nowhere in the row', function () {
    $typed = 'staff.member@example.test';

    $this->post(route('admin.session.store'), ['email' => $typed, 'password' => 'wrong-password'])->assertRedirect();

    $row = securityEvents(SecurityEvent::LOGIN_FAILED)->sole();
    $serialized = strtolower(json_encode($row, JSON_THROW_ON_ERROR));

    expect($row->actor_admin_id)->toBeNull()
        ->and($row->identifier_hash)->toBe(hash_hmac('sha256', strtolower($typed), (string) config('app.key')))
        ->and($serialized)->not->toContain('staff.member')->not->toContain('example.test')->not->toContain('wrong-password')
        ->and(json_decode($row->ctx, true))->toBe(['method' => 'password', 'account_exists' => true]);
});

it('records a lockout when the login rate limit refuses an attempt', function () {
    config(['admin.auth.login_rate_limit' => 2]);

    foreach (range(1, 3) as $attempt) {
        $response = $this->post(route('admin.session.store'), ['email' => 'staff.member@example.test', 'password' => 'wrong-'.$attempt]);
    }

    $response->assertStatus(429);

    $row = securityEvents(SecurityEvent::LOGIN_LOCKED_OUT)->sole();

    expect($row->guard)->toBe('admin')
        ->and($row->identifier_hash)->toBe(hash_hmac('sha256', 'staff.member@example.test', (string) config('app.key')))
        ->and(securityEvents(SecurityEvent::LOGIN_FAILED))->toHaveCount(2);
});

it('records a logout with its actor', function () {
    $this->actingAs($this->admin, 'admin')->delete(route('admin.session.destroy'))->assertRedirect();

    expect((int) securityEvents(SecurityEvent::LOGOUT_SUCCEEDED)->sole()->actor_admin_id)->toBe($this->admin->id);
});

it('records a password reset with its actor', function () {
    $token = Password::broker('admins')->createToken($this->admin);

    $this->post(route('admin.reset_password.store'), [
        'token' => $token, 'email' => 'staff.member@example.test', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
    ]);

    $row = securityEvents(SecurityEvent::PASSWORD_RESET)->sole();

    expect((int) $row->actor_admin_id)->toBe($this->admin->id)
        ->and($row->identifier_hash)->toBe(hash_hmac('sha256', 'staff.member@example.test', (string) config('app.key')));
});

/** An SSO callback through the fake provider, for the admin its provider account is linked to. */
function securitySsoCallback($test)
{
    FakeSsoProvider::$enabled = true;
    FakeSsoProvider::$email = 'staff.member@example.test';
    AdminIdentity::query()->firstOrCreate(
        ['provider' => 'fake', 'subject' => 'fake-subject-staff.member@example.test'],
        ['admin_id' => $test->admin->id],
    );
    config()->set('sso.providers.fake', FakeSsoProvider::class);
    app()->forgetInstance(SsoManager::class);

    return $test->withSession(['sso_handshake' => ['fake' => ['state' => 's', 'verifier' => 'v', 'nonce' => 'n']]])
        ->get(route('admin.session.sso.callback', ['provider' => 'fake', 'state' => 's', 'code' => 'c']));
}

it('records SSO logins and refusals with their method and provider', function () {
    $callback = fn () => securitySsoCallback($this);

    $callback();
    $this->assertAuthenticatedAs($this->admin, 'admin');
    auth('admin')->logout();

    $this->admin->forceFill(['status' => 0])->save();
    $callback();

    expect(json_decode(securityEvents(SecurityEvent::LOGIN_SUCCEEDED)->sole()->ctx, true))->toMatchArray(['method' => 'sso', 'provider' => 'fake'])
        ->and(json_decode(securityEvents(SecurityEvent::LOGIN_FAILED)->sole()->ctx, true))->toBe(['method' => 'sso', 'provider' => 'fake', 'reason' => 'inactive']);
});

it('still logs the admin in when the security event cannot be written', function () {
    // Fails the insert itself, without DDL a MySQL run would commit past the test's transaction.
    DB::beforeExecuting(function (string $query): void {
        if (str_contains($query, SecurityEventRecorder::TABLE)) {
            throw new RuntimeException('security event store unavailable');
        }
    });

    $this->post(route('admin.session.store'), ['email' => 'staff.member@example.test', 'password' => 'right-password'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($this->admin, 'admin');
});

it('counts one SSO session once: its login, an access change and its logout share one session hash', function () {
    securitySsoCallback($this)->assertRedirect();
    $this->assertAuthenticatedAs($this->admin, 'admin');

    // The browser keeps the session the callback ended on; a test request has to send it back (and a JSON
    // test request sends cookies only withCredentials()).
    $session = [config('session.cookie') => session()->getId()];
    $this->withCredentials()->withCookies($session)->postJson(route('admin.settings.groups.store'), ['name' => 'P1a SSO session group', 'member_ids' => []])
        ->assertCreated();
    $this->withCookies($session)->delete(route('admin.session.destroy'))->assertRedirect();

    $rows = DB::table(SecurityEventRecorder::TABLE)->orderBy('id')->get(['event', 'session_hash']);

    expect($rows->pluck('event')->all())->toBe([SecurityEvent::LOGIN_SUCCEEDED, 'access.group.saved.v1', SecurityEvent::LOGOUT_SUCCEEDED])
        ->and($rows->pluck('session_hash')->unique()->all())->toBe([hash('sha256', $session[config('session.cookie')])])
        ->and(app(SecurityReports::class)->loginAccess(days: 7)['daily'][0]['sessions'])->toBe(1);
});

it('records every moved file and folder by id even with the opt-in access log off', function () {
    config(['dam.access_logging' => false]);
    Storage::fake(Directory::getAssetDisk());
    $this->actingAs($this->admin, 'admin');
    $mover = Admin::factory()->create();

    $from = Directory::create(['name' => 'P1a move from', 'parent_id' => null]);
    $to = Directory::create(['name' => 'P1a move to', 'parent_id' => null]);
    $massTo = Directory::create(['name' => 'P1a mass move to', 'parent_id' => null]);
    $moved = Directory::create(['name' => 'P1a moved folder', 'parent_id' => $from->id]);
    [$single, $first, $second] = array_map(fn (int $n) => (int) $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image("p1a-move-{$n}.png", 40, 20)],
        'directory_id' => $from->id,
    ])->assertStatus(201)->json('files.0.id'), [1, 2, 3]);

    // Placing a new file or folder is not a change of access, so nothing is recorded until something moves.
    expect(securityEvents(SecurityEvent::ACCESS_ADOPTED_ON_MOVE))->toBeEmpty();

    $this->postJson(route('admin.dam.assets.moved'), ['move_item_id' => $single, 'new_parent_id' => $to->id])->assertOk();
    (new MassMove([$first, $second], [], $massTo->id, $mover->id))->handle();
    (new MoveDirectoryStructure($moved->id, $to->id, $this->admin->id))->handle();

    $recorded = securityEvents(SecurityEvent::ACCESS_ADOPTED_ON_MOVE)->map(fn ($row) => [(int) $row->actor_admin_id] + json_decode($row->ctx, true))->all();

    // One event per moved file, the mass move's included; a folder's event stands for its subtree.
    expect($recorded)->toEqualCanonicalizing([
        [$this->admin->id, 'asset_id' => $single, 'moved' => true, 'subject_id' => $to->id, 'is_directory' => false],
        [$mover->id, 'asset_id'           => $first, 'moved' => true, 'subject_id' => $massTo->id, 'is_directory' => false],
        [$mover->id, 'asset_id'           => $second, 'moved' => true, 'subject_id' => $massTo->id, 'is_directory' => false],
        [$this->admin->id, 'directory_id' => $moved->id, 'moved' => true, 'subject_id' => $to->id, 'is_directory' => true],
    ])->and(strtolower(json_encode(securityEvents(SecurityEvent::ACCESS_ADOPTED_ON_MOVE), JSON_THROW_ON_ERROR)))
        ->not->toContain('p1a move')->not->toContain('p1a-move');
});

it('writes every event of a 250-file move without a statement over 2,100 bound values', function () {
    $to = Directory::create(['name' => 'P1a bulk move to', 'parent_id' => null]);
    $assetIds = Asset::factory()->count(250)->create()->pluck('id')->map(fn ($id) => (int) $id)->all();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = ['sql' => $query->sql, 'bindings' => count($query->bindings)];
    });

    Directory::withTreeLock(fn () => DB::transaction(
        fn () => app(AccessListWriter::class)->adoptForAssets(assetIds: $assetIds, destinationId: $to->id, actorId: $this->admin->id)
    ));

    $recorded = securityEvents(SecurityEvent::ACCESS_ADOPTED_ON_MOVE)->map(fn ($row) => json_decode($row->ctx, true)['asset_id'])->sort()->values()->all();
    $eventInserts = array_filter($statements, fn (array $statement) => securityEventInsert(sql: $statement['sql']));

    expect($recorded)->toBe($assetIds)
        ->and(count($eventInserts))->toBeGreaterThan(1)
        ->and(max(array_column($statements, 'bindings')))->toBeLessThanOrEqual(2100);
});

it('writes none of the events when a later batch fails, and leaves the caller\'s transaction usable', function () {
    $inserts = 0;
    DB::beforeExecuting(function (string $query) use (&$inserts): void {
        if (securityEventInsert(sql: $query) && ++$inserts === 2) {
            throw new RuntimeException('second batch refused');
        }
    });
    $events = array_map(fn (int $n) => new SecurityEvent(name: SecurityEvent::ACCESS_ADOPTED_ON_MOVE, ctx: ['asset_id' => $n]), range(1, 200));

    $groupId = DB::transaction(function () use ($events) {
        expect(fn () => app(SecurityEventRecorder::class)->recordAll(events: $events))->toThrow(RuntimeException::class, 'second batch refused');

        // The caller carries on in the same transaction.
        return DB::table('dam_groups')->insertGetId(['name' => 'P1a after a failed batch', 'created_at' => now(), 'updated_at' => now()]);
    });

    expect($inserts)->toBe(2)
        ->and(securityEvents(SecurityEvent::ACCESS_ADOPTED_ON_MOVE))->toBeEmpty()
        ->and(DB::table('dam_groups')->where('id', $groupId)->exists())->toBeTrue();
});

it('rolls back a move\'s events with its access-list change', function (string $failure) {
    $from = Directory::create(['name' => 'P1a rollback from', 'parent_id' => null]);
    $to = Directory::create(['name' => 'P1a rollback to', 'parent_id' => null]);
    $before = Admin::factory()->create();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $to->id, 'admin_id' => Admin::factory()->create()->id]);
    $asset = Asset::factory()->create();
    DB::table('dam_asset_user_access')->where('asset_id', $asset->id)->delete();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $before->id]);

    if ($failure === 'its events cannot be written') {
        DB::beforeExecuting(function (string $query): void {
            if (securityEventInsert(sql: $query)) {
                throw new RuntimeException('audit store unavailable');
            }
        });
    }

    $move = fn () => Directory::withTreeLock(fn () => DB::transaction(function () use ($asset, $to, $failure): void {
        app(AccessListWriter::class)->adoptForAssets(assetIds: [$asset->id], destinationId: $to->id, actorId: $this->admin->id);

        if ($failure === 'a later step fails') {
            throw new RuntimeException('later step failed');
        }
    }));

    expect($move)->toThrow(RuntimeException::class)
        ->and(securityEvents(SecurityEvent::ACCESS_ADOPTED_ON_MOVE))->toBeEmpty()
        ->and(DB::table('dam_asset_user_access')->where('asset_id', $asset->id)->pluck('admin_id')->map(fn ($id) => (int) $id)->all())->toBe([$before->id]);
})->with(['a later step fails', 'its events cannot be written']);
