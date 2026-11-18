<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

uses(DatabaseTransactions::class);

function webPageRow(string $title, array $extra = []): Asset
{
    $asset = Asset::factory()->create(array_merge(['file_type' => 'web', 'file_name' => strtolower($title).'.html', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/'.strtolower($title).'.html', 'title' => $title], $extra));
    $asset->directories()->attach(1);

    return $asset;
}

/** @return array<int, string> display_name of each row in listing()'s pages.data, in order */
function pageTitles(array $listingJson): array
{
    return collect($listingJson['pages']['data'])->pluck('display_name')->all();
}

it('lets an ordinary owner trash a page and restore its bundle and memberships unchanged', function () {
    Storage::fake(Directory::getAssetDisk());
    $admin = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view', 'dam.asset.destroy']);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $admin->role_id], ['all_directories' => true, 'delete_own_only' => true]);
    app(DirectoryPermissionService::class)->flush();
    $page = webPageRow('Trashablepage', ['created_by' => $admin->id, 'meta_data' => ['web_bundle' => ['version' => 1]]]);
    $bundle = 'bundles/'.$page->id.'/1/index.html';
    Storage::disk(Directory::getAssetDisk())->put($bundle, '<h1>Synthetic page</h1>');
    Storage::disk(Directory::getAssetDisk())->put($page->path, '<h1>Synthetic original</h1>');
    $directories = $page->directories()->pluck('dam_directories.id')->all();
    $metadata = $page->meta_data;

    $this->getJson(route('admin.dam.pages.listing'))->assertOk()->assertJsonPath('pages.data.0.can_delete', true);
    $this->deleteJson(route('admin.dam.pages.destroy', $page->id))->assertOk();
    expect(Asset::find($page->id))->toBeNull();
    Storage::disk(Directory::getAssetDisk())->assertExists($bundle);
    expect(Storage::disk(Directory::getAssetDisk())->get($page->path))->toBe('<h1>Synthetic original</h1>');
    $this->getJson(route('admin.dam.pages.listing'))->assertOk()->assertJsonPath('pages.total', 0);

    app(AssetTrashService::class)->restore(Asset::onlyTrashed()->findOrFail($page->id));
    expect($page->fresh()->meta_data)->toBe($metadata)
        ->and($page->fresh()->directories()->pluck('dam_directories.id')->all())->toBe($directories);
    Storage::disk(Directory::getAssetDisk())->assertExists($bundle);
    expect(Storage::disk(Directory::getAssetDisk())->get($page->path))->toBe('<h1>Synthetic original</h1>');
});

it('does not let the page route bypass delete entitlement ownership visibility or file type', function () {
    $owner = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view']);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $owner->role_id], ['all_directories' => true, 'delete_own_only' => true]);
    app(DirectoryPermissionService::class)->flush();
    $mine = webPageRow('Ownerpage', ['created_by' => $owner->id]);
    $this->getJson(route('admin.dam.pages.listing'))->assertOk()->assertJsonPath('pages.data.0.can_delete', false);
    $this->deleteJson(route('admin.dam.pages.destroy', $mine->id))->assertForbidden();

    $other = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view', 'dam.asset.destroy']);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $other->role_id], ['all_directories' => true, 'delete_own_only' => true]);
    app(DirectoryPermissionService::class)->flush();
    $this->deleteJson(route('admin.dam.pages.destroy', $mine->id))->assertForbidden();
    $ordinary = webPageRow('Ordinary', ['created_by' => $other->id, 'file_type' => 'image']);
    $this->deleteJson(route('admin.dam.pages.destroy', $ordinary->id))->assertNotFound();
    DB::table('dam_role_settings')->where('role_id', $other->role_id)->update(['all_directories' => false]);
    app(DirectoryPermissionService::class)->flush();
    $this->deleteJson(route('admin.dam.pages.destroy', $mine->id))->assertNotFound();
    $this->deleteJson(route('admin.dam.pages.destroy', 99999999))->assertNotFound();
    expect($mine->fresh()->deleted_at)->toBeNull();
});

it('refuses a dependent page even when a caller supplies force', function () {
    $this->loginAsAdmin();
    $page = webPageRow('Dependency');
    $other = webPageRow('Dependent');
    AssetRelationship::factory()->create([
        'from_asset_id' => $other->id,
        'to_asset_id'   => $page->id,
        'type'          => AssetRelationship::TYPE_SOURCE_FOR,
    ]);
    $this->deleteJson(route('admin.dam.pages.destroy', $page->id), ['force' => true])->assertUnprocessable();
    expect($page->fresh()->deleted_at)->toBeNull();
});

it('lets a full admin trash another owners page but preserves resource-bound pages', function () {
    $this->loginAsAdmin();
    $other = Admin::factory()->create();
    $page = webPageRow('Otherowner', ['created_by' => $other->id]);
    $this->getJson(route('admin.dam.pages.listing'))->assertOk()->assertJsonPath('pages.data.0.can_delete', true);
    $page->resources()->create(['type' => 'product', 'related_field' => 'image']);
    $this->deleteJson(route('admin.dam.pages.destroy', $page->id))->assertUnprocessable();
    expect($page->fresh()->deleted_at)->toBeNull();
    $page->resources()->delete();
    $this->deleteJson(route('admin.dam.pages.destroy', $page->id))->assertOk();
    $this->postJson(route('admin.dam.trash.restore', $page->id))->assertOk();
    expect($page->fresh()->deleted_at)->toBeNull();
});

it('index() mounts the Vue browser with the initial JSON, permissions, labels and route map', function () {
    $this->loginAsAdmin();
    webPageRow('Shellpage');

    $response = $this->get(route('admin.dam.pages.index'))->assertOk();
    $response->assertSee('v-dam-web-pages-browser', false)
        ->assertSee('Shellpage')
        ->assertSee(':initial=', false)
        ->assertSee(':routes=', false)
        ->assertSee(':can=', false)
        ->assertSee(':labels=', false);
});

it('listing() lists web pages newest first and hides other kinds', function () {
    $this->loginAsAdmin();
    webPageRow('Oldpage', ['updated_at' => now()->subDay()]);
    webPageRow('Newpage');
    Asset::factory()->create(['title' => 'Justanimage']);

    $body = $this->getJson(route('admin.dam.pages.listing'))->assertOk()->json();

    expect(pageTitles($body))->toBe(['Newpage', 'Oldpage']);
});

it('listing() filters by title search', function () {
    $this->loginAsAdmin();
    webPageRow('Alpha');
    webPageRow('Beta');

    $body = $this->getJson(route('admin.dam.pages.listing', ['q' => 'Alp']))->assertOk()->json();

    expect(pageTitles($body))->toBe(['Alpha']);
});

it('registers the sidebar entry, acl key and Sandbox capability', function () {
    // Re-keyed to a top-level 'dam_pages' row (Library's former children became top-level sidebar
    // rows) with an explicit 'acl' pin so gating stays on the original 'dam.pages' permission key.
    expect(collect(config('menu.admin'))->firstWhere('key', 'dam_pages'))->toMatchArray(['route' => 'admin.dam.pages.index', 'acl' => 'dam.pages', 'icon' => 'icon-file'])
        ->and(collect(config('acl'))->firstWhere('key', 'dam.pages'))->not->toBeNull()
        ->and(RoleBuilder::CAPABILITIES['web_pages'])->toBe(['dam.pages', 'dam.pages.organize', 'dam.asset.upload', 'dam.asset.view']);
});

it('hides the index from a role without dam.pages', function () {
    $this->loginWithPermissions('custom', ['dashboard']);

    $this->get(route('admin.dam.pages.index'))->assertForbidden();
});

it('listing() matches the resolved display name, not just the raw title or file name', function () {
    $this->loginAsAdmin();
    $asset = webPageRow('X1', ['title' => null]);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Title', 'value' => "O'Reilly Guide"]);
    webPageRow('X2', ['title' => 'Unrelated']);

    // The apostrophe exercises the parameter binding in the raw LIKE predicate directly (a
    // naive string-concatenated LIKE would break or, worse, inject on this exact character).
    $body = $this->getJson(route('admin.dam.pages.listing', ['q' => "O'Reilly"]))->assertOk()->json();

    expect(pageTitles($body))->toBe(["O'Reilly Guide"]);
});

it('hides Upload and Download capability flags for a role with only the web_pages capability keys', function () {
    $admin = $this->loginWithPermissions('custom', ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view', 'dam.asset.upload']);
    DB::table('dam_role_settings')->updateOrInsert(['role_id' => $admin->role_id], ['all_directories' => true]);
    app(DirectoryPermissionService::class)->flush();
    $page = webPageRow('Capabilitypage');

    $body = $this->getJson(route('admin.dam.pages.listing'))->assertOk()->json();
    expect($body['can'])->toMatchArray(['upload' => false, 'download' => false]);

    // dam.asset.view (part of the web_pages capability) still has to reach the full-screen
    // viewer this role's Open-full-screen link points at.
    $this->get(route('admin.dam.pages.show', ['id' => $page->id]))->assertOk();
});

it('shows Upload and Download capability flags for a full-access admin', function () {
    $this->loginAsAdmin();

    $body = $this->getJson(route('admin.dam.pages.listing'))->assertOk()->json();
    expect($body['can'])->toMatchArray(['upload' => true, 'download' => true]);
});

it('filters to the requesting admin when owner=me is selected and carries the owner name', function () {
    $admin = $this->loginAsAdmin();
    $other = Admin::factory()->create();
    webPageRow('Mine', ['created_by' => $admin->id]);
    webPageRow('Theirs', ['created_by' => $other->id]);
    $ownerless = webPageRow('Ownerless');
    // Asset::creating() backfills created_by from the logged-in admin whenever it is falsy, so an
    // explicit null in the factory attributes never survives creation -- force it after the fact.
    DB::table('dam_assets')->where('id', $ownerless->id)->update(['created_by' => null]);

    $mine = $this->getJson(route('admin.dam.pages.listing', ['owner' => 'me']))->assertOk()->json();
    expect(pageTitles($mine))->toBe(['Mine']);

    $everyone = $this->getJson(route('admin.dam.pages.listing'))->assertOk()->json();
    expect(collect($everyone['pages']['data'])->pluck('creator'))->toContain($admin->name)->toContain($other->name)->toContain(null);
});

it('scopes the listing to the directories the role can access', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);

    $granted = Directory::create(['name' => 'Granted', 'parent_id' => null]);
    $ungranted = Directory::create(['name' => 'Ungranted', 'parent_id' => null]);
    DB::table('dam_directory_role')->insert(['directory_id' => $granted->id, 'role_id' => $role->id]);

    $inGranted = webPageRow('Ingranted');
    $inGranted->directories()->sync([$granted->id]);
    $inUngranted = webPageRow('Inungranted');
    $inUngranted->directories()->sync([$ungranted->id]);
    $inBoth = webPageRow('Inboth');
    $inBoth->directories()->sync([$granted->id, $ungranted->id]);

    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    $body = $this->getJson(route('admin.dam.pages.listing'))->assertOk()->json();

    expect(pageTitles($body))->toContain('Ingranted')->toContain('Inboth')->not->toContain('Inungranted');
});

it('offers only directly granted directories to Upload here, not their breadcrumb-only ancestors', function () {
    $parent = Directory::create(['name' => 'Parentonly', 'parent_id' => null]);
    $child = Directory::create(['name' => 'Childgranted', 'parent_id' => $parent->id]);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard', 'dam', 'dam.assets', 'dam.asset', 'dam.asset.view', 'dam.asset.upload', 'dam.pages']]);
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    DB::table('dam_directory_role')->insert(['directory_id' => $child->id, 'role_id' => $role->id]);

    $this->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    // viewableIds() would include Parentonly too (a breadcrumb ancestor of the granted child),
    // but it is not an upload target, so the picker payload must not offer it.
    $response = $this->get(route('admin.dam.pages.index'))->assertOk();
    $response->assertSee('Childgranted')->assertDontSee('Parentonly');
});

it('offers only a dedicated root Web Pages folder as the upload target, created on first visit', function () {
    Storage::fake(Directory::getAssetDisk());
    $this->loginAsAdmin();
    $root = Directory::query()->whereNull('parent_id')->orderBy('id')->firstOrFail();
    Storage::disk(Directory::getAssetDisk())->makeDirectory('assets/'.$root->name);
    Directory::query()->create(['name' => 'Content Folder', 'parent_id' => $root->id]);

    $this->get(route('admin.dam.pages.index'))->assertOk();
    $response = $this->get(route('admin.dam.pages.index'))->assertOk();

    $dedicated = Directory::query()->where('parent_id', $root->id)->where('name', 'Web Pages')->get();
    expect($dedicated)->toHaveCount(1);
    $response->assertViewHas('uploadDirectories', fn ($dirs) => collect($dirs)->all() === [['id' => (int) $dedicated->first()->id, 'name' => 'Web Pages']]);
});

it('listing() sorts by name in either direction and by oldest first, and echoes the sort', function () {
    $this->loginAsAdmin();
    webPageRow('Bravo', ['updated_at' => now()->subDays(2)]);
    webPageRow('Alpha', ['updated_at' => now()->subDay()]);
    webPageRow('Charlie');

    expect(pageTitles($this->getJson(route('admin.dam.pages.listing', ['sort' => 'name_asc']))->assertOk()->json()))->toBe(['Alpha', 'Bravo', 'Charlie'])
        ->and(pageTitles($this->getJson(route('admin.dam.pages.listing', ['sort' => 'name_desc']))->assertOk()->json()))->toBe(['Charlie', 'Bravo', 'Alpha'])
        ->and(pageTitles($this->getJson(route('admin.dam.pages.listing', ['sort' => 'oldest']))->assertOk()->json()))->toBe(['Bravo', 'Alpha', 'Charlie']);

    $body = $this->getJson(route('admin.dam.pages.listing', ['sort' => 'bogus']))->assertOk()->json();

    expect(pageTitles($body))->toBe(['Charlie', 'Alpha', 'Bravo'])
        ->and($body['filters']['sort'])->toBe('newest');
});
