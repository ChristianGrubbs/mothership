<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\DataGrids\Share\ShareDataGrid;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Sharing slice 5: under lists, every signed-in user keeps the share-link feature (D7), scoped by the
 * link's creator, the target's owner, or a full admin — never the ACL key alone, since the switch grants it
 * to every custom role. Under grants, every behaviour here stays exactly as it is on the base commit, except that
 * P2 S3 lists a link only when the viewer may see its target, in both models (AvailabilityAccessProbesTest), and
 * that changing a link needs its creator, its target's owner or a full admin in both models too (R14).
 */

beforeEach(function () {
    DB::table('dam_shares')->delete();
});

function shareLinksSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/** A custom-role admin with no share keys of its own — the switch to lists is what grants them. */
function shareLinksCustomAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** Simulates what dam:access:switch lists grants every custom role (AccessSwitchTest.php covers the grant itself). */
function shareLinksGrantShareKeys(Admin $admin): void
{
    $permissions = (array) json_decode((string) DB::table('roles')->where('id', $admin->role_id)->value('permissions'), true);

    DB::table('roles')->where('id', $admin->role_id)->update([
        'permissions' => json_encode(array_values(array_unique(array_merge($permissions, [
            'dam.asset.share', 'dam.directory.share', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke', 'dam.shares.delete',
        ])))),
    ]);
}

it('lets a custom role without the share keys create a link for an item it can access once lists verifies, and refuses one it cannot', function () {
    $admin = shareLinksCustomAdmin();
    $owned = Asset::factory()->create(['created_by' => $admin->id]);
    $foreign = Asset::factory()->create(['created_by' => Admin::factory()->create()->id]);

    // Switch to lists, which grants every custom role the six keys (AccessSwitchTest.php covers the grant
    // itself); simulate the settled state directly here so this test stays focused on store()'s own gate.
    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($admin);

    $this->actingAs($admin, 'admin');

    $this->post(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $owned->id,
        'expiry_days'           => 7,
        'public_link_confirmed' => true,
    ])->assertOk()->assertJsonPath('success', true);

    // An item the actor cannot see answers like one that does not exist.
    $this->post(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $foreign->id,
        'expiry_days'           => 7,
        'public_link_confirmed' => true,
    ])->assertNotFound();
});

it('sees the Shared Links page once lists verifies, without dam.shares in its stored permissions before the switch', function () {
    $admin = shareLinksCustomAdmin();
    expect(DB::table('roles')->where('id', $admin->role_id)->value('permissions'))
        ->not->toContain('dam.shares');

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($admin);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.dam.shared-links.index'))
        ->assertOk();
});

it('scopes the grid under lists to the actor own links plus links to items it owns, and shows every link to a full admin', function () {
    $owner = shareLinksCustomAdmin();
    $other = shareLinksCustomAdmin();
    $fullAdmin = Admin::factory()->create();

    $ownItem = Asset::factory()->create(['created_by' => $owner->id]);
    $otherItem = Asset::factory()->create(['created_by' => $other->id]);

    // A link the owner created for their own item, and a link someone else created for the owner's item.
    $createdByOwner = Share::factory()->forAsset($ownItem->id)->create(['created_by' => $owner->id]);
    $onOwnedItemCreatedByOther = Share::factory()->forAsset($ownItem->id)->create(['created_by' => $other->id]);
    $unrelated = Share::factory()->forAsset($otherItem->id)->create(['created_by' => $other->id]);

    shareLinksSetModel(AccessModel::LISTS);

    $this->actingAs($owner, 'admin');
    $ownerRows = app(ShareDataGrid::class)->prepareQueryBuilder()->pluck('id')->sort()->values()->all();
    expect($ownerRows)->toBe(collect([$createdByOwner->id, $onOwnedItemCreatedByOther->id])->sort()->values()->all());

    $this->actingAs($fullAdmin, 'admin');
    $adminRows = app(ShareDataGrid::class)->prepareQueryBuilder()->pluck('id')->sort()->values()->all();
    expect($adminRows)->toBe(collect([$createdByOwner->id, $onOwnedItemCreatedByOther->id, $unrelated->id])->sort()->values()->all());
});

