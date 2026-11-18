<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mothership\DAM\Listeners\DeleteAdminAccessRows;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessComparator;
use Mothership\DAM\Services\Access\AccessListRebuilder;
use Mothership\DAM\Services\Access\LegacyGrantReader;
use Mothership\User\Models\Admin;
use Mothership\User\Models\AdminProxy;
use Mothership\User\Models\Role;
use Mothership\User\Repositories\AdminRepository;

/*
 * The rebuild turns today's grants into access lists (ADR 0010), and the comparison proves that every
 * active admin keeps exactly the access the grants give. A deleted admin's list rows go with them on
 * every driver: through the foreign-key cascade, or on SQL Server through a cleanup that commits or
 * rolls back with the delete. The rebuild and the comparison scan whole tables, so each test starts
 * from an empty tree (rolled back with the test transaction).
 */

beforeEach(function () {
    $this->damResetAssetTables();
});

function accessRebuildRole(string $name, string $permissionType = 'custom', ?array $settings = null): Role
{
    $role = Role::factory()->create(['name' => $name, 'permission_type' => $permissionType, 'permissions' => ['dam', 'dam.asset.view']]);

    if ($settings !== null) {
        DB::table('dam_role_settings')->insert(array_merge(
            ['role_id' => $role->id, 'all_directories' => false, 'inherit_children' => false, 'created_at' => now(), 'updated_at' => now()],
            $settings
        ));
    }

    return $role;
}

function accessRebuildGroup(string $name, array $memberIds): int
{
    $groupId = DB::table('dam_groups')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(array_map(fn (int $adminId) => ['group_id' => $groupId, 'admin_id' => $adminId], $memberIds));

    return $groupId;
}

/**
 * Every kind of grant the rebuild translates: role grants with and without inherit_children, an
 * all_directories role, user and group grants with and without inherit_children, a file in two
 * folders, a file in no folder, an admin with no role, a disabled admin and an owner who is not a full
 * admin. Built before anyone signs in, so only the owner's file has an owner.
 */
