<?php

use Mothership\User\Models\Role;

use function Pest\Laravel\postJson;

/*
 * Guards against privilege escalation through user creation: a non-superadmin
 * must not assign a role (all-access, or a custom role) that carries permissions
 * beyond the acting admin's own set.
 */
function createUserPayload(int $roleId): array
{
    return [
        'name'                  => 'Escalation Target',
        'email'                 => 'escalation-'.uniqid().'@example.com',
        'password'              => 'admin1234',
        'password_confirmation' => 'admin1234',
        'status'                => 1,
        'role_id'               => $roleId,
        'timezone'              => 'Asia/Kolkata',
        'ui_locale_id'          => 2,
    ];
}

it('forbids assigning a custom role that exceeds the acting admin permissions', function () {
    $this->loginWithPermissions('custom', ['dashboard', 'settings.users.users.create']);

    $powerRole = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dashboard', 'settings.roles', 'settings.roles.edit'],
    ]);

    postJson(route('admin.settings.users.store'), createUserPayload($powerRole->id))
        ->assertStatus(403);
});

it('allows assigning a custom role within the acting admin permissions', function () {
    $this->loginWithPermissions('custom', ['dashboard', 'settings.users.users.create']);

    $withinRole = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dashboard'],
    ]);

    postJson(route('admin.settings.users.store'), createUserPayload($withinRole->id))
        ->assertStatus(200);
});

/*
 * A role whose `permission_type` is neither `all` nor `custom` is not a custom
 * role: everything downstream reads "not custom" as full access. A non-superadmin
 * must not be able to assign such a role even when its permission list is a
 * subset of their own, which is all the permission diff below ever checked.
 */
it('forbids assigning a role with an unrecognised permission_type', function () {
    $this->loginWithPermissions('custom', ['dashboard', 'settings.users.users.create']);

    $malformedRole = Role::factory()->create([
        'permission_type' => 'super',
        'permissions'     => ['dashboard'],
    ]);

    postJson(route('admin.settings.users.store'), createUserPayload($malformedRole->id))
        ->assertStatus(403)
        ->assertJsonFragment([
            'message' => trans('admin::app.settings.users.cannot-escalate-role'),
        ]);

    $this->assertDatabaseMissing('admins', ['role_id' => $malformedRole->id]);
});

it('omits a role with an unrecognised permission_type from the assignable list', function () {
    $malformedRole = Role::factory()->create([
        'permission_type' => 'super',
        'permissions'     => ['dashboard'],
    ]);

    $this->loginWithPermissions('custom', [
        'dashboard',
        'settings',
        'settings.users',
        'settings.users.users',
        'settings.users.users.create',
    ]);

    $response = $this->get(route('admin.settings.users.index'))->assertOk();

    $offered = collect($response->original->getData()['roles'])->pluck('id')->all();

    expect($offered)->not->toContain($malformedRole->id);
});
