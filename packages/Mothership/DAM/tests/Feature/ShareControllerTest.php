<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

uses(DatabaseTransactions::class);

beforeEach(function () {
    DB::table('dam_shares')->delete();
    $this->loginAsAdmin();
});

it('lists the shares manage page', function () {
    $this->get(route('admin.dam.shared-links.index'))
        ->assertOk()
        ->assertSeeText(trans('dam::app.admin.dam.share.index.title'));
});

it('creates a share for an asset', function () {
    $asset = Asset::factory()->create();

    $response = $this->post(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $asset->id,
        'expiry_days'           => 7,
        'public_link_confirmed' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('share.share_type', Share::TYPE_ASSET)
        ->assertJsonPath('share.target_id', $asset->id);

    $this->assertDatabaseHas('dam_shares', [
        'share_type' => Share::TYPE_ASSET,
        'target_id'  => $asset->id,
    ]);
});

it('creates a share for a directory', function () {
    $directory = Directory::factory()->create();

    $this->post(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_DIRECTORY,
        'target_id'             => $directory->id,
        'expiry_days'           => 30,
        'public_link_confirmed' => true,
    ])
        ->assertOk()
        ->assertJsonPath('share.share_type', Share::TYPE_DIRECTORY);
});

it('creates a non-expiring share when no_expiry is true', function () {
    $asset = Asset::factory()->create();

    $response = $this->post(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $asset->id,
        'no_expiry'             => 1,
        'public_link_confirmed' => true,
    ]);

    $response->assertOk();
    $share = Share::query()->where('target_id', $asset->id)->first();
    expect($share->expires_at)->toBeNull();
});

it('rejects share creation for a non-existent target', function () {
    $this->post(route('admin.dam.shared-links.store'), [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => 999999,
        'expiry_days'           => 7,
        'public_link_confirmed' => true,
    ])->assertNotFound();
});

it('rejects share creation with invalid share_type', function () {
    $asset = Asset::factory()->create();

    $this->postJson(route('admin.dam.shared-links.store'), [
        'share_type' => 'banana',
        'target_id'  => $asset->id,
    ])->assertUnprocessable();
});

it('revokes a share', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();

    $response = $this->patch(route('admin.dam.shared-links.revoke', $share->id));

    $response->assertOk()->assertJsonPath('success', true);
    expect($share->fresh()->revoked_at)->not->toBeNull();
});

it('returns 404 when revoking a non-existent share', function () {
    $this->patch(route('admin.dam.shared-links.revoke', 999999))
        ->assertNotFound();
});

it('hard-deletes a share', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->create();

    $this->delete(route('admin.dam.shared-links.destroy', $share->id))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($share->fresh())->toBeNull();
    $this->assertDatabaseMissing('dam_shares', ['id' => $share->id]);
});

it('returns 404 when hard-deleting a non-existent share', function () {
    $this->delete(route('admin.dam.shared-links.destroy', 999999))
        ->assertNotFound();
});

it('lists active shares for a target', function () {
    $asset = Asset::factory()->create();
    Share::factory()->forAsset($asset->id)->create();
    Share::factory()->forAsset($asset->id)->revoked()->create();

    $response = $this->get(route('admin.dam.shared-links.active_for_target', [
        'type'     => Share::TYPE_ASSET,
        'targetId' => $asset->id,
    ]));

    $response->assertOk()->assertJsonPath('success', true);
    expect($response->json('shares'))->toHaveCount(1);
});