function accessRebuildFixture(): array
{
    $f = ['root' => Directory::create(['name' => 'Rebuild root', 'parent_id' => null])];
    foreach (['a', 'b', 'c', 'd'] as $branch) {
        $f[$branch] = Directory::create(['name' => 'Rebuild '.$branch, 'parent_id' => $f['root']->id]);
        $f[$branch.'1'] = Directory::create(['name' => 'Rebuild '.$branch.'1', 'parent_id' => $f[$branch]->id]);
    }

    $f['flat'] = accessRebuildRole('Rebuild flat', settings: ['inherit_children' => false]);
    $f['deep'] = accessRebuildRole('Rebuild deep', settings: ['inherit_children' => true]);
    $f['everywhere'] = accessRebuildRole('Rebuild everywhere', settings: ['all_directories' => true]);
    $plain = accessRebuildRole('Rebuild plain');
    DB::table('dam_directory_role')->insert([
        ['directory_id' => $f['a']->id, 'role_id' => $f['flat']->id, 'created_at' => now(), 'updated_at' => now()],
        ['directory_id' => $f['b']->id, 'role_id' => $f['deep']->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $f['flatMember'] = Admin::factory()->create(['role_id' => $f['flat']->id]);
    $f['disabled'] = Admin::factory()->create(['role_id' => $f['flat']->id, 'status' => 0]);
    $f['deepMember'] = Admin::factory()->create(['role_id' => $f['deep']->id]);
    $f['everywhereMember'] = Admin::factory()->create(['role_id' => $f['everywhere']->id]);
    $f['fullAdmin'] = Admin::factory()->create(['role_id' => accessRebuildRole('Rebuild full', permissionType: 'all')->id]);
    $f['noRole'] = Admin::factory()->create(['role_id' => null]);
    $f['userFlat'] = Admin::factory()->create(['role_id' => $plain->id]);
    $f['userDeep'] = Admin::factory()->create(['role_id' => $plain->id]);
    $f['groupMember'] = Admin::factory()->create(['role_id' => $plain->id]);
    $f['owner'] = Admin::factory()->create(['role_id' => $plain->id]);

    DB::table('dam_directory_user')->insert([
        ['directory_id' => $f['c']->id, 'admin_id' => $f['userFlat']->id, 'inherit_children' => false],
        ['directory_id' => $f['d']->id, 'admin_id' => $f['userDeep']->id, 'inherit_children' => true],
    ]);
    $f['flatCrew'] = accessRebuildGroup('Rebuild flat crew', [$f['groupMember']->id]);
    $f['deepCrew'] = accessRebuildGroup('Rebuild deep crew', [$f['groupMember']->id]);
    DB::table('dam_directory_group')->insert([
        ['directory_id' => $f['c1']->id, 'group_id' => $f['flatCrew'], 'inherit_children' => false],
        ['directory_id' => $f['a']->id, 'group_id' => $f['deepCrew'], 'inherit_children' => true],
    ]);

    $f['shared'] = Asset::factory()->create(['file_name' => 'rebuild-shared.jpg']);
    $f['shared']->directories()->attach([$f['a1']->id, $f['c']->id]);
    $f['deepFile'] = Asset::factory()->create(['file_name' => 'rebuild-deep.jpg']);
    $f['deepFile']->directories()->attach($f['b1']->id);
    $f['ownerFile'] = Asset::factory()->create(['file_name' => 'rebuild-owned.jpg']);
    $f['ownerFile']->directories()->attach($f['d1']->id);
    DB::table('dam_assets')->where('id', $f['ownerFile']->id)->update(['created_by' => $f['owner']->id]);
    $f['orphan'] = Asset::factory()->create(['file_name' => 'rebuild-orphan.jpg']);

    return $f;
}

function accessRebuildRoleGroup(Role $role): int
{
    return (int) DB::table('dam_groups')->where('system_key', 'role:'.$role->id)->value('id');
}

/** The sorted item ids a principal is listed on in one list table. */
function accessRebuildListed(string $table, string $principal, int $principalId): array
{
    $item = str_starts_with($table, 'dam_asset_') ? 'asset_id' : 'directory_id';

    return DB::table($table)->where($principal, $principalId)->orderBy($item)->pluck($item)->map(fn ($id) => (int) $id)->all();
}

/**
 * Two admins on the same folder's and file's user lists: one to delete, one that must keep its rows.
 *
 * @return array{0: Admin, 1: Admin}
 */
function accessRebuildLeavingAdmins(): array
{
    $folder = Directory::create(['name' => 'Rebuild delete folder', 'parent_id' => null]);
    $file = Asset::factory()->create(['file_name' => 'rebuild-delete.jpg']);
    $role = accessRebuildRole('Rebuild leaving');
    $leaving = Admin::factory()->create(['role_id' => $role->id]);
    $staying = Admin::factory()->create(['role_id' => $role->id]);

    foreach ([$leaving, $staying] as $admin) {
        DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id]);
        DB::table('dam_asset_user_access')->insert(['asset_id' => $file->id, 'admin_id' => $admin->id]);
    }

    return [$leaving, $staying];
}

/** The admins on the folder and file user lists, sorted. */
function accessRebuildUserListAdmins(): array
{
    $admins = fn (string $table) => DB::table($table)->orderBy('admin_id')->pluck('admin_id')->map(fn ($id) => (int) $id)->all();

    return ['folders' => $admins('dam_directory_user_access'), 'files' => $admins('dam_asset_user_access')];
}

/**
 * A role with a folder grant and two admins, rebuilt once so its generated group exists. With $granted,
 * the legacy folder access panel then grants that group a folder of its own.
 *
 * @return array{role: Role, members: list<Admin>, group: int, folder: Directory}
 */
function accessRebuildGeneratedGroup(bool $granted): array
{
    $role = accessRebuildRole('Rebuild generated');
    $roleFolder = Directory::create(['name' => 'Rebuild role folder', 'parent_id' => null]);
    $folder = Directory::create(['name' => 'Rebuild group folder', 'parent_id' => null]);
    DB::table('dam_directory_role')->insert(['directory_id' => $roleFolder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    $members = [Admin::factory()->create(['role_id' => $role->id]), Admin::factory()->create(['role_id' => $role->id])];

    app(AccessListRebuilder::class)->run();
    $group = accessRebuildRoleGroup($role);

    if ($granted) {
        DB::table('dam_directory_group')->insert(['directory_id' => $folder->id, 'group_id' => $group, 'inherit_children' => false]);
    }

    return ['role' => $role, 'members' => $members, 'group' => $group, 'folder' => $folder];
}

/** The grants model's answers for every active admin: the bypass, open folders, visible folders and open files. */
function accessRebuildGrantsAnswers(): array
{
    $reader = new LegacyGrantReader;
    $sorted = fn ($ids) => collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
    $answers = [];

    foreach (Admin::query()->where('status', true)->orderBy('id')->get() as $admin) {
        $files = DB::table('dam_assets');
        $reader->applyAssetDirectoryGrantFilter(admin: $admin, query: $files);

        $answers[$admin->id] = [
            'bypass'  => $reader->bypass(admin: $admin),
            'folders' => $sorted($reader->directlyGrantedIds(admin: $admin)),
            'paths'   => $sorted($reader->viewableIds(admin: $admin)),
            'files'   => $sorted($files->pluck('dam_assets.id')),
        ];
    }

    return $answers;
}

/** Every row the rebuild writes, in a stable order. */
function accessRebuildSnapshot(): array
{
    $rows = fn ($query) => $query->get()->map(fn ($row) => (array) $row)->all();

    return [
        'dam_directory_user_access'  => $rows(DB::table('dam_directory_user_access')->orderBy('directory_id')->orderBy('admin_id')),
        'dam_directory_group_access' => $rows(DB::table('dam_directory_group_access')->orderBy('directory_id')->orderBy('group_id')),
        'dam_asset_user_access'      => $rows(DB::table('dam_asset_user_access')->orderBy('asset_id')->orderBy('admin_id')),
        'dam_asset_group_access'     => $rows(DB::table('dam_asset_group_access')->orderBy('asset_id')->orderBy('group_id')),
        'system_groups'              => $rows(DB::table('dam_groups')->whereNotNull('system_key')->orderBy('id')->select(['id', 'name', 'system_key'])),
        'members'                    => $rows(DB::table('dam_group_members')->orderBy('group_id')->orderBy('admin_id')),
        'folder_owners'              => DB::table('dam_directories')->orderBy('id')->pluck('created_by', 'id')->all(),
        'file_owners'                => DB::table('dam_assets')->orderBy('id')->pluck('created_by', 'id')->all(),
    ];
}

it('rebuilds lists that keep every active admin at the access the grants give, so compare exits 0', function () {
    accessRebuildFixture();

    // Empty lists lose every grant: the comparison is not vacuous.
    expect(app(AccessComparator::class)->compare()['differences']['folder_lost'])->toBeGreaterThan(0);

    $this->artisan('dam:access:rebuild')->assertExitCode(0);
    $this->artisan('dam:access:report', ['--compare' => true])->assertExitCode(0);

    $report = app(AccessComparator::class)->compare();

    // The two expected differences: the owner gains their own file, and its folders as a path.
    expect($report['failures'])->toBe(0)
        ->and($report['differences']['file_gained_by_owner'])->toBe(1)
        ->and($report['differences']['folder_gained_as_path'])->toBeGreaterThan(0);
});

it('fails the switch on every difference but an owner gain or a path-only gain', function (string $difference, bool $fails) {
    $f = accessRebuildFixture();
    app(AccessListRebuilder::class)->run();
    $clean = app(AccessComparator::class)->compare();

    // One edit to the rebuilt lists (or an owner) per case; the comparison must name it.
    switch ($difference) {
        case 'file_gained':
            DB::table('dam_asset_user_access')->insert(['asset_id' => $f['deepFile']->id, 'admin_id' => $f['userFlat']->id]);
            break;
        case 'file_lost':
            DB::table('dam_asset_user_access')->where('asset_id', $f['shared']->id)->where('admin_id', $f['userFlat']->id)->delete();
            break;
        case 'folder_lost':
            // c still holds a file userFlat opens, so c stays visible and only its access is lost.
            DB::table('dam_directory_user_access')->where('directory_id', $f['c']->id)->where('admin_id', $f['userFlat']->id)->delete();
            break;
        case 'folder_path_lost':
            // A path cannot go on its own: it goes with what it led to (d, d1 and the file in d1).
            DB::table('dam_directory_user_access')->where('admin_id', $f['userDeep']->id)->delete();
            DB::table('dam_asset_user_access')->where('admin_id', $f['userDeep']->id)->delete();
            break;
        case 'folder_gained':
            DB::table('dam_directory_user_access')->insert(['directory_id' => $f['b']->id, 'admin_id' => $f['userFlat']->id]);
            break;
        case 'folder_gained_by_owner':
            DB::table('dam_directories')->where('id', $f['b']->id)->update(['created_by' => $f['userFlat']->id]);
            break;
    }

    $report = app(AccessComparator::class)->compare();

    expect($clean['failures'])->toBe(0)
        ->and($report['differences'][$difference])->toBe($clean['differences'][$difference] + 1)
        ->and($report['failures'] > 0)->toBe($fails);

    if ($difference === 'folder_lost') {
        expect($report['failures'])->toBe(1);
    }

    $this->artisan('dam:access:report', ['--compare' => true])->assertExitCode($fails ? 1 : 0);
})->with([
    'an unauthorized file gain'     => ['file_gained', true],
    'a file loss'                   => ['file_lost', true],
    'a folder access loss'          => ['folder_lost', true],
    'a path loss'                   => ['folder_path_lost', true],
    'an unauthorized folder gain'   => ['folder_gained', true],
    'an owner gaining their folder' => ['folder_gained_by_owner', false],
]);

it('writes each grant onto the folders it reaches and each file onto the union of its folders', function () {
    $f = accessRebuildFixture();
    $owners = accessRebuildSnapshot();

    app(AccessListRebuilder::class)->run();

    $folders = DB::table('dam_directories')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    $files = DB::table('dam_assets')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect(accessRebuildListed('dam_directory_group_access', 'group_id', accessRebuildRoleGroup($f['flat'])))->toBe([$f['a']->id])
        ->and(accessRebuildListed('dam_directory_group_access', 'group_id', accessRebuildRoleGroup($f['deep'])))->toBe([$f['b']->id, $f['b1']->id])
        // all_directories reached every folder and every file, the one in no folder included.
        ->and(accessRebuildListed('dam_directory_group_access', 'group_id', accessRebuildRoleGroup($f['everywhere'])))->toBe($folders)
        ->and(accessRebuildListed('dam_asset_group_access', 'group_id', accessRebuildRoleGroup($f['everywhere'])))->toBe($files)
        ->and(accessRebuildListed('dam_directory_user_access', 'admin_id', $f['userFlat']->id))->toBe([$f['c']->id])
        ->and(accessRebuildListed('dam_directory_user_access', 'admin_id', $f['userDeep']->id))->toBe([$f['d']->id, $f['d1']->id])
        ->and(accessRebuildListed('dam_directory_group_access', 'group_id', $f['flatCrew']))->toBe([$f['c1']->id])
        ->and(accessRebuildListed('dam_directory_group_access', 'group_id', $f['deepCrew']))->toBe([$f['a']->id, $f['a1']->id])
        // The file in a1 and c carries both folders' principals.
        ->and(accessRebuildListed('dam_asset_user_access', 'admin_id', $f['userFlat']->id))->toBe([$f['shared']->id])
        ->and(accessRebuildListed('dam_asset_group_access', 'group_id', $f['deepCrew']))->toBe([$f['shared']->id])
        ->and(accessRebuildListed('dam_asset_user_access', 'admin_id', $f['userDeep']->id))->toBe([$f['ownerFile']->id]);

    // A role group is named for its role and holds the role's admins, the disabled one included.
    expect(DB::table('dam_groups')->where('id', accessRebuildRoleGroup($f['flat']))->value('name'))->toBe('Rebuild flat (from role)')
        ->and(DB::table('dam_group_members')->where('group_id', accessRebuildRoleGroup($f['flat']))->pluck('admin_id')->map(fn ($id) => (int) $id)->all())
        ->toEqualCanonicalizing([$f['flatMember']->id, $f['disabled']->id]);

    // The rebuild fills in no owners; only `dam:access:switch lists` does, once its comparison passes.
    expect(accessRebuildSnapshot()['folder_owners'])->toBe($owners['folder_owners'])
        ->and(accessRebuildSnapshot()['file_owners'])->toBe($owners['file_owners'])
        ->and(DB::table('dam_assets')->whereNull('created_by')->count())->toBe(3);
});

it('never changes a grants answer for any active admin, folder or file', function () {
    $f = accessRebuildFixture();
    $grants = accessRebuildGrantsAnswers();

    app(AccessListRebuilder::class)->run();

    expect(accessRebuildGrantsAnswers())->toBe($grants);

    // A folder grant to a generated group whose members still match its role does not stop a rebuild.
    DB::table('dam_directory_group')->insert(['directory_id' => $f['d1']->id, 'group_id' => accessRebuildRoleGroup($f['flat']), 'inherit_children' => false]);
    $grants = accessRebuildGrantsAnswers();

    app(AccessListRebuilder::class)->run();

    expect(accessRebuildGrantsAnswers())->toBe($grants);
});

it('refuses a rebuild that would change a generated group a folder grant uses, and writes nothing', function (string $edit) {
    $generated = accessRebuildGeneratedGroup(granted: true);
    $outsider = Admin::factory()->create(['role_id' => accessRebuildRole('Rebuild outsider')->id]);

    // The Groups page edits the generated group: a role holder taken out, or someone else put in.
    $edit === 'a role member removed'
        ? DB::table('dam_group_members')->where('group_id', $generated['group'])->where('admin_id', $generated['members'][1]->id)->delete()
        : DB::table('dam_group_members')->insert(['group_id' => $generated['group'], 'admin_id' => $outsider->id]);

    $rows = accessRebuildSnapshot();
    $grants = accessRebuildGrantsAnswers();

    $this->artisan('dam:access:rebuild')
        ->expectsOutputToContain('Refused')
        ->assertExitCode(1);

    expect(accessRebuildSnapshot())->toBe($rows)
        ->and(accessRebuildGrantsAnswers())->toBe($grants);
})->with(['a role member removed', 'a non-role member added']);

it('resets a generated group no folder grant uses to its role\'s admins, and counts it', function () {
    $generated = accessRebuildGeneratedGroup(granted: false);
    DB::table('dam_group_members')->where('group_id', $generated['group'])->where('admin_id', $generated['members'][1]->id)->delete();

    $counts = app(AccessListRebuilder::class)->run();

    expect($counts['role_groups_reset'])->toBe(1)
        ->and($counts['role_groups_created'])->toBe(0)
        ->and(DB::table('dam_group_members')->where('group_id', $generated['group'])->orderBy('admin_id')->pluck('admin_id')->map(fn ($id) => (int) $id)->all())
        ->toBe(collect($generated['members'])->pluck('id')->sort()->values()->all());
});

it('writes identical rows on a second run', function () {
    accessRebuildFixture();

    app(AccessListRebuilder::class)->run();
    $first = accessRebuildSnapshot();
    app(AccessListRebuilder::class)->run();

    expect(accessRebuildSnapshot())->toBe($first)
        ->and($first['dam_directory_group_access'])->not->toBeEmpty();
});

it('removes only the deleted admin\'s user list rows through the SQL Server cleanup', function () {
    [$leaving, $staying] = accessRebuildLeavingAdmins();

    // The listener alone is what SQL Server has, where these admin_id keys do not cascade.
    app(DeleteAdminAccessRows::class)->handle($leaving);

    expect(accessRebuildUserListAdmins())->toBe(['folders' => [$staying->id], 'files' => [$staying->id]]);
});

/*
 * Every veto and success case runs through both repository delete paths: delete($id), and deleteModel() on the
 * model locked the way the importers lock it (AdminRepository::lockedAdmin()). Each runs inside an outer
 * transaction that does unrelated work after the delete, so a veto must roll back only its own savepoint.
 */
dataset('admin deletes', [
    'delete(id)'             => [fn (Admin $admin) => app(AdminRepository::class)->delete($admin->id)],
    'deleteModel(locked)'    => [fn (Admin $admin) => app(AdminRepository::class)->deleteModel(app(AdminRepository::class)->lockedAdmin($admin->id))],
]);

/**
 * Runs $delete inside an outer transaction that commits its own unrelated work afterwards, and returns what the
 * delete returned or threw.
 *
 * @return array{result: mixed, thrown: ?Throwable}
 */
function accessRebuildDeleteInOuterTransaction(Closure $delete, Admin $admin): array
{
    return DB::transaction(function () use ($delete, $admin) {
        [$result, $thrown] = [null, null];

        try {
            $result = $delete($admin);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        DB::table('dam_groups')->insert(['name' => 'Rebuild outer work', 'created_at' => now(), 'updated_at' => now()]);

        return ['result' => $result, 'thrown' => $thrown];
    });
}

it('keeps an admin, their list rows and the outer work when a later deleting listener throws', function (Closure $delete) {
    [$leaving] = accessRebuildLeavingAdmins();
    $before = accessRebuildUserListAdmins();
    $event = 'eloquent.deleting: '.AdminProxy::modelClass();

    // Registered as the SQL Server provider does, then a listener after it that refuses the delete.
    Event::listen($event, DeleteAdminAccessRows::class);
    Event::listen($event, fn () => throw new RuntimeException('Delete vetoed by a later listener.'));

    ['result' => $result, 'thrown' => $thrown] = accessRebuildDeleteInOuterTransaction($delete, $leaving);

    // The cleanup ran in the same savepoint as the vetoed delete, so it rolled back with it — and only it.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Delete vetoed by a later listener.')
        ->and($result)->toBeNull()
        ->and(DB::table('admins')->where('id', $leaving->id)->exists())->toBeTrue()
        ->and(accessRebuildUserListAdmins())->toBe($before)
        ->and(DB::table('dam_groups')->where('name', 'Rebuild outer work')->exists())->toBeTrue();
})->with('admin deletes');

it('keeps an admin, their list rows and the outer work when a later deleting listener returns false', function (Closure $delete) {
    [$leaving] = accessRebuildLeavingAdmins();
    $before = accessRebuildUserListAdmins();
    $event = 'eloquent.deleting: '.AdminProxy::modelClass();

    // Returning false vetoes the delete without an exception: the earlier cleanup must still roll back.
    Event::listen($event, DeleteAdminAccessRows::class);
    Event::listen($event, fn () => false);

    ['result' => $result, 'thrown' => $thrown] = accessRebuildDeleteInOuterTransaction($delete, $leaving);

    expect($thrown)->toBeNull()
        ->and($result)->toBeFalse()
        ->and(DB::table('admins')->where('id', $leaving->id)->exists())->toBeTrue()
        ->and(accessRebuildUserListAdmins())->toBe($before)
        ->and(DB::table('dam_groups')->where('name', 'Rebuild outer work')->exists())->toBeTrue();
})->with('admin deletes');

it('keeps the outer work when a false veto lands inside a nested savepoint', function (Closure $delete) {
    [$leaving] = accessRebuildLeavingAdmins();
    $before = accessRebuildUserListAdmins();
    $event = 'eloquent.deleting: '.AdminProxy::modelClass();
    Event::listen($event, DeleteAdminAccessRows::class);
    Event::listen($event, fn () => false);

    // Two levels deep: the outer transaction, a nested one with its own work, then the vetoed delete's savepoint.
    $result = DB::transaction(function () use ($delete, $leaving) {
        DB::table('dam_groups')->insert(['name' => 'Rebuild outer work', 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($delete, $leaving) {
            $result = $delete($leaving);
            DB::table('dam_groups')->insert(['name' => 'Rebuild nested work', 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        });
    });

    expect($result)->toBeFalse()
        ->and(DB::table('dam_groups')->whereIn('name', ['Rebuild outer work', 'Rebuild nested work'])->count())->toBe(2)
        ->and(DB::table('admins')->where('id', $leaving->id)->exists())->toBeTrue()
        ->and(accessRebuildUserListAdmins())->toBe($before);
})->with('admin deletes');

it('removes only the deleted admin and their rows, and keeps the outer work, when the delete succeeds', function (Closure $delete) {
    [$leaving, $staying] = accessRebuildLeavingAdmins();
    Event::listen('eloquent.deleting: '.AdminProxy::modelClass(), DeleteAdminAccessRows::class);

    ['result' => $result, 'thrown' => $thrown] = accessRebuildDeleteInOuterTransaction($delete, $leaving);

    expect($thrown)->toBeNull()
        ->and($result)->toBeTrue()
        ->and(DB::table('admins')->where('id', $leaving->id)->exists())->toBeFalse()
        ->and(DB::table('admins')->where('id', $staying->id)->exists())->toBeTrue()
        ->and(accessRebuildUserListAdmins())->toBe(['folders' => [$staying->id], 'files' => [$staying->id]])
        ->and(DB::table('dam_groups')->where('name', 'Rebuild outer work')->exists())->toBeTrue();
})->with('admin deletes');

it('leaves the rows to the foreign-key cascade on every driver but SQL Server', function () {
    [$leaving, $staying] = accessRebuildLeavingAdmins();
    $this->mock(DeleteAdminAccessRows::class)->shouldNotReceive('handle');

    app(AdminRepository::class)->delete($leaving->id);

    expect(accessRebuildUserListAdmins())->toBe(['folders' => [$staying->id], 'files' => [$staying->id]]);
})->skip(fn () => DB::getDriverName() === 'sqlsrv', 'SQL Server registers the cleanup; its admin_id keys do not cascade.');

it('recreates a deleted Everyone group and never renames an operator group', function () {
    $clash = accessRebuildRole('Rebuild clash');
    $folder = Directory::create(['name' => 'Rebuild clash folder', 'parent_id' => null]);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $clash->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_groups')->where('system_key', 'everyone')->delete();
    $operatorEveryone = accessRebuildGroup('Everyone', []);
    $operatorClash = accessRebuildGroup('Rebuild clash (from role)', []);

    $counts = app(AccessListRebuilder::class)->run();

    expect(DB::table('dam_groups')->where('system_key', 'everyone')->value('name'))->toBe('Everyone (all users)')
        ->and($counts['everyone_group_id'])->toBe((int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id'))
        ->and(DB::table('dam_groups')->where('id', accessRebuildRoleGroup($clash))->value('name'))->toBe('Rebuild clash (from role) 2')
        ->and(DB::table('dam_groups')->whereIn('id', [$operatorEveryone, $operatorClash])->pluck('name')->all())
        ->toEqualCanonicalizing(['Everyone', 'Rebuild clash (from role)']);
});
