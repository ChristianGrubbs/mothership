<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Role;

/*
 * Sharing slice 5: RoleBuilder::keepShareKeysUnderLists() keeps the six share ACL keys on every custom role
 * save under lists, independent of whether the request went through the DAM Sandbox tab
 * (dam_role_builder_managed). These tests drive the real admin.settings.roles routes, not the service
 * directly, so they prove the wiring RoleController::fenceRoleWrite() sits in front of, not just the
 * service's own logic (ShareKeyGrantTest.php covers that in isolation). Under grants, parity: nothing here
 * changes from the base commit.
 */

beforeEach(function () {
    $this->loginAsAdmin();
});

function roleShareKeysSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

it('keeps the six share keys on a new custom role created under lists, even without the DAM tab', function () {
    roleShareKeysSetModel(AccessModel::LISTS);

    $this->post(route('admin.settings.roles.store'), [
        'name'            => 'Lists create role',
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset.view'],
    ])->assertRedirect();

    $role = Role::query()->where('name', 'Lists create role')->firstOrFail();

    expect($role->permissions)->toContain(
        'dam.asset.share', 'dam.directory.share', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke', 'dam.shares.delete',
    )->and($role->permissions)->toContain('dam', 'dam.asset.view');
});

it('keeps the six share keys on an existing custom role updated under lists, even without the DAM tab', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);

    roleShareKeysSetModel(AccessModel::LISTS);

    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'            => $role->name,
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset.view'],
    ])->assertRedirect();

    $role->refresh();

    expect($role->permissions)->toContain(
        'dam.asset.share', 'dam.directory.share', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke', 'dam.shares.delete',
    );
});

it('never adds the six share keys under grants, matching the base commit', function () {
    roleShareKeysSetModel(AccessModel::GRANTS);

    $this->post(route('admin.settings.roles.store'), [
        'name'            => 'Grants create role',
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset.view'],
    ])->assertRedirect();

    $role = Role::query()->where('name', 'Grants create role')->firstOrFail();

    expect($role->permissions)->toBe(['dam', 'dam.asset.view']);
});

it('keeps the six share keys on a managed Sandbox-tab save under lists, even though public_shares is hidden from the form', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);

    roleShareKeysSetModel(AccessModel::LISTS);

    // A real DAM Sandbox tab submission: dam_role_builder_managed=1, capabilities chosen from the rendered
    // checkboxes only — public_shares never appears among them once lists hides it (DAMServiceProvider's
    // shareCapabilityManaged flag), so it is never in dam_capabilities[] either.
    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'                     => $role->name,
        'permission_type'          => 'custom',
        'permissions'              => [],
        'dam_role_builder_managed' => '1',
        'dam_capabilities'         => ['library'],
        'dam_home_widgets'         => [],
    ])->assertRedirect();

    $role->refresh();

    expect($role->permissions)->toContain(
        'dam.asset.share', 'dam.directory.share', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke', 'dam.shares.delete',
    )->and($role->permissions)->not->toContain('public_shares');
});

it('never adds the six share keys to an existing custom role updated under grants, matching the base commit', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);

    roleShareKeysSetModel(AccessModel::GRANTS);

    $this->put(route('admin.settings.roles.update', $role->id), [
        'name'            => $role->name,
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.asset.view'],
    ])->assertRedirect();

    $role->refresh();

    expect($role->permissions)->toBe(['dam', 'dam.asset.view']);
});

it('never adds the six share keys to a role saved as all-access, whatever the model', function () {
    roleShareKeysSetModel(AccessModel::LISTS);

    $this->post(route('admin.settings.roles.store'), [
        'name'            => 'All access role',
        'permission_type' => 'all',
        'permissions'     => [],
    ])->assertRedirect();

    $role = Role::query()->where('name', 'All access role')->firstOrFail();

    expect($role->permissions ?? [])->not->toContain('dam.shares');
});
