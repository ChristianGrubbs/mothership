<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\DataGrids\Asset\AssetDataGrid;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\CollectionLog;
use Mothership\User\Models\Admin;

require_once __DIR__.'/../Support/CollectionTestHelpers.php';

/*
 * P4 D2: a collection's audience grant is bound to its container (roadmap §3.3). An audience member reads a member
 * only through the collection's own routes; the same asset through the Library, search, zip and REST answers exactly
 * like a missing one. Expiry closes every member read (410), a member that is not out for use is neither served nor
 * counted, removing a member revokes on the next request, and adding one needs the right to redistribute it.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    p4cUseModel(AccessModel::LISTS);

    $this->owner = p4cMember(P4C_OWNER_KEYS);
    $this->member = p4cMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit', 'dam.asset.download', 'dam.asset.download_compressed', 'dam.directory', 'dam.directory.download_zip']);
    $this->folder = Directory::factory()->create(['name' => 'P4c folder '.bin2hex(random_bytes(3))]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->member->id]);

    // Private to its owner: the member sees the folder, never this file, outside the collection.
    $this->private = p4cImage($this->owner, name: 'private');
    $this->private->directories()->attach($this->folder->id);
    // Listed for the member, so the Library listings below are shown to be live, not empty by accident.
    $this->listed = p4cImage($this->owner, name: 'listed');
    $this->listed->directories()->attach($this->folder->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->listed->id, 'admin_id' => $this->member->id]);
    app(DirectoryPermissionService::class)->flush();

    $this->collection = p4cCollection($this->owner, ['user_ids' => [$this->member->id]]);
    p4cAdd($this->collection, $this->owner, [$this->private]);
});

/** The collection-scoped member routes for one asset, by route name. */
function p4cMemberRoutes(AssetCollection $collection, int $assetId): array
{
    $params = ['id' => $collection->id, 'assetId' => $assetId];

    return [
        'admin.dam.collections.members.thumbnail' => route('admin.dam.collections.members.thumbnail', $params),
        'admin.dam.collections.members.preview'   => route('admin.dam.collections.members.preview', $params),
        'admin.dam.collections.members.download'  => route('admin.dam.collections.members.download', $params),
    ];
}

