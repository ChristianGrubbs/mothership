<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Exceptions\WebCollectionException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\WebCollection;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Stack\StackMembers;
use Mothership\DAM\Services\WebCollectionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/* Web page collections (ADR 0008): service, placement/listing, routes, ACL and capability. */

uses(DatabaseTransactions::class);

function collections(): WebCollectionService
{
    return app(WebCollectionService::class);
}

function collectionPage(string $title, array $directoryIds = [1]): Asset
{
    $asset = Asset::factory()->create(['file_type' => 'web', 'file_name' => strtolower($title).'.html', 'extension' => 'html', 'mime_type' => 'text/html', 'path' => 'assets/Root/'.strtolower($title).'-'.bin2hex(random_bytes(4)).'.html', 'title' => $title]);
    $asset->directories()->sync($directoryIds);

    return $asset;
}

function collectionAdmin(array $directoryIds, array $permissions = ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view']): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    foreach ($directoryIds as $id) {
        DB::table('dam_directory_role')->insert(['directory_id' => $id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $admin = Admin::factory()->create(['role_id' => $role->id]);
    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    return $admin;
}

function placeIn(Asset $page, WebCollection $collection): void
{
    DB::table('dam_web_collection_assets')->insert(['asset_id' => $page->id, 'collection_id' => $collection->id]);
}

// ── Service ────────────────────────────────────────────────────────────────────────────────

it('creates nested collections and rejects invalid names and case-insensitive sibling clashes', function () {
    $root = collections()->create('Projects', null, null);
    $child = collections()->create('Notes', $root->id, null);

    expect($child->depth())->toBe(2)->and($child->pathString())->toBe('Projects / Notes');
    expect(fn () => collections()->create(' projects ', null, null))->toThrow(WebCollectionException::class, 'name-taken');
    expect(fn () => collections()->create('NOTES', $root->id, null))->toThrow(WebCollectionException::class, 'name-taken');
    foreach (['', '   ', str_repeat('a', 121), 'a/b', "a\x07b"] as $bad) {
        expect(fn () => collections()->create($bad, null, null))->toThrow(WebCollectionException::class, 'name-invalid');
    }
});

it('caps depth on create and move and refuses cycles', function () {
    config(['dam.web_pages.max_collection_depth' => 3]);
    $a = collections()->create('a', null, null);
    $b = collections()->create('b', $a->id, null);
    $c = collections()->create('c', $b->id, null);
    expect(fn () => collections()->create('d', $c->id, null))->toThrow(WebCollectionException::class, 'too-deep');

    expect(fn () => collections()->move($a, $a->id))->toThrow(WebCollectionException::class, 'cycle');
    expect(fn () => collections()->move($a, $c->id))->toThrow(WebCollectionException::class, 'cycle');

    $x = collections()->create('x', null, null);
    expect(fn () => collections()->move($a, $x->id))->toThrow(WebCollectionException::class, 'too-deep');
    expect(collections()->move($c, null)->parent_id)->toBeNull();
});

it('delete reparents children and placements, suffixing a clashing child name, and unfiles at the root', function () {
    $a = collections()->create('a', null, null);
    $b = collections()->create('b', $a->id, null);
    $c = collections()->create('c', $b->id, null);
    collections()->create('C', $a->id, null);
    $page = collectionPage('Placed');
    placeIn($page, $b);

    expect(collections()->delete($b))->toBe(['children' => 1, 'pages' => 1])
        ->and($c->fresh()->only('parent_id', 'name'))->toBe(['parent_id' => $a->id, 'name' => 'c (2)'])
        ->and($page->webCollection()->first()->id)->toBe($a->id)
        ->and(WebCollection::find($b->id))->toBeNull();

    collections()->delete($a);
    expect($page->webCollection()->count())->toBe(0)
        ->and($c->fresh()->parent_id)->toBeNull()
        ->and(Asset::find($page->id))->not->toBeNull();
});

it('delete reparents placements the actor cannot see', function () {
    $hidden = Directory::create(['name' => 'Hiddendir', 'parent_id' => 1]);
    collectionAdmin([1]);
    $parent = collections()->create('Parent', null, null);
    $doomed = collections()->create('Doomed', $parent->id, null);
    $page = collectionPage('Invisible', [$hidden->id]);
    placeIn($page, $doomed);

    expect(app(DirectoryPermissionService::class)->canAccessAsset($page->id))->toBeFalse();
    expect(collections()->delete($doomed))->toBe(['children' => 0, 'pages' => 1])
        ->and(DB::table('dam_web_collection_assets')->where('asset_id', $page->id)->value('collection_id'))->toBe($parent->id)
        ->and(Asset::find($page->id))->not->toBeNull();
});

// ── Placement and listing ──────────────────────────────────────────────────────────────────

it('root lists unfiled pages only and a collection lists its own, with child counts', function () {
    $this->loginAsAdmin();
    $projects = collections()->create('Projects', null, null);
    collections()->create('Notes', $projects->id, null);
    $unfiled = collectionPage('Unfiledpage');
    $filed = collectionPage('Filedpage');
    placeIn($filed, $projects);
    $perms = app(DirectoryPermissionService::class);

    $root = collections()->listing(null, [], $perms);
    expect($root['pages']->pluck('id')->all())->toContain($unfiled->id)->not->toContain($filed->id)
        ->and(collect($root['children'])->firstWhere('id', $projects->id))->toBe(['id' => $projects->id, 'name' => 'Projects', 'page_count' => 1, 'total_page_count' => 1, 'children_count' => 1, 'can_modify' => true, 'can_delete' => true])
        ->and($root['search_mode'])->toBeFalse();

    $inside = collections()->listing($projects, [], $perms);
    expect($inside['pages']->pluck('id')->all())->toBe([$filed->id])
        ->and(collect($inside['breadcrumb'])->pluck('id')->all())->toBe([null, $projects->id]);

    $tree = collections()->listing(null, ['tree' => true], $perms);
    expect(collect($tree['children'])->firstWhere('id', $projects->id)['children'][0]['name'])->toBe('Notes');
});

it('parent collections roll up visible pages from the whole subtree while page_count stays direct', function () {
    $hidden = Directory::create(['name' => 'Hiddenroll', 'parent_id' => 1]);
    collectionAdmin([1]);
    $top = collections()->create('Interactive', null, null);
    $mid = collections()->create('Timeline', $top->id, null);
    $leafA = collections()->create('LeafA', $mid->id, null);
    $leafB = collections()->create('LeafB', $mid->id, null);
    foreach ([$leafA, $leafA, $leafB, $leafB] as $i => $leaf) {
        placeIn(collectionPage('Rollpage'.$i), $leaf);
    }
    placeIn(collectionPage('Rollhidden', [$hidden->id]), $leafA);

    $tree = collections()->listing(null, ['tree' => true], app(DirectoryPermissionService::class))['children'];
    $topNode = collect($tree)->firstWhere('id', $top->id);
    $midNode = $topNode['children'][0];

    expect($topNode['page_count'])->toBe(0)->and($topNode['total_page_count'])->toBe(4)
        ->and($midNode['page_count'])->toBe(0)->and($midNode['total_page_count'])->toBe(4)
        ->and(collect($midNode['children'])->pluck('total_page_count')->all())->toBe([2, 2])
        ->and(collect($midNode['children'])->pluck('page_count')->all())->toBe([2, 2]);
});

it('search spans collections and returns collection paths', function () {
    $this->loginAsAdmin();
    $projects = collections()->create('Projects', null, null);
    $notes = collections()->create('Notes', $projects->id, null);
    $elsewhere = collections()->create('Elsewhere', null, null);
    $hit = collectionPage('Alphapage');
    placeIn($hit, $notes);
    $loose = collectionPage('Alphaloose');

    $result = collections()->listing($elsewhere, ['q' => 'Alpha'], app(DirectoryPermissionService::class));
    $byId = $result['pages']->keyBy('id');

    expect($result['search_mode'])->toBeTrue()
        ->and($byId[$hit->id]->collection_path)->toBe('Projects / Notes')
        ->and($byId[$loose->id]->collection_path)->toBeNull();
});

it('listing never shows a page outside the directory grant even when placed in the collection', function () {
    $hidden = Directory::create(['name' => 'Hiddenlist', 'parent_id' => 1]);
    collectionAdmin([1]);
    $bin = collections()->create('Bin', null, null);
    $visible = collectionPage('Seenpage');
    $invisible = collectionPage('Unseenpage', [$hidden->id]);
    placeIn($visible, $bin);
    placeIn($invisible, $bin);
    $perms = app(DirectoryPermissionService::class);

    expect(collections()->listing($bin, [], $perms)['pages']->pluck('id')->all())->toBe([$visible->id])
        ->and(collections()->listing(null, ['q' => 'Unseen'], $perms)['pages']->count())->toBe(0)
        ->and(collect(collections()->listing(null, [], $perms)['children'])->firstWhere('id', $bin->id)['page_count'])->toBe(1);
});

it('placePages writes only web, accessible ids and reports the rest', function () {
    collectionAdmin([1]);
    $web = collectionPage('Okpage');
    $image = Asset::factory()->create(['title' => 'Img']);
    $image->directories()->attach(1);
    $far = collectionPage('Farpage', [Directory::create(['name' => 'Far', 'parent_id' => 1])->id]);
    $bin = collections()->create('Bin', null, null);

    $result = collections()->placePages([$web->id, $image->id, $far->id, 999999], $bin->id, app(DirectoryPermissionService::class));

    expect($result['placed'])->toBe([$web->id])
        ->and(collect($result['rejected'])->pluck('reason', 'id')->all())->toBe([$image->id => 'not-web', $far->id => 'missing', 999999 => 'missing'])
        ->and($web->webCollection()->first()->id)->toBe($bin->id);

    collections()->placePages([$web->id], null, app(DirectoryPermissionService::class));
    expect($web->webCollection()->count())->toBe(0);
});

// ── Routes, ACL and capability ─────────────────────────────────────────────────────────────

it('requires dam.pages.organize for every write route and dam.pages for the listing', function () {
    $bin = collections()->create('Bin', null, null);
    collectionAdmin([1]);

    $this->postJson(route('admin.dam.pages.collections.store'), ['name' => 'X'])->assertForbidden();
    $this->patchJson(route('admin.dam.pages.collections.update', ['id' => $bin->id]), ['name' => 'Y'])->assertForbidden();
    $this->deleteJson(route('admin.dam.pages.collections.destroy', ['id' => $bin->id]))->assertForbidden();
    $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [1], 'collection_id' => null])->assertForbidden();
    $this->getJson(route('admin.dam.pages.listing'))->assertOk();

    collectionAdmin([1], ['dashboard']);
    $this->getJson(route('admin.dam.pages.listing'))->assertForbidden();
});

