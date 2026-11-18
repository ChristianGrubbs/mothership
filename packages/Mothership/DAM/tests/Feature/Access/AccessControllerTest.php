<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * The Share dialog's three endpoints (ADR 0010, sharing slice 3). They carry no ACL key on purpose, so these
 * cases pin what stands in for one: 404 everywhere under `grants`, 404 on an item the actor cannot open, 403
 * on an item they can open but do not own, and a picker that never returns a membership list.
 *
 * `grants` parity also covers the folder-access panel this slice retires: its two routes answer as before
 * under `grants`, and 404 under `lists`.
 */

beforeEach(function () {
    $this->folder = Directory::create(['name' => 'Endpoint folder', 'parent_id' => null]);
    $this->child = Directory::create(['name' => 'Endpoint child', 'parent_id' => $this->folder->id]);
    $this->file = Asset::factory()->create(['file_name' => 'endpoint.jpg']);
    $this->file->directories()->attach($this->folder->id);

    $this->owner = accessRoutesAdmin();
    $this->stranger = accessRoutesAdmin();
    $this->friend = accessRoutesAdmin();
    $this->fullAdmin = accessRoutesAdmin(permissionType: 'all');

    DB::table('dam_directories')->update(['created_by' => $this->owner->id]);
    DB::table('dam_assets')->update(['created_by' => $this->owner->id]);

    $this->showFolder = '/admin/dam/access/directory/'.$this->folder->id;
    $this->showFile = '/admin/dam/access/asset/'.$this->file->id;
});

