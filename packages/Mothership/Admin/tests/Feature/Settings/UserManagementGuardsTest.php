<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Mothership\Core\Models\Locale;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * UX eval 2026-10-10.
 * PERM-07: at least one active user on a full-access role must remain, on every path that deactivates, demotes or
 * deletes an admin or turns a full-access role custom (AdminRepository::ensureActiveFullAdminRemains()).
 * PERM-08: a user whose role outranks the actor's cannot be edited or deleted by them.
 * PERM-09: a user's role must exist, and a user whose role is already gone can still be opened and repaired.
 */

beforeEach(function () {
    $this->withoutMiddleware([PreventRequestForgery::class, ThrottleRequests::class]);

    // The seeded Administrator(s) step aside, so each test decides exactly who holds full access.
    Admin::query()->update(['status' => 0]);
});

function guardsAllRole(): Role
{
    return Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);
}

function guardsUserPayload(Admin $target, array $overrides = []): array
{
    return array_merge([
        'id'                    => $target->id,
        'name'                  => 'Guard Probe',
        'email'                 => $target->email,
        'role_id'               => $target->role_id,
        'ui_locale_id'          => $target->ui_locale_id ?: Locale::where('status', 1)->value('id'),
        'timezone'              => 'UTC',
        'password'              => '',
        'password_confirmation' => '',
        'status'                => '1',
        'current_password'      => 'password',
    ], $overrides);
}

function guardsUpdate($test, Admin $target, array $overrides = [])
{
    return $test->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->put(route('admin.settings.users.update'), guardsUserPayload($target, $overrides));
}

it('refuses a self-demotion that would leave only an inactive full admin', function () {
    $role = guardsAllRole();
    $custom = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    $me = Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'password' => Hash::make('password')]);
    Admin::factory()->create(['role_id' => $role->id, 'status' => 0]);

    $this->actingAs($me, 'admin');

    guardsUpdate($this, $me, ['role_id' => $custom->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id' => trans('admin::app.settings.users.last-active-full-admin')]);

    expect($me->fresh()->role_id)->toBe($role->id);
});

it('refuses deactivating the last active full admin, and allows deactivating one while another stays active', function () {
    $role = guardsAllRole();
    $me = Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'password' => Hash::make('password')]);
    $this->actingAs($me, 'admin');

    guardsUpdate($this, $me, ['status' => '0'])->assertUnprocessable()->assertJsonValidationErrors('status');
    expect((int) $me->fresh()->status)->toBe(1);

    $other = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    guardsUpdate($this, $other, ['status' => '0'])->assertOk();
    expect((int) $other->fresh()->status)->toBe(0);
});

it('refuses turning a full-access role custom while it holds every active full admin, however many members it has', function () {
    $role = guardsAllRole();
    $me = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);

    $this->actingAs($me, 'admin');

    $payload = ['name' => $role->name, 'description' => 'x', 'permission_type' => 'custom', 'permissions' => ['dashboard']];

    $this->putJson(route('admin.settings.roles.update', $role->id), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('permission_type');
    expect($role->fresh()->permission_type)->toBe('all');

    // With an active full admin on another role, the change goes through.
    Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1]);
    $this->putJson(route('admin.settings.roles.update', $role->id), $payload)->assertOk();
    expect($role->fresh()->permission_type)->toBe('custom');
});

it('refuses deleting yourself or another user when that leaves no active full admin', function () {
    $role = guardsAllRole();
    $me = Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'password' => Hash::make('password')]);
    Admin::factory()->create(['role_id' => $role->id, 'status' => 0]);

    $this->actingAs($me, 'admin');

    $this->putJson(route('admin.settings.users.destroy'), ['password' => 'password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
    $this->assertDatabaseHas('admins', ['id' => $me->id]);
});

it('stops a delegated user manager from editing, resetting or deleting a full admin (PERM-08)', function () {
    $fullAdmin = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1, 'password' => Hash::make('original-pass')]);
    $manager = $this->loginWithPermissions('custom', ['dashboard', 'settings', 'settings.users', 'settings.users.users', 'settings.users.users.edit', 'settings.users.users.delete']);

    $this->get(route('admin.settings.users.edit', $fullAdmin->id))->assertForbidden();

    guardsUpdate($this, $fullAdmin, [
        'role_id'               => $manager->role_id,
        'password'              => 'Taken-over-123!',
        'password_confirmation' => 'Taken-over-123!',
    ])->assertForbidden()->assertJsonPath('message', trans('admin::app.settings.users.cannot-manage-higher-role'));

    $this->deleteJson(route('admin.settings.users.delete', $fullAdmin->id))->assertForbidden();

    $fresh = $fullAdmin->fresh();
    expect($fresh->role_id)->toBe($fullAdmin->role_id)
        ->and(Hash::check('original-pass', $fresh->password))->toBeTrue();
});

