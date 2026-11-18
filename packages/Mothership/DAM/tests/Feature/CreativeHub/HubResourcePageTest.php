<?php

require_once __DIR__.'/../../Support/CreativeHubFixtures.php';
require_once __DIR__.'/../../Support/HubResourceFixtures.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\HubResource;
use Mothership\DAM\Services\CreativeHub\HubResourceStore;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The Fonts & brushes manager page (ADR 0015, PR3): who may open it and what it hands its component. The page's
 * data is the listing, so the owner name the rows show is covered here too.
 */

beforeEach(function () {
    hubEnable();
    Storage::fake(Directory::getAssetDisk());
});

it('opens the manager page for every person, offering only the groups they may share with', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    $person = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    $member = hubPageGroup(name: 'Type team', members: [$person]);
    $unrelated = hubPageGroup(name: 'Legal holds');
    $roleGroup = hubPageGroup(name: 'Role Finance desk', systemKey: 'role:'.uniqid());
    $everyone = DB::table('dam_groups')->where('system_key', 'everyone')->value('id');

    $this->actingAs($person, 'admin');
    $response = $this->get(route('admin.dam.hub.resources'))->assertOk()
        ->assertSee('data-dam-hub-resources', escape: false)
        ->assertSee('MothershipHubResources', escape: false)
        ->assertSee(trans('dam::app.admin.hub-resources.page.title'));
    $config = $response->viewData('config');
    $ids = collect($config['groups'])->pluck('id')->all();
    $names = collect($config['groups'])->pluck('name')->all();

    expect($ids)->toBe([$member['id']])
        ->and($names)->toBe([$member['name']])
        ->and($ids)->not->toContain($unrelated['id'], $roleGroup['id'], $everyone === null ? -1 : (int) $everyone)
        ->and($config['canShare'])->toBeTrue()
        ->and($config['limits'])->toBe(['font' => 20 * 1024 * 1024, 'brush' => 50 * 1024 * 1024, 'quota' => 5 * 1024 * 1024 * 1024])
        ->and($config['urls']['list'])->toBe(route('admin.dam.hub.resources.list'))
        ->and($config['urls']['store'])->toBe(route('admin.dam.hub.resources.store'));

    // The rendered page (the inline config the component reads) names neither group either.
    $response->assertDontSee($unrelated['name'])->assertDontSee($roleGroup['name']);
});

it('offers a group already on a resource the person owns, and every group to a full admin', function () {
    $owner = hubResourceAdmin();
    $former = hubPageGroup(name: 'Former team', members: [$owner]);
    $other = hubPageGroup(name: 'Print shop');
    $store = app(HubResourceStore::class);
    $resource = $store->store(path: hubResourceFile(hubResourceDejaVu('DejaVuSans.ttf')), originalName: 'Kept.ttf', owner: $owner)['resource'];
    $store->setVisibility(resource: $resource, actor: $owner, visibility: HubResource::VISIBILITY_GROUPS, groupIds: [$former['id']], licenseNote: 'OFL', licenseAttested: true);
    DB::table('dam_group_members')->where('group_id', $former['id'])->delete();

    $this->actingAs($owner, 'admin');
    expect(collect($this->get(route('admin.dam.hub.resources'))->assertOk()->viewData('config')['groups'])->pluck('id')->all())
        ->toBe([$former['id']]);

    $this->actingAs(hubResourceAdmin(permissionType: 'all'), 'admin');
    expect(collect($this->get(route('admin.dam.hub.resources'))->assertOk()->viewData('config')['groups'])->pluck('id')->all())
        ->toContain($former['id'], $other['id']);
});

it('refuses to share with a group the owner may not see, but keeps one already on the resource', function () {
    $owner = hubResourceAdmin();
    $member = hubPageGroup(name: 'Type team', members: [$owner]);
    $former = hubPageGroup(name: 'Former team', members: [$owner]);
    $hidden = hubPageGroup(name: 'Legal holds');
    $store = app(HubResourceStore::class);
    $resource = $store->store(path: hubResourceFile(hubResourceDejaVu('DejaVuSans.ttf')), originalName: 'Shared.ttf', owner: $owner)['resource'];
    $store->setVisibility(resource: $resource, actor: $owner, visibility: HubResource::VISIBILITY_GROUPS, groupIds: [$former['id']], licenseNote: 'OFL', licenseAttested: true);
    DB::table('dam_group_members')->where('group_id', $former['id'])->delete();
    $share = fn (array $groupIds) => $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $resource->uuid]), [
        'visibility' => 'groups', 'group_ids' => $groupIds, 'license_attested' => true, 'license_note' => 'OFL',
    ]);

    $this->actingAs($owner, 'admin');
    $share([$member['id'], $hidden['id']])->assertStatus(422)->assertJsonPath('reason', 'bad_visibility');
    expect($resource->fresh()->groupIds())->toBe([$former['id']]);

    $share([$former['id'], $member['id']])->assertOk()->assertJsonPath('resource.group_ids', [$member['id'], $former['id']]);
});

