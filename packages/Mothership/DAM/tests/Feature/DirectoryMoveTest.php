<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('rejects moving a folder into itself before queueing work', function () {
    Bus::fake();
    $folder = Directory::factory()->create(['name' => 'Self target']);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $folder->id,
        'new_parent_id' => $folder->id,
    ])->assertUnprocessable();

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
    expect($folder->fresh()->parent_id)->toBe($folder->parent_id);
});

it('rejects moving a folder into its descendant before queueing work', function () {
    Bus::fake();
    $parent = Directory::factory()->create(['name' => 'Move parent']);
    $child = Directory::factory()->create(['name' => 'Move child', 'parent_id' => $parent->id]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $parent->id,
        'new_parent_id' => $child->id,
    ])->assertUnprocessable();

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
    expect($parent->fresh()->parent_id)->toBe($parent->parent_id);
});

it('rejects moving the protected root folder', function () {
    Bus::fake();
    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    $target = Directory::factory()->create(['name' => 'Root target']);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $root->id,
        'new_parent_id' => $target->id,
    ])->assertForbidden();

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
});

it('keeps the Library client in bypass mode for an all-permission admin', function () {
    $root = Directory::firstOrCreate(['parent_id' => null], ['name' => 'Library']);
    $allRole = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $allRole->id]);
    Directory::factory()->create(['name' => 'Ordinary source', 'parent_id' => $root->id]);

    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(dam_acl_bypass())->toBeTrue()
        ->and(dam_accessible_dir_ids())->toBe([]);
});

it('still ships an explicit accessible-id list to a role that is not a bypass role', function () {
    $root = Directory::firstOrCreate(['parent_id' => null], ['name' => 'Library']);
    $granted = Directory::factory()->create(['name' => 'Granted', 'parent_id' => $root->id]);
    $denied = Directory::factory()->create(['name' => 'Denied', 'parent_id' => $root->id]);
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $granted->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(dam_acl_bypass())->toBeFalse()
        ->and(dam_accessible_dir_ids())->toContain($granted->id)
        ->and(dam_accessible_dir_ids())->not->toContain($denied->id);
});

it('names the folders a refused move was about instead of the permissions screen', function () {
    Bus::fake();
    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Ungranted source', 'parent_id' => $root->id]);
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.directory', 'dam.directory.moved'],
    ]);
    // A granted child keeps both folders in the role's tree: a folder it cannot even see answers like a
    // missing id instead (DirectoryHiddenEqualsMissingTest).
    $child = Directory::factory()->create(['name' => 'Granted child', 'parent_id' => $source->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $child->id, 'role_id' => $role->id]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $source->id,
        'new_parent_id' => $root->id,
    ])->assertForbidden()
        ->assertJsonPath('message', trans('dam::app.admin.permissions.move-unauthorized'));

    expect(trans('dam::app.admin.permissions.move-unauthorized'))
        ->toBe('You do not have access to the folder being moved or its destination.');

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
});

it('requires direct access to both the source folder and destination root', function () {
    Bus::fake();
    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Granted source', 'parent_id' => $root->id]);
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.directory', 'dam.directory.moved'],
    ]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $source->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $source->id,
        'new_parent_id' => $root->id,
    ])->assertForbidden();

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
});

it('queues a move to root when the role can access both folders', function () {
    Bus::fake();
    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Move to root', 'parent_id' => $root->id]);
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.directory', 'dam.directory.moved'],
    ]);
    foreach ([$source->id, $root->id] as $directoryId) {
        DB::table('dam_directory_role')->insert([
            'directory_id' => $directoryId,
            'role_id'      => $role->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    app(DirectoryPermissionService::class)->flush();

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $source->id,
        'new_parent_id' => $root->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});
