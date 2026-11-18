<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Analytics\EventStore;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Collections\CollectionService;

require_once __DIR__.'/../Support/CollectionTestHelpers.php';

/*
 * P4 D2: a public collection reuses Share (`share_type = collection`) with every S5 rule: a hashed token, the password
 * prompt, download-off, creator access, Reissue and expiry, through ShareGate and EnforceShareGate. The page lists the
 * servable members in position order, and the link closes with the collection (expired, archived, made internal).
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    p4cUseModel(AccessModel::LISTS);

    $this->owner = p4cMember(P4C_OWNER_KEYS);
    $this->first = p4cImage($this->owner, name: 'first');
    $this->second = p4cImage($this->owner, name: 'second');
    $this->collection = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC]);
    p4cAdd($this->collection, $this->owner, [$this->second, $this->first]);
});

/** Creates the collection's link through the web route, as its owner; returns the Share and its URL token. */
function p4cLink(AssetCollection $collection, $owner, array $options = []): array
{
    $created = test()->actingAs($owner, 'admin')
        ->postJson(route('admin.dam.collections.link.store', $collection->id), $options)
        ->assertCreated();

    $token = $created->json('link.token');
    expect($token)->toBeString();

    return [Share::query()->findOrFail($created->json('link.id')), $token, $created];
}

/** Every read route of a collection link for one member, by name. */
function p4cLinkRoutes(string $token, int $assetId): array
{
    return [
        'page'      => route('dam.share.show', $token),
        'list'      => route('dam.share.list_assets', $token),
        'zip'       => route('dam.share.download_zip', $token),
        'thumbnail' => route('dam.share.thumbnail', ['token' => $token, 'assetId' => $assetId]),
        'member'    => route('dam.share.asset_view', ['token' => $token, 'assetId' => $assetId]),
        'preview'   => route('dam.share.asset_preview', ['token' => $token, 'assetId' => $assetId]),
        'download'  => route('dam.share.asset_download', ['token' => $token, 'assetId' => $assetId]),
    ];
}

it('stores only a hash of the link token and shows the servable members in position order, nothing else', function () {
    [$share, $token] = p4cLink($this->collection, $this->owner);
    $row = DB::table('dam_shares')->where('id', $share->id)->first();

    expect($row->share_type)->toBe(Share::TYPE_COLLECTION)
        ->and((int) $row->target_id)->toBe($this->collection->id)
        ->and($row->token)->toBeNull()
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and(Crypt::decryptString($row->token_encrypted))->toBe($token);

    $withdrawn = p4cImage($this->owner, ['availability' => Asset::AVAILABILITY_ARCHIVED], name: 'withdrawn');
    $trashed = p4cImage($this->owner, name: 'trashed');
    p4cAdd($this->collection, $this->owner, [$withdrawn, $trashed]);
    $trashed->delete();
    $outsider = p4cImage($this->owner, name: 'outsider');

    $page = p4cGet(route('dam.share.show', $token))->assertOk()
        ->assertSee($this->first->file_name)->assertSee($this->second->file_name)
        ->assertDontSee($withdrawn->file_name)->assertDontSee($trashed->file_name)->assertDontSee($outsider->file_name);
    expect(strpos($page->getContent(), $this->second->file_name))->toBeLessThan(strpos($page->getContent(), $this->first->file_name));

    expect(collect(p4cGet(route('dam.share.list_assets', $token))->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$this->second->id, $this->first->id])
        ->and(array_map('basename', p4cZipEntries(p4cBody(p4cGet(route('dam.share.download_zip', $token))->assertOk()))))
        ->toBe(collect([$this->first->file_name, $this->second->file_name])->sort()->values()->all());

    foreach (p4cLinkRoutes($token, $this->first->id) as $name => $url) {
        expect(p4cGet($url)->getStatusCode())->toBe(200, $name);
    }

    // A file outside the collection, and one not served, answer exactly like one that does not exist.
    $missingId = Asset::withTrashed()->max('id') + 1000;
    foreach (['member', 'preview', 'download', 'thumbnail'] as $name) {
        $missing = p4cGet(p4cLinkRoutes($token, $missingId)[$name]);
        foreach (['outsider' => $outsider, 'withdrawn' => $withdrawn, 'trashed' => $trashed] as $case => $asset) {
            $hidden = p4cGet(p4cLinkRoutes($token, $asset->id)[$name]);
            expect($hidden->getStatusCode())->toBe($missing->getStatusCode(), "{$case} {$name}")
                ->and($hidden->getStatusCode())->toBe(404, "{$case} {$name}");
        }
    }
});

