<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Move, copy and Trash over REST: the Library's own actions behind an API key. A key needs its api-acl permission and
 * its admin needs the role permission and folder access the Library asks of a person.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['app.debug' => false]);

    $this->root = Directory::query()->whereNull('parent_id')->orderBy('id')->first()
        ?? Directory::factory()->create(['name' => 'Root']);
    $this->source = Directory::factory()->create(['name' => 'Reorganize source', 'parent_id' => $this->root->id]);
    $this->target = Directory::factory()->create(['name' => 'Reorganize target', 'parent_id' => $this->root->id]);
    $this->fullAdmin = Admin::factory()->create();
    $this->headers = reorganizeHeadersFor(admin: $this->fullAdmin);
});

/**
 * Bearer headers for $admin's own API key. forgetGuards(): Passport's guard caches the first user it resolves, so a
 * second actor in one test would otherwise still be the first.
 *
 * @param  array<int, string>|null  $permissions
 * @return array<string, string>
 */
function reorganizeHeadersFor(Admin $admin, string $permissionType = 'all', ?array $permissions = null): array
{
    test()->withoutMiddleware(ThrottleRequests::class);
    app('auth')->forgetGuards();

    $admin->forceFill(['password' => Hash::make('password')])->save();

    $client = (new ClientRepository)->createPasswordGrantClient('Client for testing reorganize', 'admins', confidential: true);
    $client->forceFill(['user_id' => $admin->id, 'owner_type' => Admin::class, 'owner_id' => $admin->id])->save();

    Apikey::factory()->create([
        'permission_type' => $permissionType,
        'admin_id'        => $admin->id,
        'oauth_client_id' => $client->getKey(),
        'permissions'     => $permissions,
    ]);

    $credential = test()->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $admin->email,
        'password'      => 'password',
        'scope'         => '',
    ])->json('access_token');

    return ['Authorization' => 'Bearer '.$credential, 'Accept' => 'application/json'];
}

/**
 * An admin whose custom role holds $permissions and opens only $folders.
 *
 * @param  array<int, string>  $permissions
 * @param  array<int, Directory>  $folders
 */
