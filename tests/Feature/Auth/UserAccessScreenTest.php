<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mothership\Admin\Access\AccessAuditLog;
use Mothership\Admin\Access\AccessGrantor;
use Mothership\Admin\Sso\SsoIdentity;
use Mothership\Admin\Sso\SsoManager;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminAccessPreapproval;
use Mothership\User\Models\AdminAccessRequest;
use Mothership\User\Models\AdminIdentity;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;

/** Answer the Google callback with this account. */
function signInWithGoogleAs($test, string $subject, string $email, bool $verified = true, string $name = 'Google Person')
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
        'name'  => $name,
    ])->setRaw(['sub' => $subject, 'email' => $email, 'email_verified' => $verified]));

    return $test->get(route('admin.session.sso.callback', ['provider' => 'google', 'state' => 's', 'code' => 'c']));
}

function accessRequest(array $overrides = []): AdminAccessRequest
{
    return AdminAccessRequest::query()->create(array_merge([
        'provider'       => 'google',
        'subject'        => 'g-'.uniqid(),
        'email'          => 'newcomer@example.test',
        'email_verified' => true,
        'name'           => 'New Comer',
        'status'         => AdminAccessRequest::STATUS_PENDING,
        'expires_at'     => now()->addHours(24),
    ], $overrides));
}

function fullAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function viewerRole(): Role
{
    return Role::factory()->create(['name' => 'Viewer', 'permission_type' => 'custom', 'permissions' => ['dashboard']]);
}