it('serves an audience member a member only through the collection, and answers 404 for it through the Library, search, zip and REST', function () {
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like']);
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[1, 0]], 'model' => 'm'])]);

    foreach ([$this->private, $this->listed] as $asset) {
        DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'lexical_text' => 'falcon mascot', 'caption' => 'falcon mascot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode([1, 0]), 'text' => 'Mascot booking', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    }

    $headers = p4cApiHeaders($this->member);
    $this->actingAs($this->member, 'admin');
    $missingId = Asset::withTrashed()->max('id') + 1000;

    // Through the collection: the page, its data, every member byte route and the zip.
    $this->get(route('admin.dam.collections.show', $this->collection->id))->assertOk();
    expect(collect($this->getJson(route('admin.dam.collections.data', $this->collection->id))->assertOk()->json('members'))->pluck('id')->all())
        ->toBe([$this->private->id]);

    foreach (p4cMemberRoutes($this->collection, $this->private->id) as $name => $url) {
        $this->get($url)->assertOk();
    }

    $zip = p4cZipEntries(p4cBody($this->get(route('admin.dam.collections.zip', $this->collection->id))->assertOk()));
    expect($zip)->toBe([$this->private->file_name]);

    // Through the Library: the asset page, its download and per-asset zip, its thumbnail, and the folder zip.
    foreach (['admin.dam.assets.edit', 'admin.dam.assets.download', 'admin.dam.assets.download_compressed'] as $route) {
        $hidden = $this->get(route($route, $this->private->id));
        $missing = $this->get(route($route, $missingId));

        expect($hidden->getStatusCode())->toBe(404, $route)
            ->and($hidden->getContent())->toBe($missing->getContent(), $route);
    }

    $thumbnail = $this->get(route('admin.dam.file.thumbnail', ['path' => $this->private->path]), ['Accept' => 'image/*']);
    $nothing = $this->get(route('admin.dam.file.thumbnail', ['path' => 'assets/Root/nothing-here.png']), ['Accept' => 'image/*']);
    expect($thumbnail->getStatusCode())->toBe(404)->and($thumbnail->getContent())->toBe($nothing->getContent());

    $folderZip = p4cZipEntries(p4cBody($this->get(route('admin.dam.directory.zip_download', $this->folder->id))->assertOk()));
    expect(array_map('basename', $folderZip))->toBe([$this->listed->file_name]);

    // The Library listing, keyword search and semantic search.
    $listing = fn (array $params) => collect($this->getJson(route('admin.dam.explorer.index', $params))->assertOk()->json('assets'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($listing(['scope' => 'all']))->toContain($this->listed->id)->not->toContain($this->private->id)
        ->and($listing(['scope' => 'all', 'search' => 'mascot']))->toBe([$this->listed->id])
        ->and($listing(['scope' => 'all', 'search' => 'zebra crossing']))->toBe([$this->listed->id]);

    // REST: show, download and the asset index.
    foreach (['admin.api.dam.assets.show', 'admin.api.dam.assets.download'] as $route) {
        $hidden = $this->withHeaders($headers)->getJson(route($route, $this->private->id));
        $missing = $this->withHeaders($headers)->getJson(route($route, $missingId));

        expect($hidden->getStatusCode())->toBe(404, $route)
            ->and($hidden->getContent())->toBe($missing->getContent(), $route);
    }

    $restIds = collect($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.index'))->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($restIds)->toContain($this->listed->id)->not->toContain($this->private->id);

    // The seam itself: the grant is honoured only for `collection:<id>`, never for the Library or REST contexts.
    $seam = app(AssetAccessDecision::class);
    expect($seam->for(AccessContext::collection(collectionId: $this->collection->id, viewer: $this->member), $this->private->id)->allowed)->toBeTrue()
        ->and($seam->for(AccessContext::forAdmin($this->member), $this->private->id)->visible)->toBeFalse()
        ->and($seam->for(AccessContext::forAdmin($this->member, via: AccessContext::VIA_API), $this->private->id)->visible)->toBeFalse()
        ->and($seam->visible(AccessContext::forAdmin($this->member), $this->private->id))->toBeFalse();
});

it('answers a collection the viewer is not in, and an asset that is not a member, like missing ones', function () {
    $stranger = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);
    $missingCollection = AssetCollection::query()->max('id') + 1000;
    $missingAsset = Asset::withTrashed()->max('id') + 1000;

    // A stranger: the collection is a 404 everywhere, the same body as a collection that does not exist.
    $this->actingAs($stranger, 'admin');
    foreach (['admin.dam.collections.show', 'admin.dam.collections.data', 'admin.dam.collections.zip'] as $route) {
        $hidden = $this->get(route($route, $this->collection->id));
        $missing = $this->get(route($route, $missingCollection));
        expect($hidden->getStatusCode())->toBe(404, $route)->and($hidden->getContent())->toBe($missing->getContent(), $route);
    }
    foreach (p4cMemberRoutes($this->collection, $this->private->id) as $name => $url) {
        expect($this->get($url)->getStatusCode())->toBe(404, $name);
    }
    $proxy = $this->get(route('admin.dam.collections.members.proxy', ['id' => $this->collection->id, 'assetId' => $this->private->id]));
    $proxyMissing = $this->get(route('admin.dam.collections.members.proxy', ['id' => $missingCollection, 'assetId' => $this->private->id]));
    expect($proxy->getStatusCode())->toBe(404)->and($proxy->getContent())->toBe($proxyMissing->getContent());
    expect(collect($this->getJson(route('admin.dam.collections.list'))->assertOk()->json('shared'))->pluck('id')->all())->toBe([]);

    // The audience member: an asset outside the collection (even one they can open in the Library) is not served.
    $this->actingAs($this->member, 'admin');
    foreach (['listed' => $this->listed->id, 'missing' => $missingAsset] as $which => $id) {
        foreach (p4cMemberRoutes($this->collection, $id) as $name => $url) {
            expect($this->get($url)->getStatusCode())->toBe(404, "{$which} {$name}");
        }
    }
    expect(collect($this->getJson(route('admin.dam.collections.list'))->assertOk()->json('shared'))->pluck('id')->all())->toBe([$this->collection->id]);
});

it('reaches an audience through a group and Everyone, and stops at once when the audience no longer names them', function () {
    $other = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);
    $groupId = DB::table('dam_groups')->insertGetId(['name' => 'P4c group '.bin2hex(random_bytes(3)), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $groupId, 'admin_id' => $other->id]);
    $preview = route('admin.dam.collections.members.preview', ['id' => $this->collection->id, 'assetId' => $this->private->id]);

    $this->actingAs($other, 'admin');
    $this->get($preview)->assertNotFound();

    app(CollectionService::class)->setAudience(collection: $this->collection, actor: $this->owner, userIds: [], groupIds: [$groupId]);
    $this->get($preview)->assertOk();

    $everyone = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
    app(CollectionService::class)->setAudience(collection: $this->collection, actor: $this->owner, userIds: [], groupIds: [$everyone]);
    $this->actingAs($this->member, 'admin');
    $this->get($preview)->assertOk();

    app(CollectionService::class)->setAudience(collection: $this->collection, actor: $this->owner, userIds: [], groupIds: []);
    $this->get($preview)->assertNotFound();
});