function reorganizeRestrictedAdmin(array $permissions, array $folders): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

    foreach ($folders as $folder) {
        DB::table('dam_directory_role')->insert([
            'directory_id' => $folder->id,
            'role_id'      => $role->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    app(DirectoryPermissionService::class)->flush();

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** A real upload, so the asset has the stored original a copy shares. */
function reorganizeUpload(Directory $folder, string $name = 'reorganize.png'): Asset
{
    $response = test()->withHeaders(test()->headers)->post(route('admin.api.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image($name, 20, 20)],
        'directory_id' => $folder->id,
    ]);

    $response->assertStatus(201);

    return Asset::findOrFail($response->json('files.0.id'));
}

function reorganizeFolderOf(Asset $asset): int
{
    return (int) DB::table('dam_asset_directory')->where('asset_id', $asset->id)->value('directory_id');
}

it('moves an asset into another folder', function () {
    $asset = reorganizeUpload(folder: $this->source);

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.assets.move', $asset->id), ['target_directory_id' => $this->target->id])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $asset->id);

    expect(reorganizeFolderOf(asset: $asset))->toBe($this->target->id)
        ->and($asset->fresh()->path)->toContain('Reorganize target/');
});

it('moves several assets into another folder', function () {
    $first = reorganizeUpload(folder: $this->source, name: 'first.png');
    $second = reorganizeUpload(folder: $this->source, name: 'second.png');

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.assets.mass_move'), [
            'asset_ids'           => [$first->id, $second->id],
            'target_directory_id' => $this->target->id,
        ])
        ->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('queued', true);

    expect(reorganizeFolderOf(asset: $first))->toBe($this->target->id)
        ->and(reorganizeFolderOf(asset: $second))->toBe($this->target->id);
});

it('copies an asset and leaves the source where it was', function () {
    $asset = reorganizeUpload(folder: $this->source);

    $response = $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.assets.copy', $asset->id), ['target_directory_id' => $this->target->id])
        ->assertStatus(201)
        ->assertJsonPath('success', true);

    $copy = Asset::findOrFail($response->json('asset.id'));

    expect($copy->id)->not->toBe($asset->id)
        ->and(reorganizeFolderOf(asset: $copy))->toBe($this->target->id)
        ->and(reorganizeFolderOf(asset: $asset))->toBe($this->source->id);
});

it('moves a folder under another folder', function () {
    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.directory.move', $this->source->id), ['target_directory_id' => $this->target->id])
        ->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('queued', true);

    expect((int) $this->source->fresh()->parent_id)->toBe($this->target->id);
});

it('refuses to move a folder inside itself', function () {
    $child = Directory::factory()->create(['name' => 'Reorganize child', 'parent_id' => $this->source->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.directory.move', $this->source->id), ['target_directory_id' => $child->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect((int) $this->source->fresh()->parent_id)->toBe($this->root->id);
});

it('copies a folder with the files in it', function () {
    reorganizeUpload(folder: $this->source);

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.directory.copy', $this->source->id), ['target_directory_id' => $this->target->id])
        ->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('queued', true);

    $copy = Directory::query()->where('parent_id', $this->target->id)->where('name', $this->source->name)->firstOrFail();

    expect(DB::table('dam_asset_directory')->where('directory_id', $copy->id)->count())->toBe(1);
});

it('lists only the trashed assets the key\'s admin could see', function () {
    $visible = reorganizeUpload(folder: $this->source, name: 'visible.png');
    $hidden = reorganizeUpload(folder: $this->target, name: 'hidden.png');
    app(AssetTrashService::class)->trash(asset: $visible);
    app(AssetTrashService::class)->trash(asset: $hidden);

    $member = reorganizeRestrictedAdmin(permissions: ['dam', 'dam.trash', 'dam.trash.index'], folders: [$this->source]);
    $headers = reorganizeHeadersFor(admin: $member, permissionType: 'custom', permissions: ['api.dam.trash', 'api.dam.trash.get']);

    $response = $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.trash.index'))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(collect($response->json('assets'))->pluck('id')->all())->toBe([$visible->id])
        ->and($response->json('assets.0.can_restore'))->toBeFalse();
});

it('offers restore in the Trash listing to an admin whose role holds it', function () {
    $asset = reorganizeUpload(folder: $this->source);
    app(AssetTrashService::class)->trash(asset: $asset);

    $member = reorganizeRestrictedAdmin(permissions: ['dam', 'dam.trash', 'dam.trash.index', 'dam.trash.restore'], folders: [$this->source]);
    $headers = reorganizeHeadersFor(admin: $member, permissionType: 'custom', permissions: ['api.dam.trash', 'api.dam.trash.get']);

    $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.trash.index'))
        ->assertOk()
        ->assertJsonPath('assets.0.id', $asset->id)
        ->assertJsonPath('assets.0.can_restore', true);
});

it('answers a copy of a missing asset exactly as it answers a hidden one', function () {
    $hidden = reorganizeUpload(folder: $this->target);
    $member = reorganizeRestrictedAdmin(permissions: ['dam', 'dam.asset', 'dam.asset.upload'], folders: [$this->source]);
    $memberHeaders = reorganizeHeadersFor(admin: $member, permissionType: 'custom', permissions: ['api.dam.assets', 'api.dam.assets.copy']);
    $body = ['target_directory_id' => $this->source->id];

    $hiddenAnswer = $this->withHeaders($memberHeaders)
        ->postJson(route('admin.api.dam.assets.copy', $hidden->id), $body)
        ->assertNotFound();

    app('auth')->forgetGuards();

    $missingAnswer = $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.assets.copy', $hidden->id + 1000), $body)
        ->assertNotFound();

    expect($missingAnswer->json())->toBe($hiddenAnswer->json());
});

it('restores a trashed asset to its folder', function () {
    $asset = reorganizeUpload(folder: $this->source);
    app(AssetTrashService::class)->trash(asset: $asset);

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.trash.restore', $asset->id))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('id', $asset->id);

    expect(Asset::find($asset->id))->not->toBeNull()
        ->and(reorganizeFolderOf(asset: $asset))->toBe($this->source->id);
});

it('purges a trashed asset for good', function () {
    $asset = reorganizeUpload(folder: $this->source);
    app(AssetTrashService::class)->trash(asset: $asset);

    $this->withHeaders($this->headers)
        ->deleteJson(route('admin.api.dam.trash.destroy', $asset->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Asset::withTrashed()->find($asset->id))->toBeNull();
});

it('answers a live asset like a missing one on restore and purge', function () {
    $asset = reorganizeUpload(folder: $this->source);

    $this->withHeaders($this->headers)->postJson(route('admin.api.dam.trash.restore', $asset->id))->assertNotFound();
    $this->withHeaders($this->headers)->deleteJson(route('admin.api.dam.trash.destroy', $asset->id))->assertNotFound();

    expect(Asset::find($asset->id))->not->toBeNull();
});

it('refuses a key without the endpoint\'s permission and changes nothing', function (string $method, string $routeName, bool $takesId) {
    $asset = reorganizeUpload(folder: $this->source);
    $restricted = reorganizeHeadersFor(admin: Admin::factory()->create(), permissionType: 'custom', permissions: ['api.dam.assets']);
    $parameters = $takesId ? [str_contains($routeName, 'directory') ? $this->source->id : $asset->id] : [];

    $this->withHeaders($restricted)
        ->json($method, route($routeName, $parameters), ['asset_ids' => [$asset->id], 'target_directory_id' => $this->target->id])
        ->assertForbidden();

    expect(reorganizeFolderOf(asset: $asset))->toBe($this->source->id)
        ->and((int) $this->source->fresh()->parent_id)->toBe($this->root->id);
})->with([
    'move an asset'      => ['POST', 'admin.api.dam.assets.move', true],
    'move several'       => ['POST', 'admin.api.dam.assets.mass_move', false],
    'copy an asset'      => ['POST', 'admin.api.dam.assets.copy', true],
    'move a folder'      => ['POST', 'admin.api.dam.directory.move', true],
    'copy a folder'      => ['POST', 'admin.api.dam.directory.copy', true],
    'list Trash'         => ['GET', 'admin.api.dam.trash.index', false],
    'restore from Trash' => ['POST', 'admin.api.dam.trash.restore', true],
    'purge from Trash'   => ['DELETE', 'admin.api.dam.trash.destroy', true],
]);

it('refuses a move into a folder the key\'s admin cannot see, as if it did not exist', function () {
    $asset = reorganizeUpload(folder: $this->source);
    $member = reorganizeRestrictedAdmin(permissions: ['dam', 'dam.asset.moved'], folders: [$this->source]);
    $headers = reorganizeHeadersFor(admin: $member, permissionType: 'custom', permissions: ['api.dam.assets.move']);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.move', $asset->id), ['target_directory_id' => $this->target->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['target_directory_id']);

    expect(reorganizeFolderOf(asset: $asset))->toBe($this->source->id);
});

it('refuses a move into a folder the key\'s admin can see but not open', function () {
    $asset = reorganizeUpload(folder: $this->source);
    // A grant on a child shows its parent in the tree without opening it.
    $child = Directory::factory()->create(['name' => 'Granted child', 'parent_id' => $this->target->id]);
    $member = reorganizeRestrictedAdmin(permissions: ['dam', 'dam.asset.moved'], folders: [$this->source, $child]);
    $headers = reorganizeHeadersFor(admin: $member, permissionType: 'custom', permissions: ['api.dam.assets.move']);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.move', $asset->id), ['target_directory_id' => $this->target->id])
        ->assertForbidden()
        ->assertJsonPath('success', false);

    expect(reorganizeFolderOf(asset: $asset))->toBe($this->source->id);
});

it('applies the access lists when the library runs on them, and records who moved the asset', function () {
    $asset = reorganizeUpload(folder: $this->source);
    $closed = Directory::factory()->create(['name' => 'Closed folder', 'parent_id' => $this->root->id]);
    $member = reorganizeRestrictedAdmin(permissions: ['dam', 'dam.asset.moved'], folders: []);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $this->source->id, 'admin_id' => $member->id],
        ['directory_id' => $this->target->id, 'admin_id' => $member->id],
    ]);
    $headers = reorganizeHeadersFor(admin: $member, permissionType: 'custom', permissions: ['api.dam.assets.move']);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.move', $asset->id), ['target_directory_id' => $closed->id])
        ->assertStatus(422);
    expect(reorganizeFolderOf(asset: $asset))->toBe($this->source->id);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.move', $asset->id), ['target_directory_id' => $this->target->id])
        ->assertOk();

    $event = DB::table('dam_security_events')->where('event', SecurityEvent::ACCESS_ADOPTED_ON_MOVE)->sole();

    expect(reorganizeFolderOf(asset: $asset))->toBe($this->target->id)
        ->and((int) $event->actor_admin_id)->toBe($member->id);
});

it('answers an asset or folder the key\'s admin cannot see like a missing one', function () {
    $hiddenAsset = reorganizeUpload(folder: $this->target);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden folder', 'parent_id' => $this->target->id]);
    $member = reorganizeRestrictedAdmin(
        permissions: ['dam', 'dam.asset.moved', 'dam.asset.upload', 'dam.directory.moved', 'dam.directory.store'],
        folders: [$this->source],
    );
    $headers = reorganizeHeadersFor(admin: $member);
    $body = ['target_directory_id' => $this->source->id];

    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.move', $hiddenAsset->id), $body)->assertNotFound();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.assets.copy', $hiddenAsset->id), $body)->assertNotFound();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.directory.move', $hiddenFolder->id), $body)->assertNotFound();
    $this->withHeaders($headers)->postJson(route('admin.api.dam.directory.copy', $hiddenFolder->id), $body)->assertNotFound();

    expect(reorganizeFolderOf(asset: $hiddenAsset))->toBe($this->target->id)
        ->and((int) $hiddenFolder->fresh()->parent_id)->toBe($this->target->id);
});

