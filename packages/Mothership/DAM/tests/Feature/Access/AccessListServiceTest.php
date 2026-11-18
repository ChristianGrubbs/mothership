<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Every write the Share dialog makes (ADR 0010, sharing slice 3): the three modes on a file, the cascade
 * through a folder's subtree, the owner-or-full-admin gate, and the validation that leaves a list untouched.
 * The locking these writes take is proved on PostgreSQL by AccessCascadeLockingCommittedTest, because
 * lockForUpdate() is a no-op on SQLite.
 */

beforeEach(function () {
    // Built before anyone signs in, so no fixture picks up an owner from the session.
    $this->root = Directory::create(['name' => 'Cascade root', 'parent_id' => null]);
    $this->child = Directory::create(['name' => 'Cascade child', 'parent_id' => $this->root->id]);
    $this->grandchild = Directory::create(['name' => 'Cascade grandchild', 'parent_id' => $this->child->id]);
    $this->outside = Directory::create(['name' => 'Cascade outside', 'parent_id' => null]);

    $this->rootFile = Asset::factory()->create(['file_name' => 'cascade-root.jpg']);
    $this->rootFile->directories()->attach($this->root->id);
    $this->deepFile = Asset::factory()->create(['file_name' => 'cascade-deep.jpg']);
    $this->deepFile->directories()->attach($this->grandchild->id);
    // In two folders, one inside the subtree and one outside it: the cascade still owns its list.
    $this->strandedFile = Asset::factory()->create(['file_name' => 'cascade-two-folders.jpg']);
    $this->strandedFile->directories()->attach([$this->child->id, $this->outside->id]);
    $this->outsideFile = Asset::factory()->create(['file_name' => 'cascade-outside.jpg']);
    $this->outsideFile->directories()->attach($this->outside->id);

    $this->owner = accessSetsAdmin();
    $this->stranger = accessSetsAdmin();
    $this->fullAdmin = accessSetsAdmin(permissionType: 'all');
    $this->friend = accessSetsAdmin();

    accessSetsOwnedBy(admin: $this->owner);
    accessSetsModel(AccessModel::LISTS);
});

function accessSetsAdmin(string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** Leaves the switch row at $model, as a finished `dam:access:switch` would. */
function accessSetsModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/** Every fixture folder and file belongs to $admin, so the owner rule alone opens them. */
function accessSetsOwnedBy(Admin $admin): void
{
    DB::table('dam_directories')->update(['created_by' => $admin->id]);
    DB::table('dam_assets')->update(['created_by' => $admin->id]);
}

function accessSetsService(): AccessListService
{
    return app(AccessListService::class);
}

/** The principals on one item's list, as ids. */
function accessSetsRows(string $type, int $id): array
{
    return [
        'users'  => DB::table("dam_{$type}_user_access")->where("{$type}_id", $id)->orderBy('admin_id')->pluck('admin_id')->map(fn ($v) => (int) $v)->all(),
        'groups' => DB::table("dam_{$type}_group_access")->where("{$type}_id", $id)->orderBy('group_id')->pluck('group_id')->map(fn ($v) => (int) $v)->all(),
    ];
}

function accessSetsEveryoneId(): int
{
    return (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
}

it('lets the owner set each mode on a file', function () {
    $service = accessSetsService();
    $everyoneId = accessSetsEveryoneId();

    $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: []);
    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
    expect($service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->owner)['mode'])->toBe(AccessListService::PEOPLE);

    $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::EVERYONE, userIds: [], groupIds: []);
    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [], 'groups' => [$everyoneId]]);
    expect($service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->owner)['mode'])->toBe(AccessListService::EVERYONE);

    $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::PRIVATE, userIds: [$this->friend->id], groupIds: [$everyoneId]);
    // `private` writes no rows whatever the request named.
    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [], 'groups' => []]);
    expect($service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->owner)['mode'])->toBe(AccessListService::PRIVATE);

    // A file's own list only; the folder holding it is untouched.
    expect(accessSetsRows(type: 'directory', id: $this->root->id))->toBe(['users' => [], 'groups' => []]);
});

