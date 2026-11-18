<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Share\ShareSlugs;
use Mothership\User\Models\Admin;

require_once __DIR__.'/../Support/CollectionTestHelpers.php';

/*
 * P4 D23 for ordinary share links: `/s/{slug}`. The slug is an alias of the link, never a second secret: it leads to
 * the link's own token URL while the link opens, so every check the token path makes answers the same through it.
 * An unknown, revoked or expired slug (and a retired or collection one) answers alike, so the slug discloses nothing.
 * Slugs are unique across shares AND collections, a renamed one answers 301, and reserved words are refused.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['mothership.media_accel_prefix' => null, 'app.debug' => false]);
    p4cUseModel(AccessModel::LISTS);

    $this->owner = p4cMember(D23_OWNER_KEYS);
    $this->asset = p4cImage($this->owner, name: 'slugged');
    $this->slug = 'spring-'.bin2hex(random_bytes(3));
});

/** The P4c owner's keys plus sharing a file and deleting a link, which the Shared Links routes require. */
if (! defined('D23_OWNER_KEYS')) {
    define('D23_OWNER_KEYS', [...P4C_OWNER_KEYS, 'dam.asset.share', 'dam.shares', 'dam.shares.delete']);
}

/** A link to $asset made as its owner would make it over the web, with $options (slug, password…); [share, token]. */
function d23Link(Admin $owner, Asset $asset, array $options = []): array
{
    $created = test()->actingAs($owner, 'admin')->postJson(route('admin.dam.shared-links.store'), $options + [
        'share_type'            => Share::TYPE_ASSET,
        'target_id'             => $asset->id,
        'public_link_confirmed' => true,
    ])->assertOk();
    app('auth')->forgetGuards();

    return [Share::query()->findOrFail($created->json('share.id')), $created->json('share.token')];
}

/** An anonymous GET that follows this app's own redirects (a 301 rename, then the token URL), as a browser does. */
function d23Open(string $url, ?string $cookie = null): TestResponse
{
    $response = p4cGet($url, $cookie);

    for ($hop = 0; $hop < 3 && $response->isRedirect() && str_starts_with((string) $response->headers->get('Location'), url('/')); $hop++) {
        $response = p4cGet((string) $response->headers->get('Location'), $cookie);
    }

    return $response;
}

/** Status and body of a response, for comparing two answers whole. */
function d23Answer(TestResponse $response): array
{
    // P1b's collector config carries a fresh page-view id ("k") on every page, so two loads never match byte for byte.
    $body = preg_replace('/"k":"[0-9a-f-]{36}"/', '"k":"page-view"', p4cBody($response));

    return [$response->getStatusCode(), $body];
}

it('answers each lifecycle state through the slug exactly as through the token', function () {
    $states = [
        'active'       => fn (Share $share, Asset $asset) => null,
        'preview-only' => fn (Share $share, Asset $asset) => $share->forceFill(['allow_download' => false])->save(),
        'withdrawn'    => fn (Share $share, Asset $asset) => $asset->forceFill(['availability' => Asset::AVAILABILITY_ARCHIVED])->save(),
        'pending'      => fn (Share $share, Asset $asset) => $asset->forceFill(['availability' => Asset::AVAILABILITY_PENDING_REVIEW])->save(),
        'out-of-window'=> fn (Share $share, Asset $asset) => $asset->forceFill(['available_until' => now()->subDay()])->save(),
        'trashed'      => fn (Share $share, Asset $asset) => $asset->delete(),
    ];

    // P3b: a merged-away source, where this base has it (the wave candidate does; this branch alone does not).
    if (Schema::hasColumn('dam_assets', 'merged_into_id')) {
        $states['merged'] = fn (Share $share, Asset $asset) => DB::table('dam_assets')->where('id', $asset->id)
            ->update(['merged_into_id' => p4cImage($this->owner, name: 'survivor')->id]);
    }

    $seen = [];

    foreach ($states as $state => $apply) {
        $asset = p4cImage($this->owner, name: 'state');
        $slug = $state.'-'.bin2hex(random_bytes(3));
        [$share, $token] = d23Link($this->owner, $asset, ['slug' => $slug]);
        $apply($share, $asset);

        $viaToken = d23Answer(d23Open(route('dam.share.show', ['token' => $token])));
        $viaSlug = d23Answer(d23Open(route('dam.share.slug', ['slug' => $slug])));

        expect($viaSlug)->toBe($viaToken, $state);
        $seen[$state] = $viaToken[0];
    }

    // The states really differ on the token path, so the parity above compares distinct answers.
    expect($seen['active'])->toBe(200)->and($seen['withdrawn'])->toBe(410)->and($seen['pending'])->toBe(404)->and($seen['trashed'])->toBe(410);
});