it('lets an owner who left a group keep it on the resource it is on, but never attach it to another one', function () {
    $owner = hubResourceAdmin();
    $left = hubPageGroup(name: 'Left team', members: [$owner]);
    $store = app(HubResourceStore::class);
    $kept = $store->store(path: hubResourceFile(hubResourceDejaVu('DejaVuSans.ttf')), originalName: 'Kept.ttf', owner: $owner)['resource'];
    $other = $store->store(path: hubResourceFile(hubResourceDejaVu('DejaVuSerif.ttf')), originalName: 'Other.ttf', owner: $owner)['resource'];
    $store->setVisibility(resource: $kept, actor: $owner, visibility: HubResource::VISIBILITY_GROUPS, groupIds: [$left['id']], licenseNote: 'OFL', licenseAttested: true);
    DB::table('dam_group_members')->where('group_id', $left['id'])->delete();
    $share = fn (HubResource $resource) => $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $resource->uuid]), [
        'visibility' => 'groups', 'group_ids' => [$left['id']], 'license_attested' => true, 'license_note' => 'OFL',
    ]);

    $this->actingAs($owner, 'admin');
    expect(collect($this->get(route('admin.dam.hub.resources'))->assertOk()->viewData('config')['groups'])->pluck('id')->all())
        ->toBe([$left['id']]);
    $share($other)->assertStatus(422)->assertJsonPath('reason', 'bad_visibility');
    expect($other->fresh()->visibility)->toBe('private')->and($other->fresh()->groupIds())->toBe([]);
    $share($kept)->assertOk()->assertJsonPath('resource.group_ids', [$left['id']]);
});

it('keeps the manager page from API accounts, signed-out visitors, and everyone while the hub is off', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    $robot = Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'type' => 'api']);

    $this->get(route('admin.dam.hub.resources'))->assertRedirect(route('admin.session.create'));

    $this->actingAs($robot, 'admin');
    $this->get(route('admin.dam.hub.resources'))->assertForbidden();

    config(['dam.hub.enabled' => false]);
    $this->actingAs(hubResourceAdmin(), 'admin');
    $this->get(route('admin.dam.hub.resources'))->assertNotFound();
});

it('names each resource\'s owner in the listing, for the owner and for a reader', function () {
    $owner = hubResourceAdmin();
    $owner->forceFill(['name' => 'Maria Okafor'])->save();
    $reader = hubResourceAdmin();
    $store = app(HubResourceStore::class);
    $resource = $store->store(path: hubResourceFile(hubResourceDejaVu('DejaVuSans.ttf')), originalName: 'Shared.ttf', owner: $owner)['resource'];
    $store->setVisibility(resource: $resource, actor: $owner, visibility: HubResource::VISIBILITY_EVERYONE, licenseNote: 'SIL Open Font License 1.1', licenseAttested: true);

    $this->actingAs($owner, 'admin');
    $this->getJson(route('admin.dam.hub.resources.list'))->assertOk()
        ->assertJsonPath('resources.0.owner', 'Maria Okafor')->assertJsonPath('resources.0.owned', true);

    $this->actingAs($reader, 'admin');
    $this->getJson(route('admin.dam.hub.resources.list'))->assertOk()
        ->assertJsonPath('resources.0.owner', 'Maria Okafor')->assertJsonPath('resources.0.owned', false);

    $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $resource->uuid]), ['name' => 'x.ttf'])->assertForbidden();
    $this->actingAs($owner, 'admin');
    $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $resource->uuid]), ['name' => 'Renamed.ttf'])
        ->assertOk()->assertJsonPath('resource.owner', 'Maria Okafor');
});

/** @return array{id: int, name: string} a group named $name plus a unique suffix, with $members in it */
function hubPageGroup(string $name, array $members = [], ?string $systemKey = null): array
{
    $name .= ' '.uniqid();
    $id = (int) DB::table('dam_groups')->insertGetId(['name' => $name, 'system_key' => $systemKey, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($members as $member) {
        DB::table('dam_group_members')->insert(['group_id' => $id, 'admin_id' => $member->id]);
    }

    return ['id' => $id, 'name' => $name];
}
