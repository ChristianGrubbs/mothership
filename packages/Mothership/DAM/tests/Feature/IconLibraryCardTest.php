<?php

use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

it('shows icon access to users without directory permissions even when optional sidebar panels are disabled', function (bool $explorer, bool $panels) {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.assets'],
    ]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    config([
        'dam-home.icon_library_url'      => 'https://icons.example.org',
        'dam.explorer.enabled'           => $explorer,
        'dam.explorer.show_tree'         => $panels,
        'dam.explorer.bookmarks_enabled' => $panels,
    ]);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('data-explorer-sidebar', false)
        ->assertSee('data-dam-icon-library', false)
        ->assertSee('https://icons.example.org/', false)
        ->assertSee('Icon Library');
})->with([
    'explorer with panels'    => [true, true],
    'explorer without panels' => [true, false],
    'legacy browser'          => [false, false],
]);

it('hides the icon library card and dialog script when no icon library URL is set', function () {
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.assets'],
    ]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    config(['dam-home.icon_library_url' => null]);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertDontSee('data-dam-icon-library', false)
        ->assertDontSee('Icon Library')
        ->assertDontSee('MothershipIconvaultUrl', false);
});