it('creates, renames, moves and deletes with the documented shapes', function () {
    $this->loginAsAdmin();
    $a = $this->postJson(route('admin.dam.pages.collections.store'), ['name' => 'Projects'])->assertCreated()->json('collection');
    $b = $this->postJson(route('admin.dam.pages.collections.store'), ['name' => 'Notes', 'parent_id' => $a['id']])->assertCreated()->json('collection');
    expect($b)->toMatchArray(['name' => 'Notes', 'parent_id' => $a['id'], 'path' => 'Projects / Notes']);

    $this->postJson(route('admin.dam.pages.collections.store'), ['name' => 'notes', 'parent_id' => $a['id']])->assertStatus(422)
        ->assertJsonPath('errors.name.0', trans('dam::app.admin.dam.web-pages.collections.name-taken'));
    $this->patchJson(route('admin.dam.pages.collections.update', ['id' => $a['id']]), ['parent_id' => $b['id']])->assertStatus(422)
        ->assertJsonPath('errors.parent_id.0', trans('dam::app.admin.dam.web-pages.collections.cycle'));
    $this->patchJson(route('admin.dam.pages.collections.update', ['id' => $b['id']]), ['name' => 'Drafts', 'parent_id' => null])->assertOk()
        ->assertJsonPath('collection.path', 'Drafts');
    $this->patchJson(route('admin.dam.pages.collections.update', ['id' => $b['id']]), ['parent_id' => $a['id']])->assertOk();

    $this->deleteJson(route('admin.dam.pages.collections.destroy', ['id' => $a['id']]))->assertOk()->assertExactJson(['children' => 1, 'pages' => 0]);
    $this->deleteJson(route('admin.dam.pages.collections.destroy', ['id' => $a['id']]))->assertNotFound();
});