it('never gives a key more than its admin\'s role holds', function (string $method, string $routeName, string $subject) {
    $asset = reorganizeUpload(folder: $this->source);
    $id = match ($subject) {
        'folder' => $this->source->id,
        default  => $asset->id,
    };

    if ($subject === 'trashed') {
        app(AssetTrashService::class)->trash(asset: $asset);
    }

    $member = reorganizeRestrictedAdmin(permissions: ['dam'], folders: [$this->source, $this->target]);
    $headers = reorganizeHeadersFor(admin: $member);

    $this->withHeaders($headers)
        ->json($method, route($routeName, $id), ['target_directory_id' => $this->target->id])
        ->assertForbidden();

    expect(Asset::withTrashed()->find($asset->id))->not->toBeNull()
        ->and((int) $this->source->fresh()->parent_id)->toBe($this->root->id);
})->with([
    'move an asset'      => ['POST', 'admin.api.dam.assets.move', 'asset'],
    'copy an asset'      => ['POST', 'admin.api.dam.assets.copy', 'asset'],
    'move a folder'      => ['POST', 'admin.api.dam.directory.move', 'folder'],
    'copy a folder'      => ['POST', 'admin.api.dam.directory.copy', 'folder'],
    'restore from Trash' => ['POST', 'admin.api.dam.trash.restore', 'trashed'],
    'purge from Trash'   => ['DELETE', 'admin.api.dam.trash.destroy', 'trashed'],
]);

it('refuses a bulk move and a Trash listing when the key\'s admin lacks the role permission', function () {
    $asset = reorganizeUpload(folder: $this->source);
    $member = reorganizeRestrictedAdmin(permissions: ['dam'], folders: [$this->source, $this->target]);
    $headers = reorganizeHeadersFor(admin: $member);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.assets.mass_move'), ['asset_ids' => [$asset->id], 'target_directory_id' => $this->target->id])
        ->assertForbidden();
    $this->withHeaders($headers)->getJson(route('admin.api.dam.trash.index'))->assertForbidden();

    expect(reorganizeFolderOf(asset: $asset))->toBe($this->source->id);
});

it('names the documented field when the destination is missing', function () {
    $asset = reorganizeUpload(folder: $this->source);

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.assets.move', $asset->id), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['target_directory_id']);
});
