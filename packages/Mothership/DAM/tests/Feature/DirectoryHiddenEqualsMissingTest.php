<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Every endpoint that takes a folder id answers a folder the viewer cannot even see (not canView) exactly
 * as it answers an id that does not exist: same status, same body. A folder the viewer can see but not
 * open — an ancestor of a granted folder, already in their tree — keeps its own 403, which leaks nothing.
 */

/** A role holding every registered ACL key, so no route ACL refuses before the per-folder branch runs. */
function folderParityRole(): Role
{
    return Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ]);
}

function folderParityAccessModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    app(DirectoryPermissionService::class)->flush();
}

/** Opens $this->granted and $this->asset to $member under $model; $this->parent and $this->hidden stay closed. */
function folderParityGrant(Admin $member, string $model, Directory $granted, Asset $asset): void
{
    folderParityAccessModel($model);

    if ($model === AccessModel::LISTS) {
        DB::table('dam_directory_user_access')->insert(['directory_id' => $granted->id, 'admin_id' => $member->id]);
        DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    } else {
        DB::table('dam_directory_role')->insert(['directory_id' => $granted->id, 'role_id' => $member->role_id]);
    }

    app(DirectoryPermissionService::class)->flush();
}

/**
 * Every admin request this sweep changed, for one folder id, as name => [method, url, data, field]. A
 * field names the body key validation refuses (422); null means a path or lookup id that answers 404.
 */
function folderParityAdminRequests(int $id, int $grantedId, int $assetId): array
{
    $chunk = [
        'session_uuid'  => '6f1c1c1e-2a8f-4c7e-9a52-1d2f3a4b5c6d',
        'original_name' => 'probe.png',
        'size'          => 1,
        'last_modified' => 0,
        'fingerprint'   => str_repeat('a', 64),
        'preserve_root' => false,
    ];

    return [
        'directory.store'               => ['POST', route('admin.dam.directory.store'), ['name' => 'Probe', 'parent_id' => $id], 'parent_id'],
        'directory.moved.item'          => ['POST', route('admin.dam.directory.moved'), ['move_item_id' => $id, 'new_parent_id' => $grantedId], 'move_item_id'],
        'directory.moved.parent'        => ['POST', route('admin.dam.directory.moved'), ['move_item_id' => $grantedId, 'new_parent_id' => $id], 'new_parent_id'],
        'directory.create_structure'    => ['POST', route('admin.dam.directory.create_structure'), ['directory_id' => $id, 'paths' => ['probe/inner']], 'directory_id'],
        'assets.upload'                 => ['POST', route('admin.dam.assets.upload'), ['directory_id' => $id], 'directory_id'],
        'assets.upload_folder'          => ['POST', route('admin.dam.assets.upload_folder'), ['directory_id' => $id], 'directory_id'],
        'assets.moved'                  => ['POST', route('admin.dam.assets.moved'), ['move_item_id' => $assetId, 'new_parent_id' => $id], 'new_parent_id'],
        'upload.tracker'                => ['POST', route('admin.dam.assets.upload.tracker'), ['session_uuid' => $chunk['session_uuid'], 'directory_id' => $id], 'directory_id'],
        'chunk-upload.initialize'       => ['POST', route('admin.dam.assets.chunk-upload.initialize'), $chunk + ['directory_id' => $id], 'directory_id'],
        'model_thumbnails.missing'      => ['GET', route('admin.dam.model_thumbnails.missing', ['directory_id' => $id]), [], 'directory_id'],
        'capture.preview'               => ['POST', route('admin.dam.configuration.capture.preview'), ['profile_id' => 0, 'directory_id' => $id], 'directory_id'],
        'explorer.index'                => ['GET', route('admin.dam.explorer.index', ['directory_id' => $id]), [], 'directory_id'],
        'bookmarks.store'               => ['POST', route('admin.dam.explorer.bookmarks.store'), ['directory_id' => $id, 'name' => 'Probe'], 'directory_id'],
        'copy.asset'                    => ['POST', route('admin.dam.explorer.copy.asset'), ['asset_id' => $assetId, 'target_directory_id' => $id], 'target_directory_id'],
        'copy.directory.source'         => ['POST', route('admin.dam.explorer.copy.directory'), ['directory_id' => $id, 'target_directory_id' => $grantedId], 'directory_id'],
        'copy.directory.target'         => ['POST', route('admin.dam.explorer.copy.directory'), ['directory_id' => $grantedId, 'target_directory_id' => $id], 'target_directory_id'],
        'copy_structure_to.source'      => ['POST', route('admin.dam.explorer.directory.copy_structure_to'), ['source_id' => $id, 'target_id' => $grantedId], 'source_id'],
        'copy_structure_to.target'      => ['POST', route('admin.dam.explorer.directory.copy_structure_to'), ['source_id' => $grantedId, 'target_id' => $id], 'target_id'],
        'mass_copy'                     => ['POST', route('admin.dam.explorer.mass_copy'), ['asset_ids' => [$assetId], 'target_directory_id' => $id], 'target_directory_id'],
        'mass_move.target'              => ['POST', route('admin.dam.explorer.mass_move'), ['asset_ids' => [$assetId], 'target_directory_id' => $id], 'target_directory_id'],
        'mass_move.item'                => ['POST', route('admin.dam.explorer.mass_move'), ['directory_ids' => [$id], 'target_directory_id' => $grantedId], 'directory_ids.0'],
        'directory.children'            => ['GET', route('admin.dam.directory.children', $id), [], null],
        'directory.path'                => ['GET', route('admin.dam.directory.path', $id), [], null],
        'directory.update'              => ['POST', route('admin.dam.directory.update'), ['id' => $id, 'name' => 'Renamed probe'], null],
        'directory.destroy'             => ['DELETE', route('admin.dam.directory.destroy', $id), [], null],
        'directory.copy_structure'      => ['POST', route('admin.dam.directory.copy_structure'), ['id' => $id], null],
        'directory.zip_download'        => ['GET', route('admin.dam.directory.zip_download', $id), [], null],
        'shared-links.store'            => ['POST', route('admin.dam.shared-links.store'), ['share_type' => Share::TYPE_DIRECTORY, 'target_id' => $id, 'expiry_days' => 7, 'public_link_confirmed' => true], null],
    ];
}