it('answers every member read with 410 once the collection has expired or is archived, until it is live again', function () {
    $this->actingAs($this->member, 'admin');
    $reads = p4cMemberRoutes($this->collection, $this->private->id) + [
        'admin.dam.collections.zip'  => route('admin.dam.collections.zip', $this->collection->id),
        'admin.dam.collections.data' => route('admin.dam.collections.data', $this->collection->id),
        'admin.dam.collections.show' => route('admin.dam.collections.show', $this->collection->id),
    ];

    foreach ($reads as $name => $url) {
        $this->get($url)->assertOk();
    }

    foreach (['expired' => ['expires_at' => now()->subMinute()], 'archived' => ['status' => AssetCollection::STATUS_ARCHIVED, 'expires_at' => null]] as $state => $change) {
        $this->collection->forceFill($change)->save();

        foreach ($reads as $name => $url) {
            expect($this->get($url)->getStatusCode())->toBe(410, "{$state} {$name}");
        }

        // Nothing through the seam either, and nothing in "Shared with me".
        expect(app(AssetAccessDecision::class)->for(AccessContext::collection(collectionId: $this->collection->id, viewer: $this->member), $this->private->id)->visible)->toBeFalse($state)
            ->and(collect($this->getJson(route('admin.dam.collections.list'))->json('shared'))->pluck('id')->all())->toBe([], $state);
    }

    $this->collection->forceFill(['status' => AssetCollection::STATUS_ACTIVE, 'expires_at' => now()->addDay()])->save();
    foreach ($reads as $name => $url) {
        $this->get($url)->assertOk();
    }

    // Its manager still opens the page and its data to extend it, though the member files stay closed.
    $this->collection->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->actingAs($this->owner, 'admin');
    $this->get(route('admin.dam.collections.show', $this->collection->id))->assertOk();
    $this->getJson(route('admin.dam.collections.data', $this->collection->id))->assertOk()->assertJsonPath('collection.state', 'expired');
    $this->get(route('admin.dam.collections.members.preview', ['id' => $this->collection->id, 'assetId' => $this->private->id]))->assertStatus(410);
});