it('rewrites every folder and file of the subtree, and leaves everything outside it alone', function () {
    $counts = accessSetsService()->set(
        actor: $this->owner, type: 'directory', id: $this->root->id,
        mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: []
    );

    expect($counts['folders'])->toBe(3)
        ->and($counts['files'])->toBe(3);

    foreach ([$this->root, $this->child, $this->grandchild] as $folder) {
        expect(accessSetsRows(type: 'directory', id: $folder->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
    }

    // The file in two folders is rewritten once, from the placement inside the subtree.
    foreach ([$this->rootFile, $this->deepFile, $this->strandedFile] as $file) {
        expect(accessSetsRows(type: 'asset', id: $file->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
    }

    expect(accessSetsRows(type: 'directory', id: $this->outside->id))->toBe(['users' => [], 'groups' => []])
        ->and(accessSetsRows(type: 'asset', id: $this->outsideFile->id))->toBe(['users' => [], 'groups' => []]);
});

it('replaces the previous list of the whole subtree rather than adding to it', function () {
    $service = accessSetsService();
    $everyoneId = accessSetsEveryoneId();

    $service->set(actor: $this->owner, type: 'directory', id: $this->root->id, mode: AccessListService::PEOPLE, userIds: [$this->friend->id, $this->stranger->id], groupIds: []);
    $service->set(actor: $this->owner, type: 'directory', id: $this->root->id, mode: AccessListService::EVERYONE, userIds: [], groupIds: []);

    expect(accessSetsRows(type: 'directory', id: $this->grandchild->id))->toBe(['users' => [], 'groups' => [$everyoneId]])
        ->and(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [], 'groups' => [$everyoneId]]);
});

it('refuses a non-owner who is not a full admin, and lets a full admin set', function () {
    $service = accessSetsService();

    // The stranger can open the file — it is shared with them — but does not own it.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->rootFile->id, 'admin_id' => $this->stranger->id]);

    $refused = null;

    try {
        $service->set(actor: $this->stranger, type: 'asset', id: $this->rootFile->id, mode: AccessListService::EVERYONE, userIds: [], groupIds: []);
    } catch (HttpException $exception) {
        $refused = $exception->getStatusCode();
    }

    expect($refused)->toBe(403)
        ->and(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [$this->stranger->id], 'groups' => []])
        // It still reads, read-only.
        ->and($service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->stranger)['can_edit'])->toBeFalse();

    $service->set(actor: $this->fullAdmin, type: 'asset', id: $this->rootFile->id, mode: AccessListService::EVERYONE, userIds: [], groupIds: []);

    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [], 'groups' => [accessSetsEveryoneId()]])
        // A full admin editing someone else's item is not its owner.
        ->and($service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->fullAdmin))
        ->toMatchArray(['can_edit' => true, 'owned_by_viewer' => false]);
});

it('leaves the lists unchanged when a principal id is not an existing active user or group', function (string $case, callable $ids) {
    $service = accessSetsService();
    $service->set(actor: $this->owner, type: 'directory', id: $this->root->id, mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: []);

    $before = accessSetsRows(type: 'directory', id: $this->grandchild->id);
    $refused = null;

    try {
        [$userIds, $groupIds] = $ids($this);
        $service->set(actor: $this->owner, type: 'directory', id: $this->root->id, mode: AccessListService::PEOPLE, userIds: $userIds, groupIds: $groupIds);
    } catch (HttpException $exception) {
        $refused = $exception->getStatusCode();
    }

    expect($refused)->toBe(422)
        ->and(accessSetsRows(type: 'directory', id: $this->grandchild->id))->toBe($before);
})->with([
    'a user id that does not exist'  => ['a user id that does not exist', fn ($test) => [[$test->friend->id, 987654], []]],
    'a group id that does not exist' => ['a group id that does not exist', fn ($test) => [[], [987654]]],
    'a disabled user'                => ['a disabled user', function ($test) {
        Admin::query()->whereKey($test->stranger->id)->update(['status' => 0]);

        return [[$test->stranger->id], []];
    }],
]);

it('refuses an unknown mode and writes nothing', function () {
    $refused = null;

    try {
        accessSetsService()->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: 'viewers', userIds: [], groupIds: []);
    } catch (HttpException $exception) {
        $refused = $exception->getStatusCode();
    }

    expect($refused)->toBe(422)
        ->and(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [], 'groups' => []]);
});

