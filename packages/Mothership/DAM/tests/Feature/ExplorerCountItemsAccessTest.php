<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\CaptureGroup;
use Mothership\DAM\Models\CaptureProfile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * countItems must agree with what the Library listing actually hands back:
 * only granted directories inside the selected subtrees, and only assets the
 * listing itself would return (visibleCaptures + the directory-grant filter).
 */
function countItemsActAsGrantedAdmin(array $grantedDirectories): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam.asset.view'],
    ]);

    foreach ($grantedDirectories as $directory) {
        DB::table('dam_directory_role')->insert([
            'directory_id' => $directory->id,
            'role_id'      => $role->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    $admin = Admin::factory()->create(['role_id' => $role->id]);

    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    return $admin;
}

/**
 * Two selected roots, each carrying an UNGRANTED descendant that holds an asset.
 * Both roots are seeded so the OR-group defect is order-independent: whichever
 * root the loop emits first is the one whose subtree escapes the grant filter.
 */
function countItemsTwoRootFixture(): array
{
    $library = Directory::create(['name' => 'CntItemsLibrary', 'parent_id' => null]);
    $rootA = Directory::create(['name' => 'CntItemsRootA', 'parent_id' => $library->id]);
    $ungrantedA = Directory::create(['name' => 'CntItemsUngrantedA', 'parent_id' => $rootA->id]);
    $rootB = Directory::create(['name' => 'CntItemsRootB', 'parent_id' => $library->id]);
    $ungrantedB = Directory::create(['name' => 'CntItemsUngrantedB', 'parent_id' => $rootB->id]);
    $elsewhere = Directory::create(['name' => 'CntItemsElsewhere', 'parent_id' => $library->id]);

    $grantedAssetA = Asset::factory()->create(['file_name' => 'granted-a.png']);
    $grantedAssetA->directories()->attach($rootA->id);

    $grantedAssetB = Asset::factory()->create(['file_name' => 'granted-b.png']);
    $grantedAssetB->directories()->attach($rootB->id);

    $ungrantedAssetA = Asset::factory()->create(['file_name' => 'ungranted-a.png']);
    $ungrantedAssetA->directories()->attach($ungrantedA->id);

    $ungrantedAssetB = Asset::factory()->create(['file_name' => 'ungranted-b.png']);
    $ungrantedAssetB->directories()->attach($ungrantedB->id);

    return compact(
        'library', 'rootA', 'ungrantedA', 'rootB', 'ungrantedB', 'elsewhere',
        'grantedAssetA', 'grantedAssetB', 'ungrantedAssetA', 'ungrantedAssetB'
    );
}

it('excludes assets in an ungranted descendant of the first selected root', function () {
    $f = countItemsTwoRootFixture();
    countItemsActAsGrantedAdmin([$f['rootA'], $f['rootB'], $f['elsewhere']]);

    $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$f['rootA']->id, $f['rootB']->id],
    ])->assertOk()->assertJsonPath('file_count', 2);
});

it('excludes ungranted descendants no matter which root is listed first', function () {
    $f = countItemsTwoRootFixture();
    countItemsActAsGrantedAdmin([$f['rootA'], $f['rootB'], $f['elsewhere']]);

    $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$f['rootB']->id, $f['rootA']->id],
    ])->assertOk()->assertJsonPath('file_count', 2);
});

/**
 * Isolates the OR-grouping defect from the asset-side access gate: this asset is
 * reachable through a granted directory, so applyLibraryAssetAccess admits it.
 * It is only ever counted because an ungranted directory leaks into the subtree
 * id list, which is precisely the OR/AND precedence bug.
 */
it('does not count a granted asset through an ungranted directory in the selection', function () {
    $f = countItemsTwoRootFixture();

    $sharedAsset = Asset::factory()->create(['file_name' => 'shared.png']);
    $sharedAsset->directories()->attach($f['ungrantedA']->id);
    $sharedAsset->directories()->attach($f['elsewhere']->id);

    countItemsActAsGrantedAdmin([$f['rootA'], $f['rootB'], $f['elsewhere']]);

    $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$f['rootA']->id, $f['rootB']->id],
    ])->assertOk()->assertJsonPath('file_count', 2);
});

it('counts a capture group once, matching what the Library lists', function () {
    $this->loginAsAdmin();

    $directory = Directory::create(['name' => 'CntItemsCapture', 'parent_id' => null]);

    $primary = Asset::factory()->create(['file_name' => 'capture.mp4', 'extension' => 'mp4']);
    $derivative = Asset::factory()->create(['file_name' => 'capture.srt', 'extension' => 'srt']);

    $profile = CaptureProfile::create([
        'name'       => 'CntItemsProfile',
        'enabled'    => true,
        'extensions' => ['mp4', 'srt'],
        'priority'   => ['mp4'],
    ]);
    $group = CaptureGroup::create(['profile_id' => $profile->id, 'primary_asset_id' => $primary->id]);
    Asset::whereIn('id', [$primary->id, $derivative->id])->update(['capture_group_id' => $group->id]);

    $directory->assets()->attach([$primary->id, $derivative->id]);

    $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$directory->id],
    ])->assertOk()->assertJsonPath('file_count', 1);
});