it('refuses a create or re-authorize without the public-link opt-in', function () {
    $asset = Asset::factory()->create();

    foreach ([[], ['public_link_confirmed' => false], ['public_link_confirmed' => 0]] as $extra) {
        $this->postJson(route('admin.dam.shared-links.store'), ['share_type' => Share::TYPE_ASSET, 'target_id' => $asset->id] + $extra)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('public_link_confirmed');
    }

    expect(Share::query()->where('target_id', $asset->id)->count())->toBe(0);

    $share = Share::factory()->forAsset($asset->id)->revoked()->create();

    $this->patchJson(route('admin.dam.shared-links.reauthorize', $share->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('public_link_confirmed');

    expect($share->fresh()->isRevoked())->toBeTrue();
});

it('defaults a new link to 30 days, honours an explicit expiry, and makes no expiry only on request', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');

    $asset = Asset::factory()->create();
    $payload = ['share_type' => Share::TYPE_ASSET, 'target_id' => $asset->id, 'public_link_confirmed' => true];

    $this->postJson(route('admin.dam.shared-links.store'), $payload)->assertOk();
    $default = Share::query()->where('target_id', $asset->id)->latest('id')->first();
    expect($default->expires_at->equalTo(now()->addDays(30)))->toBeTrue();

    $this->postJson(route('admin.dam.shared-links.store'), $payload + ['expires_at' => now()->addDays(7)->toIso8601String()])->assertOk();
    $explicit = Share::query()->where('target_id', $asset->id)->latest('id')->first();
    expect($explicit->expires_at->equalTo(now()->addDays(7)))->toBeTrue();

    $this->postJson(route('admin.dam.shared-links.store'), $payload + ['no_expiry' => true])->assertOk();
    expect(Share::query()->where('target_id', $asset->id)->latest('id')->first()->expires_at)->toBeNull();

    Carbon::setTestNow();
});

it('stores an explicit expiry given with any UTC offset as the same instant, on create and re-authorize (PERM-12)', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');

    $asset = Asset::factory()->create();
    $payload = ['share_type' => Share::TYPE_ASSET, 'target_id' => $asset->id, 'public_link_confirmed' => true];

    // The same moment named from two zones far from the app's own: the link must close at that moment, not hours
    // early or late because the offset was dropped.
    foreach (['America/New_York', 'Pacific/Kiritimati'] as $zone) {
        $at = now()->addDays(3)->setTimezone($zone);

        $this->postJson(route('admin.dam.shared-links.store'), $payload + ['expires_at' => $at->toIso8601String()])->assertOk();
        $share = Share::query()->where('target_id', $asset->id)->latest('id')->first();
        expect($share->fresh()->expires_at->equalTo($at))->toBeTrue();

        $share->forceFill(['revoked_at' => now()])->save();
        $this->patchJson(route('admin.dam.shared-links.reauthorize', $share->id), ['public_link_confirmed' => true, 'expires_at' => $at->toIso8601String()])->assertOk();
        expect($share->fresh()->expires_at->equalTo($at))->toBeTrue();
    }

    Carbon::setTestNow();
});

it('gives a re-authorized expired link 30 days unless an expiry is named', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');

    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->revoked()->create(['expires_at' => now()->subDay()]);

    $this->patchJson(route('admin.dam.shared-links.reauthorize', $share->id), ['public_link_confirmed' => true])->assertOk();

    $fresh = $share->fresh();
    expect($fresh->isRevoked())->toBeFalse()
        ->and($fresh->expires_at->equalTo(now()->addDays(30)))->toBeTrue();

    $never = Share::factory()->forAsset($asset->id)->revoked()->create(['expires_at' => now()->subDay()]);
    $this->patchJson(route('admin.dam.shared-links.reauthorize', $never->id), ['public_link_confirmed' => true, 'no_expiry' => true])->assertOk();
    expect($never->fresh()->expires_at)->toBeNull();

    Carbon::setTestNow();
});