it('shows a collection manager the real member count of a closed collection, while the served count stays at zero', function () {
    p4cAdd($this->collection, $this->owner, [p4cImage($this->owner, name: 'second')]);
    $this->actingAs($this->owner, 'admin');
    $card = fn () => collect($this->getJson(route('admin.dam.collections.list'))->json('managed'))->firstWhere('id', $this->collection->id);

    expect($card())->toMatchArray(['member_count' => 2, 'total_members' => 2]);

    foreach (['expired' => ['expires_at' => now()->subMinute()], 'archived' => ['status' => AssetCollection::STATUS_ARCHIVED, 'expires_at' => null]] as $state => $change) {
        $this->collection->forceFill($change)->save();

        // Served count: nothing is served from a closed collection. Manager count: the members it still holds.
        expect($card())->toMatchArray(['state' => $state, 'member_count' => 0, 'total_members' => 2], $state);
    }

    // The audience never learns the member rows: no total, and a closed collection is not listed for them at all.
    $this->actingAs($this->member, 'admin');
    expect($this->getJson(route('admin.dam.collections.list'))->json('shared'))->toBe([]);
    $this->collection->forceFill(['status' => AssetCollection::STATUS_ACTIVE, 'expires_at' => null])->save();
    expect(collect($this->getJson(route('admin.dam.collections.list'))->json('shared'))->firstWhere('id', $this->collection->id))->not->toHaveKey('total_members');
});

it('offers the legacy Library grid an Add to collection mass action only to those who may change collections', function () {
    $actions = function (Admin $who) {
        $this->actingAs($who, 'admin');
        $grid = app(AssetDataGrid::class);
        $grid->prepareMassActions();

        return collect($grid->getMassActions())->pluck('options.actionType')->all();
    };

    expect($actions($this->owner))->toContain('add-to-collection')
        ->and($actions($this->member))->not->toContain('add-to-collection');
});

it('neither serves nor counts a withdrawn, pending, expired, scheduled, trashed or merged member', function () {
    $states = [
        'draft'     => ['availability' => Asset::AVAILABILITY_DRAFT],
        'pending'   => ['availability' => Asset::AVAILABILITY_PENDING_REVIEW],
        'withdrawn' => ['availability' => Asset::AVAILABILITY_ARCHIVED],
        'expired'   => ['available_until' => now()->subDay()],
        'scheduled' => ['available_from' => now()->addDay()],
    ];
    $members = [];
    foreach ($states as $state => $attributes) {
        $members[$state] = p4cImage($this->owner, $attributes, name: $state);
    }
    $members['trashed'] = p4cImage($this->owner, name: 'trashed');
    $members['merged'] = p4cImage($this->owner, name: 'merged');
    p4cAdd($this->collection, $this->owner, array_values($members));
    $members['trashed']->delete();
    // P3b-1: a merge source (merged into a file outside the collection) answers like trash.
    DB::table('dam_assets')->where('id', $members['merged']->id)->update(['merged_into_id' => p4cImage($this->owner, name: 'merge-destination')->id]);

    $this->actingAs($this->member, 'admin');
    $data = $this->getJson(route('admin.dam.collections.data', $this->collection->id))->assertOk();
    expect(collect($data->json('members'))->pluck('id')->all())->toBe([$this->private->id])
        ->and($data->json('collection.member_count'))->toBe(1)
        ->and(collect($this->getJson(route('admin.dam.collections.list'))->json('shared'))->firstWhere('id', $this->collection->id)['member_count'])->toBe(1)
        ->and(p4cZipEntries(p4cBody($this->get(route('admin.dam.collections.zip', $this->collection->id))->assertOk())))->toBe([$this->private->file_name]);

    foreach ($members as $state => $asset) {
        foreach (p4cMemberRoutes($this->collection, $asset->id) as $name => $url) {
            expect($this->get($url)->getStatusCode())->toBe(404, "{$state} {$name}");
        }
    }

    // Strict for the owner too: the Library's owner exemption does not carry into the collection. The manager's list
    // keeps the rows (to remove them) but flags them as not served.
    $this->actingAs($this->owner, 'admin');
    $managed = collect($this->getJson(route('admin.dam.collections.data', $this->collection->id))->assertOk()->json('members'));
    expect($managed->where('servable', true)->pluck('id')->all())->toBe([$this->private->id])
        ->and($managed->where('servable', false)->count())->toBe(7);
    $this->get(route('admin.dam.collections.members.preview', ['id' => $this->collection->id, 'assetId' => $members['draft']->id]))->assertNotFound();
});

