<?php

require_once __DIR__.'/../../Support/CreativeHubFixtures.php';
require_once __DIR__.'/../../Support/HubResourceFixtures.php';

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\HubResource;
use Mothership\DAM\Services\CreativeHub\HubResourceStore;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Hub resources over HTTP (ADR 0015): who may list, read, change and delete a font or brush. A resource the viewer
 * may not read answers like a missing one on every route; one they may read but do not own is theirs to use, not
 * to change.
 */

beforeEach(function () {
    hubEnable();
    Storage::fake(Directory::getAssetDisk());
    $this->owner = hubResourceAdmin();
    $this->member = hubResourceAdmin();
    $this->stranger = hubResourceAdmin();
    $this->group = hubApiGroup(name: 'Type team', members: [$this->member]);

    $this->private = hubApiStored(owner: $this->owner, bytes: hubResourceDejaVu('DejaVuSans.ttf'), name: 'Private.ttf');
    $this->everyone = hubApiShared(resource: hubApiStored(owner: $this->owner, bytes: hubResourceDejaVu('DejaVuSans-Bold.ttf'), name: 'Everyone.ttf'), owner: $this->owner, visibility: HubResource::VISIBILITY_EVERYONE);
    $this->grouped = hubApiShared(resource: hubApiStored(owner: $this->owner, bytes: hubResourceAbr(version: 6), name: 'Inks.abr'), owner: $this->owner, visibility: HubResource::VISIBILITY_GROUPS, groupIds: [$this->group]);
});

function hubApiStored(Admin $owner, string $bytes, string $name): HubResource
{
    return app(HubResourceStore::class)->store(path: hubResourceFile($bytes), originalName: $name, owner: $owner)['resource'];
}

function hubApiShared(HubResource $resource, Admin $owner, string $visibility, array $groupIds = []): HubResource
{
    return app(HubResourceStore::class)->setVisibility(resource: $resource, actor: $owner, visibility: $visibility, groupIds: $groupIds, licenseNote: 'SIL Open Font License 1.1', licenseAttested: true);
}

