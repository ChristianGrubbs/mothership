<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;

require_once __DIR__.'/../Support/CollectionTestHelpers.php';

/*
 * P4 D2/D23 over REST: the same rules as the web, through the same service. CRUD, members (add, remove, reorder),
 * audience, link and slug; a collection the caller does not open answers 404 like a missing one, an audience member
 * changing it 403, and the link URL only to the #126 three.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    p4cUseModel(AccessModel::LISTS);

    $this->owner = p4cMember(P4C_OWNER_KEYS);
    $this->audience = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);
    $this->first = p4cImage($this->owner, name: 'first');
    $this->second = p4cImage($this->owner, name: 'second');
});

it('creates, reads, updates and deletes a collection, with members, audience, link and slug, as the web does', function () {
    $headers = p4cApiHeaders($this->owner);
    $api = fn (string $method, string $route, array $params = [], array $body = []) => $this->withHeaders($headers)->json($method, route($route, $params), $body);
    $slug = 'api-'.bin2hex(random_bytes(3));

    $created = $api('POST', 'admin.api.dam.collections.store', [], ['name' => 'API collection', 'user_ids' => [$this->audience->id], 'slug' => $slug])->assertCreated();
    $id = $created->json('data.id');
    expect($created->json('data.slug'))->toBe($slug)
        ->and($created->json('data.audience_principals.users.0.id'))->toBe($this->audience->id);

    $api('POST', 'admin.api.dam.collections.assets.store', ['id' => $id], ['asset_ids' => [$this->first->id, $this->second->id]])->assertOk()
        ->assertJsonPath('data.added', [$this->first->id, $this->second->id]);
    $api('PUT', 'admin.api.dam.collections.assets.order', ['id' => $id], ['asset_ids' => [$this->second->id, $this->first->id]])->assertOk();
    expect(collect($api('GET', 'admin.api.dam.collections.assets.index', ['id' => $id])->assertOk()->json('data'))->pluck('id')->all())
        ->toBe([$this->second->id, $this->first->id]);

    // Parity: the same members, in the same order, as the web data the page reads.
    $web = $this->actingAs($this->owner, 'admin')->getJson(route('admin.dam.collections.data', $id))->assertOk();
    expect(collect($web->json('members'))->pluck('id')->all())->toBe([$this->second->id, $this->first->id]);

    $api('DELETE', 'admin.api.dam.collections.assets.destroy', ['id' => $id, 'assetId' => $this->second->id])->assertOk();
    $api('DELETE', 'admin.api.dam.collections.assets.destroy', ['id' => $id, 'assetId' => $this->second->id])->assertNotFound();

    $api('PUT', 'admin.api.dam.collections.audience', ['id' => $id], ['user_ids' => [], 'group_ids' => []])->assertOk()
        ->assertJsonPath('data.audience_principals.users', []);
    $api('PUT', 'admin.api.dam.collections.update', ['id' => $id], ['name' => 'Renamed', 'audience' => 'public', 'slug' => $slug.'-b'])->assertOk()
        ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.slug', $slug.'-b');

    $link = $api('POST', 'admin.api.dam.collections.link.store', ['id' => $id], ['allow_download' => false])->assertCreated();
    expect($link->json('data.token'))->toBeString()
        ->and($link->json('data.allow_download'))->toBeFalse()
        ->and($link->json('data'))->not->toHaveKey('reissue_url');
    $api('POST', 'admin.api.dam.collections.link.store', ['id' => $id])->assertStatus(422);
    expect($api('GET', 'admin.api.dam.collections.show', ['id' => $id])->assertOk()->json('data.link.token'))->toBe($link->json('data.token'));

    // The link is changed through the share endpoints, which the collection's manager reaches.
    $this->withHeaders($headers)->postJson(route('admin.api.dam.shares.reissue', $link->json('data.id')))->assertOk();

    $api('PUT', 'admin.api.dam.collections.update', ['id' => $id], ['slug' => 'admin'])->assertStatus(422)->assertJsonValidationErrors('slug');

    expect(collect($api('GET', 'admin.api.dam.collections.index')->assertOk()->json('data.managed'))->pluck('id')->all())->toContain($id);

    $api('DELETE', 'admin.api.dam.collections.destroy', ['id' => $id])->assertOk();
    $api('GET', 'admin.api.dam.collections.show', ['id' => $id])->assertNotFound();
    expect(DB::table('dam_collection_assets')->where('collection_id', $id)->count())->toBe(0)
        ->and(DB::table('dam_shares')->where('share_type', 'collection')->where('target_id', $id)->count())->toBe(0);
});

it('answers a stranger 404 like a missing collection, an audience member 403 on changes, and refuses redistribution', function () {
    $collection = p4cCollection($this->owner, ['user_ids' => [$this->audience->id]]);
    p4cAdd($collection, $this->owner, [$this->first]);
    $missing = AssetCollection::query()->max('id') + 1000;
    $stranger = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);

    $calls = [
        'show'    => fn (int $id) => ['GET', route('admin.api.dam.collections.show', $id), []],
        'members' => fn (int $id) => ['GET', route('admin.api.dam.collections.assets.index', $id), []],
        'update'  => fn (int $id) => ['PUT', route('admin.api.dam.collections.update', $id), ['name' => 'x']],
        'add'     => fn (int $id) => ['POST', route('admin.api.dam.collections.assets.store', $id), ['asset_ids' => [$this->second->id]]],
        'order'   => fn (int $id) => ['PUT', route('admin.api.dam.collections.assets.order', $id), ['asset_ids' => [$this->first->id]]],
        'remove'  => fn (int $id) => ['DELETE', route('admin.api.dam.collections.assets.destroy', ['id' => $id, 'assetId' => $this->first->id]), []],
        'link'    => fn (int $id) => ['POST', route('admin.api.dam.collections.link.store', $id), []],
        'delete'  => fn (int $id) => ['DELETE', route('admin.api.dam.collections.destroy', $id), []],
    ];

    $headers = p4cApiHeaders($stranger);
    foreach ($calls as $name => $call) {
        [$method, $url, $body] = $call($collection->id);
        [, $missingUrl] = $call($missing);
        $hidden = $this->withHeaders($headers)->json($method, $url, $body);
        $absent = $this->withHeaders($headers)->json($method, $missingUrl, $body);
        expect($hidden->getStatusCode())->toBe(404, $name)->and($hidden->getContent())->toBe($absent->getContent(), $name);
    }

    $headers = p4cApiHeaders($this->audience);
    foreach (['show' => 200, 'members' => 200, 'update' => 403, 'add' => 403, 'order' => 403, 'remove' => 403, 'link' => 403, 'delete' => 403] as $name => $status) {
        [$method, $url, $body] = $calls[$name]($collection->id);
        expect($this->withHeaders($headers)->json($method, $url, $body)->getStatusCode())->toBe($status, $name);
    }
    $shown = $this->withHeaders($headers)->getJson(route('admin.api.dam.collections.show', $collection->id));
    expect($shown->json('data'))->not->toHaveKey('link')->not->toHaveKey('audience_principals')
        ->and(collect($this->withHeaders($headers)->getJson(route('admin.api.dam.collections.index'))->json('data.shared'))->pluck('id')->all())->toBe([$collection->id]);

    // Redistribution: a file the caller sees but neither owns nor may share is 403; one they cannot see is 404.
    $sees = p4cImage($stranger, name: 'theirs');
    DB::table('dam_asset_user_access')->insert(['asset_id' => $sees->id, 'admin_id' => $this->owner->id]);
    app(DirectoryPermissionService::class)->flush();
    $hiddenAsset = p4cImage($stranger, name: 'hidden');
    $headers = p4cApiHeaders($this->owner);
    $add = fn (array $ids) => $this->withHeaders($headers)->postJson(route('admin.api.dam.collections.assets.store', $collection->id), ['asset_ids' => $ids]);

    $add([$sees->id])->assertForbidden();
    $hidden = $add([$hiddenAsset->id]);
    $absent = $add([Asset::withTrashed()->max('id') + 1000]);
    expect($hidden->getStatusCode())->toBe(404)->and($hidden->getContent())->toBe($absent->getContent());
});

it('serves a closed collection to its audience as 410 over REST too, and never lists an unservable member', function () {
    $collection = p4cCollection($this->owner, ['user_ids' => [$this->audience->id]]);
    $pending = p4cImage($this->owner, ['availability' => Asset::AVAILABILITY_PENDING_REVIEW], name: 'pending');
    p4cAdd($collection, $this->owner, [$this->first, $pending]);
    $headers = p4cApiHeaders($this->audience);

    $members = $this->withHeaders($headers)->getJson(route('admin.api.dam.collections.assets.index', $collection->id))->assertOk();
    expect(collect($members->json('data'))->pluck('id')->all())->toBe([$this->first->id])
        ->and($this->withHeaders($headers)->getJson(route('admin.api.dam.collections.show', $collection->id))->json('data.member_count'))->toBe(1);

    $collection->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->withHeaders($headers)->getJson(route('admin.api.dam.collections.show', $collection->id))->assertStatus(410);
    $this->withHeaders($headers)->getJson(route('admin.api.dam.collections.assets.index', $collection->id))->assertStatus(410);
});