function accessRoutesAdmin(string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function accessRoutesModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

it('answers 404 on every access route while the model is not lists', function (string $state) {
    accessRoutesModel($state);
    test()->actingAs($this->owner, 'admin');

    test()->getJson($this->showFolder)->assertNotFound();
    test()->getJson($this->showFile)->assertNotFound();
    test()->getJson('/admin/dam/access/principals?q=a')->assertNotFound();
    test()->putJson($this->showFile, ['mode' => 'everyone', 'users' => [], 'groups' => []])->assertNotFound();

    expect(DB::table('dam_asset_group_access')->count())->toBe(0);
})->with([AccessModel::GRANTS, 'pending:endpoint-run', 'nonsense']);

it('keeps the folder-access panel routes answering under grants and retires them under lists', function () {
    accessRoutesModel(AccessModel::GRANTS);
    test()->actingAs($this->fullAdmin, 'admin');

    $panel = '/admin/dam/directory/'.$this->folder->id.'/access';
    test()->getJson($panel)->assertOk();
    test()->putJson($panel, ['users' => [], 'groups' => []])->assertOk();

    accessRoutesModel(AccessModel::LISTS);
    test()->getJson($panel)->assertNotFound();
    test()->putJson($panel, ['users' => [], 'groups' => []])->assertNotFound();
});

it('lets the owner read and set a list under lists', function () {
    accessRoutesModel(AccessModel::LISTS);
    test()->actingAs($this->owner, 'admin');

    test()->getJson($this->showFile)
        ->assertOk()
        ->assertJson(['type' => 'asset', 'id' => $this->file->id, 'mode' => 'private', 'can_edit' => true, 'owned_by_viewer' => true]);

    test()->putJson($this->showFile, ['mode' => 'people', 'users' => [$this->friend->id], 'groups' => []])
        ->assertOk()
        ->assertJson(['saved' => true, 'mode' => 'people', 'users' => 1, 'groups' => 0, 'files' => 1]);

    test()->assertDatabaseHas('dam_asset_user_access', ['asset_id' => $this->file->id, 'admin_id' => $this->friend->id]);

    // A folder reports what its cascade rewrote.
    test()->putJson($this->showFolder, ['mode' => 'everyone', 'users' => [], 'groups' => []])
        ->assertOk()
        ->assertJson(['saved' => true, 'mode' => 'everyone', 'folders' => 2, 'files' => 1]);
});

it('hides an item the actor cannot open behind 404, and refuses a non-owner with 403', function () {
    accessRoutesModel(AccessModel::LISTS);
    test()->actingAs($this->stranger, 'admin');

    test()->getJson($this->showFile)->assertNotFound();
    test()->putJson($this->showFile, ['mode' => 'everyone', 'users' => [], 'groups' => []])->assertNotFound();

    // Now they can open it, but still do not own it.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->stranger->id]);

    test()->getJson($this->showFile)->assertOk()->assertJson(['can_edit' => false, 'owned_by_viewer' => false]);
    test()->putJson($this->showFile, ['mode' => 'everyone', 'users' => [], 'groups' => []])->assertForbidden();

    expect(DB::table('dam_asset_group_access')->where('asset_id', $this->file->id)->count())->toBe(0);
});

it('validates the mode and the principal ids', function () {
    accessRoutesModel(AccessModel::LISTS);
    test()->actingAs($this->owner, 'admin');

    test()->putJson($this->showFile, ['users' => [], 'groups' => []])->assertJsonValidationErrors('mode');
    test()->putJson($this->showFile, ['mode' => 'viewers', 'users' => [], 'groups' => []])->assertJsonValidationErrors('mode');
    test()->putJson($this->showFile, ['mode' => 'people', 'groups' => []])->assertJsonValidationErrors('users');
    test()->putJson($this->showFile, ['mode' => 'people', 'users' => ['abc'], 'groups' => []])->assertJsonValidationErrors('users.0');
    test()->putJson($this->showFile, ['mode' => 'people', 'users' => [$this->friend->id, $this->friend->id], 'groups' => []])->assertJsonValidationErrors('users.0');
    test()->putJson($this->showFile, ['mode' => 'people', 'users' => range(1, 1001), 'groups' => []])->assertJsonValidationErrors('users');

    // An id of the right shape that names nothing is the service's 422, not a validation error.
    test()->putJson($this->showFile, ['mode' => 'people', 'users' => [987654], 'groups' => []])->assertStatus(422);

    // An unknown item type never matches the route at all.
    test()->getJson('/admin/dam/access/folder/'.$this->folder->id)->assertNotFound();

    expect(DB::table('dam_asset_user_access')->count())->toBe(0);
});

it('offers every signed-in admin the picker, with active users, Everyone first, and no members', function () {
    accessRoutesModel(AccessModel::LISTS);
    $group = DB::table('dam_groups')->insertGetId(['name' => 'Picker crew', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $this->owner->id]);
    Admin::query()->whereKey($this->stranger->id)->update(['status' => 0]);

    // The stranger owns nothing and is on no list: the picker is still theirs to use.
    test()->actingAs($this->friend, 'admin');

    $data = test()->getJson('/admin/dam/access/principals')->assertOk()->json();

    expect(array_column($data['users'], 'id'))->not->toContain($this->stranger->id)
        ->and($data['groups'][0]['name'])->toContain('Everyone');

    foreach ($data['groups'] as $entry) {
        expect(array_keys($entry))->toBe(['id', 'name', 'system_key', 'member_count']);
    }

    foreach ($data['users'] as $entry) {
        expect(array_keys($entry))->toBe(['id', 'name', 'email']);
    }

    test()->getJson('/admin/dam/access/principals?q=Picker')
        ->assertOk()
        ->assertJsonCount(1, 'groups')
        ->assertJsonPath('groups.0.member_count', 1);

    test()->getJson('/admin/dam/access/principals?q='.str_repeat('a', 256))->assertJsonValidationErrors('q');
});

it('needs a signed-in admin', function () {
    accessRoutesModel(AccessModel::LISTS);

    // FAULT-06: a signed-out background request gets a 401 it can act on, not a 302 to the sign-in page that its
    // XHR would follow and read as data. A page load still goes to sign-in.
    test()->getJson($this->showFile)->assertUnauthorized()->assertJsonPath('message', trans('admin::app.requests.session'));
    test()->putJson($this->showFile, ['mode' => 'everyone', 'users' => [], 'groups' => []])->assertUnauthorized();
    test()->get($this->showFile)->assertRedirect(route('admin.session.create'));
});

it('never widens the list when the model moves to grants between the request and the write', function () {
    accessRoutesModel(AccessModel::LISTS);
    test()->actingAs($this->owner, 'admin');

    // The controller's gate has already passed; the service re-reads the state under its own hold.
    DB::table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => AccessModel::GRANTS]);

    expect(fn () => app(AccessListService::class)->set(
        actor: $this->owner, type: 'asset', id: $this->file->id,
        mode: AccessListService::EVERYONE, userIds: [], groupIds: []
    ))->toThrow(NotFoundHttpException::class);

    expect(DB::table('dam_asset_group_access')->count())->toBe(0);
});

it('answers 404 on a folder the actor only sees as a path, for both reads and writes', function () {
    accessRoutesModel(AccessModel::LISTS);
    // The stranger reaches the child, so the parent shows in the tree as a name only.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->child->id, 'admin_id' => $this->stranger->id]);
    test()->actingAs($this->stranger, 'admin');

    $parent = '/admin/dam/access/directory/'.$this->folder->id;

    test()->getJson($parent)->assertNotFound();
    test()->putJson($parent, ['mode' => 'everyone', 'users' => [], 'groups' => []])->assertNotFound();

    // Nothing was written anywhere in the subtree.
    expect(DB::table('dam_directory_group_access')->count())->toBe(0);

    // The folder they can actually open reads, read-only.
    test()->getJson('/admin/dam/access/directory/'.$this->child->id)->assertOk()->assertJson(['can_edit' => false]);
});