it('move reports every rejected id with a reason', function () {
    collectionAdmin([1], ['dashboard', 'dam', 'dam.pages', 'dam.pages.organize', 'dam.asset', 'dam.asset.view']);
    $web = collectionPage('Movable');
    $image = Asset::factory()->create(['title' => 'Notapage']);
    $image->directories()->attach(1);
    $far = collectionPage('Faraway', [Directory::create(['name' => 'Faraway', 'parent_id' => 1])->id]);
    $bin = collections()->create('Bin', null, null);

    $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$web->id, $image->id, $far->id, 999999], 'collection_id' => $bin->id])->assertOk()
        ->assertExactJson(['placed' => [$web->id], 'rejected' => [
            ['id' => $image->id, 'reason' => 'not-web'],
            ['id' => $far->id, 'reason' => 'missing'],
            ['id' => 999999, 'reason' => 'missing'],
        ]]);
    $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$far->id], 'collection_id' => $bin->id])->assertForbidden()
        ->assertJsonPath('rejected.0.reason', 'missing');
    $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$web->id], 'collection_id' => 999999])->assertStatus(422)
        ->assertJsonPath('errors.collection_id.0', trans('dam::app.admin.dam.web-pages.collections.missing-parent'));
});

it('listing returns breadcrumb, children with counts and pages; an unknown collection renders root with a notice', function () {
    $this->loginAsAdmin();
    $projects = collections()->create('Projects', null, null);
    $page = collectionPage('Listedpage');
    placeIn($page, $projects);

    $this->getJson(route('admin.dam.pages.listing', ['collection' => $projects->id]))->assertOk()
        ->assertJsonPath('breadcrumb.1', ['id' => $projects->id, 'name' => 'Projects'])
        ->assertJsonPath('pages.data.0.id', $page->id)
        ->assertJsonPath('pages.total', 1)
        ->assertJsonPath('can.organize', true);
    $this->getJson(route('admin.dam.pages.listing'))->assertOk()
        ->assertJsonPath('children.0', ['id' => $projects->id, 'name' => 'Projects', 'page_count' => 1, 'total_page_count' => 1, 'children_count' => 0, 'can_modify' => true, 'can_delete' => true]);

    $this->get(route('admin.dam.pages.index', ['collection' => $projects->id]))->assertOk()->assertSee('Listedpage');
    $this->get(route('admin.dam.pages.index', ['collection' => 999999]))->assertOk()
        ->assertSee(trans('dam::app.admin.dam.web-pages.browser.missing-collection'))->assertDontSee('Listedpage');
});

it('rename+move validates the name against the new parent, not the old one', function () {
    $this->loginAsAdmin();
    collections()->create('Notes', null, null);
    $target = collections()->create('Target', null, null);
    $moving = collections()->create('Moving', null, null);

    $this->patchJson(route('admin.dam.pages.collections.update', ['id' => $moving->id]), ['name' => 'Notes', 'parent_id' => $target->id])->assertOk()
        ->assertJsonPath('collection.path', 'Target / Notes');
});

it('collection writes log their collection fields', function () {
    config(['dam.library_log_enabled' => true]);
    $log = storage_path('logs/web-pages.jsonl');
    $before = is_file($log) ? filesize($log) : 0;

    $c = collections()->create('Logged', null, null);

    $lines = array_filter(explode(PHP_EOL, substr((string) file_get_contents($log), $before)));
    expect(json_decode(end($lines), true)['ctx']['collection_id'] ?? null)->toBe($c->id);
});

it('non-web upload is filed in the Library only', function () {
    $this->loginAsAdmin();
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);
    Storage::disk($disk)->makeDirectory('assets/Uploads');
    $directory = Directory::factory()->create(['name' => 'Uploads', 'parent_id' => null]);
    $collection = collections()->create('Notes', null, null);

    $file = UploadedFile::fake()->image('screenshot-'.uniqid().'.png', 40, 40)->size(5);
    $upload = $this->postJson(route('admin.dam.assets.upload'), ['files' => [$file], 'directory_id' => $directory->id])
        ->assertStatus(201)->json();
    $uploadedId = $upload['files'][0]['id'];

    $result = $this->postJson(route('admin.dam.pages.move'), ['asset_ids' => [$uploadedId], 'collection_id' => $collection->id])
        ->assertStatus(403)->json();

    expect($result['rejected'])->toBe([['id' => $uploadedId, 'reason' => 'not-web']])
        ->and($result['placed'])->toBe([]);
    $this->assertDatabaseHas($this->getFullTableName(Asset::class), ['id' => $uploadedId, 'file_type' => 'image']);
    $this->assertDatabaseMissing('dam_web_collection_assets', ['asset_id' => $uploadedId]);
});

it('gives a page link token only to the link creator, the page owner and a full admin', function () {
    $page = collectionPage('Shared page');
    $creator = collectionAdmin([1]);
    $viewer = collectionAdmin([1]);
    $page->forceFill(['created_by' => $creator->id])->save();
    $share = Share::factory()->forAsset($page->id)->create(['created_by' => $creator->id]);

    $read = fn (Admin $actor) => collect($this->actingAs($actor, 'admin')->getJson(route('admin.dam.pages.listing'))->assertOk()->json('pages.data'))->firstWhere('id', $page->id);

    expect($read($creator)['active_share_token'])->toBe($share->urlToken());

    $other = $read($viewer);
    expect($other['active_share_token'])->toBeNull()->and($other['has_active_share'])->toBeTrue();

    $this->loginAsAdmin();
    expect($read(Admin::factory()->create())['active_share_token'])->toBe($share->urlToken());
});