it('offers a user manager Edit and Delete only on the users it may manage (PERM-08)', function () {
    $manager = $this->loginWithPermissions('custom', ['dashboard', 'settings', 'settings.users', 'settings.users.users', 'settings.users.users.edit', 'settings.users.users.delete']);
    $fullAdmin = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1]);
    $stronger = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard', 'catalog']])->id]);
    $weaker = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id]);

    $records = collect($this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->get(route('admin.settings.users.index', ['pagination' => ['page' => 1, 'per_page' => 50]]))
        ->assertOk()
        ->json('records'))
        ->keyBy('user_id')
        ->map(fn ($row) => collect($row['actions'])->pluck('index')->all());

    expect($records[$fullAdmin->id])->toBe([])
        ->and($records[$stronger->id])->toBe([])
        ->and($records[$weaker->id])->toBe(['edit', 'delete'])
        ->and($records[$manager->id])->toBe(['edit', 'delete']);
});

it('stops a user manager from editing a custom user holding permissions it lacks, and lets it edit one it covers', function () {
    $permissions = ['dashboard', 'settings', 'settings.users', 'settings.users.users', 'settings.users.users.edit'];
    $manager = $this->loginWithPermissions('custom', $permissions);

    $stronger = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard', 'catalog']])->id]);
    $weaker = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id]);

    guardsUpdate($this, $stronger, ['name' => 'Renamed'])->assertForbidden();
    expect($stronger->fresh()->name)->not->toBe('Renamed');

    guardsUpdate($this, $weaker, ['name' => 'Renamed'])->assertOk();
    expect($weaker->fresh()->name)->toBe('Renamed');

    // Its own account is never outranked by its own role.
    guardsUpdate($this, $manager, ['name' => 'Self Renamed'])->assertOk();
});

it('rejects a role that does not exist on create and update (PERM-09)', function () {
    $me = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1]);
    $this->actingAs($me, 'admin');

    $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('admin.settings.users.store'), [
            'name'                  => 'Ghost Role',
            'email'                 => 'ghost-role@example.com',
            'password'              => 'Ghost-role-123!',
            'password_confirmation' => 'Ghost-role-123!',
            'role_id'               => 999999,
            'status'                => '1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role_id');
    $this->assertDatabaseMissing('admins', ['email' => 'ghost-role@example.com']);

    $target = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']])->id]);
    guardsUpdate($this, $target, ['role_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('role_id');
});

it('opens and repairs a user whose role is already gone', function () {
    $me = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1]);
    $this->actingAs($me, 'admin');

    $broken = Admin::factory()->create(['status' => 1]);
    $broken->forceFill(['role_id' => null])->save();

    $this->get(route('admin.settings.users.edit', $broken->id))->assertOk();

    $custom = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    guardsUpdate($this, $broken->fresh(), ['role_id' => $custom->id])->assertOk();
    expect($broken->fresh()->role_id)->toBe($custom->id);
});

/*
 * PERM-12: a password change ends that user's other sessions (Laravel's AuthenticateSession on the admin routes,
 * which compares the hash a session signed in with against the account's current one). A session here is the
 * guard user plus the `password_hash_admin` the middleware keeps in it.
 */
function guardsSessionGet($test, Admin $as, string $passwordHash)
{
    return $test->flushHeaders()
        ->actingAs($as, 'admin')
        ->withSession(['password_hash_admin' => $passwordHash])
        ->get(route('admin.account.edit'));
}

it('ends every session of a user whose password an admin changes, and leaves the admin signed in (PERM-12)', function () {
    $me = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1, 'password' => Hash::make('password')]);
    $target = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1, 'password' => Hash::make('OldPass123')]);
    $targetOldHash = $target->password;

    guardsSessionGet($this, $target, $targetOldHash)->assertOk();

    $this->actingAs($me, 'admin');
    guardsUpdate($this, $target, ['password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertOk();

    guardsSessionGet($this, $target->fresh(), $targetOldHash)->assertRedirect(route('admin.session.create'));
    guardsSessionGet($this, $me->fresh(), $me->password)->assertOk();
});

it('keeps the session that changes its own password and ends that user\'s other sessions (PERM-12)', function (string $via) {
    $me = Admin::factory()->create(['role_id' => guardsAllRole()->id, 'status' => 1, 'password' => Hash::make('password')]);
    $oldHash = $me->password;

    $this->actingAs($me, 'admin')->withSession(['password_hash_admin' => $oldHash]);

    $payload = ['password' => 'NewPass123', 'password_confirmation' => 'NewPass123'];

    if ($via === 'account') {
        $this->withHeaders(['Accept' => 'application/json'])->put(route('admin.account.update'), [
            'name'             => 'Guard Probe', 'email' => $me->email, 'timezone' => 'UTC', 'ui_locale_id' => $me->ui_locale_id ?: 1,
            'current_password' => 'password', ...$payload,
        ])->assertOk();
    } else {
        guardsUpdate($this, $me, $payload)->assertOk();
    }

    $fresh = $me->fresh();
    $kept = session('password_hash_admin');

    expect(Hash::check('NewPass123', $fresh->password))->toBeTrue()
        ->and(hash_equals(auth('admin')->hashPasswordForCookie($fresh->password), (string) $kept))->toBeTrue();

    guardsSessionGet($this, $fresh, $kept)->assertOk();
    guardsSessionGet($this, $fresh, $oldHash)->assertRedirect(route('admin.session.create'));
})->with(['account', 'users screen']);