it('combines the own-links scope with a search filter, and the pagination total reflects both', function () {
    $owner = shareLinksCustomAdmin();
    $other = shareLinksCustomAdmin();

    $ownItem = Asset::factory()->create(['created_by' => $owner->id, 'file_name' => 'searchable-target.jpg']);
    $otherOwnedButUnnamedItem = Asset::factory()->create(['created_by' => $owner->id, 'file_name' => 'other-target.jpg']);
    $otherItem = Asset::factory()->create(['created_by' => $other->id, 'file_name' => 'searchable-target.jpg']);

    // Matches the search term and is in the owner's scope: the one row the combination should return.
    $matching = Share::factory()->forAsset($ownItem->id)->create(['created_by' => $owner->id]);
    // In scope, but the search term does not match its target's file name.
    Share::factory()->forAsset($otherOwnedButUnnamedItem->id)->create(['created_by' => $owner->id]);
    // Matches the search term, but outside the owner's scope (a stranger's item and link).
    Share::factory()->forAsset($otherItem->id)->create(['created_by' => $other->id]);

    shareLinksSetModel(AccessModel::LISTS);
    $this->actingAs($owner, 'admin');

    $grid = app(ShareDataGrid::class);
    $grid->prepareColumns();
    request()->merge(['filters' => ['all' => ['searchable-target']]]);
    $grid->setQueryBuilder();
    $grid->processRequest();
    $data = $grid->formatData();

    expect(collect($data['records'])->pluck('id')->all())->toBe([$matching->id])
        ->and($data['meta']['total'])->toBe(1);
});

it('leaves the grid unscoped by creator or owner under grants, as on the base commit', function () {
    $someone = shareLinksCustomAdmin();
    $folder = Directory::factory()->create();
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $someone->role_id]);
    $asset = Asset::factory()->create();
    $folder->assets()->attach($asset->id);
    $listed = Share::factory()->forAsset($asset->id)->create(['created_by' => Admin::factory()->create()->id]);
    // P2 S3: a link to a file the viewer cannot see is left out, whoever created it.
    Share::factory()->forAsset(Asset::factory()->create()->id)->create(['created_by' => Admin::factory()->create()->id]);

    shareLinksSetModel(AccessModel::GRANTS);

    $this->actingAs($someone, 'admin');
    expect(app(ShareDataGrid::class)->prepareQueryBuilder()->pluck('dam_shares.id')->all())->toBe([$listed->id]);
});