it('gives a custom role that reaches every folder no page link token: it is no full admin', function () {
    // Codex re-review of dafd1a9b: under grants, all_directories makes bypass() true but not fullAdmin().
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    $page = collectionPage('Shared page');
    $creator = collectionAdmin([1]);
    $page->forceFill(['created_by' => $creator->id])->save();
    $share = Share::factory()->forAsset($page->id)->create(['created_by' => $creator->id]);

    $allFolders = collectionAdmin([]);
    DB::table('dam_role_settings')->insert(['role_id' => $allFolders->role_id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($allFolders, 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->bypass())->toBeTrue()
        ->and(app(DirectoryPermissionService::class)->fullAdmin())->toBeFalse();

    $listing = $this->getJson(route('admin.dam.pages.listing'))->assertOk();
    $row = collect($listing->json('pages.data'))->firstWhere('id', $page->id);
    expect($row)->not->toBeNull()
        ->and($row['active_share_token'])->toBeNull()
        ->and($row['has_active_share'])->toBeTrue()
        ->and($listing->getContent())->not->toContain($share->urlToken());
});

it('caps a collection name at the one limit the dialog also enforces', function () {
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.pages.collections.store'), ['name' => str_repeat('a', WebCollectionService::NAME_MAX + 1)])->assertUnprocessable();
    $this->postJson(route('admin.dam.pages.collections.store'), ['name' => str_repeat('a', WebCollectionService::NAME_MAX)])->assertCreated();
});

// ── Share pages in folder (snapshot of the DIRECT pages; ordinary per-page links) ─────────────────────────

function folderShareAdmin(array $directoryIds = [1]): Admin
{
    return collectionAdmin($directoryIds, ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view', 'dam.asset.share']);
}

/** Production's own-items shape (OwnItemsOnlyTest): a custom role with the share key whose dam_role_settings row says delete_own_only. */
function ownItemsShareAdmin(): Admin
{
    $admin = folderShareAdmin();
    DB::table('dam_role_settings')->insert([
        'role_id' => $admin->role_id, 'delete_own_only' => true, 'home_widget_ids' => json_encode(['recent']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $admin;
}

function folderCandidates(object $test, WebCollection $folder): TestResponse
{
    return $test->getJson(route('admin.dam.shared-links.web_pages_candidates', ['id' => $folder->id]));
}

/** The ordinary share POST a confirmed folder share sends for one listed candidate. */
function folderShareBody(array $candidate, WebCollection|int $folder, array $extra = []): array
{
    return array_replace([
        'share_type'    => 'asset', 'target_id' => $candidate['id'], 'web_collection_id' => $folder instanceof WebCollection ? $folder->id : $folder,
        'page_revision' => $candidate['revision'], 'public_link_confirmed' => true, 'expiry_days' => 30,
    ], $extra);
}

function folderShareCandidate(object $test, WebCollection $folder, Asset $page): array
{
    return collect(folderCandidates($test, $folder)->assertOk()->json('pages'))->firstWhere('id', $page->id);
}

it('lists only the direct, visible web pages of a folder for sharing, and writes nothing', function () {
    $hiddenDirectory = Directory::create(['name' => 'Hiddenshare', 'parent_id' => 1]);
    folderShareAdmin();
    $folder = collections()->create('Campaign', null, null);
    $sub = collections()->create('Nested', $folder->id, null);
    [$alpha, $bravo, $deep, $hidden, $loose] = [collectionPage('Alpha'), collectionPage('Bravo'), collectionPage('Deepnested'), collectionPage('Secretpage', [$hiddenDirectory->id]), collectionPage('Loosepage')];
    $image = Asset::factory()->create(['title' => 'Pictureinfolder']);
    $image->directories()->attach(1);
    foreach ([$bravo, $alpha, $hidden, $image] as $page) {
        placeIn($page, $folder);
    }
    placeIn($deep, $sub);
    $before = [DB::table('dam_web_collection_assets')->count(), WebCollection::count(), Share::count(), $alpha->fresh()->updated_at->getTimestamp()];

    $response = folderCandidates($this, $folder)->assertOk()->assertHeader('Cache-Control');

    expect(collect($response->json('pages'))->pluck('id')->all())->toBe([$alpha->id, $bravo->id])
        ->and($response->json())->toMatchArray(['success' => true, 'collection' => $folder->id, 'total' => 2, 'omitted' => 0])
        ->and($response->json('pages.0'))->toMatchArray(['display_name' => 'Alpha', 'reuse' => null])
        ->and($response->getContent())->not->toContain('Secretpage')->not->toContain('Deepnested')->not->toContain('Pictureinfolder')->not->toContain('Loosepage')
        ->and([DB::table('dam_web_collection_assets')->count(), WebCollection::count(), Share::count(), $alpha->fresh()->updated_at->getTimestamp()])->toBe($before);

    // The sub-folder is its own folder: its direct page is listed there, and the parent's pages are not.
    expect(collect(folderCandidates($this, $sub)->assertOk()->json('pages'))->pluck('id')->all())->toBe([$deep->id]);

    // An empty folder is an empty, honest answer; an unknown folder id answers like a hidden one.
    $empty = collections()->create('Empty', null, null);
    expect(folderCandidates($this, $empty)->assertOk()->json())->toMatchArray(['total' => 0, 'omitted' => 0, 'pages' => []]);
    $this->getJson(route('admin.dam.shared-links.web_pages_candidates', ['id' => 999999]))->assertNotFound();
});

it('names the pages a cap leaves out instead of calling the batch the whole folder', function () {
    folderShareAdmin();
    config(['dam.web_pages.share_candidate_cap' => 2]);
    $folder = collections()->create('Big', null, null);
    foreach (['One', 'Two', 'Three'] as $title) {
        placeIn(collectionPage($title), $folder);
    }

    $response = folderCandidates($this, $folder)->assertOk();

    expect($response->json('pages'))->toHaveCount(2)
        ->and($response->json())->toMatchArray(['cap' => 2, 'total' => 3, 'omitted' => 1]);
});

it('needs the page-link permission for folder shares, as creating any link does', function () {
    $folder = collections()->create('Gated', null, null);
    $page = collectionPage('Gatedpage');
    placeIn($page, $folder);
    $candidate = null;

    folderShareAdmin();
    $candidate = folderShareCandidate($this, $folder, $page);
    $shares = Share::count();

    collectionAdmin([1]);
    folderCandidates($this, $folder)->assertForbidden();
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidate, $folder))->assertForbidden();
    expect(Share::count())->toBe($shares);
});

it('answers a hidden page like a missing one when a folder share reaches creation', function () {
    $hiddenDirectory = Directory::create(['name' => 'Latehidden', 'parent_id' => 1]);
    $folder = collections()->create('Late', null, null);
    $page = collectionPage('Latepage');
    placeIn($page, $folder);

    folderShareAdmin();
    $candidate = folderShareCandidate($this, $folder, $page);
    $page->directories()->sync([$hiddenDirectory->id]);
    app(DirectoryPermissionService::class)->flush();
    $shares = Share::count();

    $hidden = $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidate, $folder))->assertNotFound();
    $missing = $this->postJson(route('admin.dam.shared-links.store'), folderShareBody(['id' => 999999, 'revision' => $candidate['revision']], $folder))->assertNotFound();

    expect($hidden->json())->toBe($missing->json())->and(Share::count())->toBe($shares);
    expect(collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->pluck('id')->all())->toBe([]);
});

it('creates ordinary 30-day links for the confirmed pages, writes no group, placement or page change, and reuses a usable link', function () {
    $admin = folderShareAdmin();
    $folder = collections()->create('Launch', null, null);
    [$a, $b] = [collectionPage('Launcha'), collectionPage('Launchb')];
    placeIn($a, $folder);
    placeIn($b, $folder);
    Share::query()->delete();
    $pageState = fn () => [DB::table('dam_web_collection_assets')->orderBy('asset_id')->get()->all(), WebCollection::count(), Asset::whereIn('id', [$a->id, $b->id])->orderBy('id')->get(['id', 'updated_at', 'meta_data', 'title'])->toArray()];
    $before = $pageState();

    $created = collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->map(fn (array $candidate) => $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidate, $folder))->assertOk());

    expect($created->map->json('reused')->all())->toBe([false, false])
        ->and(Share::count())->toBe(2)
        ->and(Share::query()->pluck('share_type')->unique()->all())->toBe([Share::TYPE_ASSET])
        ->and(Share::query()->pluck('created_by')->unique()->all())->toBe([$admin->id])
        ->and($pageState())->toEqual($before);
    foreach (Share::all() as $share) {
        expect($share->expires_at->between(now()->addDays(29), now()->addDays(31)))->toBeTrue()
            ->and($share->revoked_at)->toBeNull()->and($share->hasPassword())->toBeFalse();
    }

    // A second run keeps the links it can use: nothing new is made, and it says so before and after.
    $candidates = folderCandidates($this, $folder)->assertOk()->json('pages');
    expect(collect($candidates)->pluck('reuse.has_password')->all())->toBe([false, false])
        ->and(collect($candidates)->pluck('reuse.allow_download')->all())->toBe([true, true])
        ->and(json_encode($candidates))->not->toContain(Share::first()->urlToken());
    $again = $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[0], $folder))->assertOk();
    expect($again->json('reused'))->toBeTrue()->and($again->json('share.token'))->toBe(Share::where('target_id', $candidates[0]['id'])->first()->urlToken())
        ->and(Share::count())->toBe(2);
});

