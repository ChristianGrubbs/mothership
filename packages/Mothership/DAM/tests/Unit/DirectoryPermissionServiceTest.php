<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->service = app(DirectoryPermissionService::class);
});

it('bypasses filtering when no admin is authenticated (API/CLI/anon)', function () {
    $this->service->flush();

    expect($this->service->bypass())->toBeTrue();
});

it('returns true for every directory when role permission_type is all', function () {
    $allRole = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $allRole->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $directory = Directory::factory()->create();

    expect($this->service->bypass())->toBeTrue();
    expect($this->service->canView($directory->id))->toBeTrue();
    expect($this->service->canView(99999))->toBeTrue();
});

it('returns true only for directories granted to the role for custom roles', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $granted = Directory::factory()->create();
    $denied = Directory::factory()->create();

    DB::table('dam_directory_role')->insert([
        'directory_id' => $granted->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    expect($this->service->canView($granted->id))->toBeTrue();
    expect($this->service->canView($denied->id))->toBeFalse();
});

it('memoises viewableIds within the same request', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $dir = Directory::factory()->create();
    DB::table('dam_directory_role')->insert([
        'directory_id' => $dir->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->service->viewableIds();
    $this->service->viewableIds();
    $this->service->viewableIds();

    $count = collect(DB::getQueryLog())->filter(
        fn ($q) => str_contains(strtolower($q['query']), 'dam_directory_role')
    )->count();

    expect($count)->toBe(1);
});

it('exposes ancestors of granted directories as viewable but not accessible', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $root = Directory::create(['name' => 'AclTestRoot', 'parent_id' => null]);
    $av = Directory::create(['name' => 'AudioVideo', 'parent_id' => $root->id]);
    $audio = Directory::create(['name' => 'Audio', 'parent_id' => $av->id]);
    $other = Directory::create(['name' => 'Other', 'parent_id' => $root->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $audio->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $viewable = $this->service->viewableIds();

    expect($viewable)->toContain($audio->id);
    expect($viewable)->toContain($av->id);
    expect($viewable)->toContain($root->id);

    expect($viewable)->not->toContain($other->id);

    expect($this->service->canView($av->id))->toBeTrue();
    expect($this->service->canView($audio->id))->toBeTrue();

    expect($this->service->canAccess($audio->id))->toBeTrue();
    expect($this->service->canAccess($av->id))->toBeFalse();
    expect($this->service->canAccess($root->id))->toBeFalse();
});

it('does not bypass for an api-guard user with a custom role', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'api');
    $this->service->flush();

    expect($this->service->bypass())->toBeFalse();
});

it('bypasses for an api-guard user with permission_type all', function () {
    $role = Role::factory()->create(['permission_type' => 'all']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'api');
    $this->service->flush();

    expect($this->service->bypass())->toBeTrue();
});

it('bypasses for a custom role with all_directories set in dam_role_settings', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'all_directories' => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $this->actingAs($admin, 'admin');
    $this->service->flush();

    expect($this->service->bypass())->toBeTrue();
});

it('does not bypass for a custom role without all_directories', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    $this->actingAs($admin, 'admin');
    $this->service->flush();

    expect($this->service->bypass())->toBeFalse();
});

it('directlyGrantedIds returns only explicit grants when inherit_children is false', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $parent = Directory::create(['name' => 'IcParent', 'parent_id' => null]);
    $child = Directory::create(['name' => 'IcChild', 'parent_id' => $parent->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $parent->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $role->id],
        ['all_directories' => false, 'inherit_children' => false, 'created_at' => now(), 'updated_at' => now()]
    );

    $ids = $this->service->directlyGrantedIds();

    expect($ids)->toContain($parent->id);
    expect($ids)->not->toContain($child->id);
});

it('directlyGrantedIds includes all descendants when inherit_children is true', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $parent = Directory::create(['name' => 'IcExpandParent', 'parent_id' => null]);
    $child = Directory::create(['name' => 'IcExpandChild', 'parent_id' => $parent->id]);
    $grandchild = Directory::create(['name' => 'IcExpandGrandchild', 'parent_id' => $child->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $parent->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $role->id],
        ['all_directories' => false, 'inherit_children' => true, 'created_at' => now(), 'updated_at' => now()]
    );

    $ids = $this->service->directlyGrantedIds();

    expect($ids)->toContain($parent->id);
    expect($ids)->toContain($child->id);
    expect($ids)->toContain($grandchild->id);
});

