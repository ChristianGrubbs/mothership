<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\LegacyGrantReader;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use OwenIt\Auditing\Models\Audit;

/**
 * An asset's history carries its comments, properties and linked resources, and the history routes check only the
 * history ACL: a member who may not open the asset must not read its history through them either.
 */
it('answers an asset the viewer cannot open with the same 404 a missing asset gets, on every history route', function () {
    $directory = Directory::factory()->create();
    $visible = Asset::factory()->create();
    $hidden = Asset::factory()->create();
    $directory->assets()->attach([$visible->id, $hidden->id]);
    AssetComments::factory()->create(['dam_asset_id' => $visible->id, 'comments' => 'open note']);
    AssetComments::factory()->create(['dam_asset_id' => $hidden->id, 'comments' => 'secret note']);

    $trail = fn (Asset $asset) => Audit::query()->where('tags', 'asset')->where('history_id', $asset->id)->where('auditable_type', AssetComments::class);
    // The audit trail itself is what leaked: without it this test would pass vacuously.
    expect($trail($hidden)->count())->toBeGreaterThan(0)->and($trail($visible)->count())->toBeGreaterThan(0);
    // MySQL and PostgreSQL number versions in an insert trigger; SQLite has none, so number them as it would.
    $trail($hidden)->whereNull('version_id')->update(['version_id' => 1]);
    $trail($visible)->whereNull('version_id')->update(['version_id' => 1]);
    $version = fn (Asset $asset) => (int) $trail($asset)->value('version_id');

    $member = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');
    config(['app.debug' => false]);

    $missingId = $hidden->id + 1000;
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    // The version read returned the comment text; the grid listed the versions. Whatever spelling of the entity
    // a database collation would match, a hidden asset answers exactly as a missing one and never shows its text.
    foreach (['asset', 'ASSET', 'Ässet', 'asset ', "as\u{200B}set"] as $entity) {
        $hiddenRead = $this->getJson(route('admin.history.version.view', ['entity' => $entity, 'id' => $hidden->id, 'versionId' => $version($hidden)]));
        $missingRead = $this->getJson(route('admin.history.version.view', ['entity' => $entity, 'id' => $missingId, 'versionId' => $version($hidden)]));

        expect($hiddenRead->getContent())->not->toContain('secret note')->toBe($missingRead->getContent())
            ->and($hiddenRead->status())->toBe($missingRead->status());
    }

    $this->getJson(route('admin.history.version.view', ['entity' => 'asset', 'id' => $hidden->id, 'versionId' => $version($hidden)]))->assertNotFound();
    $this->getJson(route('admin.history.index', ['entity' => 'asset', 'id' => $hidden->id]), $ajax)->assertNotFound();

    foreach (['admin.history.view' => 'get', 'admin.history.version.restore' => 'post', 'admin.history.version.delete' => 'delete'] as $name => $verb) {
        $this->{$verb.'Json'}(route($name, ['entity' => 'asset', 'id' => $hidden->id, 'historyId' => 1, 'versionId' => 1]))->assertNotFound();
    }

    // The asset the member may open keeps its history.
    $this->getJson(route('admin.history.version.view', ['entity' => 'asset', 'id' => $visible->id, 'versionId' => $version($visible)]))
        ->assertOk()->assertSee('open note');
    expect($this->getJson(route('admin.history.index', ['entity' => 'asset', 'id' => $visible->id]), $ajax)->assertOk()->json('records'))->not->toBeEmpty();
});