it('reports the public links that still open an item, to editors only', function () {
    accessRoutesModel(AccessModel::LISTS);
    $creator = $this->owner->id;
    $mk = fn (string $type, int $target, array $extra = []) => Share::factory()->create(['share_type' => $type, 'target_id' => $target, 'created_by' => $creator] + $extra);

    $mk('asset', $this->file->id);
    $mk('asset', $this->file->id, ['revoked_at' => now()]);
    $mk('asset', $this->file->id, ['expires_at' => now()->subDay()]);
    $mk('directory', $this->folder->id);
    $mk('directory', $this->folder->id);
    // Links on the folder's child serve the child's subtree only, never the file one level up.
    $mk('directory', $this->child->id);

    test()->actingAs($this->owner, 'admin');

    test()->getJson($this->showFile)->assertOk()->assertJsonPath('public_links', [
        'direct'    => 1,
        'inherited' => [['folder_id' => $this->folder->id, 'folder_name' => 'Endpoint folder', 'count' => 2]],
    ]);

    // A folder: its own two links are direct; the child's links are not inherited downward.
    test()->getJson($this->showFolder)->assertOk()->assertJsonPath('public_links', ['direct' => 2, 'inherited' => []]);

    test()->getJson('/admin/dam/access/directory/'.$this->child->id)->assertOk()->assertJsonPath('public_links', [
        'direct'    => 1,
        'inherited' => [['folder_id' => $this->folder->id, 'folder_name' => 'Endpoint folder', 'count' => 2]],
    ]);

    // A viewer who cannot edit gets no public_links at all.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->friend->id]);
    test()->actingAs($this->friend, 'admin');
    expect(test()->getJson($this->showFile)->assertOk()->json())->not->toHaveKey('public_links');
});

it('revokes only the direct links of an item, and only for an owner or full admin', function () {
    accessRoutesModel(AccessModel::LISTS);
    $mk = fn (string $type, int $target) => Share::factory()->create(['share_type' => $type, 'target_id' => $target, 'created_by' => $this->stranger->id]);

    $direct = [$mk('asset', $this->file->id), $mk('asset', $this->file->id)];
    $inherited = $mk('directory', $this->folder->id);
    $other = $mk('asset', Asset::factory()->create(['created_by' => $this->owner->id])->id);

    $url = $this->showFile.'/revoke-public-links';

    // Not a viewer: 404, nothing touched. A viewer who cannot edit: 403, same as PUT.
    test()->actingAs($this->stranger, 'admin');
    test()->postJson($url)->assertNotFound();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->stranger->id]);
    test()->postJson($url)->assertForbidden();
    expect(Share::query()->active()->count())->toBe(4);

    test()->actingAs($this->owner, 'admin');
    test()->postJson($url)->assertOk()->assertJsonPath('public_links', [
        'direct'    => 0,
        'inherited' => [['folder_id' => $this->folder->id, 'folder_name' => 'Endpoint folder', 'count' => 1]],
    ]);

    foreach ($direct as $share) {
        expect($share->fresh()->revoked_at)->not->toBeNull();
    }
    expect($inherited->fresh()->revoked_at)->toBeNull()->and($other->fresh()->revoked_at)->toBeNull();

    // A full admin may too, and the folder's own endpoint touches that folder's links only.
    test()->actingAs($this->fullAdmin, 'admin');
    test()->postJson('/admin/dam/access/directory/'.$this->folder->id.'/revoke-public-links')
        ->assertOk()->assertJsonPath('public_links.direct', 0);
    expect($inherited->fresh()->revoked_at)->not->toBeNull();

    // Under grants the endpoint does not exist.
    accessRoutesModel(AccessModel::GRANTS);
    test()->postJson($url)->assertNotFound();
});

it('leaves integration accounts out of the people list and the picker', function () {
    accessRoutesModel(AccessModel::LISTS);
    $integration = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id, 'status' => 1, 'type' => 'api', 'name' => 'Automation (API)']);

    test()->actingAs($this->owner, 'admin');

    $people = collect(test()->getJson($this->showFile)->assertOk()->json('people'))->pluck('id');
    expect($people)->toContain($this->fullAdmin->id)->not->toContain($integration->id);

    $picked = collect(test()->getJson('/admin/dam/access/principals?q=Automation')->assertOk()->json('users'))->pluck('id');
    expect($picked)->not->toContain($integration->id);
});

it('names an inherited public-link folder only when the viewer can open it', function () {
    accessRoutesModel(AccessModel::LISTS);
    $hiddenFolder = Directory::create(['name' => 'Secret parent', 'parent_id' => null]);
    $inside = Directory::create(['name' => 'Shared inside', 'parent_id' => $hiddenFolder->id]);
    DB::table('dam_directories')->where('id', $hiddenFolder->id)->update(['created_by' => $this->owner->id]);
    DB::table('dam_directories')->where('id', $inside->id)->update(['created_by' => $this->friend->id]);
    // The editor (friend) owns the file and its folder but cannot open the ancestor folder.
    $file = Asset::factory()->create(['created_by' => $this->friend->id]);
    $file->directories()->attach($inside->id);

    Share::factory()->create(['share_type' => 'directory', 'target_id' => $hiddenFolder->id, 'created_by' => $this->owner->id]);

    test()->actingAs($this->friend, 'admin');
    $links = test()->getJson('/admin/dam/access/asset/'.$file->id)->assertOk()->json('public_links.inherited');

    expect($links)->toHaveCount(1)
        ->and($links[0]['folder_id'])->toBe($hiddenFolder->id)
        ->and($links[0]['count'])->toBe(1)
        ->and($links[0]['folder_name'])->toBeNull();
});