function hubApiGroup(string $name, array $members = []): int
{
    $id = (int) DB::table('dam_groups')->insertGetId(['name' => $name.'-'.uniqid(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ($members as $member) {
        DB::table('dam_group_members')->insert(['group_id' => $id, 'admin_id' => $member->id]);
    }

    return $id;
}

function hubApiListed(Admin $viewer): array
{
    test()->actingAs($viewer, 'admin');

    return collect(test()->getJson(route('admin.dam.hub.resources.list'))->assertOk()->json('resources'))->pluck('id')->sort()->values()->all();
}

function hubApiSha(HubResource $resource): string
{
    return (string) FileObject::query()->whereKey($resource->file_object_id)->value('sha256');
}

function hubApiBytesUrl(HubResource $resource, ?string $sha256 = null): string
{
    return route('admin.dam.hub.resources.bytes', ['uuid' => $resource->uuid, 'sha256' => $sha256 ?? hubApiSha($resource)]);
}

it('lists only the resources the viewer may read on admin.dam.hub.resources.list', function () {
    $ids = fn (HubResource ...$resources): array => collect($resources)->pluck('uuid')->sort()->values()->all();
    $full = hubResourceAdmin(permissionType: 'all');

    expect(hubApiListed($this->owner))->toBe($ids($this->private, $this->everyone, $this->grouped))
        ->and(hubApiListed($this->member))->toBe($ids($this->everyone, $this->grouped))
        ->and(hubApiListed($this->stranger))->toBe($ids($this->everyone))
        ->and(hubApiListed($full))->toBe($ids($this->private, $this->everyone, $this->grouped));

    // A disabled account is signed out before the listing runs.
    $this->actingAs(hubResourceAdmin(status: 0), 'admin');
    $this->getJson(route('admin.dam.hub.resources.list'))->assertUnauthorized();
});

it('lists metadata and a pinned bytes URL, and the sharing groups only to the owner', function () {
    $this->actingAs($this->owner, 'admin');
    $mine = collect($this->getJson(route('admin.dam.hub.resources.list'))->assertOk()->json('resources'))->keyBy('id');
    $this->actingAs($this->member, 'admin');
    $theirs = collect($this->getJson(route('admin.dam.hub.resources.list', ['kind' => 'brush']))->assertOk()->json('resources'));

    expect($mine[$this->grouped->uuid])->toMatchArray(['kind' => 'brush', 'format' => 'abr', 'visibility' => 'groups', 'group_ids' => [$this->group], 'owned' => true, 'sha256' => hubApiSha($this->grouped)])
        ->and($mine[$this->grouped->uuid]['bytes_url'])->toBe(hubApiBytesUrl($this->grouped))
        ->and($mine[$this->everyone->uuid])->toMatchArray(['kind' => 'font', 'family' => 'DejaVu Sans', 'license_note' => 'SIL Open Font License 1.1'])
        ->and($mine[$this->everyone->uuid])->not->toHaveKey('file_object_id')
        ->and($theirs->pluck('id')->all())->toBe([$this->grouped->uuid])
        ->and($theirs[0])->toMatchArray(['owned' => false, 'group_ids' => []]);
});

it('answers a resource the viewer may not read like a missing one on admin.dam.hub.resources.bytes', function () {
    $this->actingAs($this->stranger, 'admin');
    $missing = $this->get(route('admin.dam.hub.resources.bytes', ['uuid' => (string) Str::uuid(), 'sha256' => hubApiSha($this->private)]));
    $hidden = $this->get(hubApiBytesUrl($this->private));
    $grouped = $this->get(hubApiBytesUrl($this->grouped));

    expect($missing->status())->toBe(404)
        ->and($hidden->status())->toBe(404)
        ->and($grouped->status())->toBe(404);

    $this->get(hubApiBytesUrl($this->everyone))->assertOk();
    $this->actingAs($this->member, 'admin');
    $this->get(hubApiBytesUrl($this->grouped))->assertOk();
    $this->get(hubApiBytesUrl($this->private))->assertNotFound();
    $this->actingAs(hubResourceAdmin(permissionType: 'all'), 'admin');
    $this->get(hubApiBytesUrl($this->private))->assertOk();
});

it('delivers bytes same-origin as a private nosniff attachment, pinned to their sha256', function () {
    $this->actingAs($this->owner, 'admin');
    $response = $this->get(hubApiBytesUrl($this->private), ['Sec-Fetch-Site' => 'same-origin'])->assertOk();

    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe(hubResourceDejaVu('DejaVuSans.ttf'))
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('Private.ttf')
        ->and($response->headers->get('Content-Type'))->toBe('font/ttf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');

    $this->get(hubApiBytesUrl($this->private, sha256: str_repeat('0', 64)))->assertStatus(409);
    $this->get(route('admin.dam.hub.resources.bytes', ['uuid' => $this->private->uuid]))->assertStatus(422);
    $this->get(route('admin.dam.hub.resources.bytes', ['uuid' => $this->private->uuid, 'sha256' => 'abc']))->assertStatus(422);
    // A hub app's own origin is another site, or the same site on another host: neither fetches the bytes itself.
    $this->get(hubApiBytesUrl($this->private), ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    $this->get(hubApiBytesUrl($this->private), ['Sec-Fetch-Site' => 'same-site'])->assertForbidden();
});

it('lets only the owner change or delete a resource, and answers a hidden one like a missing one', function () {
    $this->actingAs($this->member, 'admin');
    $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $this->grouped->uuid]), ['name' => 'Mine now.abr'])
        ->assertForbidden()->assertJsonPath('reason', 'not_owner');
    $this->deleteJson(route('admin.dam.hub.resources.destroy', ['uuid' => $this->grouped->uuid]))->assertForbidden();
    $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $this->private->uuid]), ['name' => 'x.ttf'])->assertNotFound();
    $this->deleteJson(route('admin.dam.hub.resources.destroy', ['uuid' => $this->private->uuid]))->assertNotFound();

    $this->actingAs(hubResourceAdmin(permissionType: 'all'), 'admin');
    $this->deleteJson(route('admin.dam.hub.resources.destroy', ['uuid' => $this->private->uuid]))->assertForbidden();

    expect(HubResource::query()->whereKey([$this->grouped->id, $this->private->id])->count())->toBe(2)
        ->and(HubResource::query()->find($this->grouped->id)->name)->toBe('Inks.abr');

    $object = FileObject::query()->findOrFail($this->private->file_object_id);
    $this->actingAs($this->owner, 'admin');
    $this->patchJson(route('admin.dam.hub.resources.update', ['uuid' => $this->private->uuid]), ['name' => ' My: Sans '])
        ->assertOk()->assertJsonPath('resource.name', 'My Sans');
    $this->deleteJson(route('admin.dam.hub.resources.destroy', ['uuid' => $this->private->uuid]))->assertOk();

    expect(HubResource::query()->whereKey($this->private->id)->exists())->toBeFalse()
        ->and(FileObject::query()->whereKey($object->id)->exists())->toBeFalse()
        ->and(Storage::disk($object->disk)->exists($object->path))->toBeFalse();
});

it('grants the resource keys to every person but no API account, and hides every route while the hub is off', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    $person = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    $robot = Admin::factory()->create(['role_id' => $role->id, 'status' => 1, 'type' => 'api']);

    expect($person->hasPermission('dam.hub.resources'))->toBeTrue()
        ->and($person->hasPermission('dam.hub.resources.share'))->toBeTrue()
        ->and($robot->hasPermission('dam.hub.resources'))->toBeFalse()
        ->and($robot->hasPermission('dam.hub.resources.share'))->toBeFalse();

    $this->actingAs($person, 'admin');
    $this->getJson(route('admin.dam.hub.resources.list'))->assertOk();
    $this->actingAs($robot, 'admin');
    $this->getJson(route('admin.dam.hub.resources.list'))->assertForbidden();

    config(['dam.hub.enabled' => false]);
    $this->actingAs($this->owner, 'admin');
    $this->getJson(route('admin.dam.hub.resources.list'))->assertNotFound();
    $this->get(hubApiBytesUrl($this->private))->assertNotFound();
    $this->postJson(route('admin.dam.hub.resources.store'), ['file' => UploadedFile::fake()->createWithContent('a.ttf', hubResourceDejaVu())])->assertNotFound();
});
