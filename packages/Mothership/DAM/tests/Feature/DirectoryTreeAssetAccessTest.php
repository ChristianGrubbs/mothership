<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * getDirectoryTree() eager-loads each folder's files. "Viewable" includes a path-only ancestor of a
 * granted folder that the viewer cannot open, and under `lists` a file's own list can leave out
 * someone who opens its folder, so every caller must list only the files the viewer can open — the
 * same rule the Explorer listing applies.
 *
 * Fixture: Parent (path-only: viewable, not accessible) holds parentFile; Child (granted) holds
 * childFile, which the viewer can open, and privateFile, which under `lists` is not on the viewer's
 * list (under `grants` a file in a granted folder is open, so privateFile is expected there). Under
 * `lists`, Parent also holds sharedFile, which is on the viewer's own list: the filter follows the
 * file's access, not the folder's, so it still shows in a folder the viewer cannot open. childFile also
 * sits in Hidden, which no grant reaches: under `grants` the viewer cannot see Hidden, so childFile's
 * folder list must leave it out; under `lists` a folder holding a file the viewer opens is viewable
 * (a path-only folder), so there Hidden is shown and named.
 */
function directoryTreeAccessFixture(string $model, Admin $viewer): array
{
    $parent = Directory::create(['name' => 'TreeAccessParent '.Str::random(6), 'parent_id' => null]);
    $child = Directory::create(['name' => 'TreeAccessChild '.Str::random(6), 'parent_id' => $parent->id]);
    $hidden = Directory::create(['name' => 'TreeAccessHidden '.Str::random(6), 'parent_id' => null]);

    $parentFile = Asset::factory()->create();
    $childFile = Asset::factory()->create();
    $privateFile = Asset::factory()->create();
    $sharedFile = Asset::factory()->create();
    $parentFile->directories()->attach($parent);
    $sharedFile->directories()->attach($parent);
    $childFile->directories()->attach([$child->id, $hidden->id]);
    $privateFile->directories()->attach($child);

    if ($model === AccessModel::LISTS) {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
        app(AccessModel::class)->flush();
        DB::table('dam_directory_user_access')->insert(['directory_id' => $child->id, 'admin_id' => $viewer->id]);
        DB::table('dam_asset_user_access')->insert([
            ['asset_id' => $childFile->id, 'admin_id' => $viewer->id],
            ['asset_id' => $sharedFile->id, 'admin_id' => $viewer->id],
        ]);
    } else {
        DB::table('dam_directory_role')->insert([
            'directory_id' => $child->id,
            'role_id'      => $viewer->role_id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    app(DirectoryPermissionService::class)->flush();

    // Self-check the premise, so a fixture drift cannot turn this into a vacuous pass. A separate
    // instance, so forAdmin() never pins the container's service to this admin.
    $service = (new DirectoryPermissionService)->forAdmin($viewer);
    expect($service->bypass())->toBeFalse()
        ->and($service->canView($parent->id))->toBeTrue()
        ->and($service->canAccess($parent->id))->toBeFalse()
        ->and($service->canAccess($child->id))->toBeTrue()
        ->and($service->canView($hidden->id))->toBe($model === AccessModel::LISTS);

    $expectedChild = $model === AccessModel::LISTS
        ? [$childFile->id]
        : collect([$childFile->id, $privateFile->id])->sort()->values()->all();

    $expectedParent = $model === AccessModel::LISTS ? [$sharedFile->id] : [];

    return compact('parent', 'child', 'hidden', 'childFile', 'expectedParent', 'expectedChild');
}

function directoryTreeAssetIds(?array $assets): array
{
    return collect($assets ?? [])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

function directoryTreeNode(array $nodes, int $id): ?array
{
    foreach ($nodes as $node) {
        if ((int) $node['id'] === $id) {
            return $node;
        }

        if ($found = directoryTreeNode($node['children'] ?? [], $id)) {
            return $found;
        }
    }

    return null;
}

it('lists only openable files through the API directory endpoint', function (string $model) {
    $headers = $this->getAuthenticationHeaders();
    $viewer = Admin::latest('id')->first();
    $viewer->update(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => []])->id]);
    $viewer->refresh();

    ['parent' => $parent, 'child' => $child, 'expectedParent' => $expectedParent, 'expectedChild' => $expectedChild] = directoryTreeAccessFixture($model, $viewer);

    $parentResponse = $this->withHeaders($headers)->getJson(route('admin.api.dam.directory.get', $parent->id));
    $parentResponse->assertOk();
    expect(directoryTreeAssetIds($parentResponse->json('data.assets')))->toBe($expectedParent);

    $childResponse = $this->withHeaders($headers)->getJson(route('admin.api.dam.directory.get', $child->id));
    $childResponse->assertOk();
    expect(directoryTreeAssetIds($childResponse->json('data.assets')))->toBe($expectedChild);
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('lists only openable files in the admin tree and folder-assets endpoints', function (string $model) {
    config()->set('dam.tree.show_assets', true);

    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.directory', 'dam.directory.index', 'dam.asset', 'dam.asset.view'],
    ])->id]);

    // Built before signing in: a folder or file the viewer created is theirs, and owners open it.
    ['parent' => $parent, 'child' => $child, 'expectedParent' => $expectedParent, 'expectedChild' => $expectedChild] = directoryTreeAccessFixture($model, $viewer);
    $this->actingAs($viewer, 'admin');

    $tree = $this->getJson(route('admin.dam.directory.index', ['with_assets' => 1]));
    $tree->assertOk();
    $parentNode = directoryTreeNode($tree->json('data'), $parent->id);
    $childNode = directoryTreeNode($tree->json('data'), $child->id);
    expect($parentNode)->not->toBeNull()
        ->and($childNode)->not->toBeNull()
        ->and(directoryTreeAssetIds($parentNode['assets']))->toBe($expectedParent)
        ->and(directoryTreeAssetIds($childNode['assets']))->toBe($expectedChild);

    $folderAssets = $this->getJson(route('admin.dam.directory.assets', $child->id));
    $folderAssets->assertOk();
    expect(directoryTreeAssetIds($folderAssets->json('data')))->toBe($expectedChild);
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('names only folders the viewer can see on each listed file', function (string $model) {
    config()->set('dam.tree.show_assets', true);

    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.directory', 'dam.directory.index', 'dam.asset', 'dam.asset.view'],
    ])->id]);

    ['child' => $child, 'hidden' => $hidden, 'childFile' => $childFile] = directoryTreeAccessFixture($model, $viewer);
    $this->actingAs($viewer, 'admin');

    $folderIds = fn (?array $assets) => collect(collect($assets ?? [])->firstWhere('id', $childFile->id)['directories'] ?? [])
        ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    $expected = $model === AccessModel::LISTS
        ? collect([$child->id, $hidden->id])->sort()->values()->all()
        : [$child->id];

    // The whole tree and a single folder load the files through separate branches of getDirectoryTree().
    $tree = $this->getJson(route('admin.dam.directory.index', ['with_assets' => 1]));
    $tree->assertOk();
    expect(directoryTreeNode($tree->json('data'), $hidden->id) !== null)->toBe($model === AccessModel::LISTS)
        ->and($folderIds(directoryTreeNode($tree->json('data'), $child->id)['assets']))->toBe($expected);

    $folderAssets = $this->getJson(route('admin.dam.directory.assets', $child->id));
    $folderAssets->assertOk();
    expect($folderIds($folderAssets->json('data')))->toBe($expected);
})->with([AccessModel::GRANTS, AccessModel::LISTS]);
