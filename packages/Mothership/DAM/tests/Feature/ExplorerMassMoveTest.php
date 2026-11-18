<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('mass moves directories to target by dispatching jobs', function () {
    Bus::fake();

    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => null]);
    $dir1 = Directory::factory()->create(['name' => 'DirA', 'parent_id' => null]);
    $dir2 = Directory::factory()->create(['name' => 'DirB', 'parent_id' => null]);

    $response = $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [$dir1->id, $dir2->id],
        'target_directory_id' => $target->id,
    ]);

    $response->assertOk();
    Bus::assertDispatchedTimes(MassMove::class, 1);
});

it('rejects a directory move when the target is inside its subtree', function () {
    Bus::fake();

    $parent = Directory::factory()->create(['name' => 'Parent', 'parent_id' => null]);
    $child = Directory::factory()->create(['name' => 'Child', 'parent_id' => $parent->id]);

    $response = $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [$parent->id],
        'target_directory_id' => $child->id,
    ]);

    $response->assertUnprocessable();
    Bus::assertNotDispatched(MassMove::class);
});

it('rejects the protected root directory in a mass move', function () {
    Bus::fake();

    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => null]);

    $response = $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [$root->id],
        'target_directory_id' => $target->id,
    ]);

    $response->assertForbidden();
    Bus::assertNotDispatched(MassMove::class);
});

it('rejects a directory move when the source is also the target', function () {
    Bus::fake();
    $folder = Directory::factory()->create(['name' => 'Same target']);

    $response = $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [$folder->id],
        'target_directory_id' => $folder->id,
    ]);

    $response->assertUnprocessable();
    Bus::assertNotDispatched(MassMove::class);
});

it('returns 422 when target_directory_id is missing', function () {
    $response = $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'     => [],
        'directory_ids' => [1],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['target_directory_id']);
});

it('returns 422 when target_directory_id does not exist', function () {
    $response = $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [],
        'target_directory_id' => 999999,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['target_directory_id']);
});

it('names the refused action in each mass-move denial: a role without folder-move rights is told so', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.directory', 'dam.directory.index']]);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => null]);
    $dir = Directory::factory()->create(['name' => 'DirA', 'parent_id' => null]);
    DB::table('dam_directory_role')->insert([['directory_id' => $target->id, 'role_id' => $role->id], ['directory_id' => $dir->id, 'role_id' => $role->id]]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $folders = $this->postJson(route('admin.dam.explorer.mass_move'), ['directory_ids' => [$dir->id], 'target_directory_id' => $target->id]);

    $folders->assertForbidden();
    expect($folders->json('message'))->toBe(trans('dam::app.admin.permissions.denied-move-folders'));

});