it('makes a new link where the existing one is revoked, expired or not one this viewer may use', function () {
    $folder = collections()->create('Reuse', null, null);
    [$revoked, $expired, $foreign, $mine] = [collectionPage('Revokedone'), collectionPage('Expiredone'), collectionPage('Foreignone'), collectionPage('Mineone')];
    $other = collectionAdmin([1]);
    foreach ([$revoked, $expired, $foreign, $mine] as $page) {
        placeIn($page, $folder);
        $page->forceFill(['created_by' => $other->id])->save();
    }
    $viewer = folderShareAdmin();
    Share::factory()->forAsset($revoked->id)->create(['created_by' => $viewer->id, 'revoked_at' => now()->subMinute()]);
    Share::factory()->forAsset($expired->id)->create(['created_by' => $viewer->id, 'expires_at' => now()->subMinute()]);
    Share::factory()->forAsset($foreign->id)->create(['created_by' => $other->id]);
    Share::factory()->forAsset($mine->id)->create(['created_by' => $viewer->id, 'expires_at' => null]);

    $candidates = collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->keyBy('id');

    expect($candidates[$revoked->id]['reuse'])->toBeNull()->and($candidates[$expired->id]['reuse'])->toBeNull()->and($candidates[$foreign->id]['reuse'])->toBeNull()
        ->and($candidates[$mine->id]['reuse'])->toMatchArray(['expires_at' => null, 'has_password' => false]);

    foreach ([$revoked, $expired, $foreign] as $page) {
        $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$page->id], $folder))->assertOk()->assertJsonPath('reused', false);
    }
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$mine->id], $folder))->assertOk()->assertJsonPath('reused', true);
    expect(Share::count())->toBe(7);
});

it('refuses a page that moved, was revised or never belonged to the folder, and creates nothing', function () {
    Storage::fake(Directory::getAssetDisk());
    folderShareAdmin();
    $folder = collections()->create('Frozen', null, null);
    $sub = collections()->create('Frozensub', $folder->id, null);
    $elsewhere = collections()->create('Elsewhere', null, null);
    [$moved, $deeper, $unfiled, $revised, $stable] = array_map(fn (string $title) => putAssetContent(collectionPage($title), 'first '.$title), ['Movedout', 'Intosub', 'Unfiledout', 'Revisedpage', 'Stablepage']);
    foreach ([$moved, $deeper, $unfiled, $revised, $stable] as $page) {
        placeIn($page, $folder);
    }
    $candidates = collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->keyBy('id');
    expect($candidates)->toHaveCount(5);

    collections()->placePages([$moved->id], $elsewhere->id, app(DirectoryPermissionService::class));
    collections()->placePages([$deeper->id], $sub->id, app(DirectoryPermissionService::class));
    collections()->placePages([$unfiled->id], null, app(DirectoryPermissionService::class));
    putAssetContent($revised, 'second Revisedpage');
    $shares = Share::count();

    foreach ([$moved, $deeper, $unfiled, $revised] as $page) {
        $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$page->id], $folder))
            ->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('reason', 'page-changed')
            ->assertJsonPath('message', trans('dam::app.admin.dam.web-pages.browser.share-folder-changed'));
    }
    expect(Share::count())->toBe($shares);

    // A folder id that is really something else never matches: it is only ever read as a folder, against a placement.
    // First an id no folder has, then a real folder that happens to carry the page's own number.
    $ghost = max(WebCollection::max('id'), Asset::max('id'), Directory::max('id')) + 1;
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$stable->id], $ghost))->assertStatus(409);
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$stable->id], $elsewhere))->assertStatus(409);
    WebCollection::unguarded(fn () => WebCollection::create(['id' => $stable->id, 'name' => 'Same number as the page']));
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$stable->id], $stable->id))->assertStatus(409);
    expect(Share::count())->toBe($shares);

    // The page that did not change is still shared, from the same frozen list.
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$stable->id], $folder))->assertOk()->assertJsonPath('share.target_id', $stable->id);
    expect(Share::count())->toBe($shares + 1);
});

it('binds a folder share to the actual current stack files, not a timestamp', function () {
    Storage::fake(Directory::getAssetDisk());
    folderShareAdmin();
    $folder = collections()->create('Fingerprint', null, null);
    $page = putAssetContent(collectionPage('Fingerprinted'), 'before');
    placeIn($page, $folder);
    $candidate = folderShareCandidate($this, $folder, $page);

    // Same updated_at second, different current bytes: a replaced file must not slip through as the listed page.
    $stamp = $page->fresh()->updated_at;
    putAssetContent($page->fresh(), 'after');
    Asset::whereKey($page->id)->update(['updated_at' => $stamp]);
    $shares = Share::count();

    expect(folderShareCandidate($this, $folder, $page)['revision'])->not->toBe($candidate['revision']);
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidate, $folder))->assertStatus(409);
    expect(Share::count())->toBe($shares);
});

