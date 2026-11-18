<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\EffectiveAccessService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\FolderAccessService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * "People with access" in the folder-access panel (`grants`) and the Share dialog (`lists`): who is listed
 * comes from DirectoryPermissionService per admin, and each person carries the reasons they can open the
 * item. Other admins already in the database (a seeded super admin) may appear too, so every assertion
 * names the fixture admins it is about rather than the whole list.
 */

beforeEach(function () {
    $this->parent = Directory::create(['name' => 'Effective parent', 'parent_id' => null]);
    $this->folder = Directory::create(['name' => 'Effective folder', 'parent_id' => $this->parent->id]);
    $this->file = Asset::factory()->create(['file_name' => 'effective.jpg']);
    $this->file->directories()->attach($this->folder->id);

    $this->direct = effectiveAdmin();
    $this->inherited = effectiveAdmin();
    $this->grouped = effectiveAdmin();
    $this->roleHolder = effectiveAdmin();
    $this->allFolders = effectiveAdmin();
    $this->fullAdmin = effectiveAdmin(permissionType: 'all');
    $this->disabled = effectiveAdmin();
    $this->peer = effectiveAdmin();
});

function effectiveAdmin(string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function effectiveModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function effectiveGroup(string $name, array $members, ?string $systemKey = null): int
{
    $id = DB::table('dam_groups')->insertGetId(['name' => $name, 'system_key' => $systemKey, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(array_map(fn (Admin $admin) => ['group_id' => $id, 'admin_id' => $admin->id], $members));

    return $id;
}

/** @return array<int, list<string>> admin id => reasons */
function effectivePeople(string $type, int $id): array
{
    $people = [];

    foreach (app(EffectiveAccessService::class)->people(type: $type, id: $id) as $person) {
        $people[$person['id']] = $person['reasons'];
    }

    return $people;
}

/**
 * The list and the real check agree for every fixture admin: listed exactly when
 * DirectoryPermissionService, evaluated for that admin, lets them open the item.
 */
function effectiveParity(string $type, int $id, array $admins): void
{
    $people = effectivePeople(type: $type, id: $id);

    foreach ($admins as $admin) {
        $admin = $admin->fresh();
        $permissions = (new DirectoryPermissionService)->forAdmin($admin);
        $allowed = (bool) $admin->status && ($type === 'directory' ? $permissions->canAccess($id) : $permissions->canAccessAsset($id));

        expect(array_key_exists($admin->id, $people))->toBe($allowed, "admin {$admin->id} on {$type} {$id}");
    }
}

it('lists everyone the grants let in, each with why, and nobody else', function () {
    effectiveModel(AccessModel::GRANTS);

    DB::table('dam_directory_user')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->direct->id, 'inherit_children' => false]);
    DB::table('dam_directory_user')->insert(['directory_id' => $this->parent->id, 'admin_id' => $this->inherited->id, 'inherit_children' => true]);
    $crew = effectiveGroup(name: 'Effective crew', members: [$this->grouped]);
    DB::table('dam_directory_group')->insert(['directory_id' => $this->folder->id, 'group_id' => $crew, 'inherit_children' => false]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $this->roleHolder->role_id]);
    DB::table('dam_role_settings')->insert(['role_id' => $this->allFolders->role_id, 'all_directories' => true]);
    DB::table('dam_directory_user')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->disabled->id, 'inherit_children' => false]);
    $this->disabled->update(['status' => 0]);

    $people = effectivePeople(type: 'directory', id: $this->folder->id);
    $t = fn (string $key, array $replace = []) => trans('dam::app.admin.dam.access.'.$key, $replace);

    expect($people[$this->direct->id])->toBe([$t('reason-direct')])
        ->and($people[$this->inherited->id])->toBe([$t('reason-inherited', ['folder' => 'Effective parent'])])
        ->and($people[$this->grouped->id])->toBe([$t('reason-group', ['group' => 'Effective crew'])])
        ->and($people[$this->roleHolder->id])->toBe([$t('reason-role', ['role' => $this->roleHolder->role->name])])
        ->and($people[$this->allFolders->id])->toBe([$t('reason-all-folders', ['role' => $this->allFolders->role->name])])
        ->and($people[$this->fullAdmin->id])->toBe([$t('reason-full-admin', ['role' => $this->fullAdmin->role->name])])
        ->and($people)->not->toHaveKey($this->disabled->id)
        ->and($people)->not->toHaveKey($this->peer->id);

    // A file answers through the folder it sits in.
    expect(effectivePeople(type: 'asset', id: $this->file->id))->toHaveKey($this->direct->id)
        ->not->toHaveKey($this->peer->id);

    // The folder-access panel carries the same list.
    $snapshot = app(FolderAccessService::class)->snapshot($this->folder->fresh());
    expect(collect($snapshot['people'])->pluck('id')->all())->toContain($this->direct->id)
        ->not->toContain($this->peer->id);
});