it('revokes a removed member through the link on the next request', function () {
    [, $token] = p4cLink($this->collection, $this->owner);
    $preview = p4cLinkRoutes($token, $this->first->id)['preview'];
    p4cGet($preview)->assertOk();

    app(CollectionService::class)->removeMember(collection: $this->collection, actor: $this->owner, assetId: $this->first->id);

    p4cGet($preview)->assertNotFound();
    expect(collect(p4cGet(route('dam.share.list_assets', $token))->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$this->second->id]);
});

it('asks for the password on every route of a protected collection link, and opens each with the cookie', function () {
    $password = 'p4c-'.bin2hex(random_bytes(8));
    [$share, $token] = p4cLink($this->collection, $this->owner, ['password' => $password]);

    foreach (p4cLinkRoutes($token, $this->first->id) as $name => $url) {
        $locked = p4cGet($url);
        expect($locked->getStatusCode())->not->toBe(200, $name)
            ->and(str_contains((string) $locked->getContent(), $this->first->file_name))->toBeFalse($name);
    }

    [, $wrong] = p4cUnlock($share, 'not-'.$password);
    expect($wrong)->toBeNull();

    [, $cookie] = p4cUnlock($share, $password);
    expect($cookie)->toBeString();

    foreach (p4cLinkRoutes($token, $this->first->id) as $name => $url) {
        expect(p4cGet($url, $cookie)->getStatusCode())->toBe(200, $name);
    }
});

it('answers 403 on a preview-only collection link\'s downloads and zip, while the page and previews open', function () {
    [$share, $token] = p4cLink($this->collection, $this->owner, ['allow_download' => false]);
    $routes = p4cLinkRoutes($token, $this->first->id);

    foreach (['download', 'zip'] as $name) {
        expect(p4cGet($routes[$name])->getStatusCode())->toBe(403, $name);
    }
    foreach (['page', 'member', 'preview', 'thumbnail'] as $name) {
        expect(p4cGet($routes[$name])->getStatusCode())->toBe(200, $name);
    }

    p4cGet($routes['page'])->assertSee('data-dam-share-preview-only', false)->assertDontSee($routes['zip'], false);
    expect($share->fresh()->download_count)->toBe(0);
});

it('closes a creator-access collection link once its creator can no longer manage the collection', function () {
    $full = p4cFullAdmin();
    [$share, $token] = p4cLink($this->collection, $full, ['requires_creator_access' => true]);
    $page = route('dam.share.show', $token);
    p4cGet($page)->assertOk();

    $full->forceFill(['status' => 0])->save();
    p4cGet($page)->assertStatus(410)->assertSeeText(trans('dam::app.share.public.revoked-title'));

    $full->forceFill(['status' => 1])->save();
    p4cGet($page)->assertOk();

    // The option off (the default): the link outlives its creator's access.
    $other = p4cFullAdmin();
    $open = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC]);
    [, $openToken] = p4cLink($open, $other);
    $other->forceFill(['status' => 0])->save();
    p4cGet(route('dam.share.show', $openToken))->assertOk();
});

it('reissues a collection link: the old address stops at once and the new one opens', function () {
    [$share, $old] = p4cLink($this->collection, $this->owner);
    p4cGet(route('dam.share.show', $old))->assertOk();

    $reissued = $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertOk();
    $new = $reissued->json('share.token');

    expect($new)->toBeString()->not->toBe($old);
    p4cGet(route('dam.share.show', $old))->assertNotFound();
    p4cGet(route('dam.share.show', $new))->assertOk();
});

