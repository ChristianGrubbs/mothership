<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Models\Apikey;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Sharing slice 5, fix round 1, finding 2: API/Asset/ShareController::update()/revoke()/reauthorize()/
 * destroy() moved from hasTargetAccess() (mere target access, the any-directory or own-list rule) to
 * canMutateShare() under lists — the creator, the target's owner, or a full admin, the same rule the admin
 * ShareController already applies. UX eval 2026-10-10 (R14) applies the same rule under grants.
 */

beforeEach(function () {
    DB::table('dam_shares')->delete();
});

/**
 * Authenticates $admin over Passport's password grant and returns bearer headers for it.
 *
 * forgetGuards() clears the 'api' TokenGuard's cached user: Laravel's AuthManager reuses one guard
 * instance for the life of a test, and Passport's guard caches the user it resolved on its first call —
 * without this, a second actor's bearer token in this same test would still resolve to the first actor once
 * more than one request has already gone through the guard.
 */
function shareApiHeadersFor(Admin $admin): array
{
    test()->withoutMiddleware(ThrottleRequests::class);
    app('auth')->forgetGuards();

    $admin->forceFill(['password' => Hash::make('password')])->save();

    $clientRepo = new ClientRepository;
    $client = $clientRepo->createPasswordGrantClient('Client for testing share API mutations', 'admins', confidential: true);
    $client->forceFill([
        'user_id'    => $admin->id,
        'owner_type' => Admin::class,
        'owner_id'   => $admin->id,
    ])->save();

    Apikey::factory()->create([
        'permission_type' => 'all',
        'admin_id'        => $admin->id,
        'oauth_client_id' => $client->getKey(),
        'permissions'     => null,
    ]);

    $token = test()->postJson('/oauth/token', [
        'grant_type'    => 'password',
        'client_id'     => $client->id,
        'client_secret' => $client->plainSecret,
        'username'      => $admin->email,
        'password'      => 'password',
        'scope'         => '',
    ])->json('access_token');

    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

function shareApiSetModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function shareApiCustomAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('refuses an accessible target whose link belongs to someone else, under lists', function () {
    $owner = shareApiCustomAdmin();
    $creator = shareApiCustomAdmin();
    // Listed on the owner's asset, so it can access the target, but is neither its creator nor its owner.
    $listedNonOwner = shareApiCustomAdmin();

    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $listedNonOwner->id]);

    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);

    shareApiSetModel(AccessModel::LISTS);
    $headers = shareApiHeadersFor($listedNonOwner);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.revoke', $share->id))
        ->assertStatus(403);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reauthorize', $share->id))
        ->assertStatus(403);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $share->id), ['name' => 'renamed'])
        ->assertStatus(403);
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.shares.destroy', $share->id))
        ->assertStatus(403);

    $this->assertDatabaseHas('dam_shares', ['id' => $share->id, 'revoked_at' => null, 'name' => null]);
});

it('lets the creator, the owner, and a full admin mutate a link over the API under lists', function () {
    $owner = shareApiCustomAdmin();
    $creator = shareApiCustomAdmin();
    $fullAdmin = Admin::factory()->create();

    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    // The creator is listed on the file it linked; a creator who cannot see the target gets a 404 (P2 S3).
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $creator->id]);

    shareApiSetModel(AccessModel::LISTS);

    foreach ([
        'the creator'  => $creator,
        'the owner'    => $owner,
        'a full admin' => $fullAdmin,
    ] as $label => $actor) {
        $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
        $headers = shareApiHeadersFor($actor);

        $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.revoke', $share->id))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->withHeaders(shareApiHeadersFor($owner))
        ->deleteJson(route('admin.api.dam.shares.destroy', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);
    $this->assertDatabaseMissing('dam_shares', ['id' => $share->id]);
});

it('lets the creator update, reauthorize, revoke and delete their own link over the API under lists', function () {
    $owner = shareApiCustomAdmin();
    $creator = shareApiCustomAdmin();
    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $creator->id]);

    shareApiSetModel(AccessModel::LISTS);
    $headers = shareApiHeadersFor($creator);

    $updated = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $updated->id), ['name' => 'creator update'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'creator update');
    $this->assertDatabaseHas('dam_shares', ['id' => $updated->id, 'name' => 'creator update']);

    $reauthorized = Share::factory()->forAsset($asset->id)->revoked()->create(['created_by' => $creator->id]);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reauthorize', $reauthorized->id))
        ->assertOk()
        ->assertJsonPath('success', true);
    $this->assertDatabaseHas('dam_shares', ['id' => $reauthorized->id, 'revoked_at' => null]);

    $deleted = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.shares.destroy', $deleted->id))
        ->assertOk()
        ->assertJsonPath('success', true);
    $this->assertDatabaseMissing('dam_shares', ['id' => $deleted->id]);
});