it('keeps going through a batch where one page is refused, and says exactly which', function () {
    folderShareAdmin();
    $hiddenDirectory = Directory::create(['name' => 'Midbatch', 'parent_id' => 1]);
    $folder = collections()->create('Batch', null, null);
    $elsewhere = collections()->create('Batchaway', null, null);
    [$ok1, $gone, $hidden, $ok2] = [collectionPage('Batcha'), collectionPage('Batchb'), collectionPage('Batchc'), collectionPage('Batchd')];
    foreach ([$ok1, $gone, $hidden, $ok2] as $page) {
        placeIn($page, $folder);
    }
    $candidates = collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->keyBy('id');
    collections()->placePages([$gone->id], $elsewhere->id, app(DirectoryPermissionService::class));
    $hidden->directories()->sync([$hiddenDirectory->id]);
    app(DirectoryPermissionService::class)->flush();
    Share::query()->delete();

    $statuses = collect([$ok1, $gone, $hidden, $ok2])->map(fn (Asset $page) => $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$page->id], $folder))->getStatusCode())->all();

    expect($statuses)->toBe([200, 409, 404, 200])
        ->and(Share::query()->pluck('target_id')->sort()->values()->all())->toBe(collect([$ok1->id, $ok2->id])->sort()->values()->all());
});

it('accepts the folder context only as a pair on a plain 30-day asset link', function () {
    folderShareAdmin();
    $folder = collections()->create('Narrow', null, null);
    $page = collectionPage('Narrowpage');
    placeIn($page, $folder);
    $candidate = folderShareCandidate($this, $folder, $page);
    $shares = Share::count();
    $post = fn (array $body) => $this->postJson(route('admin.dam.shared-links.store'), $body);

    foreach ([['slug' => 'custom-link'], ['password' => 'hunter22'], ['no_expiry' => true], ['expires_at' => now()->addDays(3)->toIso8601String()], ['name' => 'Named'], ['expiry_days' => 7], ['allow_download' => false], ['allow_download' => true], ['requires_creator_access' => true], ['requires_creator_access' => false]] as $extra) {
        $post(folderShareBody($candidate, $folder, $extra))->assertUnprocessable();
    }
    $post(collect(folderShareBody($candidate, $folder))->except('page_revision')->all())->assertUnprocessable();
    $post(collect(folderShareBody($candidate, $folder))->except('web_collection_id')->all() + ['page_revision' => $candidate['revision']])->assertUnprocessable();
    $post(folderShareBody($candidate, $folder, ['page_revision' => 'not-a-checksum']))->assertUnprocessable();
    $post(folderShareBody($candidate, $folder, ['share_type' => 'directory', 'target_id' => 1]))->assertUnprocessable();
    expect(Share::count())->toBe($shares);

    // Without the context nothing about the ordinary link changed: it still takes a custom expiry.
    $post(['share_type' => 'asset', 'target_id' => $page->id, 'expiry_days' => 7, 'public_link_confirmed' => true])->assertOk()->assertJsonMissingPath('reused');
    $post(['share_type' => 'asset', 'target_id' => $page->id, 'allow_download' => false, 'requires_creator_access' => true, 'public_link_confirmed' => true])->assertOk()
        ->assertJsonPath('share.allow_download', false)->assertJsonPath('share.requires_creator_access', true);
});

it('logs folder shares by id and outcome only', function () {
    config(['dam.library_log_enabled' => true]);
    folderShareAdmin();
    $folder = collections()->create('Logged share', null, null);
    $page = collectionPage('Secretly named page');
    placeIn($page, $folder);
    $log = storage_path('logs/web-pages.jsonl');
    $before = is_file($log) ? filesize($log) : 0;

    $candidate = folderShareCandidate($this, $folder, $page);
    $created = $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidate, $folder))->assertOk();
    collections()->placePages([$page->id], null, app(DirectoryPermissionService::class));
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidate, $folder))->assertStatus(409);

    $written = substr((string) file_get_contents($log), $before);
    $events = collect(array_filter(explode(PHP_EOL, $written)))->map(fn (string $line) => json_decode($line, true))->keyBy('event');
    expect($events->get('web_pages.folder_share_candidates')['ctx'])->toMatchArray(['collection_id' => $folder->id, 'pages' => 1, 'state' => 'complete'])
        ->and($events->get('web_pages.folder_share_created')['ctx'])->toMatchArray(['asset_id' => $page->id, 'state' => 'created'])
        ->and($events->get('web_pages.folder_share_rejected')['ctx'])->toMatchArray(['asset_id' => $page->id, 'reason' => 'page-changed'])
        ->and($events->get('web_pages.folder_share_rejected')['level'])->toBe('warning')
        ->and($written)->not->toContain('Secretly named page')->not->toContain($created->json('share.token'));
});

it('keeps an existing link exactly as it is, password, preview-only and creator-access included, and says so', function () {
    $viewer = folderShareAdmin();
    $folder = collections()->create('Options', null, null);
    [$locked, $preview, $creator, $plain] = [collectionPage('Lockedone'), collectionPage('Previewone'), collectionPage('Creatorone'), collectionPage('Plainone')];
    foreach ([$locked, $preview, $creator, $plain] as $page) {
        placeIn($page, $folder);
    }
    Share::query()->delete();
    $links = [
        $locked->id  => Share::factory()->forAsset($locked->id)->create(['created_by' => $viewer->id, 'password_hash' => Hash::make('hunter22'), 'expires_at' => now()->addDays(3)]),
        $preview->id => Share::factory()->forAsset($preview->id)->create(['created_by' => $viewer->id, 'allow_download' => false, 'expires_at' => null]),
        $creator->id => Share::factory()->forAsset($creator->id)->create(['created_by' => $viewer->id, 'requires_creator_access' => true, 'expires_at' => now()->addDays(9)]),
        $plain->id   => Share::factory()->forAsset($plain->id)->create(['created_by' => $viewer->id, 'expires_at' => now()->addDays(5)]),
    ];
    $stored = fn () => Share::query()->orderBy('id')->get()->map->getAttributes()->all();
    $before = $stored();

    $candidates = collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->keyBy('id');

    expect($candidates[$locked->id]['reuse'])->toMatchArray(['has_password' => true, 'allow_download' => true, 'requires_creator_access' => false])
        ->and($candidates[$preview->id]['reuse'])->toMatchArray(['expires_at' => null, 'has_password' => false, 'allow_download' => false, 'requires_creator_access' => false])
        ->and($candidates[$creator->id]['reuse'])->toMatchArray(['has_password' => false, 'allow_download' => true, 'requires_creator_access' => true])
        ->and($candidates[$plain->id]['reuse'])->toMatchArray(['has_password' => false, 'allow_download' => true, 'requires_creator_access' => false]);
    // The listing shows options, never a secret: no token, hash or URL.
    foreach ($links as $link) {
        expect(json_encode($candidates->all()))->not->toContain($link->urlToken());
    }
    expect(json_encode($candidates->all()))->not->toContain((string) $links[$locked->id]->password_hash);

    foreach ($links as $pageId => $created) {
        $link = $created->fresh();
        $answer = $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$pageId], $folder))->assertOk();
        expect($answer->json('reused'))->toBeTrue()->and($answer->json('share.id'))->toBe($link->id)
            ->and($answer->json('share.has_password'))->toBe($link->hasPassword())
            ->and($answer->json('share.allow_download'))->toBe((bool) $link->allow_download)
            ->and($answer->json('share.requires_creator_access'))->toBe((bool) $link->requires_creator_access);
    }

    expect($stored())->toEqual($before);
});