it('revokes access through the collection on the next request once a member is removed', function () {
    $this->actingAs($this->member, 'admin');
    $preview = route('admin.dam.collections.members.preview', ['id' => $this->collection->id, 'assetId' => $this->private->id]);
    $this->get($preview)->assertOk();

    $this->actingAs($this->owner, 'admin');
    $this->deleteJson(route('admin.dam.collections.members.destroy', ['id' => $this->collection->id, 'assetId' => $this->private->id]))->assertOk();
    $this->deleteJson(route('admin.dam.collections.members.destroy', ['id' => $this->collection->id, 'assetId' => $this->private->id]))->assertNotFound();

    $this->actingAs($this->member, 'admin');
    $this->get($preview)->assertNotFound();
    expect($this->getJson(route('admin.dam.collections.data', $this->collection->id))->json('members'))->toBe([]);
});

it('lets only someone who may redistribute an asset add it: its owner, a full admin, or a sharer who sees it', function () {
    $sharerKeys = array_merge(P4C_OWNER_KEYS, ['dam.asset.share']);
    $plain = p4cMember(P4C_OWNER_KEYS);
    $sharer = p4cMember($sharerKeys);
    $full = p4cFullAdmin();
    $missingId = Asset::withTrashed()->max('id') + 1000;
    // Listed for both, so each can see it; neither owns it.
    $shared = p4cImage($this->owner, name: 'shared');
    DB::table('dam_asset_user_access')->insert([['asset_id' => $shared->id, 'admin_id' => $plain->id], ['asset_id' => $shared->id, 'admin_id' => $sharer->id]]);
    app(DirectoryPermissionService::class)->flush();

    $add = fn (Admin $actor, AssetCollection $collection, array $ids) => $this->actingAs($actor, 'admin')
        ->postJson(route('admin.dam.collections.members.store', $collection->id), ['asset_ids' => $ids]);

    // A non-owner without dam.asset.share: 403 for an asset they see, and nothing is added, not even their own.
    $plainCollection = p4cCollection($plain);
    $own = p4cImage($plain, name: 'own');
    $add($plain, $plainCollection, [$own->id, $shared->id])->assertForbidden();
    expect(DB::table('dam_collection_assets')->where('collection_id', $plainCollection->id)->count())->toBe(0);

    // A hidden asset answers exactly like a missing one, before the permission is asked.
    $hidden = $add($plain, $plainCollection, [$this->private->id]);
    $missing = $add($plain, $plainCollection, [$missingId]);
    expect($hidden->getStatusCode())->toBe(404)->and($hidden->getContent())->toBe($missing->getContent());

    // Its owner without the key, a sharer who sees it, and a full admin may.
    $add($plain, $plainCollection, [$own->id])->assertOk()->assertJsonPath('added', [$own->id]);
    $add($sharer, p4cCollection($sharer), [$shared->id])->assertOk()->assertJsonPath('added', [$shared->id]);
    $add($full, p4cCollection($full), [$this->private->id])->assertOk();

    // A sharer still cannot add what they cannot see.
    $add($sharer, p4cCollection($sharer), [$this->private->id])->assertNotFound();

    // Changing someone else's collection: an audience member gets 403, a stranger the 404 of a missing one.
    $add($this->member, $this->collection, [$this->listed->id])->assertForbidden();
    $add($sharer, $this->collection, [$shared->id])->assertNotFound();
});

