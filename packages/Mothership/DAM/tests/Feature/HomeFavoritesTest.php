<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->favoriteAdmin = Admin::factory()->create();
    $this->actingAs($this->favoriteAdmin, 'admin');
});

it('favorites assets idempotently per account and returns newest first', function () {
    $assets = Asset::withoutEvents(fn () => Asset::factory()->count(2)->create());
    foreach ($assets as $asset) {
        $this->postJson('/admin/home/favorites/'.$asset->id)->assertOk()->assertJsonPath('favorite', true);
        $this->getJson('/admin/home/favorites/'.$asset->id)->assertOk()->assertJsonPath('favorite', true);
    }
    $this->postJson('/admin/home/favorites/'.$assets[1]->id)->assertOk();
    expect(DB::table('dam_asset_favorites')->count())->toBe(2);
    $this->getJson('/admin/home/favorites')->assertOk()->assertJsonCount(2, 'assets')
        ->assertJsonPath('assets.0.id', $assets[1]->id);

    $other = Admin::factory()->create();
    $this->actingAs($other, 'admin');
    $this->getJson('/admin/home/favorites')->assertJsonCount(0, 'assets');
    $this->postJson('/admin/home/favorites/'.$assets[0]->id)->assertOk();
    expect(DB::table('dam_asset_favorites')->where('dam_asset_id', $assets[0]->id)->count())->toBe(2);

    $this->deleteJson('/admin/home/favorites/'.$assets[0]->id)->assertOk()->assertJsonPath('favorite', false);
    $this->getJson('/admin/home/favorites')->assertJsonCount(0, 'assets');
});

it('never lists or favorites an asset outside the account folder grants', function () {
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $assets = Asset::withoutEvents(fn () => Asset::factory()->count(2)->create());
    $assets[0]->directories()->attach($allowed->id);
    $assets[1]->directories()->attach($private->id);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson('/admin/home/favorites/'.$assets[0]->id)->assertOk();
    $this->postJson('/admin/home/favorites/'.$assets[1]->id)->assertNotFound();
    $this->deleteJson('/admin/home/favorites/'.$assets[1]->id)->assertNotFound();
    $this->getJson('/admin/home/favorites')->assertOk()->assertJsonCount(1, 'assets')
        ->assertJsonPath('assets.0.id', $assets[0]->id);

    DB::table('dam_directory_role')->where('role_id', $role->id)->delete();
    app(DirectoryPermissionService::class)->flush();
    $this->getJson('/admin/home/favorites')->assertJsonCount(0, 'assets');
});

it('returns not found for missing assets and removes deleted assets through the foreign key', function () {
    $this->getJson('/admin/home/favorites/999999')->assertNotFound();
    $this->postJson('/admin/home/favorites/999999')->assertNotFound();
    $this->deleteJson('/admin/home/favorites/999999')->assertNotFound();
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create());
    $this->postJson('/admin/home/favorites/'.$asset->id)->assertOk();
    // In the trash (P2 S4) the favourite is hidden and answers like a missing asset, and comes back with Restore.
    Asset::withoutEvents(fn () => $asset->delete());
    $this->getJson('/admin/home/favorites')->assertJsonCount(0, 'assets');
    $this->getJson('/admin/home/favorites/'.$asset->id)->assertNotFound();
    $this->assertDatabaseHas('dam_asset_favorites', ['dam_asset_id' => $asset->id]);
    Asset::withoutEvents(fn () => $asset->forceDelete());
    $this->assertDatabaseMissing('dam_asset_favorites', ['dam_asset_id' => $asset->id]);
});

it('requires asset permission for every favorite route', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['settings']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->getJson('/admin/home/favorites')->assertForbidden();
    $this->getJson('/admin/home/favorites/status?ids[]=1')->assertForbidden();
    $this->postJson('/admin/home/favorites/1')->assertForbidden();
    $this->deleteJson('/admin/home/favorites/1')->assertForbidden();
});

it('returns favorite status for a batch without leaking inaccessible assets', function () {
    $allowed = Directory::factory()->create();
    $private = Directory::factory()->create();
    $assets = Asset::withoutEvents(fn () => Asset::factory()->count(3)->create());
    $assets[0]->directories()->attach($allowed->id);
    $assets[1]->directories()->attach($allowed->id);
    $assets[2]->directories()->attach($private->id);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.assets']]);
    DB::table('dam_directory_role')->insert(['role_id' => $role->id, 'directory_id' => $allowed->id]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    DB::table('dam_asset_favorites')->insert([
        ['admin_id' => $admin->id, 'dam_asset_id' => $assets[0]->id, 'created_at' => now()],
        ['admin_id' => $admin->id, 'dam_asset_id' => $assets[2]->id, 'created_at' => now()],
    ]);
    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->getJson('/admin/home/favorites/status?'.http_build_query(['ids' => $assets->pluck('id')->all()]))
        ->assertOk()
        ->assertExactJson(['favorites' => [(string) $assets[0]->id => true, (string) $assets[1]->id => false]]);
    $this->getJson('/admin/home/favorites/status?'.http_build_query(['ids' => range(1, 101)]))->assertUnprocessable();
});

it('labels a favorited GLB "3D model" in the favorites payload', function () {
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_type' => 'document', 'extension' => 'glb']));
    $this->postJson('/admin/home/favorites/'.$asset->id)->assertOk();

    $this->getJson('/admin/home/favorites')->assertOk()
        ->assertJsonPath('assets.0.type_label', '3D model');
});
