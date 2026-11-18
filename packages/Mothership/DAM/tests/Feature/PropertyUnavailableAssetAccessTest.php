<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The three property endpoints that answer for an asset id (web list, web create, REST create) obey the access seam:
 * the owner, an editor and a full admin reach every unavailable asset, nobody reaches a trashed or merged one, and a
 * missing id answers 404 whether or not the payload is valid (a full admin included: the seam cannot see a row that
 * does not exist, so the controller must).
 */

/** Role, folder and the signed-in actor for one kind of viewer; the API token belongs to the same admin. */
function puaActor(string $viewer): array
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
    app(AccessModel::class)->flush();

    $headers = test()->getAuthenticationHeaders();
    $actor = Admin::latest('id')->firstOrFail();
    $keys = collect(config('acl'))->pluck('key')->unique()
        ->reject(fn ($key) => $viewer === 'owner' && $key === 'dam.asset.update')->values()->all();
    $role = Role::factory()->create(['permission_type' => $viewer === 'admin' ? 'all' : 'custom', 'permissions' => $keys]);
    $actor->update(['role_id' => $role->id, 'status' => 1]);

    $folder = Directory::factory()->create();
    DB::table('dam_directory_role')->insert(['directory_id' => $folder->id, 'role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();
    test()->actingAs($actor, 'admin');

    return [$actor, $folder, $headers];
}

function puaAsset(string $state, Directory $folder, Admin $actor, bool $owned): Asset
{
    $asset = Asset::factory()->create(['created_by' => $owned ? $actor->id : null]);
    $folder->assets()->attach($asset->id);

    match ($state) {
        'trashed'   => $asset->delete(),
        'scheduled' => $asset->update(['available_from' => now()->addDay()]),
        'expired'   => $asset->update(['available_until' => now()->subDay()]),
        'merged'    => DB::table('dam_assets')->where('id', $asset->id)->update(['merged_into_id' => Asset::factory()->create()->id]),
        default     => $asset->update(['availability' => $state]),
    };

    return $asset;
}

const PUA_WEB_PAYLOAD = ['name' => 'Credit line', 'type' => 'Text', 'value' => 'Photo desk', 'language' => 'English'];
const PUA_API_PAYLOAD = ['name' => 'Credit line', 'type' => 'Text', 'value' => 'Photo desk', 'language' => 'en_US'];

it('lets the owner, an editor and a full admin list and create properties on an unavailable asset, and 404s a trashed or merged one', function (string $viewer, string $state) {
    [$actor, $folder, $headers] = puaActor($viewer);
    $asset = puaAsset($state, $folder, $actor, owned: $viewer === 'owner');
    $reachable = ! in_array($state, ['trashed', 'merged'], true);
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    $list = $this->getJson(route('admin.dam.asset.properties.index', $asset->id), $ajax);
    $web = $this->postJson(route('admin.dam.asset.property.store', $asset->id), PUA_WEB_PAYLOAD);
    $api = $this->withHeaders($headers)->postJson(route('admin.api.dam.property.add', $asset->id), PUA_API_PAYLOAD);

    if ($reachable) {
        $list->assertOk();
        $web->assertOk();
        $api->assertSuccessful();
        expect(AssetProperty::where('dam_asset_id', $asset->id)->count())->toBe(2);

        return;
    }

    expect([$list->status(), $web->status(), $api->status()])->toBe([404, 404, 404])
        ->and(AssetProperty::where('dam_asset_id', $asset->id)->count())->toBe(0);
})->with(function () {
    foreach (['owner', 'editor', 'admin'] as $viewer) {
        foreach (['draft', 'pending_review', 'archived', 'scheduled', 'expired', 'trashed', 'merged'] as $state) {
            yield "{$viewer} {$state}" => [$viewer, $state];
        }
    }
});

it('answers 404 for a missing asset id on all three property endpoints, with a valid or an incomplete payload', function (string $viewer) {
    [, , $headers] = puaActor($viewer);
    $missing = (int) Asset::withTrashed()->max('id') + 10000;
    $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

    $this->getJson(route('admin.dam.asset.properties.index', $missing), $ajax)->assertNotFound();
    foreach ([PUA_WEB_PAYLOAD, []] as $payload) {
        $this->postJson(route('admin.dam.asset.property.store', $missing), $payload)->assertNotFound();
        $this->withHeaders($headers)->postJson(route('admin.api.dam.property.add', $missing), $payload === [] ? [] : PUA_API_PAYLOAD)->assertNotFound();
    }
    expect(AssetProperty::where('dam_asset_id', $missing)->count())->toBe(0);
})->with(['owner', 'editor', 'admin']);
