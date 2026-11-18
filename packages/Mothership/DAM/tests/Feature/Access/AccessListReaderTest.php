<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The access-list rule (ADR 0010) through DirectoryPermissionService's public methods, with the model
 * switched to `lists`. Each case checks a folder and a file, through the direct methods and through the
 * listing filter: a filter that disagrees with canAccessAsset() is how a page shows in the index but
 * refuses to open (audit A1).
 */

beforeEach(function () {
    // Built before anyone signs in, so no fixture picks up an owner from the session.
    $this->library = Directory::create(['name' => 'Lists library', 'parent_id' => null]);
    $this->shared = Directory::create(['name' => 'Lists shared', 'parent_id' => $this->library->id]);
    $this->elsewhere = Directory::create(['name' => 'Lists elsewhere', 'parent_id' => null]);
    $this->file = Asset::factory()->create(['file_name' => 'lists-shared.jpg']);
    $this->file->directories()->attach($this->shared->id);
    $this->otherFile = Asset::factory()->create(['file_name' => 'lists-elsewhere.jpg']);
    $this->otherFile->directories()->attach($this->elsewhere->id);

    listsReaderModel(AccessModel::LISTS);
});

/** Leaves the switch row at $model, as a finished `dam:access:switch` would. */
function listsReaderModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function listsReaderAdmin(string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function listsReaderActAs(Admin $admin): DirectoryPermissionService
{
    test()->actingAs($admin, 'admin');
    $service = app(DirectoryPermissionService::class);
    $service->flush();

    return $service;
}

/** Puts $folder and $file on the lists of one principal kind, for $admin. */
function listsReaderShare(string $principal, Admin $admin, Directory $folder, Asset $file): void
{
    switch ($principal) {
        case 'owner':
            DB::table('dam_directories')->where('id', $folder->id)->update(['created_by' => $admin->id]);
            DB::table('dam_assets')->where('id', $file->id)->update(['created_by' => $admin->id]);
            break;
        case 'user row':
            DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id]);
            DB::table('dam_asset_user_access')->insert(['asset_id' => $file->id, 'admin_id' => $admin->id]);
            break;
        case 'group row':
            $groupId = DB::table('dam_groups')->insertGetId(['name' => 'Lists crew '.$admin->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('dam_group_members')->insert(['group_id' => $groupId, 'admin_id' => $admin->id]);
            DB::table('dam_directory_group_access')->insert(['directory_id' => $folder->id, 'group_id' => $groupId]);
            DB::table('dam_asset_group_access')->insert(['asset_id' => $file->id, 'group_id' => $groupId]);
            break;
        case 'Everyone':
            $everyoneId = DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
            DB::table('dam_directory_group_access')->insert(['directory_id' => $folder->id, 'group_id' => $everyoneId]);
            DB::table('dam_asset_group_access')->insert(['asset_id' => $file->id, 'group_id' => $everyoneId]);
            break;
    }
}

/** The ids among $candidates that the listing filter lets through for the signed-in admin. */
function listsReaderListed(DirectoryPermissionService $service, array $candidates): array
{
    $query = Asset::query()->whereIn('dam_assets.id', $candidates);
    $service->applyAssetDirectoryGrantFilter($query);

    return $query->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

it('opens a listed folder and file through every method and shows the folders above as a path', function (string $principal) {
    $admin = listsReaderAdmin();
    listsReaderShare($principal, $admin, $this->shared, $this->file);
    $service = listsReaderActAs($admin);

    expect($service->bypass())->toBeFalse()
        ->and($service->canAccess($this->shared->id))->toBeTrue()
        ->and($service->canView($this->shared->id))->toBeTrue()
        ->and($service->directlyGrantedIds())->toBe([$this->shared->id])
        ->and($service->canAccessAsset($this->file->id))->toBeTrue()
        ->and(listsReaderListed($service, [$this->file->id, $this->otherFile->id]))->toBe([$this->file->id])
        ->and($service->viewableIds())->toEqualCanonicalizing([$this->library->id, $this->shared->id])
        // The folder above is a path only, and nothing else opens.
        ->and($service->canView($this->library->id))->toBeTrue()
        ->and($service->canAccess($this->library->id))->toBeFalse()
        ->and($service->canView($this->elsewhere->id))->toBeFalse()
        ->and($service->canAccess($this->elsewhere->id))->toBeFalse()
        ->and($service->canAccessAsset($this->otherFile->id))->toBeFalse();
})->with(['owner', 'user row', 'group row', 'Everyone']);

it('gives a non-member nothing that the lists name for someone else', function () {
    $other = listsReaderAdmin();
    foreach (['owner', 'user row', 'group row'] as $principal) {
        listsReaderShare($principal, $other, $this->shared, $this->file);
    }
    $service = listsReaderActAs(listsReaderAdmin());

    expect($service->bypass())->toBeFalse()
        ->and($service->canAccess($this->shared->id))->toBeFalse()
        ->and($service->canView($this->shared->id))->toBeFalse()
        ->and($service->canView($this->library->id))->toBeFalse()
        ->and($service->canAccessAsset($this->file->id))->toBeFalse()
        ->and(listsReaderListed($service, [$this->file->id, $this->otherFile->id]))->toBe([])
        ->and($service->viewableIds())->toBe([])
        ->and($service->directlyGrantedIds())->toBe([]);
});

it('lets a full admin and an admin with no role reach every folder and file (open decision 2)', function (string $who) {
    $admin = $who === 'full admin' ? listsReaderAdmin(permissionType: 'all') : Admin::factory()->create(['role_id' => null]);
    $service = listsReaderActAs($admin);

    expect($service->bypass())->toBeTrue()
        ->and($service->canAccess($this->elsewhere->id))->toBeTrue()
        ->and($service->canView($this->library->id))->toBeTrue()
        ->and($service->canAccessAsset($this->file->id))->toBeTrue()
        ->and($service->canAccessAsset($this->otherFile->id))->toBeTrue()
        ->and(listsReaderListed($service, [$this->file->id, $this->otherFile->id]))->toBe([$this->file->id, $this->otherFile->id])
        ->and($service->viewableIds())->toContain($this->library->id, $this->shared->id, $this->elsewhere->id);
})->with(['full admin', 'no role']);

it('denies a disabled admin every folder and file, whatever reaches them, through forAdmin()', function (string $who) {
    $admin = match ($who) {
        'full admin' => listsReaderAdmin(permissionType: 'all'),
        'no role'    => Admin::factory()->create(['role_id' => null]),
        default      => listsReaderAdmin(),
    };

    if (! in_array($who, ['full admin', 'no role'], true)) {
        listsReaderShare($who, $admin, $this->shared, $this->file);
    }

    // Enabled, the same admin opens both, so the denial below is the status check and nothing else.
    $service = app(DirectoryPermissionService::class)->forAdmin($admin);

    expect($service->canAccess($this->shared->id))->toBeTrue()
        ->and($service->canAccessAsset($this->file->id))->toBeTrue();

    DB::table('admins')->where('id', $admin->id)->update(['status' => 0]);
    $service->forAdmin($admin->fresh());

    expect($service->bypass())->toBeFalse()
        ->and($service->canAccess($this->shared->id))->toBeFalse()
        ->and($service->canView($this->shared->id))->toBeFalse()
        ->and($service->canView($this->library->id))->toBeFalse()
        ->and($service->canAccessAsset($this->file->id))->toBeFalse()
        ->and($service->directlyGrantedIds())->toBe([])
        ->and($service->viewableIds())->toBe([])
        ->and(listsReaderListed($service, [$this->file->id, $this->otherFile->id]))->toBe([]);
})->with(['full admin', 'no role', 'owner', 'user row', 'group row', 'Everyone']);

it('refuses a disabled admin through adminCanAccessAsset even when the list names them', function () {
    $admin = listsReaderAdmin();
    listsReaderShare('user row', $admin, $this->shared, $this->file);

    expect(DirectoryPermissionService::adminCanAccessAsset(adminId: $admin->id, assetId: $this->file->id))->toBeTrue()
        ->and(DirectoryPermissionService::adminCanAccessAsset(adminId: $admin->id, assetId: $this->otherFile->id))->toBeFalse();

    DB::table('admins')->where('id', $admin->id)->update(['status' => 0]);

    expect(DirectoryPermissionService::adminCanAccessAsset(adminId: $admin->id, assetId: $this->file->id))->toBeFalse();
});

it('never widens a file to the lists of the folders that hold it', function () {
    $admin = listsReaderAdmin();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $admin->id]);
    $service = listsReaderActAs($admin);

    expect($service->canAccess($this->shared->id))->toBeTrue()
        ->and($service->canAccessAsset($this->file->id))->toBeFalse()
        ->and(listsReaderListed($service, [$this->file->id]))->toBe([]);
});