it('answers every link route like an expired link once the collection expires or is archived, and like a revoked one once made internal', function () {
    [$share, $token] = p4cLink($this->collection, $this->owner);
    $expiredLink = Share::factory()->forAsset($this->first->id)->create(['expires_at' => now()->subDay()]);
    $expected = p4cLinkRoutes($expiredLink->urlToken(), $this->first->id);

    foreach (['expired' => ['expires_at' => now()->subMinute()], 'archived' => ['status' => AssetCollection::STATUS_ARCHIVED, 'expires_at' => null]] as $state => $change) {
        $this->collection->forceFill($change)->save();

        foreach (p4cLinkRoutes($token, $this->first->id) as $name => $url) {
            $actual = p4cGet($url);
            expect($actual->getStatusCode())->toBe(p4cGet($expected[$name] ?? $expected['page'])->getStatusCode(), "{$state} {$name}")
                ->and(str_contains((string) $actual->getContent(), $this->first->file_name))->toBeFalse("{$state} {$name}");
        }
        p4cGet(route('dam.share.show', $token))->assertStatus(410)->assertSeeText(trans('dam::app.share.public.expired-title'));

        $this->collection->forceFill(['status' => AssetCollection::STATUS_ACTIVE, 'expires_at' => null])->save();
        p4cGet(route('dam.share.show', $token))->assertOk();
    }

    // Changing the audience to internal revokes the link for good.
    $this->actingAs($this->owner, 'admin')->patchJson(route('admin.dam.collections.update', $this->collection->id), ['audience' => AssetCollection::AUDIENCE_INTERNAL])->assertOk();
    expect($share->fresh()->revoked_at)->not->toBeNull();
    p4cGet(route('dam.share.show', $token))->assertStatus(410)->assertSeeText(trans('dam::app.share.public.revoked-title'));

    // An internal collection cannot have a link at all.
    $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.collections.link.store', $this->collection->id))->assertStatus(422);
});

it('records one link visit for a rendered collection page, none for a HEAD or an expired collection', function () {
    [$share, $token] = p4cLink($this->collection, $this->owner);
    $visits = fn () => DB::table(EventStore::TABLE)->where('name', 'share.link.visited')->where('share_id', $share->id);

    p4cGet(route('dam.share.show', $token))->assertOk();
    $this->call('HEAD', route('dam.share.show', $token));

    $row = $visits()->sole();
    expect($row->subject_type)->toBe('collection')
        ->and((int) $row->subject_id)->toBe($this->collection->id)
        ->and(json_decode($row->props, true)['share_type'])->toBe(Share::TYPE_COLLECTION);

    $this->collection->forceFill(['expires_at' => now()->subMinute()])->save();
    p4cGet(route('dam.share.show', $token))->assertStatus(410);

    expect($visits()->count())->toBe(1);
});

it('records one link created event for a new collection link, as asset and folder links do', function () {
    [$share] = p4cLink($this->collection, $this->owner);

    $row = DB::table(EventStore::TABLE)->where('name', 'share.link.created')->sole();
    expect($row->subject_type)->toBe('collection')
        ->and((int) $row->subject_id)->toBe($this->collection->id)
        ->and((int) $row->share_id)->toBe($share->id)
        ->and(json_decode($row->props, true))->toBe(['share_type' => Share::TYPE_COLLECTION, 'expires' => $share->expires_at !== null]);
});

