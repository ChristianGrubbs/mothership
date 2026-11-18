<?php

use Mothership\DAM\Vectorization\Allowance;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

function vectorizationManager(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['settings.users.users.edit'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

it('starts at zero and exempts only the configured account', function () {
    $owner = Admin::factory()->create();
    $other = Admin::factory()->create();
    config(['vectorization.owner_admin_id' => $owner->id]);
    $service = app(Allowance::class);

    expect($service->read(adminId: $other->id))->toMatchArray([
        'unlimited' => false,
        'remaining' => 0,
        'reserved'  => 0,
    ]);
    expect($service->read(adminId: $owner->id))->toMatchArray([
        'unlimited' => true,
        'remaining' => null,
    ]);

    config(['vectorization.owner_admin_id' => null]);
    expect($service->read(adminId: $owner->id)['remaining'])->toBe(0);
});

it('sets finite balances with revision checks', function () {
    $manager = vectorizationManager();
    $target = Admin::factory()->create();
    $this->actingAs($manager, 'admin');
    $url = route('admin.home.vectorization.allowance', ['admin' => $target->id]);

    $this->getJson($url)
        ->assertOk()
        ->assertExactJson([
            'unlimited' => false,
            'remaining' => 0,
            'reserved'  => 0,
            'revision'  => 0,
        ]);
    $this->putJson($url, ['remaining' => 7, 'revision' => 0])
        ->assertOk()
        ->assertJsonPath('remaining', 7);
    $this->putJson($url, ['remaining' => 9, 'revision' => 0])->assertConflict();
    $this->putJson($url, ['remaining' => -1, 'revision' => 1])->assertUnprocessable();
    $this->putJson($url, ['remaining' => null, 'revision' => 1])->assertUnprocessable();

    expect(app(Allowance::class)->read(adminId: $target->id)['remaining'])->toBe(7);
});

it('blocks self grants without user-management permission and protects the owner', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.assets'],
    ]);
    $user = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($user, 'admin');
    $this->putJson(
        route('admin.home.vectorization.allowance', ['admin' => $user->id]),
        ['remaining' => 10, 'revision' => 0],
    )->assertForbidden();

    $manager = vectorizationManager();
    $this->actingAs($manager, 'admin');
    config(['vectorization.owner_admin_id' => $user->id]);
    $this->putJson(
        route('admin.home.vectorization.allowance', ['admin' => $user->id]),
        ['remaining' => 10, 'revision' => 0],
    )->assertUnprocessable();
});

it('renders the allowance panel on a manager user-edit route', function () {
    $manager = vectorizationManager();
    // A user the manager may edit: one whose role does not outrank theirs (PERM-08).
    $target = Admin::factory()->create(['role_id' => $manager->role_id]);
    $this->actingAs($manager, 'admin');

    $this->get(route('admin.settings.users.edit', ['id' => $target->id]))
        ->assertOk()
        ->assertSee('id="vectorization-allowance"', false);
});

it('omits the allowance panel from the self account route', function () {
    $manager = vectorizationManager();
    $this->actingAs($manager, 'admin');

    $this->get(route('admin.account.edit'))
        ->assertOk()
        ->assertDontSee('id="vectorization-allowance"', false);
});