it('makes a new link where a creator-access link would no longer open, and keeps one that still does', function () {
    $viewer = folderShareAdmin();
    $gone = Admin::factory()->create(['status' => 0]);
    $folder = collections()->create('Creatorgate', null, null);
    [$dead, $alive] = [collectionPage('Deadone'), collectionPage('Aliveone')];
    foreach ([$dead, $alive] as $page) {
        placeIn($page, $folder);
        // The viewer owns both pages, so ownership alone would let them see the old links: only the gate separates them.
        $page->forceFill(['created_by' => $viewer->id])->save();
    }
    Share::query()->delete();
    Share::factory()->forAsset($dead->id)->create(['created_by' => $gone->id, 'requires_creator_access' => true, 'expires_at' => null]);
    Share::factory()->forAsset($alive->id)->create(['created_by' => $viewer->id, 'requires_creator_access' => true, 'expires_at' => null]);
    // Same creator-less link without the option still opens, so it is kept: the option, not the creator, is what closes a link.
    $open = collectionPage('Openone');
    placeIn($open, $folder);
    $open->forceFill(['created_by' => $viewer->id])->save();
    Share::factory()->forAsset($open->id)->create(['created_by' => $gone->id, 'requires_creator_access' => false, 'expires_at' => null]);

    $candidates = collect(folderCandidates($this, $folder)->assertOk()->json('pages'))->keyBy('id');

    expect($candidates[$dead->id]['reuse'])->toBeNull()
        ->and($candidates[$alive->id]['reuse'])->toMatchArray(['requires_creator_access' => true])
        ->and($candidates[$open->id]['reuse'])->toMatchArray(['requires_creator_access' => false]);
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$dead->id], $folder))->assertOk()->assertJsonPath('reused', false);
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody($candidates[$alive->id], $folder))->assertOk()->assertJsonPath('reused', true);
    expect(Share::count())->toBe(4);
});

it('gives each actor exactly what the ordinary share link would, under lists and under grants', function () {
    $folder = collections()->create('Parity', null, null);
    $colleague = Admin::factory()->create(['status' => 1]);
    [$own, $listed, $hidden] = [collectionPage('Ownone'), collectionPage('Listedone'), collectionPage('Hiddenone')];
    foreach ([$own, $listed, $hidden] as $page) {
        placeIn($page, $folder);
    }

    $compare = function (Admin $actor, bool $lists) use ($folder, $own, $listed, $hidden, $colleague) {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $lists ? AccessModel::LISTS : AccessModel::GRANTS]);
        app(AccessModel::class)->flush();
        $own->forceFill(['created_by' => $actor->id])->save();
        $listed->forceFill(['created_by' => $colleague->id])->save();
        $hidden->forceFill(['created_by' => $colleague->id])->save();
        DB::table('dam_asset_user_access')->where('admin_id', $actor->id)->delete();
        DB::table('dam_asset_user_access')->insert(['asset_id' => $listed->id, 'admin_id' => $actor->id]);
        $this->actingAs($actor, 'admin');
        Share::query()->delete();

        $ordinary = collect([$own, $listed, $hidden])->mapWithKeys(fn (Asset $page) => [$page->id => $this->postJson(route('admin.dam.shared-links.store'), ['share_type' => 'asset', 'target_id' => $page->id, 'public_link_confirmed' => true])->status()]);
        Share::query()->delete();
        $listing = folderCandidates($this, $folder);
        $revisions = collect([$own, $listed, $hidden])->mapWithKeys(fn (Asset $page) => [$page->id => collections()->pageFingerprint($page)]);
        $context = $revisions->map(fn (string $revision, int $pageId) => $this->postJson(route('admin.dam.shared-links.store'), folderShareBody(['id' => $pageId, 'revision' => $revision], $folder))->status());

        return [$ordinary, $listing, $context];
    };

    // Lists (the flip grants every custom role the share keys): sight of the page decides, and the page that is neither theirs nor listed to them is a 404 on every path.
    [$ordinary, $listing, $context] = $compare(folderShareAdmin(), true);
    expect($ordinary->all())->toBe([$own->id => 200, $listed->id => 200, $hidden->id => 404])
        ->and($context->all())->toBe($ordinary->all())
        ->and($listing->status())->toBe(200)
        ->and(collect($listing->json('pages'))->pluck('id')->sort()->values()->all())->toBe(collect([$own->id, $listed->id])->sort()->values()->all());

    // Without the share key the route ACL refuses all three alike, in either model, exactly as it refuses an ordinary link.
    foreach ([true, false] as $lists) {
        [$ordinary, $listing, $context] = $compare(collectionAdmin([1], ['dashboard', 'dam', 'dam.pages', 'dam.asset', 'dam.asset.view']), $lists);
        expect($ordinary->unique()->values()->all())->toBe([403])->and($context->unique()->values()->all())->toBe([403])->and($listing->status())->toBe(403);
    }

    // Grants with the share key and no ownership of the colleague's pages: there is no own-items rule on sharing, on any path.
    [$ordinary, $listing, $context] = $compare(folderShareAdmin(), false);
    expect($context->all())->toBe($ordinary->all())->and($listing->status())->toBe(200);
    expect(collect($listing->json('pages'))->pluck('id')->sort()->values()->all())->toBe(
        $ordinary->filter(fn (int $status) => $status === 200)->keys()->sort()->values()->all(),
    );
    // Under grants the actor's directory decides, not who made the page or whom it is listed to: the ordinary link answers 200 for all three.
    expect($ordinary->all())->toBe([$own->id => 200, $listed->id => 200, $hidden->id => 200]);
    $grantsStatuses = $ordinary->all();
    // The contrast the own-items actor below is measured against: this signed-in actor is not restricted to own items.
    expect(app(RoleBuilder::class)->restrictedToOwn())->toBeFalse();

    // The same under the production own-items setting (delete_own_only) plus the share key: that setting restricts deleting, renaming
    // and the like, never sharing, so the candidate list, the folder-context POST and the ordinary POST all still agree, in both models.
    $before = collect([$own, $listed, $hidden])->mapWithKeys(fn (Asset $page) => [$page->id => collections()->pageFingerprint($page)])->all();
    $ownItems = ownItemsShareAdmin();
    [$ordinary, $listing, $context] = $compare($ownItems, true);
    // Bound to the real state, not to the presence of a settings row: the signed-in actor IS this admin and RoleBuilder reads them as restricted to own items.
    expect(auth('admin')->id())->toBe($ownItems->id)->and(app(RoleBuilder::class)->restrictedToOwn())->toBeTrue();
    expect($ordinary->all())->toBe([$own->id => 200, $listed->id => 200, $hidden->id => 404])
        ->and($context->all())->toBe($ordinary->all())
        ->and($listing->status())->toBe(200)
        ->and(collect($listing->json('pages'))->pluck('id')->sort()->values()->all())->toBe(collect([$own->id, $listed->id])->sort()->values()->all());
    [$ordinary, $listing, $context] = $compare($ownItems, false);
    expect(auth('admin')->id())->toBe($ownItems->id)->and(app(RoleBuilder::class)->restrictedToOwn())->toBeTrue();
    expect($ordinary->all())->toBe($grantsStatuses, 'the own-items setting changes nothing about a link under grants')
        ->and($context->all())->toBe($ordinary->all())
        ->and($listing->status())->toBe(200)
        ->and(collect($listing->json('pages'))->pluck('id')->sort()->values()->all())->toBe($ordinary->filter(fn (int $status) => $status === 200)->keys()->sort()->values()->all());

    // Nothing the probes did changed a page's members or where it sits: the organizer's membership state is exactly as it was.
    expect(collect([$own, $listed, $hidden])->mapWithKeys(fn (Asset $page) => [$page->id => collections()->pageFingerprint($page)])->all())->toBe($before);
    expect(DB::table('dam_web_collection_assets')->where('collection_id', $folder->id)->pluck('asset_id')->sort()->values()->all())->toBe(collect([$own->id, $listed->id, $hidden->id])->sort()->values()->all());
});