it('shows a share link\'s history, and so its token, only to those who may see the token', function () {
    $directory = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $directory->assets()->attach($asset->id);
    // The link's creator holds a custom role, so the creator rule, not a full-admin bypass, is what keeps it.
    $owner = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ])->id]);
    // A legacy (plaintext) link, whose token must not surface through its history either.
    $share = Share::factory()->legacy()->forAsset($asset->id)->create(['created_by' => $owner->id, 'token' => str_repeat('t', 40)]);
    $trail = Audit::query()->where('tags', 'Share')->where('history_id', $share->id);
    expect($trail->count())->toBeGreaterThan(0);
    $trail->whereNull('version_id')->update(['version_id' => 1]);
    $versionId = (int) Audit::query()->where('tags', 'Share')->where('history_id', $share->id)->value('version_id');

    // A member who may open the asset but did not create the link: the share list hides its token, and so must history.
    $member = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->values()->all(),
    ])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    $this->actingAs($member, 'admin');
    $hiddenRead = $this->getJson(route('admin.history.version.view', ['entity' => 'Share', 'id' => $share->id, 'versionId' => $versionId]));
    $missingRead = $this->getJson(route('admin.history.version.view', ['entity' => 'Share', 'id' => $share->id + 1000, 'versionId' => $versionId]));
    $hiddenRead->assertNotFound();
    expect($hiddenRead->getContent())->not->toContain($share->urlToken())->toBe($missingRead->assertNotFound()->getContent());
    $this->getJson(route('admin.history.index', ['entity' => 'Share', 'id' => $share->id]), $ajax)->assertNotFound();

    // The link's creator keeps it, and since P2 S5a no audit records the token at all.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $owner->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($owner, 'admin');
    $this->getJson(route('admin.history.version.view', ['entity' => 'Share', 'id' => $share->id, 'versionId' => $versionId]))->assertOk()->assertDontSee($share->urlToken());
});

it('keeps a share link\'s history from a custom role that reaches every folder: it is no full admin', function () {
    // Codex re-review of dafd1a9b: under grants, all_directories makes bypass() true but not fullAdmin().
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    $directory = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $directory->assets()->attach($asset->id);
    $creator = Admin::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $trail = Audit::query()->where('tags', 'Share')->where('history_id', $share->id);
    expect($trail->count())->toBeGreaterThan(0);
    $trail->whereNull('version_id')->update(['version_id' => 1]);
    $versionId = (int) Audit::query()->where('tags', 'Share')->where('history_id', $share->id)->value('version_id');

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->unique()->values()->all()]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    $allFolders = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($allFolders, 'admin');
    app(DirectoryPermissionService::class)->flush();
    expect(app(DirectoryPermissionService::class)->bypass())->toBeTrue()
        ->and(app(DirectoryPermissionService::class)->fullAdmin())->toBeFalse();
    config(['app.debug' => false]);

    $hiddenRead = $this->getJson(route('admin.history.version.view', ['entity' => 'Share', 'id' => $share->id, 'versionId' => $versionId]));
    $missingRead = $this->getJson(route('admin.history.version.view', ['entity' => 'Share', 'id' => $share->id + 1000, 'versionId' => $versionId]));
    $hiddenRead->assertNotFound();
    expect($hiddenRead->getContent())->toBe($missingRead->assertNotFound()->getContent());
    $this->getJson(route('admin.history.index', ['entity' => 'Share', 'id' => $share->id]), ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
});

/**
 * Codex review of the P2 S4 + #144 + main merge (a1ff2db1, B1): the history guard asked folder access alone, so an
 * asset out of use (draft, archived, outside its window) or in the trash kept a readable history. It now asks the
 * access seam, so the history is exactly as hidden as the asset, under either access model and for full admins too.
 */
function historyTrail(Asset $asset)
{
    return Audit::query()->where('tags', 'asset')->where('history_id', $asset->id)->where('auditable_type', AssetComments::class);
}

/** A commented asset in the folder, created by someone else, with its trail numbered as MySQL and PostgreSQL would. */
function commentedHistoryAsset(Directory $directory, string $note, array $attributes = []): Asset
{
    $asset = Asset::factory()->create(['created_by' => Admin::factory()->create()->id] + $attributes);
    $directory->assets()->attach($asset->id);
    AssetComments::factory()->create(['dam_asset_id' => $asset->id, 'comments' => $note]);
    // The trail is what would leak: without it these tests would pass vacuously.
    expect(historyTrail($asset)->count())->toBeGreaterThan(0);
    historyTrail($asset)->whereNull('version_id')->update(['version_id' => 1]);

    return $asset;
}

/**
 * The asset's history answers 404 on the version read and the grid, and never shows its note: for every viewer, editors
 * and full admins included, exactly as a missing asset's history answers.
 */