function expectFolderHiddenEqualsMissing(array $hiddenRequests, array $missingRequests, callable $send): void
{
    foreach ($hiddenRequests as $name => [$method, $url, $data, $field]) {
        $hidden = $send([$method, $url, $data]);
        $missingRequest = $missingRequests[$name];
        $missing = $send([$missingRequest[0], $missingRequest[1], $missingRequest[2]]);

        expect($hidden->getStatusCode())->toBe($field ? 422 : 404, $name)
            ->and($missing->getStatusCode())->toBe($field ? 422 : 404, $name)
            ->and($hidden->getContent())->toBe($missing->getContent(), $name);

        if ($field) {
            expect($hidden->json('errors'))->toHaveKey($field);
        }
    }
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    Bus::fake();

    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first() ?? Directory::factory()->create(['name' => 'Root']);

    // parent: in the member's tree as the granted folder's ancestor, but not openable. hidden: not in it at all.
    $this->parent = Directory::factory()->create(['name' => 'Parity parent', 'parent_id' => $root->id]);
    $this->granted = Directory::factory()->create(['name' => 'Parity granted', 'parent_id' => $this->parent->id]);
    $this->hidden = Directory::factory()->create(['name' => 'Parity hidden', 'parent_id' => $root->id]);
    $this->asset = Asset::factory()->create();
    $this->granted->assets()->attach($this->asset->id);
    $this->missingId = Directory::max('id') + 1000;
});

