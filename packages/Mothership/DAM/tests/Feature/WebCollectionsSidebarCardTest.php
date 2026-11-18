<?php

use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

function actingAsDamAdminWithPermissions(array $permissions): void
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    test()->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
}

it('lists the Web Pages collection folders in the Library sidebar instead of bookmarks', function () {
    actingAsDamAdminWithPermissions(['dam', 'dam.assets', 'dam.pages']);
    config([
        'dam.explorer.enabled'           => true,
        'dam.explorer.show_tree'         => true,
        'dam.explorer.bookmarks_enabled' => true,
        'dam.web_pages.enabled'          => true,
    ]);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-dam-web-collections', false)
        ->assertSee(route('admin.dam.pages.listing'), false)
        ->assertSee('All web pages')
        ->assertDontSee('No bookmarks yet.');
});

it('hides the Web Pages folders card from roles without the pages permission', function () {
    actingAsDamAdminWithPermissions(['dam', 'dam.assets']);
    config(['dam.explorer.enabled' => true, 'dam.web_pages.enabled' => true]);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertDontSee('data-dam-web-collections', false);
});

it('hides the Web Pages folders card when the Web Pages area is disabled', function () {
    actingAsDamAdminWithPermissions(['dam', 'dam.assets', 'dam.pages']);
    config(['dam.explorer.enabled' => true, 'dam.web_pages.enabled' => false]);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertDontSee('data-dam-web-collections', false);
});