it('keeps a password-protected link behind its prompt through the slug, and opens it only with the unlock cookie', function () {
    $password = 'd23-'.bin2hex(random_bytes(8));
    [$share, $token] = d23Link($this->owner, $this->asset, ['slug' => $this->slug, 'password' => $password]);

    $prompt = d23Open(route('dam.share.slug', ['slug' => $this->slug]));
    expect(d23Answer($prompt))->toBe(d23Answer(d23Open(route('dam.share.show', ['token' => $token]))))
        ->and(p4cBody($prompt))->not->toContain($this->asset->file_name);

    [, $cookie] = p4cUnlock($share, $password);
    expect($cookie)->not->toBeNull();

    $opened = d23Open(route('dam.share.slug', ['slug' => $this->slug]), $cookie);
    expect($opened->getStatusCode())->toBe(200)
        ->and(d23Answer($opened))->toBe(d23Answer(d23Open(route('dam.share.show', ['token' => $token]), $cookie)));
});

it('counts a visit through the slug once, as the token path counts it', function () {
    [$share] = d23Link($this->owner, $this->asset, ['slug' => $this->slug]);

    shareVisitRan(d23Open(route('dam.share.slug', ['slug' => $this->slug]))->assertOk());

    expect($share->fresh()->view_count)->toBe(1);
});

it('answers an unknown, revoked, expired, creator-closed, retired and collection slug alike: status and body', function () {
    $unknown = d23Answer(p4cGet(route('dam.share.slug', ['slug' => 'nothing-'.$this->slug])));
    expect($unknown[0])->toBe(404);

    $closers = [
        'revoked'        => fn (Share $share) => $share->forceFill(['revoked_at' => now()])->save(),
        'expired'        => fn (Share $share) => $share->forceFill(['expires_at' => now()->subMinute()])->save(),
        // The creator-access option: the creator disabled, so the link reads as revoked on the token path.
        'creator-closed' => function (Share $share) {
            $share->forceFill(['requires_creator_access' => true])->save();
            Admin::query()->where('id', $share->created_by)->update(['status' => 0]);
        },
        'reissued'       => function (Share $share) {
            test()->actingAs(Admin::query()->findOrFail($share->created_by), 'admin')
                ->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertOk();
            app('auth')->forgetGuards();
        },
    ];

    foreach ($closers as $state => $close) {
        $owner = p4cMember(D23_OWNER_KEYS);
        $slug = $state.'-'.bin2hex(random_bytes(3));
        [$share] = d23Link($owner, p4cImage($owner, name: 'closed'), ['slug' => $slug]);
        expect(p4cGet(route('dam.share.slug', ['slug' => $slug]))->isRedirect())->toBeTrue($state);

        $close($share);

        expect(d23Answer(p4cGet(route('dam.share.slug', ['slug' => $slug]))))->toBe($unknown, $state);
    }

    // The token path tells an expired link apart (410); the slug never does.
    [$expired, $token] = d23Link($this->owner, p4cImage($this->owner), ['slug' => 'exp-'.$this->slug]);
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    expect(p4cGet(route('dam.share.show', ['token' => $token]))->getStatusCode())->toBe(410);

    // A collection's own link is never reached by /s/, even under its collection's slug.
    $collection = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC, 'slug' => 'col-'.$this->slug]);
    $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.collections.link.store', $collection->id))->assertCreated();
    expect(d23Answer(p4cGet(route('dam.share.slug', ['slug' => 'col-'.$this->slug]))))->toBe($unknown);
});