it('writes nothing while the access model is anything but lists', function (string $state) {
    accessSetsModel($state);

    $refused = null;

    try {
        accessSetsService()->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::EVERYONE, userIds: [], groupIds: []);
    } catch (NotFoundHttpException) {
        $refused = 404;
    }

    expect($refused)->toBe(404)
        ->and(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [], 'groups' => []]);
})->with([AccessModel::GRANTS, 'pending:some-run', 'something-else']);

it('previews the folders and files a cascade would rewrite, and how many others own', function () {
    // The deep file and the grandchild folder belong to someone else.
    DB::table('dam_assets')->where('id', $this->deepFile->id)->update(['created_by' => $this->stranger->id]);
    DB::table('dam_directories')->where('id', $this->grandchild->id)->update(['created_by' => null]);

    $preview = accessSetsService()->describe(type: 'directory', id: $this->root->id, actor: $this->owner)['cascade'];

    // Descendants only, so the note reads as what the save applies to; three files, the two-folder one once.
    expect($preview)->toBe(['folders' => 2, 'files' => 3, 'owned_by_others' => 2]);

    // A leaf folder has no descendant folders; it still holds two files, one of them the two-folder file.
    expect(accessSetsService()->describe(type: 'directory', id: $this->outside->id, actor: $this->owner)['cascade'])
        ->toBe(['folders' => 0, 'files' => 2, 'owned_by_others' => 0]);
});

it('describes an item without its members, and 404s when the actor cannot open it', function () {
    $service = accessSetsService();
    $groupId = DB::table('dam_groups')->insertGetId(['name' => 'Describe crew', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $groupId, 'admin_id' => $this->friend->id]);

    $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: [$groupId]);

    $described = $service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->owner);

    expect($described['users'])->toBe([['id' => $this->friend->id, 'name' => $this->friend->name, 'email' => $this->friend->email]])
        ->and($described['groups'])->toBe([['id' => (int) $groupId, 'name' => 'Describe crew', 'system_key' => null, 'member_count' => 1]])
        ->and($described['owner'])->toBe(['id' => $this->owner->id, 'name' => $this->owner->name])
        ->and($described)->not->toHaveKey('cascade');

    // The stranger is on no list and owns nothing: the response must not confirm the file exists.
    expect(fn () => $service->describe(type: 'asset', id: $this->rootFile->id, actor: $this->stranger))
        ->toThrow(NotFoundHttpException::class);
});

it('reads a folder the actor only sees as a path as not found', function () {
    $service = accessSetsService();
    // The stranger reaches the grandchild, so the root and child show as paths only.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->grandchild->id, 'admin_id' => $this->stranger->id]);

    expect(fn () => $service->describe(type: 'directory', id: $this->root->id, actor: $this->stranger))
        ->toThrow(NotFoundHttpException::class);

    // The folder they can actually open reads fine, read-only.
    expect($service->describe(type: 'directory', id: $this->grandchild->id, actor: $this->stranger)['can_edit'])->toBeFalse();
});

it('returns the modes of a page of items in one pass, Everyone winning over a named list', function () {
    $service = accessSetsService();
    $everyoneId = accessSetsEveryoneId();

    $service->set(actor: $this->owner, type: 'directory', id: $this->child->id, mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: []);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $this->outside->id, 'group_id' => $everyoneId]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->outside->id, 'admin_id' => $this->friend->id]);

    expect($service->modesFor(type: 'directory', ids: [$this->root->id, $this->child->id, $this->grandchild->id, $this->outside->id]))
        ->toBe([
            $this->root->id       => AccessListService::PRIVATE,
            $this->child->id      => AccessListService::PEOPLE,
            $this->grandchild->id => AccessListService::PEOPLE,
            $this->outside->id    => AccessListService::EVERYONE,
        ]);

    expect($service->modesFor(type: 'asset', ids: []))->toBe([]);
});

