<?php

use Mothership\User\Models\Role;

use function Pest\Laravel\putJson;

/*
 * Guards against privilege escalation through the role editor: a non-superadmin
 * must not promote a role to full access. (Defining an over-privileged custom
 * role is allowed by design — the escalation vector is *assigning* it, which is
 * blocked separately in UserController; see UserRoleAssignmentEscalationTest.)
 */
it('forbids a non-all admin from promoting a role to full access', function () {
    $admin = $this->loginWithPermissions('custom', ['dashboard', 'settings.roles', 'settings.roles.edit']);

    putJson(route('admin.settings.roles.update', ['id' => $admin->role_id]), [
        'name'            => 'Escalated',
        'permission_type' => 'all',
    ])->assertStatus(403);

    expect(Role::find($admin->role_id)->permission_type)->toBe('custom');
});

it('forbids a non-all admin from promoting a role to full access regardless of casing', function () {
    $admin = $this->loginWithPermissions('custom', ['dashboard', 'settings.roles', 'settings.roles.edit']);

    putJson(route('admin.settings.roles.update', ['id' => $admin->role_id]), [
        'name'            => 'Escalated',
        'permission_type' => 'All',
    ])->assertStatus(403);

    expect(Role::find($admin->role_id)->permission_type)->toBe('custom');
});

it('allows a superadmin to keep a role at full access', function () {
    $this->loginWithPermissions('all', ['dashboard']);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);

    putJson(route('admin.settings.roles.update', ['id' => $role->id]), [
        'name'            => 'Promoted',
        'permission_type' => 'all',
    ])->assertStatus(200);

    expect(Role::find($role->id)->permission_type)->toBe('all');
});

/*
 * Creation side of the same boundary. `permission_type` was only constrained to
 * `in:all,custom` when updating, so a role created with an unrecognised value was
 * persisted verbatim — and every downstream check reads "not custom" as full
 * access (DirectoryPermissionService::roleBypass(), UserController assignment).
 */
it('rejects an unrecognised permission_type when creating a role', function () {
    $this->loginWithPermissions('custom', [
        'dashboard',
        'settings.roles',
        'settings.roles.create',
        'settings.users',
        'settings.users.users.create',
    ]);

    $this->postJson(route('admin.settings.roles.store'), [
        'name'            => 'Malformed Type',
        'permission_type' => 'super',
        'permissions'     => ['dashboard'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permission_type');

    expect(Role::where('name', 'Malformed Type')->exists())->toBeFalse();
});

it('rejects a permission_type that only differs from custom by casing', function () {
    // The `in` rule is case-sensitive, and it has to be: a role stored as `Custom`
    // is "not custom" to roleBypass() and to the assignment guard — i.e. full access.
    $this->loginWithPermissions('custom', [
        'dashboard',
        'settings.roles',
        'settings.roles.create',
    ]);

    $this->postJson(route('admin.settings.roles.store'), [
        'name'            => 'Near Miss Type',
        'permission_type' => 'Custom',
        'permissions'     => ['dashboard'],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('permission_type');

    expect(Role::where('name', 'Near Miss Type')->exists())->toBeFalse();
});

it('still creates a custom role for an admin holding the create permission', function () {
    $this->loginWithPermissions('custom', [
        'dashboard',
        'settings.roles',
        'settings.roles.create',
    ]);

    $this->postJson(route('admin.settings.roles.store'), [
        'name'            => 'Legitimate Custom',
        'permission_type' => 'custom',
        'permissions'     => ['dashboard'],
    ])->assertStatus(200);

    expect(Role::where('name', 'Legitimate Custom')->value('permission_type'))->toBe('custom');
});