it('keeps slugs unique across shares and collections, lowercase, and refuses reserved words and bad formats', function () {
    [$share] = d23Link($this->owner, $this->asset, ['slug' => strtoupper($this->slug)]);
    expect($share->fresh()->slug)->toBe($this->slug);

    $second = p4cImage($this->owner, name: 'second');
    $store = fn (array $options) => $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.shared-links.store'), $options + [
        'share_type' => Share::TYPE_ASSET, 'target_id' => $second->id, 'public_link_confirmed' => true,
    ]);

    // Another share's slug, and a collection's, are both taken; a refused slug creates no link at all.
    $before = Share::query()->count();
    $store(['slug' => $this->slug])->assertStatus(422)->assertJsonValidationErrors('slug');
    p4cCollection($this->owner, ['slug' => 'col-'.$this->slug]);
    $store(['slug' => 'col-'.$this->slug])->assertStatus(422)->assertJsonValidationErrors('slug');
    expect(Share::query()->count())->toBe($before);

    // ...and a share's slug is taken for a collection too.
    $collection = p4cCollection($this->owner);
    $this->actingAs($this->owner, 'admin')->patchJson(route('admin.dam.collections.update', $collection->id), ['slug' => $this->slug])
        ->assertStatus(422)->assertJsonValidationErrors('slug');

    foreach (['admin', 'share', 'api', 'login'] as $reserved) {
        $store(['slug' => $reserved])->assertStatus(422)->assertJsonValidationErrors('slug');
    }
    foreach (['ab', 'with space', 'trailing-', '-leading', 'double--hyphen', 'under_score', str_repeat('a', 65)] as $bad) {
        $store(['slug' => $bad])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    // A slug another write has just taken (straight in the table, past the check) is refused, never a 500.
    [$racer] = d23Link($this->owner, $second);
    DB::table('dam_shares')->where('id', $share->id)->update(['slug' => 'race-'.$this->slug]);
    expect(fn () => ShareSlugs::write(share: $racer, slug: 'race-'.$this->slug))->toThrow(ValidationException::class);
});

it('keeps a renamed slug as a 301 to the current one, which nothing else may take, and frees it with the link', function () {
    [$share, $token] = d23Link($this->owner, $this->asset, ['slug' => $this->slug]);
    $update = fn (Share $link, ?string $slug) => $this->actingAs($this->owner, 'admin')
        ->patchJson(route('admin.dam.shared-links.update', $link->id), ['slug' => $slug]);

    $update($share, $this->slug.'-new')->assertOk()->assertJsonPath('share.slug', $this->slug.'-new')
        ->assertJsonPath('share.share_url', route('dam.share.slug', ['slug' => $this->slug.'-new']));

    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertStatus(301)->assertRedirect(route('dam.share.slug', ['slug' => $this->slug.'-new']));
    p4cGet(route('dam.share.slug', ['slug' => $this->slug.'-new']))->assertRedirect(route('dam.share.show', ['token' => $token]));

    // The old slug stays this link's: another link or a collection cannot take it; this link can take it back.
    [$other] = d23Link($this->owner, p4cImage($this->owner, name: 'other'));
    $update($other, $this->slug)->assertStatus(422)->assertJsonValidationErrors('slug');
    $update($share, $this->slug)->assertOk();
    p4cGet(route('dam.share.slug', ['slug' => $this->slug.'-new']))->assertStatus(301)->assertRedirect(route('dam.share.slug', ['slug' => $this->slug]));

    // Clearing the slug closes the address (it answers as unknown) but keeps it the link's.
    $update($share, null)->assertOk()->assertJsonPath('share.slug', null)
        ->assertJsonPath('share.share_url', route('dam.share.show', ['token' => $token]));
    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertNotFound();
    $update($other, $this->slug)->assertStatus(422);

    // Deleting the link frees every slug it held.
    $this->actingAs($this->owner, 'admin')->deleteJson(route('admin.dam.shared-links.destroy', $share->id))->assertOk();
    $update($other, $this->slug)->assertOk();
    expect(DB::table('dam_slug_redirects')->where('share_id', $share->id)->count())->toBe(0);
});

it('retires the slug with the token on reissue, so the old slug stops at once; the link may set it again', function () {
    [$share] = d23Link($this->owner, $this->asset, ['slug' => $this->slug]);

    $reissued = $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.shared-links.reissue', $share->id))->assertOk();
    expect($reissued->json('share.slug'))->toBeNull()
        ->and($share->fresh()->slug)->toBeNull();
    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertNotFound();

    $this->actingAs($this->owner, 'admin')->patchJson(route('admin.dam.shared-links.update', $share->id), ['slug' => $this->slug])->assertOk();
    p4cGet(route('dam.share.slug', ['slug' => $this->slug]))->assertRedirect(route('dam.share.show', ['token' => $reissued->json('share.token')]));
});