it('allows the creator, the owner, and a full admin to revoke or delete a link under lists, and refuses everyone else', function () {
    $owner = shareLinksCustomAdmin();
    $creator = shareLinksCustomAdmin();
    $stranger = shareLinksCustomAdmin();
    $fullAdmin = Admin::factory()->create();

    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    // The creator and the stranger are both listed on the file, so each can see it; a 403 is only for a
    // visible target (P2 S3).
    DB::table('dam_asset_user_access')->insert([
        ['asset_id' => $asset->id, 'admin_id' => $creator->id],
        ['asset_id' => $asset->id, 'admin_id' => $stranger->id],
    ]);

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($owner);
    shareLinksGrantShareKeys($creator);
    shareLinksGrantShareKeys($stranger);

    foreach ([
        'the owner'      => $owner,
        'the creator'    => $creator,
        'a full admin'   => $fullAdmin,
    ] as $label => $actor) {
        $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);

        $this->actingAs($actor, 'admin')
            ->patch(route('admin.dam.shared-links.revoke', $share->id))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->actingAs($stranger, 'admin')
        ->patch(route('admin.dam.shared-links.revoke', $share->id))
        ->assertStatus(403);

    $deletable = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->actingAs($stranger, 'admin')
        ->delete(route('admin.dam.shared-links.destroy', $deletable->id))
        ->assertStatus(403);

    $this->actingAs($owner, 'admin')
        ->delete(route('admin.dam.shared-links.destroy', $deletable->id))
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('pins D6: a folder link keeps serving an Only-me file inside the folder under lists, on the JSON endpoint and the public page', function () {
    $owner = Admin::factory()->create();
    $folder = Directory::create(['name' => 'D6 folder', 'parent_id' => null, 'created_by' => $owner->id]);
    $onlyMeFile = Asset::factory()->create(['created_by' => $owner->id, 'file_name' => 'd6-only-me.jpg']);
    $onlyMeFile->directories()->attach($folder->id);

    $share = Share::factory()->forDirectory($folder->id)->create(['created_by' => $owner->id]);

    foreach ([AccessModel::GRANTS, AccessModel::LISTS] as $model) {
        shareLinksSetModel($model);

        $json = $this->getJson(route('dam.share.list_assets', $share->urlToken()));
        $json->assertOk();
        expect(collect($json->json('data'))->pluck('id'))->toContain($onlyMeFile->id);

        // The public page itself (SharedViewerController::show()), not only the paginated JSON endpoint,
        // still lists the Only-me file inside the shared folder.
        $this->get(route('dam.share.show', $share->urlToken()))
            ->assertOk()
            ->assertSeeText($onlyMeFile->file_name);
    }
});

it('keeps an existing token listAssets response identical before and after the switch to lists', function () {
    $folder = Directory::create(['name' => 'Unchanged folder', 'parent_id' => null]);
    $asset = Asset::factory()->create();
    $asset->directories()->attach($folder->id);
    $share = Share::factory()->forDirectory($folder->id)->create();

    shareLinksSetModel(AccessModel::GRANTS);
    $before = $this->getJson(route('dam.share.list_assets', $share->urlToken()))->json();

    shareLinksSetModel(AccessModel::LISTS);
    $after = $this->getJson(route('dam.share.list_assets', $share->urlToken()))->json();

    expect($after)->toBe($before);
});

it('selects no authorization-only columns under grants, so the grid records and their SQL match the base commit', function () {
    $asset = Asset::factory()->create();
    Share::factory()->forAsset($asset->id)->create(['created_by' => Admin::factory()->create()->id]);

    shareLinksSetModel(AccessModel::GRANTS);
    $this->actingAs(Admin::factory()->create(), 'admin');

    $queryBuilder = app(ShareDataGrid::class)->prepareQueryBuilder();

    // "created_by" itself still appears in the admins join condition (dam_shares.created_by = admins.id, on
    // the base commit too); target_owner_id is an alias that only ever appears when explicitly selected. P2 S5a
    // selects link_created_by and link_target_owner_id under both models for the copy-link rule (#126); the grid
    // drops them before its records and export (ShareTokenHardeningTest), so only the whole word is checked here.
    expect($queryBuilder->toSql())->not->toMatch('/\btarget_owner_id\b/');

    $record = $queryBuilder->first();
    expect((array) $record)
        ->not->toHaveKey('created_by')
        ->not->toHaveKey('target_owner_id');
});

it('produces the exact same record shape under grants as the base commit, not merely without the two new fields', function () {
    $asset = Asset::factory()->create(['file_name' => 'normalized-record.jpg']);
    Share::factory()->forAsset($asset->id)->create(['created_by' => Admin::factory()->create()->id, 'name' => 'A custom name']);

    shareLinksSetModel(AccessModel::GRANTS);
    $this->actingAs(Admin::factory()->create(), 'admin');

    $grid = app(ShareDataGrid::class);
    $grid->prepareColumns();
    $grid->prepareActions();
    $grid->setQueryBuilder();
    $grid->processRequest();
    $record = (array) collect($grid->formatData()['records'])->first();

    // Exactly the base commit's own fields: the query's raw columns, is_closure (every closure column sets
    // it) and actions — nothing sharing slice 5 added under grants. P2 S5a dropped `token`: the stored token
    // columns only build the copy-link action's URL.
    $expectedKeys = [
        'id', 'share_name', 'share_type', 'target_id', 'expires_at', 'revoked_at',
        'view_count', 'download_count', 'last_accessed_at', 'created_at', 'created_by_name',
        'target_name', 'status', 'is_closure', 'actions',
    ];
    sort($expectedKeys);
    $actualKeys = array_keys($record);
    sort($actualKeys);

    expect($actualKeys)->toBe($expectedKeys);
});

it('shows Shared Links in the sidebar once lists verifies for a role that never held the ACL key, and hides it under grants', function () {
    $admin = shareLinksCustomAdmin();

    shareLinksSetModel(AccessModel::GRANTS);
    $this->actingAs($admin, 'admin');
    expect(collect(app('mothership.admin.menu')['tree']->visibleItems())->pluck('key'))->not->toContain('dam_shares');

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($admin);
    app()->forgetInstance('mothership.admin.menu');
    // A fresh model, not the one already used above: bouncer()->hasPermission() reads $admin->role, and
    // Eloquent would otherwise keep serving the relation it cached before the permissions changed in the
    // database out from under it.
    $this->actingAs(Admin::find($admin->id), 'admin');
    expect(collect(app('mothership.admin.menu')['tree']->visibleItems())->pluck('key'))->toContain('dam_shares');
});

it('lets a folder link be created and scoped the same way an asset link is, under lists', function () {
    $owner = shareLinksCustomAdmin();
    $stranger = shareLinksCustomAdmin();
    $folder = Directory::create(['name' => 'Slice 5 folder store', 'parent_id' => null, 'created_by' => $owner->id]);

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($owner);
    shareLinksGrantShareKeys($stranger);

    $this->actingAs($owner, 'admin')
        ->post(route('admin.dam.shared-links.store'), [
            'share_type'            => Share::TYPE_DIRECTORY,
            'target_id'             => $folder->id,
            'expiry_days'           => 7,
            'public_link_confirmed' => true,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $share = Share::query()->where('share_type', Share::TYPE_DIRECTORY)->where('target_id', $folder->id)->firstOrFail();

    // A stranger to the folder cannot create a link for it (it answers like a missing one), and does
    // not see the existing one in the grid.
    $this->actingAs($stranger, 'admin')
        ->post(route('admin.dam.shared-links.store'), [
            'share_type'            => Share::TYPE_DIRECTORY,
            'target_id'             => $folder->id,
            'expiry_days'           => 7,
            'public_link_confirmed' => true,
        ])
        ->assertNotFound();

    expect(app(ShareDataGrid::class)->prepareQueryBuilder()->pluck('id')->all())->not->toContain($share->id);

    $this->actingAs($owner, 'admin');
    expect(app(ShareDataGrid::class)->prepareQueryBuilder()->pluck('id')->all())->toContain($share->id);

    // The owner can revoke and delete their own folder link; the stranger, who cannot see the folder, gets
    // the same 404 as for a link that does not exist (P2 S3).
    $this->actingAs($stranger, 'admin')
        ->patch(route('admin.dam.shared-links.revoke', $share->id))
        ->assertNotFound();

    $this->actingAs($owner, 'admin')
        ->patch(route('admin.dam.shared-links.revoke', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($stranger, 'admin')
        ->delete(route('admin.dam.shared-links.destroy', $share->id))
        ->assertNotFound();

    $this->actingAs($owner, 'admin')
        ->delete(route('admin.dam.shared-links.destroy', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('lets the creator revoke and delete a link on a target they do not own, once they could access it', function () {
    $owner = shareLinksCustomAdmin();
    $creator = shareLinksCustomAdmin();
    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $creator->id]);

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($creator);

    $this->actingAs($creator, 'admin')
        ->post(route('admin.dam.shared-links.store'), [
            'share_type'            => Share::TYPE_ASSET,
            'target_id'             => $asset->id,
            'expiry_days'           => 7,
            'public_link_confirmed' => true,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $share = Share::query()->where('share_type', Share::TYPE_ASSET)->where('target_id', $asset->id)->firstOrFail();
    expect($share->created_by)->toBe($creator->id);

    $this->actingAs($creator, 'admin')
        ->patch(route('admin.dam.shared-links.revoke', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('refuses an admin who can access the target but is neither its creator nor its owner', function () {
    $owner = shareLinksCustomAdmin();
    $creator = shareLinksCustomAdmin();
    $listedNonOwner = shareLinksCustomAdmin();

    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $listedNonOwner->id]);

    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($listedNonOwner);

    $this->actingAs($listedNonOwner, 'admin')
        ->patch(route('admin.dam.shared-links.revoke', $share->id))
        ->assertStatus(403);

    $this->actingAs($listedNonOwner, 'admin')
        ->delete(route('admin.dam.shared-links.destroy', $share->id))
        ->assertStatus(403);

    $this->assertDatabaseHas('dam_shares', ['id' => $share->id, 'revoked_at' => null]);
});

it('serializes row actions end to end under lists: the owner sees revoke/delete on their own row, and a full admin sees them on a row it neither created nor owns', function () {
    $owner = shareLinksCustomAdmin();
    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $owner->id]);
    $fullAdmin = Admin::factory()->create();

    shareLinksSetModel(AccessModel::LISTS);
    shareLinksGrantShareKeys($owner);

    $this->actingAs($owner, 'admin');
    $ownerGrid = app(ShareDataGrid::class);
    $ownerGrid->prepare();
    $ownerRecord = collect($ownerGrid->formatData()['records'])->firstWhere('id', $share->id);
    expect($ownerRecord)->not->toBeNull()
        ->and(collect($ownerRecord->actions)->pluck('index')->all())->toContain('revoke')->toContain('delete');

    // A full admin bypasses the creator/owner rule (D4), so it can act on a row it neither created nor owns —
    // this exercises prepareActions()'s condition closure end to end, through formatData()'s own filtering,
    // not just actorCanActOnShare() in isolation.
    $this->actingAs($fullAdmin, 'admin');
    $adminGrid = app(ShareDataGrid::class);
    $adminGrid->prepare();
    $adminRecord = collect($adminGrid->formatData()['records'])->firstWhere('id', $share->id);
    expect($adminRecord)->not->toBeNull()
        ->and(collect($adminRecord->actions)->pluck('index')->all())->toContain('revoke')->toContain('delete');
});

it('offers Edit, Reissue, Revoke and Delete under grants only on rows the viewer created or owns, and every row to a full admin', function () {
    $creator = Admin::factory()->create();
    $viewer = shareLinksCustomAdmin();
    $asset = Asset::factory()->create();
    // In a folder the viewer's role is granted, so both links list (P2 S3).
    $folder = Directory::factory()->create();
    $folder->assets()->attach($asset->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $viewer->role_id]);
    $others = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $own = Share::factory()->forAsset($asset->id)->create(['created_by' => $viewer->id]);

    shareLinksSetModel(AccessModel::GRANTS);
    shareLinksGrantShareKeys($viewer);

    $actionsFor = function (Admin $actor) {
        $this->actingAs($actor, 'admin');
        $grid = app(ShareDataGrid::class);
        $grid->prepare();

        return collect($grid->formatData()['records'])->mapWithKeys(fn ($record) => [$record->id => collect($record->actions)->pluck('index')->all()]);
    };

    $mutating = ['edit', 'reissue', 'revoke', 'delete'];

    // R14: the row is listed, but another admin's link offers the viewer nothing it may not do.
    $viewerActions = $actionsFor(Admin::find($viewer->id));
    expect($viewerActions)->toHaveKey($others->id)
        ->and(array_intersect($mutating, $viewerActions[$others->id]))->toBe([])
        ->and($viewerActions[$own->id])->toContain(...$mutating);

    $adminActions = $actionsFor(Admin::factory()->create());
    expect($adminActions[$others->id])->toContain(...$mutating);
});

it('refuses revoke and delete under grants on another admin\'s link the actor can see, and allows its own and a full admin', function () {
    $creator = Admin::factory()->create();
    $viewer = shareLinksCustomAdmin();
    $asset = Asset::factory()->create(['created_by' => $creator->id]);
    $folder = Directory::factory()->create();
    $folder->assets()->attach($asset->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $viewer->role_id]);

    shareLinksSetModel(AccessModel::GRANTS);
    shareLinksGrantShareKeys($viewer);
    $viewer = Admin::find($viewer->id);

    $others = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->actingAs($viewer, 'admin')->patch(route('admin.dam.shared-links.revoke', $others->id))->assertForbidden();
    $this->actingAs($viewer, 'admin')->delete(route('admin.dam.shared-links.destroy', $others->id))->assertForbidden();
    $this->assertDatabaseHas('dam_shares', ['id' => $others->id, 'revoked_at' => null]);

    $own = Share::factory()->forAsset($asset->id)->create(['created_by' => $viewer->id]);
    $this->actingAs($viewer, 'admin')->patch(route('admin.dam.shared-links.revoke', $own->id))->assertOk()->assertJsonPath('success', true);
    $this->actingAs($viewer, 'admin')->delete(route('admin.dam.shared-links.destroy', $own->id))->assertOk()->assertJsonPath('success', true);

    $fullAdmin = Admin::factory()->create();
    $this->actingAs($fullAdmin, 'admin')->patch(route('admin.dam.shared-links.revoke', $others->id))->assertOk()->assertJsonPath('success', true);
    $this->actingAs($fullAdmin, 'admin')->delete(route('admin.dam.shared-links.destroy', $others->id))->assertOk()->assertJsonPath('success', true);
    $this->assertDatabaseMissing('dam_shares', ['id' => $others->id]);
});

it('tells the share dialog which links the viewer may change, so it offers Revoke and the rest only there (R14)', function () {
    $creator = Admin::factory()->create();
    $viewer = shareLinksCustomAdmin();
    $asset = Asset::factory()->create(['created_by' => $creator->id]);
    $folder = Directory::factory()->create();
    $folder->assets()->attach($asset->id);
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $viewer->role_id]);

    shareLinksSetModel(AccessModel::GRANTS);
    $active = fn (Admin $actor) => $this->actingAs($actor, 'admin')
        ->getJson(route('admin.dam.shared-links.active_for_target', ['type' => 'asset', 'targetId' => $asset->id]))
        ->assertOk()
        ->json('shares.0');

    $own = Share::factory()->forAsset($asset->id)->create(['created_by' => $viewer->id]);

    // Its own link, but no dam.shares.revoke / dam.shares.delete under grants: the server would refuse, so neither is offered.
    expect($active($viewer))->toMatchArray(['id' => $own->id, 'can_manage' => false, 'can_delete' => false]);

    shareLinksGrantShareKeys($viewer);
    $viewer = Admin::find($viewer->id);
    expect($active($viewer))->toMatchArray(['id' => $own->id, 'can_manage' => true, 'can_delete' => true]);

    $own->delete();
    $others = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    expect($active($viewer))->toMatchArray(['id' => $others->id, 'can_manage' => false, 'can_delete' => false, 'public_url' => null]);
    expect($active(Admin::factory()->create()))->toMatchArray(['id' => $others->id, 'can_manage' => true, 'can_delete' => true]);
});