it('directlyGrantedIds returns empty array when inherit_children is true but no explicit grants exist', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $role->id],
        ['all_directories' => false, 'inherit_children' => true, 'created_at' => now(), 'updated_at' => now()]
    );

    expect($this->service->directlyGrantedIds())->toBe([]);
});

it('canAccess returns true for inherited descendants when inherit_children is true', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $parent = Directory::create(['name' => 'IcAccessParent', 'parent_id' => null]);
    $child = Directory::create(['name' => 'IcAccessChild', 'parent_id' => $parent->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $parent->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $role->id],
        ['all_directories' => false, 'inherit_children' => true, 'created_at' => now(), 'updated_at' => now()]
    );

    expect($this->service->canAccess($child->id))->toBeTrue();
    expect($this->service->canAccess($parent->id))->toBeTrue();
});

it('directlyGrantedIds result is memoised per request when inherit_children is true', function () {
    $role = Role::factory()->create(['permission_type' => 'custom']);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $parent = Directory::create(['name' => 'IcMemoParent', 'parent_id' => null]);
    Directory::create(['name' => 'IcMemoChild', 'parent_id' => $parent->id]);

    DB::table('dam_directory_role')->insert([
        'directory_id' => $parent->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $role->id],
        ['all_directories' => false, 'inherit_children' => true, 'created_at' => now(), 'updated_at' => now()]
    );

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->service->directlyGrantedIds();
    $this->service->directlyGrantedIds();
    $this->service->directlyGrantedIds();

    $count = collect(DB::getQueryLog())->filter(
        fn ($q) => str_contains(strtolower($q['query']), 'dam_directory_role')
    )->count();

    expect($count)->toBe(1);
});

/*
 * roleBypass() asked whether the role was *not* custom, so a role carrying any
 * unrecognised permission_type silently received the full-access directory
 * bypass. Only the explicit `all` type is a superadmin; every other value is
 * restricted exactly like `custom` — grants plus its own dam_role_settings opt-in.
 */
it('does not bypass directory filtering for a role with an unrecognised permission_type', function () {
    $role = Role::factory()->create(['permission_type' => 'super', 'permissions' => ['dashboard']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    $granted = Directory::factory()->create();
    $denied = Directory::factory()->create();

    DB::table('dam_directory_role')->insert([
        'directory_id' => $granted->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    expect($this->service->roleBypass())->toBeFalse();
    expect($this->service->bypass())->toBeFalse();
    expect($this->service->bypassesDirectory($denied->id))->toBeFalse();
    expect($this->service->canAccess($granted->id))->toBeTrue();
    expect($this->service->canAccess($denied->id))->toBeFalse();
});

it('does not bypass directory filtering for a near-miss permission_type', function () {
    $role = Role::factory()->create(['permission_type' => ' all', 'permissions' => ['dashboard']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    expect($this->service->roleBypass())->toBeFalse();
});

it('treats an unrecognised permission_type exactly like custom for dam_role_settings', function () {
    /*
     * The restricted branch is shared, not special-cased: an unrecognised type still
     * honours an all_directories row an administrator deliberately granted, the same
     * way a `custom` role does. What it never gets is the implicit superadmin bypass.
     */
    $role = Role::factory()->create(['permission_type' => 'super', 'permissions' => ['dashboard']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'all_directories' => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $this->actingAs($admin, 'admin');
    $this->service->flush();

    expect($this->service->roleBypass())->toBeTrue();
});

it('still bypasses for an admin with no role row at all', function () {
    /*
     * Pre-existing behaviour for the nullable admins.role_id column, unchanged by
     * the permission_type hardening and pinned here so it stays deliberate.
     */
    $admin = Admin::factory()->create(['role_id' => null]);
    $this->actingAs($admin, 'admin');
    $this->service->flush();

    expect($this->service->roleBypass())->toBeTrue();
});