it('shows and sets the slug only for whom the token is shown, keeps it out of the audit trail, and refuses one on a collection link', function () {
    [$share] = d23Link($this->owner, $this->asset, ['slug' => $this->slug]);

    // Under grants, an editor with the revoke key who sees the file through a folder, but neither made the link nor
    // owns the file, may not edit it at all (R14), so neither renames it nor sets its slug.
    p4cUseModel(AccessModel::GRANTS);
    $folder = Directory::create(['name' => 'D23 folder '.bin2hex(random_bytes(3)), 'parent_id' => null]);
    $this->asset->directories()->attach($folder->id);
    $editor = p4cMember(['dam', 'dam.asset', 'dam.asset.view', 'dam.shares', 'dam.shares.index', 'dam.shares.revoke']);
    DB::table('dam_directory_user')->insert(['directory_id' => $folder->id, 'admin_id' => $editor->id, 'inherit_children' => false]);
    app(DirectoryPermissionService::class)->flush();

    $this->actingAs($editor, 'admin');
    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => 'renamed'])->assertForbidden();
    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['slug' => 'stolen-'.$this->slug])->assertForbidden();
    $this->patchJson(route('admin.dam.shared-links.update', $share->id), ['slug' => null])->assertForbidden();
    expect($share->fresh()->slug)->toBe($this->slug)->and($share->fresh()->name)->not->toBe('renamed');

    // A full admin may rename it and is shown it.
    $fullAdmin = p4cFullAdmin();
    $this->actingAs($fullAdmin, 'admin')->patchJson(route('admin.dam.shared-links.update', $share->id), ['name' => 'renamed'])->assertOk();
    $this->actingAs($fullAdmin, 'admin')->getJson(route('admin.dam.shared-links.active_for_target', ['type' => 'asset', 'targetId' => $this->asset->id]))
        ->assertOk()->assertJsonPath('shares.0.slug', $this->slug);

    // Neither the slug nor its column reaches the audit trail.
    expect(DB::table('audits')->where('auditable_type', (new Share)->getMorphClass())->where('auditable_id', $share->id)
        ->where(fn ($q) => $q->where('new_values', 'like', '%slug%')->orWhere('old_values', 'like', '%slug%'))->count())->toBe(0);

    // A collection's link takes no slug: it has the collection's own address.
    p4cUseModel(AccessModel::LISTS);
    $collection = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC]);
    $link = $this->actingAs($this->owner, 'admin')->postJson(route('admin.dam.collections.link.store', $collection->id))->assertCreated();
    $this->actingAs($this->owner, 'admin')->patchJson(route('admin.dam.shared-links.update', $link->json('link.id')), ['slug' => 'col-link-'.$this->slug])
        ->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('checks a slug live: free, malformed, reserved or in use, never by what; a link\'s own slugs stay free to it', function () {
    [$share] = d23Link($this->owner, $this->asset, ['slug' => $this->slug]);
    p4cCollection($this->owner, ['slug' => 'col-'.$this->slug]);
    $check = fn (array $query) => $this->actingAs($this->owner, 'admin')->getJson(route('admin.dam.shared-links.slug_check', $query))->assertOk();

    $check(['slug' => 'Free-'.$this->slug])->assertExactJson(['slug' => 'free-'.$this->slug, 'available' => true, 'problem' => null, 'message' => null]);
    $check(['slug' => $this->slug])->assertJsonPath('available', false)->assertJsonPath('problem', 'taken');
    $check(['slug' => 'col-'.$this->slug])->assertJsonPath('problem', 'taken')
        ->assertJsonPath('message', trans('dam::app.admin.dam.share.slug-taken'));
    $check(['slug' => 'admin'])->assertJsonPath('problem', 'reserved');
    $check(['slug' => 'a_b'])->assertJsonPath('problem', 'format');
    $check(['slug' => $this->slug, 'share_id' => $share->id])->assertJsonPath('available', true);

    // Someone else's share id does not free its slug for them.
    $stranger = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);
    $this->actingAs($stranger, 'admin')->getJson(route('admin.dam.shared-links.slug_check', ['slug' => $this->slug, 'share_id' => $share->id]))
        ->assertOk()->assertJsonPath('available', false);
});

it('rate-limits slug lookups as the token route is limited', function () {
    $middleware = app('router')->getRoutes()->getByName('dam.share.slug')->gatherMiddleware();
    $tokenMiddleware = app('router')->getRoutes()->getByName('dam.share.show')->gatherMiddleware();

    expect($middleware)->toContain('throttle:dam-share-view')->and($tokenMiddleware)->toContain('throttle:dam-share-view');
});