it('lists the owner, the list entries and full admins under lists, and hides them from non-editors', function () {
    effectiveModel(AccessModel::LISTS);

    DB::table('dam_assets')->where('id', $this->file->id)->update(['created_by' => $this->direct->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->inherited->id]);
    $crew = effectiveGroup(name: 'Effective list crew', members: [$this->grouped]);
    DB::table('dam_asset_group_access')->insert(['asset_id' => $this->file->id, 'group_id' => $crew]);

    $people = effectivePeople(type: 'asset', id: $this->file->id);
    $t = fn (string $key, array $replace = []) => trans('dam::app.admin.dam.access.'.$key, $replace);

    expect($people[$this->direct->id])->toBe([$t('reason-owner')])
        ->and($people[$this->inherited->id])->toBe([$t('reason-direct')])
        ->and($people[$this->grouped->id])->toBe([$t('reason-group', ['group' => 'Effective list crew'])])
        ->and($people[$this->fullAdmin->id])->toBe([$t('reason-full-admin', ['role' => $this->fullAdmin->role->name])])
        ->and($people)->not->toHaveKey($this->peer->id)
        ->and($people)->not->toHaveKey($this->roleHolder->id);

    // Everyone: every active admin, through the built-in group; a disabled admin still never appears.
    $everyone = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
    DB::table('dam_asset_group_access')->insert(['asset_id' => $this->file->id, 'group_id' => $everyone]);
    $this->disabled->update(['status' => 0]);
    $people = effectivePeople(type: 'asset', id: $this->file->id);
    expect($people[$this->peer->id])->toBe([$t('reason-everyone')])
        ->and($people)->not->toHaveKey($this->disabled->id);

    $service = app(AccessListService::class);
    expect($service->describe(type: 'asset', id: $this->file->id, actor: $this->direct))->toHaveKey('people')
        ->and($service->describe(type: 'asset', id: $this->file->id, actor: $this->fullAdmin))->toHaveKey('people')
        // A listed non-editor opens the file but never sees who else can.
        ->and($service->describe(type: 'asset', id: $this->file->id, actor: $this->inherited))->not->toHaveKey('people');
});

it('renders the folder-access panel with the people list and its labels', function () {
    // The dialog lives in the pushed `scripts` stack, not in the tag the component renders in place.
    $html = Blade::render("@include('dam::components.folder-access')\n@stack('scripts')");

    expect($html)->toContain('data-dam-access-people')
        ->toContain('data-dam-access-person')
        ->toContain(trans('dam::app.admin.dam.access.people-heading'))
        ->toContain('hasAccessLabel(item)');
});

it('labels inherited group and role grants, skips a non-inheriting ancestor, and matches the real check', function () {
    effectiveModel(AccessModel::GRANTS);

    $crew = effectiveGroup(name: 'Effective parent crew', members: [$this->grouped]);
    DB::table('dam_directory_group')->insert(['directory_id' => $this->parent->id, 'group_id' => $crew, 'inherit_children' => true]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->parent->id, 'role_id' => $this->roleHolder->role_id]);
    DB::table('dam_role_settings')->insert(['role_id' => $this->roleHolder->role_id, 'all_directories' => false, 'inherit_children' => true]);
    // A grant on the parent that does not include subfolders reaches nobody here.
    DB::table('dam_directory_user')->insert(['directory_id' => $this->parent->id, 'admin_id' => $this->peer->id, 'inherit_children' => false]);
    $noRole = Admin::factory()->create(['role_id' => null, 'status' => 1]);
    $this->fullAdmin->update(['status' => 0]);

    $people = effectivePeople(type: 'directory', id: $this->folder->id);
    $t = fn (string $key, array $replace = []) => trans('dam::app.admin.dam.access.'.$key, $replace);

    expect($people[$this->grouped->id])->toBe([$t('reason-group-inherited', ['group' => 'Effective parent crew', 'folder' => 'Effective parent'])])
        ->and($people[$this->roleHolder->id])->toBe([$t('reason-role-inherited', ['role' => $this->roleHolder->role->name, 'folder' => 'Effective parent'])])
        ->and($people[$noRole->id])->toBe([$t('reason-no-role')])
        ->and($people)->not->toHaveKey($this->peer->id)
        ->and($people)->not->toHaveKey($this->fullAdmin->id);

    $everyone = [$this->direct, $this->inherited, $this->grouped, $this->roleHolder, $this->allFolders, $this->fullAdmin, $this->disabled, $this->peer, $noRole];
    effectiveParity(type: 'directory', id: $this->folder->id, admins: $everyone);
    effectiveParity(type: 'directory', id: $this->parent->id, admins: $everyone);
    effectiveParity(type: 'asset', id: $this->file->id, admins: $everyone);
});

it('lists a folder under lists, where an all-folders role is no bypass, and matches the real check', function () {
    effectiveModel(AccessModel::LISTS);

    DB::table('dam_directories')->where('id', $this->folder->id)->update(['created_by' => $this->direct->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->inherited->id]);
    DB::table('dam_role_settings')->insert(['role_id' => $this->allFolders->role_id, 'all_directories' => true]);

    $people = effectivePeople(type: 'directory', id: $this->folder->id);

    expect($people)->toHaveKey($this->direct->id)
        ->toHaveKey($this->inherited->id)
        ->not->toHaveKey($this->allFolders->id)
        ->not->toHaveKey($this->peer->id);

    $everyone = [$this->direct, $this->inherited, $this->grouped, $this->roleHolder, $this->allFolders, $this->fullAdmin, $this->disabled, $this->peer];
    effectiveParity(type: 'directory', id: $this->folder->id, admins: $everyone);
    effectiveParity(type: 'asset', id: $this->file->id, admins: $everyone);
});
