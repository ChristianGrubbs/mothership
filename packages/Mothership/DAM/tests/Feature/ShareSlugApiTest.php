<?php

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;

require_once __DIR__.'/../Support/CollectionTestHelpers.php';

/*
 * P4 D23 over REST: the share endpoints take and show a link's custom slug as the web dialog does, under the same
 * rules (unique across shares and collections, reserved words, only for whom the token is shown), and the slug check
 * answers the same. A refused slug creates no link; reissue retires the slug.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    p4cUseModel(AccessModel::LISTS);

    $this->owner = p4cMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.share']);
    $this->asset = p4cImage($this->owner, name: 'rest');
    $this->slug = 'rest-'.bin2hex(random_bytes(3));
});

it('creates, shows, lists, renames and clears a link\'s slug over REST, as the web does', function () {
    $headers = p4cApiHeaders($this->owner);
    $api = fn (string $method, string $route, array $params = [], array $body = []) => $this->withHeaders($headers)->json($method, route($route, $params), $body);

    $created = $api('POST', 'admin.api.dam.shares.store', [], ['share_type' => Share::TYPE_ASSET, 'asset_id' => $this->asset->id, 'slug' => strtoupper($this->slug)])
        ->assertCreated()
        ->assertJsonPath('data.slug', $this->slug)
        ->assertJsonPath('data.slug_url', route('dam.share.slug', ['slug' => $this->slug]))
        ->assertJsonPath('data.share_url', route('dam.share.slug', ['slug' => $this->slug]));
    $id = $created->json('data.id');
    $token = $created->json('data.token');

    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertRedirect(route('dam.share.show', ['token' => $token]));

    $api('GET', 'admin.api.dam.shares.show', ['id' => $id])->assertOk()->assertJsonPath('data.slug', $this->slug);
    // The listing, read by a full admin (who sees every link and is shown every token).
    $listing = $this->withHeaders(p4cApiHeaders(p4cFullAdmin()))->getJson(route('admin.api.dam.shares.index', ['limit' => 100]))->assertOk();
    $listed = collect($listing->json('data'))->first(fn ($row) => (int) $row['id'] === (int) $id);
    expect($listed['slug'])->toBe($this->slug)->and($listed['share_url'])->toBe(route('dam.share.slug', ['slug' => $this->slug]));

    // Web parity: the dialog's data for the same link carries the same slug.
    $this->actingAs($this->owner, 'admin')->getJson(route('admin.dam.shared-links.active_for_target', ['type' => 'asset', 'targetId' => $this->asset->id]))
        ->assertOk()->assertJsonPath('shares.0.slug', $this->slug);
    app('auth')->forgetGuards();

    $api('PUT', 'admin.api.dam.shares.update', ['id' => $id], ['slug' => $this->slug.'-b'])->assertOk()->assertJsonPath('data.slug', $this->slug.'-b');
    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertStatus(301);

    // A name-only update leaves the slug alone; null clears it.
    $api('PUT', 'admin.api.dam.shares.update', ['id' => $id], ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.slug', $this->slug.'-b');
    $api('PUT', 'admin.api.dam.shares.update', ['id' => $id], ['slug' => null])->assertOk()->assertJsonPath('data.slug', null)
        ->assertJsonPath('data.share_url', route('dam.share.show', ['token' => $token]));

    // Reissue retires a slug.
    $api('PUT', 'admin.api.dam.shares.update', ['id' => $id], ['slug' => $this->slug])->assertOk();
    $api('POST', 'admin.api.dam.shares.reissue', ['id' => $id])->assertOk()->assertJsonPath('data.slug', null);
    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertNotFound();
});

it('refuses a taken, reserved or malformed slug over REST, creating nothing, and checks one as the web does', function () {
    $headers = p4cApiHeaders($this->owner);
    $store = fn (array $body) => $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), $body + ['share_type' => Share::TYPE_ASSET, 'asset_id' => $this->asset->id]);

    $store(['slug' => $this->slug])->assertCreated();
    p4cCollection($this->owner, ['slug' => 'col-'.$this->slug]);
    $before = Share::query()->count();

    foreach ([$this->slug, 'col-'.$this->slug, 'admin', 'a_b', 'ab'] as $refused) {
        $store(['slug' => $refused])->assertStatus(422)->assertJsonValidationErrors('slug');
    }
    expect(Share::query()->count())->toBe($before);

    $check = fn (string $slug) => $this->withHeaders($headers)->getJson(route('admin.api.dam.shares.slug_check', ['slug' => $slug]))->assertOk()->json('data');
    foreach (['free-'.$this->slug, $this->slug, 'col-'.$this->slug, 'admin', 'a_b'] as $slug) {
        $web = $this->actingAs($this->owner, 'admin')->getJson(route('admin.dam.shared-links.slug_check', ['slug' => $slug]))->assertOk()->json();
        app('auth')->forgetGuards();
        expect($check($slug))->toBe($web, $slug);
    }
    expect($check('free-'.$this->slug)['available'])->toBeTrue()->and($check($this->slug)['problem'])->toBe('taken');
});

it('shows and sets a slug only for whom the token is shown, over REST too', function () {
    $headers = p4cApiHeaders($this->owner);
    $id = $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.store'), ['share_type' => Share::TYPE_ASSET, 'asset_id' => $this->asset->id, 'slug' => $this->slug])
        ->assertCreated()->json('data.id');

    // A full admin is shown it; a stranger gets the link answered like a missing one.
    $admin = p4cApiHeaders(p4cFullAdmin());
    $this->withHeaders($admin)->getJson(route('admin.api.dam.shares.show', $id))->assertOk()->assertJsonPath('data.slug', $this->slug);

    $stranger = p4cApiHeaders(p4cMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.share']));
    $this->withHeaders($stranger)->getJson(route('admin.api.dam.shares.show', $id))->assertNotFound();
    $this->withHeaders($stranger)->putJson(route('admin.api.dam.shares.update', $id), ['slug' => 'stolen-'.$this->slug])->assertNotFound();
    expect(Share::query()->findOrFail($id)->slug)->toBe($this->slug);
});