it('keeps positions in the order given and refuses a reorder that does not name exactly the members', function () {
    $second = p4cImage($this->owner, name: 'second');
    $third = p4cImage($this->owner, name: 'third');
    p4cAdd($this->collection, $this->owner, [$second, $third, $this->private]);
    $this->actingAs($this->owner, 'admin');
    $order = fn () => collect($this->getJson(route('admin.dam.collections.data', $this->collection->id))->json('members'))->pluck('id')->all();

    expect($order())->toBe([$this->private->id, $second->id, $third->id]);

    $this->putJson(route('admin.dam.collections.members.order', $this->collection->id), ['asset_ids' => [$third->id, $this->private->id, $second->id]])->assertOk();
    expect($order())->toBe([$third->id, $this->private->id, $second->id]);

    $this->putJson(route('admin.dam.collections.members.order', $this->collection->id), ['asset_ids' => [$third->id, $second->id]])->assertStatus(422);
    $this->putJson(route('admin.dam.collections.members.order', $this->collection->id), ['asset_ids' => [$third->id, $third->id, $second->id]])->assertStatus(422);
    expect($order())->toBe([$third->id, $this->private->id, $second->id]);

    // The audience sees the same order; an audience member cannot change it.
    $this->actingAs($this->member, 'admin');
    expect($order())->toBe([$third->id, $this->private->id, $second->id]);
    $this->putJson(route('admin.dam.collections.members.order', $this->collection->id), ['asset_ids' => [$second->id, $third->id, $this->private->id]])->assertForbidden();
});

it('never adds a member twice, and appends each add after the last position', function () {
    $more = collect(range(1, 3))->map(fn ($i) => p4cImage($this->owner, name: "more{$i}"));
    $service = app(CollectionService::class);

    $first = $service->addMembers(collection: $this->collection, actor: $this->owner, assetIds: [$more[0]->id, $this->private->id, $more[0]->id]);
    $second = $service->addMembers(collection: $this->collection, actor: $this->owner, assetIds: [$more[1]->id, $more[2]->id]);

    expect($first)->toBe(['added' => [$more[0]->id], 'already' => [$this->private->id]])
        ->and($second['added'])->toBe([$more[1]->id, $more[2]->id])
        ->and(DB::table('dam_collection_assets')->where('collection_id', $this->collection->id)->pluck('position')->sort()->values()->all())->toBe([0, 1, 2, 3]);
});

it('logs adds, removals, audience changes and visits as JSONL by id only, never names', function () {
    config(['dam.collection_logging' => true]);
    $path = storage_path(CollectionLog::PATH);
    $before = is_file($path) ? strlen((string) file_get_contents($path)) : 0;
    $extra = p4cImage($this->owner, name: 'logged');

    $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.collections.members.store', $this->collection->id), ['asset_ids' => [$extra->id]])->assertOk();
    $this->deleteJson(route('admin.dam.collections.members.destroy', ['id' => $this->collection->id, 'assetId' => $extra->id]))->assertOk();
    $this->putJson(route('admin.dam.collections.audience', $this->collection->id), ['user_ids' => [$this->member->id], 'group_ids' => []])->assertOk();
    $this->actingAs($this->member, 'admin')->get(route('admin.dam.collections.show', $this->collection->id))->assertOk();

    $written = substr((string) file_get_contents($path), $before);
    $lines = array_values(array_filter(array_map(fn ($line) => json_decode($line, true), explode(PHP_EOL, $written))));
    $events = array_column($lines, 'event');

    expect($events)->toContain('collection.member.added', 'collection.member.removed', 'collection.audience.changed', 'collection.visit')
        ->and(collect($lines)->firstWhere('event', 'collection.member.added')['ctx']['asset_ids'] ?? null)->toBe([$extra->id])
        ->and(collect($lines)->firstWhere('event', 'collection.visit')['ctx'])->toMatchArray(['collection_id' => $this->collection->id, 'viewer_id' => $this->member->id]);

    foreach ([$this->collection->name, $extra->file_name, $this->member->name, $this->member->email] as $secretish) {
        expect(str_contains($written, $secretish))->toBeFalse($secretish);
    }
    foreach ($lines as $line) {
        expect(array_keys($line))->toBe(['ts', 'run_id', 'level', 'event', 'loc', 'ctx']);
    }
});
