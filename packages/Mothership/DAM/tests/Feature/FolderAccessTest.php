<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->super = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    $this->member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    $this->folder = Directory::create(['name' => 'Access parent', 'parent_id' => null]);
    $this->child = Directory::create(['name' => 'Access child', 'parent_id' => $this->folder->id]);
    $this->accessUrl = '/admin/dam/directory/'.$this->folder->id.'/access';
    $this->actingAs($this->super, 'admin');
});

it('lets a super user create rename and delete a group with members', function () {
    $group = $this->postJson('/admin/settings/groups', ['name' => 'Editors', 'member_ids' => [$this->member->id]])
        ->assertCreated()->json('data');
    $this->getJson('/admin/settings/groups/list')->assertOk()->assertJsonFragment(['name' => 'Editors']);
    $this->putJson('/admin/settings/groups/'.$group['id'], ['name' => 'Producers', 'member_ids' => []])->assertOk();
    $this->assertDatabaseMissing('dam_group_members', ['group_id' => $group['id'], 'admin_id' => $this->member->id]);
    $this->deleteJson('/admin/settings/groups/'.$group['id'])->assertOk();
    $this->assertDatabaseMissing('dam_groups', ['id' => $group['id']]);
});

it('grants one user access without granting their role peers access', function () {
    $peer = Admin::factory()->create(['role_id' => $this->member->role_id]);
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => false]], 'groups' => []])->assertOk();
    $this->actingAs($this->member, 'admin');
    $service = app(DirectoryPermissionService::class);
    $service->flush();
    expect($service->canAccess($this->folder->id))->toBeTrue();
    expect($service->canAccess($this->child->id))->toBeFalse();
    $this->actingAs($peer, 'admin');
    $service->flush();
    expect($service->canAccess($this->folder->id))->toBeFalse();
});

it('inherits group grants into existing and newly created descendants and revokes membership', function () {
    $group = $this->postJson('/admin/settings/groups', ['name' => 'Staff', 'member_ids' => [$this->member->id]])->assertCreated()->json('data');
    $this->putJson($this->accessUrl, ['users' => [], 'groups' => [['id' => $group['id'], 'inherit_children' => true]]])->assertOk();
    $newChild = Directory::create(['name' => 'New child', 'parent_id' => $this->folder->id]);
    $this->actingAs($this->member, 'admin');
    $service = app(DirectoryPermissionService::class);
    $service->flush();
    expect($service->canAccess($this->child->id))->toBeTrue();
    expect($service->canAccess($newChild->id))->toBeTrue();
    $this->actingAs($this->super, 'admin');
    $this->putJson('/admin/settings/groups/'.$group['id'], ['name' => 'Staff', 'member_ids' => []])->assertOk();
    $this->actingAs($this->member, 'admin');
    $service->flush();
    expect($service->canAccess($this->child->id))->toBeFalse();
});

it('keeps role access when a direct user grant is removed and reports its source', function () {
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $this->member->role_id]);
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => false]], 'groups' => []])->assertOk();
    $this->putJson($this->accessUrl, ['users' => [], 'groups' => []])->assertOk();
    $this->getJson($this->accessUrl)->assertOk()->assertJsonFragment(['type' => 'role', 'id' => $this->member->role_id]);
    $this->actingAs($this->member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->canAccess($this->folder->id))->toBeTrue();
});

it('reports parent grants as inherited and preserves them when child grants are saved', function () {
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => true]], 'groups' => []])->assertOk();
    $url = '/admin/dam/directory/'.$this->child->id.'/access';
    $this->getJson($url)->assertOk()->assertJsonFragment(['source_directory_id' => $this->folder->id, 'inherited' => true]);
    $this->putJson($url, ['users' => [], 'groups' => []])->assertOk();
    $this->assertDatabaseHas('dam_directory_user', ['directory_id' => $this->folder->id, 'admin_id' => $this->member->id]);
});

it('rejects access and group management by non super users even with broad folder access', function () {
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $this->member->role_id], ['all_directories' => true]);
    $this->actingAs($this->member, 'admin');
    $this->getJson($this->accessUrl)->assertForbidden();
    $this->putJson($this->accessUrl, ['users' => [], 'groups' => []])->assertForbidden();
    $this->getJson('/admin/settings/groups/list')->assertForbidden();
    $this->postJson('/admin/settings/groups', ['name' => 'Escalation', 'member_ids' => []])->assertForbidden();
    $this->putJson('/admin/settings/groups/1', ['name' => 'Escalation', 'member_ids' => []])->assertForbidden();
    $this->deleteJson('/admin/settings/groups/1')->assertForbidden();
});