it('offers the picker active users and groups with Everyone first, and never a membership list', function () {
    $service = accessSetsService();
    $groupId = DB::table('dam_groups')->insertGetId(['name' => 'Zulu crew', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert([
        ['group_id' => $groupId, 'admin_id' => $this->friend->id],
        ['group_id' => $groupId, 'admin_id' => $this->owner->id],
    ]);
    Admin::query()->whereKey($this->stranger->id)->update(['status' => 0]);

    $principals = $service->principals();

    expect(array_column($principals['users'], 'id'))->not->toContain($this->stranger->id)
        ->and($principals['groups'][0]['id'])->toBe(accessSetsEveryoneId())
        // Everyone has no member rows: its count is the active admins.
        ->and($principals['groups'][0]['member_count'])->toBe(Admin::query()->where('status', true)->count());

    $named = collect($principals['groups'])->firstWhere('id', (int) $groupId);
    expect($named)->toBe(['id' => (int) $groupId, 'name' => 'Zulu crew', 'system_key' => null, 'member_count' => 2]);

    foreach ($principals['groups'] as $group) {
        expect(array_keys($group))->toBe(['id', 'name', 'system_key', 'member_count']);
    }

    // The search matches a name or an email, and never a disabled account.
    expect(array_column($service->principals(query: $this->friend->email)['users'], 'id'))->toBe([$this->friend->id])
        ->and($service->principals(query: 'Zulu')['groups'])->toHaveCount(1);
});

it('applies a subtree list with no authorization and no model check, for slice 2 to reuse', function () {
    // The primitive slice 2 calls: it works under `grants` too, where set() itself refuses.
    accessSetsModel(AccessModel::GRANTS);

    $counts = accessSetsService()->applyToSubtree(directoryId: $this->child->id, userIds: [$this->stranger->id], groupIds: []);

    expect($counts)->toBe(['folders' => 2, 'files' => 2])
        ->and(accessSetsRows(type: 'directory', id: $this->grandchild->id))->toBe(['users' => [$this->stranger->id], 'groups' => []])
        ->and(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [$this->stranger->id], 'groups' => []])
        // The root is above the subtree it was given.
        ->and(accessSetsRows(type: 'directory', id: $this->root->id))->toBe(['users' => [], 'groups' => []]);
});

it('rewrites nothing when the folder has been deleted since the caller read it', function () {
    $id = $this->grandchild->id;
    Directory::findOrFail($id)->delete();

    expect(accessSetsService()->applyToSubtree(directoryId: $id, userIds: [$this->friend->id], groupIds: []))
        ->toBe(['folders' => 0, 'files' => 0]);
});

it('refuses to write when the access model row is absent altogether', function () {
    // A database that has never run a switch has no row at all, which modelFor() reads as grants.
    DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
    app(AccessModel::class)->flush();

    expect(fn () => accessSetsService()->set(
        actor: $this->owner, type: 'asset', id: $this->rootFile->id,
        mode: AccessListService::EVERYONE, userIds: [], groupIds: []
    ))->toThrow(NotFoundHttpException::class);

    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [], 'groups' => []])
        // The refusal must not create the row either: only transactionally() may, under FOR UPDATE.
        ->and(DB::table('dam_configuration')->where('key', AccessModel::KEY)->exists())->toBeFalse();
});

it('takes exactly the principal cap and refuses one more', function () {
    $service = accessSetsService();

    // The boundary is told apart by which refusal fires: at the cap the list gets as far as the existence
    // check, one over it never does. That needs no 1,000 real admins to be exact about the edge.
    $atCap = range(500001, 500000 + AccessListService::MAX_PRINCIPALS);
    expect($atCap)->toHaveCount(AccessListService::MAX_PRINCIPALS);

    $refusal = function (array $userIds) use ($service) {
        try {
            $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::PEOPLE, userIds: $userIds, groupIds: []);
        } catch (HttpException $exception) {
            return [$exception->getStatusCode(), $exception->getMessage()];
        }

        return null;
    };

    expect($refusal([...$atCap, 500000]))->toBe([422, trans('dam::app.admin.dam.access.too-many')])
        ->and($refusal($atCap))->toBe([422, trans('dam::app.admin.dam.access.unknown-principal')])
        ->and($refusal([...range(1, AccessListService::MAX_PRINCIPALS), 0]))->toBe([422, trans('dam::app.admin.dam.access.too-many')]);

    // The same boundary on the group side.
    try {
        $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::PEOPLE, userIds: [], groupIds: [...$atCap, 500000]);
    } catch (HttpException $exception) {
        expect($exception->getMessage())->toBe(trans('dam::app.admin.dam.access.too-many'));
    }

    // A real list still writes, and nothing above left a row behind.
    $service->set(actor: $this->owner, type: 'asset', id: $this->rootFile->id, mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: []);
    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
});