it('shows the link and its URL only to whoever manages the collection, and keeps collection links off the Shared Links lists', function () {
    $member = p4cMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.shares', 'dam.shares.revoke']);
    app(CollectionService::class)->update(collection: $this->collection, actor: $this->owner, data: ['audience' => AssetCollection::AUDIENCE_INTERNAL]);
    app(CollectionService::class)->setAudience(collection: $this->collection, actor: $this->owner, userIds: [$member->id], groupIds: []);
    app(CollectionService::class)->update(collection: $this->collection, actor: $this->owner, data: ['audience' => AssetCollection::AUDIENCE_PUBLIC]);
    $full = p4cFullAdmin();
    [$share, $token] = p4cLink($this->collection, $full);

    // The owner and a full admin (the #126 three: creator, owner, full admin) see the URL.
    foreach (['owner' => $this->owner, 'creator' => $full, 'another full admin' => p4cFullAdmin()] as $who => $admin) {
        $data = $this->actingAs($admin, 'admin')->getJson(route('admin.dam.collections.data', $this->collection->id))->assertOk();
        expect($data->json('collection.link.token'))->toBe($token, $who);
    }

    // Anyone else gets no link at all, and its share id answers like a missing link.
    $this->actingAs($member, 'admin');
    expect(str_contains($this->get(route('admin.dam.collections.show', $this->collection->id))->getContent() ?: '', $token))->toBeFalse();
    $this->getJson(route('admin.dam.collections.data', $this->collection->id))->assertNotFound();
    $missingShare = Share::query()->max('id') + 1000;
    foreach ([
        fn (int $id) => $this->patchJson(route('admin.dam.shared-links.update', $id), ['name' => 'x']),
        fn (int $id) => $this->postJson(route('admin.dam.shared-links.reissue', $id)),
        fn (int $id) => $this->patchJson(route('admin.dam.shared-links.revoke', $id)),
    ] as $i => $call) {
        $hidden = $call($share->id);
        $missing = $call($missingShare);
        expect($hidden->getStatusCode())->toBe($missing->getStatusCode(), "call {$i}")->and($hidden->getContent())->toBe($missing->getContent(), "call {$i}");
    }

    // The Shared Links page and REST list leave collection links out, even for a full admin; they live on the collection.
    $this->actingAs($full, 'admin');
    $grid = $this->getJson(route('admin.dam.shared-links.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    expect(collect($grid->json('records'))->pluck('id')->map(fn ($id) => (int) $id)->all())->not->toContain($share->id)
        ->and(str_contains($grid->getContent(), $token))->toBeFalse();

    $rest = $this->withHeaders(p4cApiHeaders($full))->getJson(route('admin.api.dam.shares.index', ['limit' => 100]))->assertOk();
    expect(collect($rest->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all())->not->toContain($share->id);
});

it('grants nothing outside the collection: a public link member stays hidden in the Library', function () {
    [, $token] = p4cLink($this->collection, $this->owner);
    $stranger = p4cMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.edit']);

    p4cGet(route('dam.share.show', $token))->assertOk();

    $this->actingAs($stranger, 'admin');
    $this->get(route('admin.dam.assets.edit', $this->first->id))->assertNotFound();
    $this->get(route('admin.dam.collections.show', $this->collection->id))->assertNotFound();
    $this->get(route('admin.dam.collections.members.preview', ['id' => $this->collection->id, 'assetId' => $this->first->id]))->assertNotFound();
});

it('serves a member\'s stacked file thumbnail, preview and files zip through the collection link, and 404s any non-member\'s slot', function () {
    [, $token] = p4cLink($this->collection, $this->owner);
    $png = fakePngBytes(6, 6);
    $still = attachPreviewFile($this->first, 'angle.png', 'image/png', AssetFile::ROLE_ALTERNATE, label: 'angle.png');
    Storage::disk($still->fileObject->disk)->put($still->fileObject->path, $png);
    DB::table('dam_file_objects')->where('id', $still->file_object_id)->update(['size' => strlen($png)]);
    $outsider = p4cImage($this->owner, name: 'outsider');
    // Real PNG bytes, so each 404 below comes from the membership check, never from a blob GD cannot decode.
    $theirFile = attachPreviewFile($outsider, 'theirs.png', 'image/png', AssetFile::ROLE_ALTERNATE, label: 'theirs.png');
    Storage::disk($theirFile->fileObject->disk)->put($theirFile->fileObject->path, $png);
    DB::table('dam_file_objects')->where('id', $theirFile->file_object_id)->update(['size' => strlen($png)]);
    $theirs = (int) $theirFile->attachment_id;
    $member = ['token' => $token, 'assetId' => $this->first->id];
    $mine = ['attachment' => (int) $still->attachment_id];

    foreach (['dam.share.asset_file_thumbnail', 'dam.share.asset_file_preview'] as $route) {
        expect((string) p4cGet(route($route, $member + $mine))->assertOk()->headers->get('Content-Type'))->toStartWith('image/');
    }
    p4cGet(route('dam.share.asset_files_zip', $member))->assertOk()->assertHeader('Content-Type', 'application/zip');

    $outside = ['token' => $token, 'assetId' => $outsider->id];
    foreach ([
        'their slot under a member'  => route('dam.share.asset_file_thumbnail', $member + ['attachment' => $theirs]),
        'their slot preview, member' => route('dam.share.asset_file_preview', $member + ['attachment' => $theirs]),
        'non-member thumbnail'       => route('dam.share.asset_file_thumbnail', $outside + ['attachment' => $theirs]),
        'non-member preview'         => route('dam.share.asset_file_preview', $outside + ['attachment' => $theirs]),
        'non-member files zip'       => route('dam.share.asset_files_zip', $outside),
    ] as $case => $url) {
        expect(p4cGet($url)->getStatusCode())->toBe(404, $case);
    }
});