it('shows the folder holding a file the admin can open as a path without opening the folder', function () {
    $admin = listsReaderAdmin();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $admin->id]);
    $service = listsReaderActAs($admin);

    expect($service->canAccessAsset($this->file->id))->toBeTrue()
        ->and($service->canView($this->shared->id))->toBeTrue()
        ->and($service->canView($this->library->id))->toBeTrue()
        ->and($service->canAccess($this->shared->id))->toBeFalse()
        ->and($service->directlyGrantedIds())->toBe([])
        ->and($service->canView($this->elsewhere->id))->toBeFalse();
});

it('keeps the filter ORs inside one group and holds under a table alias', function () {
    $admin = listsReaderAdmin();
    // Access through the middle branch: a leaked OR would let it past the caller's own condition.
    listsReaderShare('user row', $admin, $this->shared, $this->file);
    $service = listsReaderActAs($admin);

    $videos = Asset::query()->whereIn('dam_assets.id', [$this->file->id, $this->otherFile->id]);
    $service->applyAssetDirectoryGrantFilter($videos);
    $videos->where('dam_assets.file_type', 'video');

    $aliased = DB::table('dam_assets as a')->whereIn('a.id', [$this->file->id, $this->otherFile->id]);
    $service->applyAssetDirectoryGrantFilter($aliased, 'a.id');

    expect($videos->pluck('dam_assets.id')->all())->toBe([])
        ->and($aliased->pluck('a.id')->map(fn ($id) => (int) $id)->all())->toBe([$this->file->id]);
});

it('stops treating an all_directories role as a bypass under lists, and keeps it under grants', function () {
    $admin = listsReaderAdmin();
    DB::table('dam_role_settings')->insert(['role_id' => $admin->role_id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    $service = listsReaderActAs($admin);

    expect($service->bypass())->toBeFalse()
        ->and($service->canAccessAsset($this->file->id))->toBeFalse();

    listsReaderModel(AccessModel::GRANTS);

    expect($service->bypass())->toBeTrue()
        ->and($service->canAccessAsset($this->file->id))->toBeTrue();
});

it('reads a pending switch, or any value but exactly lists, as grants', function (string $state) {
    $admin = listsReaderAdmin();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $admin->id]);
    listsReaderModel($state);
    $service = listsReaderActAs($admin);

    // The user list names the admin, but no grant does: only the lists model would open the folder.
    expect(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS)
        ->and(app(AccessModel::class)->raw())->toBe($state)
        ->and($service->canAccess($this->shared->id))->toBeFalse();
})->with(['a pending switch' => ['pending:0f8b6c1e'], 'a stray value' => ['Lists']]);