/**
 * The invariant the audit named as the highest-value gap: for one directory set,
 * the selection count and the listing total must be the same number. The fixture
 * carries a capture derivative (must not be counted) and a multi-directory asset
 * (must be counted once) so the assertion is not trivially true.
 */
it('reports the same total as the Library listing for the same directory set', function () {
    $this->loginAsAdmin();

    $root = Directory::create(['name' => 'CntItemsInvariantRoot', 'parent_id' => null]);
    $child = Directory::create(['name' => 'CntItemsInvariantChild', 'parent_id' => $root->id]);

    $plain = Asset::factory()->create(['file_name' => 'plain.png']);
    $plain->directories()->attach($root->id);

    $inBoth = Asset::factory()->create(['file_name' => 'in-both.png']);
    $inBoth->directories()->attach($root->id);
    $inBoth->directories()->attach($child->id);

    $primary = Asset::factory()->create(['file_name' => 'invariant.mp4', 'extension' => 'mp4']);
    $derivative = Asset::factory()->create(['file_name' => 'invariant.srt', 'extension' => 'srt']);
    $profile = CaptureProfile::create([
        'name'       => 'CntItemsInvariantProfile',
        'enabled'    => true,
        'extensions' => ['mp4', 'srt'],
        'priority'   => ['mp4'],
    ]);
    $group = CaptureGroup::create(['profile_id' => $profile->id, 'primary_asset_id' => $primary->id]);
    Asset::whereIn('id', [$primary->id, $derivative->id])->update(['capture_group_id' => $group->id]);
    $child->assets()->attach([$primary->id, $derivative->id]);

    $listing = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'       => $root->id,
        'include_subfolders' => 1,
        'per_page'           => 1,
    ]));
    $listing->assertOk();

    $counted = $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$root->id, $child->id],
    ]);
    $counted->assertOk();

    expect($counted->json('file_count'))->toBe($listing->json('meta.total_assets'));
});

it('still counts the whole subtree for a bypass role', function () {
    $this->loginAsAdmin();

    $f = countItemsTwoRootFixture();

    $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$f['rootA']->id, $f['rootB']->id],
    ])->assertOk()->assertJsonPath('file_count', 4);
});

it('keeps counting explicitly selected asset ids alongside directory selections', function () {
    $f = countItemsTwoRootFixture();
    countItemsActAsGrantedAdmin([$f['rootA'], $f['rootB'], $f['elsewhere']]);

    $extra = Asset::factory()->create(['file_name' => 'extra.png']);
    $extra->directories()->attach($f['elsewhere']->id);

    $this->postJson(route('admin.dam.explorer.count_items'), [
        'directory_ids' => [$f['rootA']->id, $f['rootB']->id],
        'asset_ids'     => [$extra->id],
    ])->assertOk()->assertJsonPath('file_count', 3);
});

it('counts only the files the viewer can open in a listed subfolder (lists)', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    $owner = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    $viewer = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']])->id]);

    $parent = Directory::create(['name' => 'CntFolderParent', 'parent_id' => null]);
    $child = Directory::create(['name' => 'CntFolderChild', 'parent_id' => $parent->id]);
    DB::table('dam_directories')->update(['created_by' => $owner->id]);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $parent->id, 'admin_id' => $viewer->id],
        ['directory_id' => $child->id, 'admin_id' => $viewer->id],
    ]);

    $visible = Asset::factory()->create(['file_name' => 'visible.png', 'created_by' => $owner->id]);
    $hidden = Asset::factory()->create(['file_name' => 'hidden.png', 'created_by' => $owner->id]);
    $visible->directories()->attach($child->id);
    $hidden->directories()->attach($child->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $viewer->id]);

    $count = fn (Admin $actor) => collect(test()->actingAs($actor, 'admin')
        ->getJson('/admin/dam/explorer/data?scope=directory&directory_id='.$parent->id)
        ->assertOk()->json('directories'))->firstWhere('id', $child->id)['assets_count'];

    expect($count($viewer))->toBe(1)->and($count($owner))->toBe(2);
});

it('tells the Library when a folder is only visible on the way to a granted one, so it is not shown as empty', function () {
    $library = Directory::create(['name' => 'NoAccessLibrary', 'parent_id' => null]);
    $parent = Directory::create(['name' => 'NoAccessParent', 'parent_id' => $library->id]);
    $granted = Directory::create(['name' => 'NoAccessGranted', 'parent_id' => $parent->id]);
    Asset::factory()->create(['file_name' => 'in-parent.png'])->directories()->attach($parent->id);
    countItemsActAsGrantedAdmin([$granted]);
    $data = fn (Directory $directory) => '/'.config('app.admin_url').'/dam/explorer/data?directory_id='.$directory->id;

    // A path to a granted folder lists no files of its own to this viewer, and says why.
    test()->getJson($data($parent))->assertOk()->assertJsonPath('meta.can_access_current', false)->assertJsonCount(0, 'assets');
    test()->getJson($data($granted))->assertOk()->assertJsonPath('meta.can_access_current', true);
});
