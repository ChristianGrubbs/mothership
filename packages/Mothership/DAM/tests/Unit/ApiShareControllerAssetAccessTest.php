<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Http\Controllers\API\Asset\ShareController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Sharing slice 5: the REST API's canActOnAsset() moves from "the asset's first folder only" (:241 on the
 * base commit) to the one asset access rule (DirectoryPermissionService::canAccessAsset), the same the
 * admin ShareController already uses. Under grants that rule is still the any-directory union; under lists
 * it is the asset's own list, not either of its folders' — this pins both.
 */

function apiShareCanActOnAsset(Asset $asset): bool
{
    $method = new ReflectionMethod(ShareController::class, 'canActOnAsset');
    $method->setAccessible(true);

    return $method->invoke(app(ShareController::class), $asset);
}

function apiShareCustomAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('reaches an asset through its second directory grant when the first is ungranted, under grants', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();

    $admin = apiShareCustomAdmin();
    $ungranted = Directory::create(['name' => 'API grants ungranted', 'parent_id' => null]);
    $granted = Directory::create(['name' => 'API grants granted', 'parent_id' => null]);
    DB::table('dam_directory_user')->insert(['directory_id' => $granted->id, 'admin_id' => $admin->id, 'inherit_children' => false]);

    $asset = Asset::factory()->create();
    $asset->directories()->attach([$ungranted->id, $granted->id]);

    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(apiShareCanActOnAsset($asset))->toBeTrue();
});

it('refuses an asset in no directory the admin can access, under grants', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();

    $admin = apiShareCustomAdmin();
    $ungranted = Directory::create(['name' => 'API grants ungranted only', 'parent_id' => null]);
    $asset = Asset::factory()->create();
    $asset->directories()->attach($ungranted->id);

    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(apiShareCanActOnAsset($asset))->toBeFalse();
});

it('reaches an asset through its own list, not either of its two directories, under lists', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    $admin = apiShareCustomAdmin();
    $folderOne = Directory::create(['name' => 'API lists folder one', 'parent_id' => null]);
    $folderTwo = Directory::create(['name' => 'API lists folder two', 'parent_id' => null]);

    $asset = Asset::factory()->create();
    $asset->directories()->attach([$folderOne->id, $folderTwo->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $admin->id]);

    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(apiShareCanActOnAsset($asset))->toBeTrue();
});

it('refuses an asset outside the admin own list under lists, even from an owned directory', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    $admin = apiShareCustomAdmin();
    $ownedFolder = Directory::create(['name' => 'API lists owned folder', 'parent_id' => null, 'created_by' => $admin->id]);
    $asset = Asset::factory()->create();
    $asset->directories()->attach($ownedFolder->id);

    test()->actingAs($admin, 'admin');
    app(DirectoryPermissionService::class)->flush();

    expect(apiShareCanActOnAsset($asset))->toBeFalse();
});
