<?php

use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

use function Pest\Laravel\deleteJson;

/*
 * The last active all-access admin must not be deletable, or the system is left
 * with no superadmin (org-wide lockout of all-access management). A custom-role
 * user manager may not delete a full admin at all: its role is outranked (PERM-08).
 */
$deletePermissions = ['dashboard', 'settings', 'settings.users', 'settings.users.users', 'settings.users.users.delete'];

it('forbids a custom-role user manager from deleting an all-access admin', function () use ($deletePermissions) {
    $superadmin = Admin::whereHas('role', fn ($query) => $query->where('permission_type', 'all'))->firstOrFail();

    $this->loginWithPermissions('custom', $deletePermissions);

    deleteJson(route('admin.settings.users.delete', ['id' => $superadmin->id]))
        ->assertForbidden();

    $this->assertDatabaseHas('admins', ['id' => $superadmin->id]);
});

it('allows a full admin to delete an all-access admin when another remains', function () {
    $allRoleId = Role::where('permission_type', 'all')->value('id');

    $extra = Admin::factory()->create(['role_id' => $allRoleId, 'status' => 1]);

    $this->loginAsAdmin();

    deleteJson(route('admin.settings.users.delete', ['id' => $extra->id]))
        ->assertOk();

    $this->assertDatabaseMissing('admins', ['id' => $extra->id]);
});