function expectHistoryHidden($test, Asset $asset, string $note): void
{
    $versionId = (int) historyTrail($asset)->value('version_id');
    $missingId = Asset::withTrashed()->max('id') + 1000;
    $read = fn (int $id) => $test->getJson(route('admin.history.version.view', ['entity' => 'asset', 'id' => $id, 'versionId' => $versionId]));
    $grid = fn (int $id) => $test->getJson(route('admin.history.index', ['entity' => 'asset', 'id' => $id]), ['X-Requested-With' => 'XMLHttpRequest']);

    $hiddenRead = $read($asset->id)->assertNotFound();
    $hiddenGrid = $grid($asset->id)->assertNotFound();
    expect($hiddenRead->getContent())->not->toContain($note)
        ->and($hiddenGrid->getContent())->not->toContain($note);

    expect($hiddenRead->getContent())->toBe($read($missingId)->assertNotFound()->getContent())
        ->and($hiddenGrid->getContent())->toBe($grid($missingId)->assertNotFound()->getContent());
}

function expectHistoryReadable($test, Asset $asset, string $note): void
{
    $test->getJson(route('admin.history.version.view', ['entity' => 'asset', 'id' => $asset->id, 'versionId' => (int) historyTrail($asset)->value('version_id')]))
        ->assertOk()->assertSee($note);
}

it('hides the history of an asset out of use from a member who may not see unavailable assets, under either access model', function (string $model) {
    $directory = Directory::factory()->create();
    $drafted = commentedHistoryAsset($directory, 'draft note', ['availability' => Asset::AVAILABILITY_DRAFT]);
    $archived = commentedHistoryAsset($directory, 'archived note', ['availability' => Asset::AVAILABILITY_ARCHIVED]);
    $expired = commentedHistoryAsset($directory, 'expired note', ['available_until' => now()->subDay()]);
    $live = commentedHistoryAsset($directory, 'live note');

    // Every ACL key but dam.asset.update: an editor sees every unavailable asset by design.
    $member = Admin::factory()->create(['role_id' => Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => collect(config('acl'))->pluck('key')->unique()->reject(fn ($key) => $key === 'dam.asset.update')->values()->all(),
    ])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
    if ($model === AccessModel::GRANTS) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $member->role_id]);
    } else {
        DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
        foreach ([$drafted, $archived, $expired, $live] as $asset) {
            DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $member->id]);
        }
    }
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);
    $this->actingAs($member, 'admin');

    expectHistoryHidden($this, $drafted, 'draft note');
    expectHistoryHidden($this, $archived, 'archived note');
    expectHistoryHidden($this, $expired, 'expired note');
    expectHistoryReadable($this, $live, 'live note');

    // Back in use, its history is back.
    $drafted->update(['availability' => Asset::AVAILABILITY_AVAILABLE]);
    expectHistoryReadable($this, $drafted, 'draft note');
})->with([AccessModel::GRANTS, AccessModel::LISTS]);

it('hides a trashed asset\'s history from everyone, editors and full admins included, until it is restored', function () {
    $directory = Directory::factory()->create();
    $trashed = commentedHistoryAsset($directory, 'binned note');

    $role = fn (array $permissions) => Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions])->id;
    $keys = collect(config('acl'))->pluck('key')->unique()->values();
    $member = Admin::factory()->create(['role_id' => $role($keys->reject(fn ($key) => $key === 'dam.asset.update')->values()->all())]);
    $editor = Admin::factory()->create(['role_id' => $role($keys->all())]);
    // An editor whose role reaches every folder: the old guard answered true for a missing id here, past the grant check.
    $bypassEditor = Admin::factory()->create(['role_id' => $role($keys->all())]);
    DB::table('dam_role_settings')->insert(['role_id' => $bypassEditor->role_id, 'all_directories' => true, 'created_at' => now(), 'updated_at' => now()]);
    $fullAdmin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();
    foreach ([$member, $editor] as $granted) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $granted->role_id]);
    }
    config(['app.debug' => false]);
    $viewers = ['member' => $member, 'editor' => $editor, 'bypass editor' => $bypassEditor, 'full admin' => $fullAdmin];
    expect(app(LegacyGrantReader::class)->bypass($bypassEditor))->toBeTrue();

    $trashed->delete();
    foreach ($viewers as $viewer) {
        app(DirectoryPermissionService::class)->flush();
        $this->actingAs($viewer, 'admin');
        expectHistoryHidden($this, $trashed, 'binned note');
    }

    $trashed->restore();
    foreach ($viewers as $viewer) {
        app(DirectoryPermissionService::class)->flush();
        $this->actingAs($viewer, 'admin');
        expectHistoryReadable($this, $trashed, 'binned note');
    }
});