it('rewrites a file placed in two folders of the same subtree exactly once', function () {
    // Both placements inside the cascade's subtree: the insert-select must not write a row per placement.
    $this->deepFile->directories()->attach($this->child->id);

    $counts = accessSetsService()->set(
        actor: $this->owner, type: 'directory', id: $this->root->id,
        mode: AccessListService::PEOPLE, userIds: [$this->friend->id], groupIds: []
    );

    expect($counts['files'])->toBe(3)
        ->and(DB::table('dam_asset_user_access')->where('asset_id', $this->deepFile->id)->count())->toBe(1)
        ->and(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
});

/** The trashed asset ids $viewer's Trash page lists, with the trash keys added to their role. */
function accessSetsTrashIds(Admin $viewer): array
{
    $viewer->role->update(['permissions' => ['dam', 'dam.asset.view', 'dam.trash', 'dam.trash.index']]);
    app(DirectoryPermissionService::class)->flush();

    return collect(test()->actingAs($viewer->fresh(), 'admin')->getJson(route('admin.dam.trash.list'))->assertOk()->json('assets'))
        ->pluck('id')->map(fn ($id) => (int) $id)->all();
}

it('leaves a trashed file\'s own list and its Trash entry alone when its folder\'s list changes, before and after Restore (P2 S4)', function () {
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->deepFile->id, 'admin_id' => $this->friend->id]);
    $this->deepFile->delete();
    expect(accessSetsTrashIds($this->friend))->toBe([$this->deepFile->id])
        ->and(accessSetsTrashIds($this->stranger))->toBe([]);

    $counts = accessSetsService()->set(actor: $this->owner, type: 'directory', id: $this->root->id, mode: AccessListService::PEOPLE, userIds: [$this->stranger->id], groupIds: []);

    // The live files of the subtree took the new list; the trashed one kept its own and is not counted.
    expect(accessSetsRows(type: 'asset', id: $this->rootFile->id)['users'])->toBe([$this->stranger->id])
        ->and(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []])
        ->and($counts['files'])->toBe(2)
        ->and(accessSetsTrashIds($this->friend))->toBe([$this->deepFile->id])
        ->and(accessSetsTrashIds($this->stranger))->toBe([]);

    app(AssetTrashService::class)->restore($this->deepFile);

    expect(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
});

it('leaves a trashed file\'s own list and its Trash entry alone when its folder moves, before and after Restore (P2 S4)', function () {
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->deepFile->id, 'admin_id' => $this->friend->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->outside->id, 'admin_id' => $this->stranger->id]);
    $this->deepFile->delete();

    // What a move of the child folder (with the grandchild holding the trashed file) under `outside` cascades.
    Directory::withTreeLock(fn () => app(AccessListWriter::class)->adoptForSubtree(movedFolderId: $this->child->id, destinationId: $this->outside->id));

    expect(accessSetsRows(type: 'asset', id: $this->strandedFile->id)['users'])->toBe([$this->stranger->id])
        ->and(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []])
        ->and(accessSetsTrashIds($this->friend))->toBe([$this->deepFile->id])
        ->and(accessSetsTrashIds($this->stranger))->toBe([]);

    app(AssetTrashService::class)->restore($this->deepFile);

    expect(accessSetsRows(type: 'asset', id: $this->deepFile->id))->toBe(['users' => [$this->friend->id], 'groups' => []]);
});

it('leaves a trashed file out of the cascade preview (P2 S4)', function () {
    $this->deepFile->delete();

    expect(accessSetsService()->describe(type: 'directory', id: $this->root->id, actor: $this->owner)['cascade'])
        ->toBe(['folders' => 2, 'files' => 2, 'owned_by_others' => 0]);
});
