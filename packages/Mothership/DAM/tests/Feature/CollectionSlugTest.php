<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Collections\CollectionService;

require_once __DIR__.'/../Support/CollectionTestHelpers.php';

/*
 * P4 D23: `/c/{slug}`. Unique, lowercase, never a reserved word; a rename keeps the old slug as a 301; cosmetic for a
 * public collection (it leads to the link, whose rules still apply) and never a credential for any other; and no
 * existence oracle: an unknown slug and one the visitor cannot open answer the same.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
    config(['app.debug' => false]);
    p4cUseModel(AccessModel::LISTS);

    $this->owner = p4cMember(P4C_OWNER_KEYS);
    $this->audience = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);
    $this->slug = 'spring-'.bin2hex(random_bytes(3));
});

/** Saves a slug as its owner would, over the web; returns the response. */
function p4cSetSlug(AssetCollection $collection, $owner, ?string $slug)
{
    return test()->actingAs($owner, 'admin')->patchJson(route('admin.dam.collections.update', $collection->id), ['slug' => $slug]);
}

it('keeps slugs unique and lowercase, and refuses reserved words and bad formats', function () {
    $first = p4cCollection($this->owner);
    $second = p4cCollection($this->owner);

    p4cSetSlug($first, $this->owner, strtoupper($this->slug))->assertOk()->assertJsonPath('collection.slug', $this->slug);
    p4cSetSlug($second, $this->owner, $this->slug)->assertStatus(422)->assertJsonValidationErrors('slug');

    foreach (['admin', 'api', 'share', 'login'] as $reserved) {
        p4cSetSlug($second, $this->owner, $reserved)->assertStatus(422)->assertJsonValidationErrors('slug');
    }
    foreach (['ab', 'with space', 'trailing-', '-leading', 'double--hyphen', 'under_score', str_repeat('a', 65)] as $bad) {
        p4cSetSlug($second, $this->owner, $bad)->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    expect($second->fresh()->slug)->toBeNull();
    p4cSetSlug($second, $this->owner, $this->slug.'-two')->assertOk();

    // A slug another write has just taken (straight in the table) is refused with the same validation error, never a 500.
    $racer = p4cCollection($this->owner);
    DB::table('dam_collections')->where('id', $first->id)->update(['slug' => 'race-'.$this->slug]);
    expect(fn () => app(CollectionService::class)->update(collection: $racer, actor: $this->owner, data: ['slug' => 'race-'.$this->slug]))
        ->toThrow(ValidationException::class);
});

it('keeps a renamed slug as a 301 to the current one, which no other collection may take', function () {
    $collection = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC, 'slug' => $this->slug]);
    [, $token] = p4cLinkFor($collection, $this->owner);

    p4cSetSlug($collection, $this->owner, $this->slug.'-new')->assertOk();

    p4cGet(route('dam.collections.slug', $this->slug))->assertStatus(301)->assertRedirect(route('dam.collections.slug', $this->slug.'-new'));
    p4cGet(route('dam.collections.slug', strtoupper($this->slug)))->assertStatus(301);
    p4cGet(route('dam.collections.slug', $this->slug.'-new'))->assertRedirect(route('dam.share.show', $token));

    // The old slug stays this collection's: another one cannot take it, and this one can take it back.
    $other = p4cCollection($this->owner);
    p4cSetSlug($other, $this->owner, $this->slug)->assertStatus(422)->assertJsonValidationErrors('slug');
    p4cSetSlug($collection, $this->owner, $this->slug)->assertOk();
    p4cGet(route('dam.collections.slug', $this->slug))->assertRedirect(route('dam.share.show', $token));
    p4cGet(route('dam.collections.slug', $this->slug.'-new'))->assertStatus(301)->assertRedirect(route('dam.collections.slug', $this->slug));
});

it('is cosmetic for a public collection: the link\'s own rules still apply behind it', function () {
    $collection = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC, 'slug' => $this->slug]);
    $password = 'p4c-'.bin2hex(random_bytes(8));
    [$share, $token] = p4cLinkFor($collection, $this->owner, ['password' => $password]);

    p4cGet(route('dam.collections.slug', $this->slug))->assertRedirect(route('dam.share.show', $token));
    expect(p4cGet(route('dam.share.show', $token))->getStatusCode())->not->toBe(200);

    // Once the link is revoked the slug leads nowhere, exactly like an unknown one.
    $share->forceFill(['revoked_at' => now()])->save();
    $unknown = p4cGet(route('dam.collections.slug', 'nothing-'.$this->slug));
    $revoked = p4cGet(route('dam.collections.slug', $this->slug));
    expect($revoked->getStatusCode())->toBe($unknown->getStatusCode())->and($revoked->headers->get('Location'))->toBe($unknown->headers->get('Location'));
});

it('needs a signed-in audience member for a non-public slug, and answers an unknown slug and an unopenable one alike', function () {
    $collection = p4cCollection($this->owner, ['slug' => $this->slug, 'user_ids' => [$this->audience->id]]);
    $unknownSlug = 'nothing-'.$this->slug;

    // Anonymous: the sign-in page for both, never the collection.
    $anonymous = p4cGet(route('dam.collections.slug', $this->slug));
    $anonymousUnknown = p4cGet(route('dam.collections.slug', $unknownSlug));
    expect($anonymous->getStatusCode())->toBe(302)
        ->and($anonymous->headers->get('Location'))->toBe(route('admin.session.create'))
        ->and($anonymousUnknown->getStatusCode())->toBe(302)
        ->and($anonymousUnknown->headers->get('Location'))->toBe($anonymous->headers->get('Location'));

    // Signed in but not in the audience: the same 404 as an unknown slug.
    $stranger = p4cMember(['dam', 'dam.asset', 'dam.asset.view']);
    $this->actingAs($stranger, 'admin');
    $hidden = $this->get(route('dam.collections.slug', $this->slug));
    $missing = $this->get(route('dam.collections.slug', $unknownSlug));
    expect($hidden->getStatusCode())->toBe(404)->and($hidden->getContent())->toBe($missing->getContent());

    // Renamed: still no 301 for the stranger (that would confirm the slug), the same 404.
    p4cSetSlug($collection, $this->owner, $this->slug.'-new')->assertOk();
    $this->actingAs($stranger, 'admin');
    expect($this->get(route('dam.collections.slug', $this->slug))->getStatusCode())->toBe(404);

    // The audience member: 301 from the old slug, then the collection page.
    $this->actingAs($this->audience, 'admin');
    $this->get(route('dam.collections.slug', $this->slug))->assertStatus(301)->assertRedirect(route('dam.collections.slug', $this->slug.'-new'));
    $this->get(route('dam.collections.slug', $this->slug.'-new'))->assertRedirect(route('admin.dam.collections.show', $collection->id));

    // A public collection without a link is not public yet: alike to an unknown slug for an anonymous visitor.
    $unlinked = p4cCollection($this->owner, ['audience' => AssetCollection::AUDIENCE_PUBLIC, 'slug' => 'unlinked-'.$this->slug]);
    expect(p4cGet(route('dam.collections.slug', $unlinked->slug))->headers->get('Location'))->toBe($anonymous->headers->get('Location'));
});

/** The link for a public collection, made through the service as its owner. */
function p4cLinkFor(AssetCollection $collection, $owner, array $options = []): array
{
    $created = test()->actingAs($owner, 'admin')->postJson(route('admin.dam.collections.link.store', $collection->id), $options)->assertCreated();
    app('auth')->forgetGuards();

    return [Share::query()->findOrFail($created->json('link.id')), $created->json('link.token')];
}