/** A custom role that holds every Users permission, the most a non-full account can have here. */
function usersManager(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dashboard', 'settings', 'settings.users', 'settings.users.users', 'settings.users.users.create', 'settings.users.users.edit', 'settings.users.users.delete'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** Collect what the audit log is asked to write. */
function recordAuditEvents(): ArrayObject
{
    $events = new ArrayObject;

    app()->instance(AccessAuditLog::class, new class($events) extends AccessAuditLog
    {
        public function __construct(private ArrayObject $events) {}

        public function write(string $event, array $ctx = [], string $level = 'info'): void
        {
            $this->events[] = ['event' => $event, 'ctx' => $ctx];
        }
    });

    return $events;
}

function auditedEvents(ArrayObject $events): array
{
    return array_column($events->getArrayCopy(), 'event');
}

beforeEach(function () {
    config()->set('admin.auth.access_log', false);
});

describe('who may use the screens', function () {
    it('shows the panel only to a role with All permissions', function () {
        $this->actingAs(fullAdmin(), 'admin')
            ->get(route('admin.settings.users.index'))
            ->assertOk()
            ->assertSee('v-user-access-template', false);

        $this->actingAs(usersManager(), 'admin')
            ->get(route('admin.settings.users.index'))
            ->assertOk()
            ->assertDontSee('v-user-access-template', false);
    });

    it('refuses every access route to a custom role, even one that manages users', function () {
        $request = accessRequest();
        $role = viewerRole();
        $preapproval = AdminAccessPreapproval::query()->create(['email' => 'kept@example.test', 'role_id' => $role->id]);

        $this->actingAs(usersManager(), 'admin');

        $this->getJson(route('admin.settings.users.access.index'))->assertForbidden();
        $this->postJson(route('admin.settings.users.access.approve', $request->id), ['role_id' => $role->id])->assertForbidden();
        $this->postJson(route('admin.settings.users.access.link', $request->id), ['admin_id' => auth('admin')->id()])->assertForbidden();
        $this->deleteJson(route('admin.settings.users.access.deny', $request->id))->assertForbidden();
        $this->postJson(route('admin.settings.users.access.preapprove'), ['email' => 'x@example.test', 'role_id' => $role->id])->assertForbidden();
        $this->deleteJson(route('admin.settings.users.access.preapprovals.remove', $preapproval->id))->assertForbidden();

        expect($request->fresh()->status)->toBe(AdminAccessRequest::STATUS_PENDING)
            ->and(AdminAccessPreapproval::query()->pluck('email')->all())->toBe(['kept@example.test'])
            ->and(AdminIdentity::query()->count())->toBe(0);
    });

    it('refuses a signed-out visitor', function () {
        $this->getJson(route('admin.settings.users.access.index'))->assertUnauthorized();
    });
});

describe('inviting people', function () {
    beforeEach(function () {
        config()->set('services.google', [
            'enabled'       => true,
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect'      => null,
        ]);

        app()->forgetInstance(SsoManager::class);
    });

    it('offers invites while password sign-in is on', function () {
        $this->actingAs(fullAdmin(), 'admin')
            ->get(route('admin.settings.users.index'))
            ->assertOk()
            ->assertSee('$refs.inviteModal.open()', false)
            ->assertSee('id="invite_emails"', false)
            ->assertSee('data-row-action="resend"', false)
            ->assertDontSee('data-access-preapprove-link', false);
    });

    it('points to Preapproved instead of an invite nobody could accept when password sign-in is off', function () {
        config()->set('admin.auth.password_login', false);

        $this->actingAs(fullAdmin(), 'admin')
            ->get(route('admin.settings.users.index'))
            ->assertOk()
            ->assertDontSee('$refs.inviteModal.open()', false)
            ->assertDontSee('id="invite_emails"', false)
            ->assertDontSee('data-row-action="resend"', false)
            ->assertSee('href="#user-access-email"', false);
    });

    it('does not point an account that cannot preapprove at the Preapproved panel', function () {
        config()->set('admin.auth.password_login', false);

        $this->actingAs(usersManager(), 'admin')
            ->get(route('admin.settings.users.index'))
            ->assertOk()
            ->assertDontSee('$refs.inviteModal.open()', false)
            ->assertDontSee('data-access-preapprove-link', false);
    });
});

describe('waiting for access', function () {
    it('lists open requests with what the decision needs and leaves out closed ones', function () {
        $open = accessRequest(['email' => 'open@example.test', 'email_verified' => false]);
        accessRequest(['email' => 'old@example.test', 'expires_at' => now()->subMinute()]);
        accessRequest(['email' => 'done@example.test', 'status' => AdminAccessRequest::STATUS_GRANTED]);

        $this->actingAs(fullAdmin(), 'admin')
            ->getJson(route('admin.settings.users.access.index'))
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.id', $open->id)
            ->assertJsonPath('requests.0.email', 'open@example.test')
            ->assertJsonPath('requests.0.provider', 'Google')
            ->assertJsonPath('requests.0.email_verified', false)
            ->assertJsonPath('requests.0.name', 'New Comer');
    });

    it('approves a request with the chosen role and links the provider account', function () {
        $events = recordAuditEvents();
        $role = viewerRole();
        $request = accessRequest();
        $actor = fullAdmin();

        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.settings.users.access.approve', $request->id), ['role_id' => $role->id])
            ->assertOk();

        $admin = Admin::query()->where('email', 'newcomer@example.test')->sole();

        expect($admin->role_id)->toBe($role->id)
            ->and((int) $admin->status)->toBe(1)
            ->and(AdminIdentity::query()->where('admin_id', $admin->id)->sole()->subject)->toBe($request->subject)
            ->and($request->fresh()->status)->toBe(AdminAccessRequest::STATUS_GRANTED)
            ->and($events[0]['event'])->toBe('access.granted')
            ->and($events[0]['ctx']['actor_id'])->toBe($actor->id);
    });

    it('needs a real role to approve', function () {
        $request = accessRequest();

        $this->actingAs(fullAdmin(), 'admin')
            ->postJson(route('admin.settings.users.access.approve', $request->id), ['role_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');

        expect(Admin::query()->where('email', 'newcomer@example.test')->exists())->toBeFalse();
    });

    it('refuses to approve or link an email the provider did not verify, but can deny it', function () {
        $role = viewerRole();
        $request = accessRequest(['email_verified' => false]);
        $actor = fullAdmin();

        $this->actingAs($actor, 'admin');

        $this->postJson(route('admin.settings.users.access.approve', $request->id), ['role_id' => $role->id])->assertUnprocessable();
        $this->postJson(route('admin.settings.users.access.link', $request->id), ['admin_id' => $actor->id])->assertUnprocessable();

        expect(Admin::query()->where('email', 'newcomer@example.test')->exists())->toBeFalse()
            ->and(AdminIdentity::query()->count())->toBe(0);

        $this->deleteJson(route('admin.settings.users.access.deny', $request->id))->assertOk();

        expect(AdminAccessRequest::query()->whereKey($request->id)->exists())->toBeFalse();
    });

    it('refuses to approve an email that already has an account and links it instead', function () {
        $events = recordAuditEvents();
        $existing = Admin::factory()->create(['email' => 'Newcomer@example.test']);
        $request = accessRequest();
        $role = viewerRole();

        $this->actingAs(fullAdmin(), 'admin');

        $this->getJson(route('admin.settings.users.access.index'))
            ->assertJsonPath('requests.0.has_account', true)
            ->assertJsonPath('requests.0.account_id', $existing->id);

        $this->postJson(route('admin.settings.users.access.approve', $request->id), ['role_id' => $role->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', trans('admin::app.user-access.requests.account-exists'));

        $this->postJson(route('admin.settings.users.access.link', $request->id), ['admin_id' => $existing->id])->assertOk();

        expect(AdminIdentity::query()->sole()->admin_id)->toBe($existing->id)
            ->and(Admin::query()->whereRaw('LOWER(email) = ?', ['newcomer@example.test'])->count())->toBe(1)
            ->and(auditedEvents($events))->toBe(['access.linked']);
    });

    it('denies a request, and a later sign-in waits as a new request', function () {
        $events = recordAuditEvents();
        $request = accessRequest(['subject' => 'g-denied', 'email' => 'denied@example.test']);

        $this->actingAs(fullAdmin(), 'admin')
            ->deleteJson(route('admin.settings.users.access.deny', $request->id))
            ->assertOk();

        expect(AdminAccessRequest::query()->count())->toBe(0)
            ->and(auditedEvents($events))->toBe(['access.denied']);

        auth('admin')->logout();

        signInWithGoogleAs($this, subject: 'g-denied', email: 'denied@example.test')
            ->assertRedirect(route('admin.session.sso.pending'));

        expect(AdminAccessRequest::query()->sole()->id)->not->toBe($request->id);
    });

    it('refuses a decision on an expired request', function () {
        $request = accessRequest(['expires_at' => now()->subMinute()]);

        $this->actingAs(fullAdmin(), 'admin')
            ->postJson(route('admin.settings.users.access.approve', $request->id), ['role_id' => viewerRole()->id])
            ->assertUnprocessable();

        expect(Admin::query()->where('email', 'newcomer@example.test')->exists())->toBeFalse();
    });
});

describe('preapproving an email', function () {
    it('adds an entry with a normalized email and audits it', function () {
        $events = recordAuditEvents();
        $role = viewerRole();
        $actor = fullAdmin();

        $this->actingAs($actor, 'admin')
            ->postJson(route('admin.settings.users.access.preapprove'), ['email' => 'Friend@Example.test', 'role_id' => $role->id])
            ->assertOk();

        $entry = AdminAccessPreapproval::query()->sole();

        expect($entry->email)->toBe('friend@example.test')
            ->and($entry->role_id)->toBe($role->id)
            ->and($entry->created_by)->toBe($actor->id)
            ->and(auditedEvents($events))->toBe(['access.preapproved']);

        $this->getJson(route('admin.settings.users.access.index'))
            ->assertJsonPath('preapprovals.0.email', 'friend@example.test')
            ->assertJsonPath('preapprovals.0.role', 'Viewer');
    });

    it('refuses an email that already has an account, whatever its case', function () {
        Admin::factory()->create(['email' => 'Taken@example.test']);

        $this->actingAs(fullAdmin(), 'admin')
            ->postJson(route('admin.settings.users.access.preapprove'), ['email' => 'taken@EXAMPLE.test', 'role_id' => viewerRole()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        expect(AdminAccessPreapproval::query()->count())->toBe(0);
    });

    it('refuses a duplicate entry', function () {
        $role = viewerRole();

        AdminAccessPreapproval::query()->create(['email' => 'twice@example.test', 'role_id' => $role->id]);

        $this->actingAs(fullAdmin(), 'admin')
            ->postJson(route('admin.settings.users.access.preapprove'), ['email' => 'TWICE@example.test', 'role_id' => $role->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        expect(AdminAccessPreapproval::query()->count())->toBe(1);
    });

    it('removes an entry and audits it', function () {
        $events = recordAuditEvents();
        $entry = AdminAccessPreapproval::query()->create(['email' => 'gone@example.test', 'role_id' => viewerRole()->id]);

        $this->actingAs(fullAdmin(), 'admin')
            ->deleteJson(route('admin.settings.users.access.preapprovals.remove', $entry->id))
            ->assertOk();

        expect(AdminAccessPreapproval::query()->count())->toBe(0)
            ->and(auditedEvents($events))->toBe(['access.preapproval_removed']);
    });
});

describe('signing in with a preapproved email', function () {
    it('creates the account with the role, links it and goes straight in', function () {
        $events = recordAuditEvents();
        $role = viewerRole();

        AdminAccessPreapproval::query()->create(['email' => 'friend@example.test', 'role_id' => $role->id]);

        signInWithGoogleAs($this, subject: 'g-friend', email: 'Friend@Example.test')
            ->assertRedirect(route('admin.home.index'));

        $admin = Admin::query()->whereRaw('LOWER(email) = ?', ['friend@example.test'])->sole();

        $this->assertAuthenticatedAs($admin, 'admin');

        expect($admin->role_id)->toBe($role->id)
            ->and((int) $admin->status)->toBe(1)
            ->and(AdminIdentity::query()->where('admin_id', $admin->id)->sole()->subject)->toBe('g-friend')
            ->and(AdminAccessPreapproval::query()->count())->toBe(0)
            ->and(AdminAccessRequest::query()->count())->toBe(0)
            ->and(auditedEvents($events))->toBe(['access.preapproval_redeemed']);
    });

    it('closes a request the same account left waiting', function () {
        $request = accessRequest(['subject' => 'g-early', 'email' => 'early@example.test']);

        AdminAccessPreapproval::query()->create(['email' => 'early@example.test', 'role_id' => viewerRole()->id]);

        signInWithGoogleAs($this, subject: 'g-early', email: 'early@example.test')->assertRedirect();

        expect($request->fresh()->status)->toBe(AdminAccessRequest::STATUS_GRANTED)
            ->and($request->fresh()->admin_id)->toBe((int) auth('admin')->id());
    });

    it('sends an unverified email to the waiting list and keeps the entry', function () {
        $events = recordAuditEvents();

        AdminAccessPreapproval::query()->create(['email' => 'friend@example.test', 'role_id' => viewerRole()->id]);

        signInWithGoogleAs($this, subject: 'g-unverified', email: 'friend@example.test', verified: false)
            ->assertRedirect(route('admin.session.sso.pending'));

        $this->assertGuest('admin');

        expect(Admin::query()->where('email', 'friend@example.test')->exists())->toBeFalse()
            ->and(AdminAccessPreapproval::query()->count())->toBe(1)
            ->and(AdminAccessRequest::query()->sole()->email_verified)->toBeFalse()
            ->and($events[0]['event'])->toBe('access.preapproval_refused')
            ->and($events[0]['ctx']['reason'])->toBe('email_unverified');
    });

    it('works once: a second provider account with the same email waits', function () {
        AdminAccessPreapproval::query()->create(['email' => 'once@example.test', 'role_id' => viewerRole()->id]);

        signInWithGoogleAs($this, subject: 'g-first', email: 'once@example.test')->assertRedirect(route('admin.home.index'));

        auth('admin')->logout();

        signInWithGoogleAs($this, subject: 'g-second', email: 'once@example.test')
            ->assertRedirect(route('admin.session.sso.pending'));

        $this->assertGuest('admin');

        expect(Admin::query()->where('email', 'once@example.test')->count())->toBe(1)
            ->and(AdminIdentity::query()->where('subject', 'g-second')->exists())->toBeFalse();
    });

    it('does nothing for a removed entry', function () {
        $entry = AdminAccessPreapproval::query()->create(['email' => 'removed@example.test', 'role_id' => viewerRole()->id]);

        $this->actingAs(fullAdmin(), 'admin')
            ->deleteJson(route('admin.settings.users.access.preapprovals.remove', $entry->id))
            ->assertOk();

        auth('admin')->logout();

        signInWithGoogleAs($this, subject: 'g-removed', email: 'removed@example.test')
            ->assertRedirect(route('admin.session.sso.pending'));

        expect(Admin::query()->where('email', 'removed@example.test')->exists())->toBeFalse();
    });

    it('never links to an account that appeared after the entry was added', function () {
        $events = recordAuditEvents();

        AdminAccessPreapproval::query()->create(['email' => 'later@example.test', 'role_id' => viewerRole()->id]);

        $existing = Admin::factory()->create(['email' => 'Later@example.test', 'status' => 1]);

        signInWithGoogleAs($this, subject: 'g-later', email: 'later@example.test')
            ->assertRedirect(route('admin.session.sso.pending'));

        $this->assertGuest('admin');

        expect(AdminIdentity::query()->where('admin_id', $existing->id)->exists())->toBeFalse()
            ->and(AdminAccessRequest::query()->sole()->subject)->toBe('g-later')
            ->and($events[0]['event'])->toBe('access.preapproval_refused')
            ->and($events[0]['ctx']['reason'])->toBe('account_exists');
    });

    it('falls back to the waiting list when the role is deleted', function () {
        $role = viewerRole();

        AdminAccessPreapproval::query()->create(['email' => 'norole@example.test', 'role_id' => $role->id]);

        $role->delete();

        signInWithGoogleAs($this, subject: 'g-norole', email: 'norole@example.test')
            ->assertRedirect(route('admin.session.sso.pending'));

        expect(Admin::query()->where('email', 'norole@example.test')->exists())->toBeFalse();
    });

    it('lets in a second callback for the account that has just redeemed the entry', function () {
        AdminAccessPreapproval::query()->create(['email' => 'twice@example.test', 'role_id' => viewerRole()->id]);

        // The first callback wins while this one waits, so this one finds the entry already spent.
        app()->instance(AccessGrantor::class, new class(app(AccessAuditLog::class), app(AdminRepository::class)) extends AccessGrantor
        {
            public function redeemPreapproval(SsoIdentity $identity, string $provider): ?AdminIdentity
            {
                parent::redeemPreapproval(identity: $identity, provider: $provider);

                return null;
            }
        });

        signInWithGoogleAs($this, subject: 'g-twice', email: 'twice@example.test')
            ->assertRedirect(route('admin.home.index'));

        $this->assertAuthenticatedAs(Admin::query()->where('email', 'twice@example.test')->sole(), 'admin');

        expect(AdminAccessRequest::query()->count())->toBe(0);
    });

    it('shortens a provider name too long for the account', function () {
        AdminAccessPreapproval::query()->create(['email' => 'long@example.test', 'role_id' => viewerRole()->id]);

        signInWithGoogleAs($this, subject: 'g-long', email: 'long@example.test', name: str_repeat('é', 300))
            ->assertRedirect(route('admin.home.index'));

        expect(mb_strlen(Admin::query()->where('email', 'long@example.test')->sole()->name))->toBe(250);
    });
});

describe('the audit log', function () {
    beforeEach(function () {
        config()->set('admin.auth.access_log', true);

        $this->auditStorage = sys_get_temp_dir().'/access-audit-'.uniqid();

        File::ensureDirectoryExists($this->auditStorage.'/logs');

        app()->useStoragePath($this->auditStorage);

        $this->auditLines = fn (): array => File::exists($this->auditStorage.'/logs/access-grants.jsonl')
            ? File::lines($this->auditStorage.'/logs/access-grants.jsonl')->filter()->values()->all()
            : [];
    });

    afterEach(function () {
        File::deleteDirectory($this->auditStorage);
    });

    it('writes a line once when the outer transaction commits', function () {
        DB::transaction(fn () => DB::transaction(fn () => app(AccessAuditLog::class)->write(event: 'access.granted')));

        $lines = ($this->auditLines)();

        expect($lines)->toHaveCount(1)
            ->and(json_decode($lines[0], true)['event'])->toBe('access.granted');
    });

    it('writes nothing when the outer transaction rolls back', function () {
        try {
            DB::transaction(function () {
                DB::transaction(fn () => app(AccessAuditLog::class)->write(event: 'access.granted'));

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // The rollback is the point of this test.
        }

        expect(($this->auditLines)())->toBe([]);
    });
});

it('keeps the shell commands working', function () {
    $request = accessRequest();

    viewerRole();

    $this->artisan('mothership:access:grant', ['request' => $request->id, '--role' => 'Viewer'])->assertSuccessful();

    expect(Admin::query()->where('email', 'newcomer@example.test')->sole()->role->name)->toBe('Viewer');
});