it('applies the same creator/owner/admin rule to directory-target links over the API under lists', function () {
    $owner = shareApiCustomAdmin();
    $creator = shareApiCustomAdmin();
    $listedNonOwner = shareApiCustomAdmin();

    $folder = Directory::create(['name' => 'API directory mutation folder', 'parent_id' => null, 'created_by' => $owner->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $listedNonOwner->id]);

    $share = Share::factory()->forDirectory($folder->id)->create(['created_by' => $creator->id]);

    shareApiSetModel(AccessModel::LISTS);

    // Accessible (listed on the folder) but neither its creator nor its owner: refused on every mutation.
    $strangerHeaders = shareApiHeadersFor($listedNonOwner);
    $this->withHeaders($strangerHeaders)->postJson(route('admin.api.dam.shares.revoke', $share->id))->assertStatus(403);
    $this->withHeaders($strangerHeaders)->putJson(route('admin.api.dam.shares.update', $share->id), ['name' => 'nope'])->assertStatus(403);

    // The owner succeeds.
    $this->withHeaders(shareApiHeadersFor($owner))
        ->postJson(route('admin.api.dam.shares.revoke', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('applies the creator/owner/admin rule over the API under grants too: another admin\'s link is refused, its own and a full admin\'s are not', function () {
    $admin = shareApiCustomAdmin();
    $creator = Admin::factory()->create();
    $asset = Asset::factory()->create();

    // Under grants, the access-list rows do not apply; grant through the legacy directory table so the
    // custom-role admin can reach the asset without being its creator or owner.
    $directory = Directory::create(['name' => 'API grants mutation dir', 'parent_id' => null]);
    $asset->directories()->attach($directory->id);
    DB::table('dam_directory_user')->insert(['directory_id' => $directory->id, 'admin_id' => $admin->id, 'inherit_children' => false]);

    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);
    $own = Share::factory()->forAsset($asset->id)->create(['created_by' => $admin->id]);

    shareApiSetModel(AccessModel::GRANTS);
    $headers = shareApiHeadersFor($admin);

    // R14: target access and the API key no longer let anyone change a link they neither made nor own.
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.revoke', $share->id))->assertStatus(403);
    $this->withHeaders($headers)->putJson(route('admin.api.dam.shares.update', $share->id), ['name' => 'renamed'])->assertStatus(403);
    $this->withHeaders($headers)->deleteJson(route('admin.api.dam.shares.destroy', $share->id))->assertStatus(403);
    $this->assertDatabaseHas('dam_shares', ['id' => $share->id, 'revoked_at' => null, 'name' => null]);

    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.revoke', $own->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->withHeaders(shareApiHeadersFor(Admin::factory()->create()))
        ->deleteJson(route('admin.api.dam.shares.destroy', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('defaults an API-created link to 30 days, honours an explicit expiry, and makes no expiry only on request', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');

    $admin = Admin::factory()->create();
    $asset = Asset::factory()->create(['created_by' => $admin->id]);
    $headers = shareApiHeadersFor($admin);
    $payload = ['share_type' => Share::TYPE_ASSET, 'asset_id' => $asset->id];
    $latest = fn () => Share::query()->where('target_id', $asset->id)->latest('id')->first();

    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), $payload)->assertCreated();
    expect($latest()->expires_at->equalTo(now()->addDays(30)))->toBeTrue();

    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), $payload + ['expires_at' => now()->addDays(3)->toIso8601String()])->assertCreated();
    expect($latest()->expires_at->equalTo(now()->addDays(3)))->toBeTrue();

    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), $payload + ['no_expiry' => true])->assertCreated();
    expect($latest()->expires_at)->toBeNull();

    Carbon::setTestNow();
});

it('re-authorizes over the API with 30 days unless an expiry is named, and validates it first', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');

    $admin = Admin::factory()->create();
    $asset = Asset::factory()->create(['created_by' => $admin->id]);
    $headers = shareApiHeadersFor($admin);
    $share = Share::factory()->forAsset($asset->id)->revoked()->create(['expires_at' => null]);
    $url = route('admin.api.dam.shares.reauthorize', $share->id);

    $this->withHeaders($headers)->postJson($url, ['expires_at' => 'garbage'])->assertUnprocessable();
    expect($share->fresh()->isRevoked())->toBeTrue();

    $this->withHeaders($headers)->postJson($url)->assertOk();
    $fresh = $share->fresh();
    expect($fresh->revoked_at)->toBeNull()->and($fresh->expires_at->equalTo(now()->addDays(30)))->toBeTrue();

    $never = Share::factory()->forAsset($asset->id)->revoked()->create(['expires_at' => now()->subDay()]);
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reauthorize', $never->id), ['no_expiry' => true])->assertOk();
    expect($never->fresh()->expires_at)->toBeNull();

    Carbon::setTestNow();
});

it('shows the capability URL over the API only to the link creator, the item owner and a full admin', function () {
    $owner = shareApiCustomAdmin();
    $creator = shareApiCustomAdmin();
    $listed = shareApiCustomAdmin();
    $fullAdmin = Admin::factory()->create();

    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert([
        ['asset_id' => $asset->id, 'admin_id' => $listed->id],
        ['asset_id' => $asset->id, 'admin_id' => $creator->id],
    ]);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);

    shareApiSetModel(AccessModel::LISTS);

    foreach ([
        'the creator'   => [$creator, true],
        'the owner'     => [$owner, true],
        'a full admin'  => [$fullAdmin, true],
        'a listed user' => [$listed, false],
    ] as $label => [$actor, $sees]) {
        $headers = shareApiHeadersFor($actor);
        $url = route('dam.share.show', ['token' => $share->urlToken()]);

        $show = $this->withHeaders($headers)->getJson(route('admin.api.dam.shares.show', $share->id))->assertOk();
        expect($show->json('data.token'))->toBe($sees ? $share->urlToken() : null, $label)
            ->and($show->json('data.public_url'))->toBe($sees ? $url : null, $label);

        $row = collect($this->withHeaders($headers)->getJson(route('admin.api.dam.shares.index'))->json('data'))->firstWhere('id', $share->id);

        if ($row !== null) {
            expect($row['token'])->toBe($sees ? $share->urlToken() : null, $label)
                ->and($row['public_url'])->toBe($sees ? $url : null, $label);
        } else {
            // The list is directory-grant scoped, so only a full admin is certain to see this asset's link there.
            expect($actor->is($fullAdmin))->toBeFalse($label);
        }
    }
});