it('shows the token only to the link creator, the item owner and a full admin', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);
    [$owner, $creator, $viewer] = collect(range(1, 3))->map(fn () => Admin::factory()->create(['role_id' => $role->id, 'status' => 1]))->all();

    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    $asset = Asset::factory()->create(['created_by' => $owner->id]);
    DB::table('dam_asset_user_access')->insert([
        ['asset_id' => $asset->id, 'admin_id' => $creator->id],
        ['asset_id' => $asset->id, 'admin_id' => $viewer->id],
    ]);
    $share = Share::factory()->forAsset($asset->id)->create(['created_by' => $creator->id]);

    $read = fn (Admin $actor) => $this->actingAs($actor, 'admin')
        ->getJson(route('admin.dam.shared-links.active_for_target', ['type' => Share::TYPE_ASSET, 'targetId' => $asset->id]))
        ->assertOk()->json('shares.0');

    foreach ([$creator, $owner, Admin::factory()->create()] as $allowed) {
        expect($read($allowed)['token'])->toBe($share->urlToken())->and($read($allowed)['public_url'])->toContain($share->urlToken());
    }

    $hidden = $read($viewer);
    expect($hidden['id'])->toBe($share->id)
        ->and($hidden['token'])->toBeNull()
        ->and($hidden['public_url'])->toBeNull()
        ->and($hidden['status'])->toBe('active');
});

it('refuses a re-authorize with a malformed or past expiry and leaves the link untouched', function () {
    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->revoked()->create(['expires_at' => null]);
    $url = route('admin.dam.shared-links.reauthorize', $share->id);

    foreach ([['expires_at' => 'not-a-date'], ['expires_at' => now()->subDay()->toIso8601String()], ['expiry_days' => 'abc'], ['no_expiry' => 'maybe']] as $bad) {
        $this->patchJson($url, ['public_link_confirmed' => true] + $bad)->assertUnprocessable();
    }

    $fresh = $share->fresh();
    expect($fresh->isRevoked())->toBeTrue()->and($fresh->expires_at)->toBeNull();
});

it('re-authorizes with 30 days even when the old link had no expiry, in one write', function () {
    Carbon::setTestNow('2026-10-04 12:00:00');

    $asset = Asset::factory()->create();
    $share = Share::factory()->forAsset($asset->id)->revoked()->create(['expires_at' => null]);

    $this->patchJson(route('admin.dam.shared-links.reauthorize', $share->id), ['public_link_confirmed' => true])
        ->assertOk()
        ->assertJsonPath('share.expires_at', now()->addDays(30)->toIso8601String());

    $fresh = $share->fresh();
    expect($fresh->revoked_at)->toBeNull()->and($fresh->expires_at->equalTo(now()->addDays(30)))->toBeTrue();

    Carbon::setTestNow();
});

it('needs the opt-in to extend a link that no longer opens, or to make any link permanent', function () {
    $asset = Asset::factory()->create();
    $expired = Share::factory()->forAsset($asset->id)->create(['expires_at' => now()->subDay()]);
    $active = Share::factory()->forAsset($asset->id)->create(['expires_at' => now()->addDays(3)]);

    // Extending an expired link, or setting no expiry, without the opt-in changes nothing.
    $this->patchJson(route('admin.dam.shared-links.update', $expired->id), ['expiry_days' => 30])->assertStatus(422);
    $this->patchJson(route('admin.dam.shared-links.update', $active->id), ['no_expiry' => 1])->assertStatus(422);
    expect($expired->fresh()->isExpired())->toBeTrue()->and($active->fresh()->expires_at)->not->toBeNull();

    // A rename, or a shorter or longer expiry on a live link, needs no opt-in.
    $this->patchJson(route('admin.dam.shared-links.update', $active->id), ['name' => 'Renamed', 'expiry_days' => 7])->assertOk();
    $this->patchJson(route('admin.dam.shared-links.update', $expired->id), ['name' => 'Still expired'])->assertOk();

    // With the opt-in both go through.
    $this->patchJson(route('admin.dam.shared-links.update', $expired->id), ['expiry_days' => 30, 'public_link_confirmed' => true])->assertOk();
    $this->patchJson(route('admin.dam.shared-links.update', $active->id), ['no_expiry' => 1, 'public_link_confirmed' => true])->assertOk();
    expect($expired->fresh()->isActive())->toBeTrue()->and($active->fresh()->expires_at)->toBeNull();
});