it('refuses a stale folder context when a stack member is added, removed or swapped, each on its own', function () {
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    folderShareAdmin();
    $folder = collections()->create('Members', null, null);
    $page = putAssetContent(collectionPage('Memberspage'), 'main bytes');
    placeIn($page, $folder);
    $ledger = app(AttachmentLedger::class);
    $revision = fn () => folderShareCandidate($this, $folder, $page)['revision'];
    $refused = function (string $frozen) use ($folder, $page) {
        $shares = Share::count();
        $this->postJson(route('admin.dam.shared-links.store'), folderShareBody(['id' => $page->id, 'revision' => $frozen], $folder))->assertStatus(409)->assertJsonPath('reason', 'page-changed');
        expect(Share::count())->toBe($shares);
    };

    $base = $revision();
    expect($revision())->toBe($base, 'an unchanged stack keeps its revision');

    // Added.
    $first = $ledger->attach($page->fresh(), UploadedFile::fake()->createWithContent('first.txt', 'first extra'), 'alternate', null);
    $withFirst = $revision();
    expect($withFirst)->not->toBe($base);
    $refused($base);

    // Swapped: one removed and one added, so the member count is the same but the stack is not.
    $ledger->remove($page->fresh(), (int) $first->id);
    $ledger->attach($page->fresh(), UploadedFile::fake()->createWithContent('second.txt', 'second extra'), 'alternate', null);
    $swapped = $revision();
    expect($swapped)->not->toBe($withFirst)->and($swapped)->not->toBe($base);
    $refused($withFirst);

    // Removed.
    $second = app(StackMembers::class)->forAsset($page->fresh())->first(fn ($file) => (string) $file->fileObject?->sha256 === hash('sha256', 'second extra'));
    $ledger->remove($page->fresh(), (int) $second->id);
    expect($revision())->toBe($base)->and($revision())->not->toBe($swapped);
    $refused($swapped);

    // The fresh revision, on the other hand, goes through.
    $this->postJson(route('admin.dam.shared-links.store'), folderShareBody(['id' => $page->id, 'revision' => $revision()], $folder))->assertOk();
});

it('never promises that a second run reaches the pages a cap left out, in any locale', function () {
    foreach (['en_US', 'en_AU', 'en_GB', 'en_NZ'] as $locale) {
        $copy = trans('dam::app.admin.dam.web-pages.browser.share-folder-omitted', ['omitted' => 7], $locale);

        // The omitted pages are the later ones in the name order the candidates are listed in; only their count is given, never a name.
        expect($copy)->toContain('7')->toContain('Copy share link')->toContain('come later by name')
            ->and(strtolower($copy))->not->toContain('again')->not->toContain('afterwards')->not->toContain('next time');
    }
    $manual = (string) file_get_contents(base_path('docs/manual/user/web-pages.md'));
    expect(strtolower($manual))->not->toContain('share this folder again')->and($manual)->toContain('Copy share link');
});

it('words the kept-link options and the stop result from the actual share rules, in every locale', function () {
    foreach (['en_US', 'en_AU', 'en_GB', 'en_NZ'] as $locale) {
        $trans = fn (string $key) => trans('dam::app.admin.dam.web-pages.browser.'.$key, [], $locale);

        // Downloads off and creator access are options of the link, never a claim about who may open it.
        expect($trans('share-folder-preview'))->toContain('download')
            ->and(strtolower($trans('share-folder-creator')))->toContain('creator')->not->toContain('only the creator')
            ->and(strtolower($trans('share-folder-stopped')))->toContain('run stopped')->not->toContain('you stopped')->not->toContain('being shared')
            ->and($trans('share-folder-waiting'))->not->toBe($trans('share-folder-working'))
            ->and($trans('share-folder-more'))->not->toBe('dam::app.admin.dam.web-pages.browser.share-folder-more');
    }
});