it('answers a folder the viewer cannot see like a missing one on every folder-id endpoint', function (string $model) {
    $member = Admin::factory()->create(['role_id' => folderParityRole()->id]);
    folderParityGrant(member: $member, model: $model, granted: $this->granted, asset: $this->asset);
    $this->actingAs($member, 'admin');

    $permissions = app(DirectoryPermissionService::class);
    expect($permissions->canView($this->hidden->id))->toBeFalse()
        ->and($permissions->canView($this->parent->id))->toBeTrue()
        ->and($permissions->canAccess($this->parent->id))->toBeFalse()
        ->and($permissions->canAccess($this->granted->id))->toBeTrue();

    // Debug pages embed the caller's line number; compare what production sends.
    config(['app.debug' => false]);
    $requests = fn (int $id): array => folderParityAdminRequests(id: $id, grantedId: $this->granted->id, assetId: $this->asset->id);

    expectFolderHiddenEqualsMissing(
        hiddenRequests: $requests($this->hidden->id),
        missingRequests: $requests($this->missingId),
        send: fn (array $request) => $this->json(...$request),
    );

    // A browser's zip download answers the same way, headers included.
    $hidden = $this->get(route('admin.dam.directory.zip_download', $this->hidden->id));
    $missing = $this->get(route('admin.dam.directory.zip_download', $this->missingId));
    expect($hidden->getStatusCode())->toBe(404)
        ->and($hidden->getContent())->toBe($missing->getContent())
        ->and($hidden->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'));

    // Nothing was written against the hidden folder.
    Bus::assertNothingDispatched();
    expect(Directory::find($this->hidden->id)?->name)->toBe('Parity hidden')
        ->and(Directory::query()->where('parent_id', $this->hidden->id)->exists())->toBeFalse()
        ->and(Share::query()->where('share_type', Share::TYPE_DIRECTORY)->where('target_id', $this->hidden->id)->exists())->toBeFalse()
        ->and(DB::table('dam_explorer_bookmarks')->where('directory_id', $this->hidden->id)->exists())->toBeFalse();
})->with([AccessModel::LISTS, AccessModel::GRANTS]);

it('keeps the 403 for a folder the viewer can see but not open', function (string $model) {
    $member = Admin::factory()->create(['role_id' => folderParityRole()->id]);
    folderParityGrant(member: $member, model: $model, granted: $this->granted, asset: $this->asset);
    $this->actingAs($member, 'admin');

    $this->deleteJson(route('admin.dam.directory.destroy', $this->parent->id))->assertForbidden();
    $this->postJson(route('admin.dam.directory.update'), ['id' => $this->parent->id, 'name' => 'Renamed probe'])->assertForbidden();
    $this->postJson(route('admin.dam.directory.store'), ['name' => 'Probe', 'parent_id' => $this->parent->id])->assertForbidden();
    $this->postJson(route('admin.dam.explorer.copy.asset'), ['asset_id' => $this->asset->id, 'target_directory_id' => $this->parent->id])->assertForbidden();
    // A folder that cannot be opened answers a share link exactly like a missing one.
    $this->postJson(route('admin.dam.shared-links.store'), ['share_type' => Share::TYPE_DIRECTORY, 'target_id' => $this->parent->id, 'expiry_days' => 7, 'public_link_confirmed' => true])->assertNotFound();

    // Its children still list: the folder is in the viewer's tree.
    $this->getJson(route('admin.dam.directory.children', $this->parent->id))->assertOk();
})->with([AccessModel::LISTS, AccessModel::GRANTS]);

it('answers a folder the caller cannot see like a missing one on the REST API', function (string $model) {
    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => folderParityRole()->id]);
    folderParityGrant(member: $member, model: $model, granted: $this->granted, asset: $this->asset);

    config(['app.debug' => false]);
    $requests = fn (int $id): array => [
        'directory.get'    => ['GET', route('admin.api.dam.directory.get', $id), [], null],
        'directory.update' => ['PUT', route('admin.api.dam.directory.update', $id), ['name' => 'Renamed probe'], null],
        'directory.delete' => ['DELETE', route('admin.api.dam.directory.delete', $id), [], null],
        'directory.store'  => ['POST', route('admin.api.dam.directory.store'), ['name' => 'Probe', 'parent_id' => $id], 'parent_id'],
        'assets.upload'    => ['POST', route('admin.api.dam.assets.upload'), ['directory_id' => $id], 'directory_id'],
        'shares.store'     => ['POST', route('admin.api.dam.shares.store'), ['share_type' => Share::TYPE_DIRECTORY, 'directory_id' => $id], null],
    ];

    expectFolderHiddenEqualsMissing(
        hiddenRequests: $requests($this->hidden->id),
        missingRequests: $requests($this->missingId),
        send: fn (array $request) => $this->withHeaders($headers)->json(...$request),
    );

    Bus::assertNothingDispatched();
    expect(Directory::find($this->hidden->id)?->name)->toBe('Parity hidden');
})->with([AccessModel::LISTS, AccessModel::GRANTS]);

it('never errors on a missing folder id for a role that sees every folder', function () {
    // The visibility checks pass for a bypass role, so each endpoint's own missing-id answer must still hold.
    $this->actingAs(Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]), 'admin');
    config(['app.debug' => false]);

    foreach (folderParityAdminRequests(id: $this->missingId, grantedId: $this->granted->id, assetId: $this->asset->id) as $name => [$method, $url, $data, $field]) {
        expect($this->json($method, $url, $data)->getStatusCode())->toBe($field ? 422 : 404, $name);
    }
});