it('validates targets and complete payloads without changing saved grants', function () {
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => false]], 'groups' => []])->assertOk();
    $this->putJson($this->accessUrl, ['users' => []])->assertUnprocessable();
    $this->putJson($this->accessUrl, ['users' => [], 'groups' => [['id' => 999999999, 'inherit_children' => true]]])->assertUnprocessable();
    $this->assertDatabaseHas('dam_directory_user', ['directory_id' => $this->folder->id, 'admin_id' => $this->member->id]);
    $this->getJson('/admin/dam/directory/999999999/access')->assertNotFound();
});

it('renders groups and the sidebar manage access control for super users', function () {
    $this->get('/admin/settings/groups')->assertOk()->assertSee('Groups');
    $this->get('/admin/dam')->assertOk()->assertSee('Manage access');
});

it('does not spread a personally accessible new subfolder to role peers', function (bool $inherit) {
    Storage::fake(Directory::getAssetDisk());
    Storage::disk(Directory::getAssetDisk())->makeDirectory('assets/'.$this->folder->name);
    $this->member->role->update(['permissions' => ['dam', 'dam.directory.store']]);
    $peer = Admin::factory()->create(['role_id' => $this->member->role_id]);
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => $inherit]], 'groups' => []])->assertOk();
    $this->actingAs($this->member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    $id = $this->postJson('/admin/dam/directory/store', ['name' => 'Personal child', 'parent_id' => $this->folder->id])->assertOk()->json('data.id');
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->canAccess($id))->toBeTrue();
    $this->actingAs($peer, 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->canAccess($id))->toBeFalse();
})->with([true, false]);

it('removes group folder grants on group deletion while retaining independent user access', function () {
    $group = $this->postJson('/admin/settings/groups', ['name' => 'Temporary', 'member_ids' => [$this->member->id]])->assertCreated()->json('data');
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => false]], 'groups' => [['id' => $group['id'], 'inherit_children' => true]]])->assertOk();
    $this->deleteJson('/admin/settings/groups/'.$group['id'])->assertOk();
    $this->assertDatabaseMissing('dam_directory_group', ['group_id' => $group['id']]);
    $this->actingAs($this->member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->canAccess($this->folder->id))->toBeTrue();
    expect(app(DirectoryPermissionService::class)->canAccess($this->child->id))->toBeFalse();
});

it('shows a global role once even when it also has a folder grant', function () {
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $this->super->role_id]);
    $sources = $this->getJson($this->accessUrl)->assertOk()->json('sources');
    $matching = collect($sources)->where('type', 'role')->where('id', $this->super->role_id)->values();
    expect($matching)->toHaveCount(1);
    expect($matching[0]['source_name'])->toBe('All folders');
});

it('rejects duplicate and blank group names and permits an unchanged name on update', function () {
    $group = $this->postJson('/admin/settings/groups', ['name' => 'Unique group', 'member_ids' => []])->assertCreated()->json('data');
    $this->postJson('/admin/settings/groups', ['name' => 'Unique group', 'member_ids' => []])->assertUnprocessable();
    $this->postJson('/admin/settings/groups', ['name' => '   ', 'member_ids' => []])->assertUnprocessable();
    $this->putJson('/admin/settings/groups/'.$group['id'], ['name' => 'Unique group', 'member_ids' => []])->assertOk();
});

it('shows both direct and inherited user grants and hides Groups from custom-role menus', function () {
    $this->putJson($this->accessUrl, ['users' => [['id' => $this->member->id, 'inherit_children' => true]], 'groups' => []])->assertOk();
    $url = '/admin/dam/directory/'.$this->child->id.'/access';
    $this->putJson($url, ['users' => [['id' => $this->member->id, 'inherit_children' => false]], 'groups' => []])->assertOk();
    $data = $this->getJson($url)->assertOk()->json();
    expect(collect($data['users'])->where('id', $this->member->id))->toHaveCount(1);
    expect(collect($data['sources'])->where('type', 'user')->where('id', $this->member->id))->toHaveCount(1);
    $this->member->role->update(['permissions' => ['settings', 'settings.users', 'settings.users.users']]);
    $this->actingAs($this->member, 'admin');
    $this->get('/admin/settings/users')->assertOk()->assertDontSee('href="'.url('/admin/settings/groups').'"', false);
});