it('never lets a folder name reveal a hidden folder', function (string $model) {
    // Created before signing in: under lists the creator owns a new folder, which would make it visible.
    $root = Directory::find($this->hidden->parent_id);
    Directory::factory()->create(['name' => 'Probe', 'parent_id' => $this->hidden->id]);
    $sibling = Directory::factory()->create(['name' => 'Parity sibling', 'parent_id' => $this->parent->id]);

    $member = Admin::factory()->create(['role_id' => folderParityRole()->id]);
    folderParityGrant(member: $member, model: $model, granted: $this->granted, asset: $this->asset);
    $this->actingAs($member, 'admin');
    config(['app.debug' => false]);
    expect(app(DirectoryPermissionService::class)->canView($this->hidden->id))->toBeFalse();
    $send = fn (array $data) => $this->postJson(route('admin.dam.directory.store'), $data);
    $rename = fn (array $data) => $this->postJson(route('admin.dam.directory.update'), $data);

    // A same-name child under a hidden parent adds no name error a missing parent would not get.
    $hidden = $send(['name' => 'Probe', 'parent_id' => $this->hidden->id]);
    $missing = $send(['name' => 'Probe', 'parent_id' => $this->missingId]);
    expect($hidden->getStatusCode())->toBe(422)
        ->and($hidden->getContent())->toBe($missing->getContent())
        ->and($hidden->json('errors'))->not->toHaveKey('name');

    // Renaming a hidden folder to its own name is not let through as "unchanged".
    $hidden = $rename(['id' => $this->hidden->id, 'name' => 'Parity hidden', 'parent_id' => $root->id]);
    $missing = $rename(['id' => $this->missingId, 'name' => 'Parity hidden', 'parent_id' => $root->id]);
    expect($hidden->getStatusCode())->toBe(422)
        ->and($hidden->getContent())->toBe($missing->getContent());

    // A visible folder keeps both halves of the rule: its own name passes, a sibling's is refused.
    $rename(['id' => $this->granted->id, 'name' => 'Parity granted', 'parent_id' => $this->parent->id])->assertOk();
    $rename(['id' => $this->granted->id, 'name' => $sibling->name, 'parent_id' => $this->parent->id])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(Directory::query()->where('parent_id', $this->hidden->id)->count())->toBe(1)
        ->and(Directory::find($this->hidden->id)->name)->toBe('Parity hidden');
})->with([AccessModel::LISTS, AccessModel::GRANTS]);

it('answers a share link for an asset the viewer cannot open like one for a missing asset', function (string $model) {
    $hiddenAsset = Asset::factory()->create();
    $this->hidden->assets()->attach($hiddenAsset->id);
    $missingAssetId = Asset::max('id') + 1000;

    $headers = $this->getAuthenticationHeaders();
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => folderParityRole()->id]);
    folderParityGrant(member: $member, model: $model, granted: $this->granted, asset: $this->asset);
    $this->actingAs($member, 'admin');
    config(['app.debug' => false]);

    $admin = fn (int $id) => $this->postJson(route('admin.dam.shared-links.store'), ['share_type' => Share::TYPE_ASSET, 'target_id' => $id, 'expiry_days' => 7, 'public_link_confirmed' => true]);
    $api = fn (int $id) => $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), ['share_type' => Share::TYPE_ASSET, 'asset_id' => $id]);

    foreach (['admin' => $admin, 'api' => $api] as $name => $send) {
        $hidden = $send($hiddenAsset->id);
        $missing = $send($missingAssetId);

        expect($hidden->getStatusCode())->toBe(404, $name)
            ->and($hidden->getContent())->toBe($missing->getContent(), $name);
    }

    expect(Share::query()->where('share_type', Share::TYPE_ASSET)->where('target_id', $hiddenAsset->id)->exists())->toBeFalse();

    // An asset the member can open still gets its link, on both stores.
    $admin($this->asset->id)->assertOk()->assertJsonPath('success', true);
    $api($this->asset->id)->assertCreated()->assertJsonPath('success', true);
    expect(Share::query()->where('share_type', Share::TYPE_ASSET)->where('target_id', $this->asset->id)->count())->toBe(2);
})->with([AccessModel::LISTS, AccessModel::GRANTS]);

it('renders only the folders the viewer can see into the Library folder strip seed', function (string $model) {
    $member = Admin::factory()->create(['role_id' => folderParityRole()->id]);
    folderParityGrant(member: $member, model: $model, granted: $this->granted, asset: $this->asset);
    $this->actingAs($member, 'admin');

    // The page embeds the root's first folder page, with counts, so the strip doesn't shift the grid in.
    $repository = app(DirectoryRepository::class);
    $page = $repository->withSubtreeAssetCounts($repository->getLibraryRootPage());
    $names = collect($page['children'])->pluck('name');

    expect($names)->toContain('Parity parent')
        ->and($names)->not->toContain('Parity hidden')
        ->and(collect($page['children'])->firstWhere('name', 'Parity parent')->assets_total_count)->toBe(1);

    $this->get(route('admin.dam.assets.index'))
        ->assertOk()
        ->assertSee('Parity parent')
        ->assertDontSee('Parity hidden');
})->with([AccessModel::LISTS, AccessModel::GRANTS]);
