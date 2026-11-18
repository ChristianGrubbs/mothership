<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Organization\SavedSearchService;
use Mothership\DAM\Support\OrganizationNameLock;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * P4 (D12): saved searches store the query, never results. They are private unless shared, only their owner changes
 * them, and every run lists what the viewer may open at that moment.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->other = Directory::factory()->create(['name' => 'Other']);
    $this->owner = organizeMember($this->open);
    $this->role = $this->owner->role;
    $this->poster = organizeAsset($this->open, 'poster-red');
    $this->posterElsewhere = organizeAsset($this->other, 'poster-blue');
    $this->logo = organizeAsset($this->open, 'logo');
});

function saveSearch(array $attributes): int
{
    return (int) test()->postJson(route('admin.dam.saved-searches.store'), $attributes)->assertCreated()->json('data.id');
}

it('saves a search privately by default, storing only its definition', function () {
    $id = saveSearch(['name' => 'Posters', 'definition' => ['search' => 'poster', 'media_type' => 'images', 'filter_prop_campaign' => 'spring']]);
    $saved = SavedSearch::query()->find($id);

    expect($saved->visibility)->toBe('private')
        ->and($saved->owner_id)->toBe($this->owner->id)
        ->and($saved->definition)->toBe(['search' => 'poster', 'media_type' => 'images', 'filter_props' => ['campaign' => 'spring']])
        ->and($this->getJson(route('admin.dam.organize.index'))->json('saved_searches.0'))->toMatchArray(['id' => $id, 'mine' => true, 'visibility' => 'private']);

    $this->postJson(route('admin.dam.saved-searches.store'), ['name' => 'Bad', 'definition' => ['asset_ids' => [1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('definition');
});

it('shows a private search to nobody else and lets only the owner change a shared one', function () {
    $private = saveSearch(['name' => 'Mine', 'definition' => ['search' => 'logo']]);
    $shared = saveSearch(['name' => 'Team', 'definition' => ['search' => 'poster'], 'visibility' => 'shared', 'pinned' => true]);

    $colleague = Admin::factory()->create(['status' => 1, 'role_id' => $this->role->id]);
    $this->actingAs($colleague, 'admin');
    config(['app.debug' => false]);
    $missing = (int) SavedSearch::max('id') + 100;

    expect(collect($this->getJson(route('admin.dam.organize.index'))->json('saved_searches'))->map(fn ($s) => [$s['id'], $s['mine']])->all())->toBe([[$shared, false]]);

    foreach ([$private, $shared] as $id) {
        organizeExpectSameAnswer($this->putJson(route('admin.dam.saved-searches.update', $id), ['name' => 'Taken']), $this->putJson(route('admin.dam.saved-searches.update', $missing), ['name' => 'Taken']), 404, "update {$id}");
        organizeExpectSameAnswer($this->deleteJson(route('admin.dam.saved-searches.destroy', $id)), $this->deleteJson(route('admin.dam.saved-searches.destroy', $missing)), 404, "delete {$id}");
    }

    $this->actingAs($this->owner, 'admin');
    $this->putJson(route('admin.dam.saved-searches.update', $shared), ['name' => 'Team posters', 'visibility' => 'private'])->assertOk()->assertJsonPath('data.visibility', 'private');
    $this->deleteJson(route('admin.dam.saved-searches.destroy', $private))->assertOk();

    expect(SavedSearch::query()->pluck('name')->all())->toBe(['Team posters']);
});

it('re-runs a saved search under the runner\'s access at that moment', function () {
    $headers = organizeApiHeaders($this->role);
    $id = DB::table('dam_saved_searches')->insertGetId([
        'kind'       => 'search', 'owner_id' => $this->apiCaller->id, 'created_by' => $this->apiCaller->id, 'name' => 'Posters',
        'definition' => json_encode(['search' => 'poster']), 'pinned' => false, 'visibility' => 'private', 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $run = fn () => array_column($this->withHeaders($headers)->getJson(route('admin.api.dam.saved-searches.assets', $id))->assertOk()->json('data'), 'id');

    expect($run())->toBe([$this->poster->id]);

    organizeGrant(role: $this->role, directory: $this->other);
    expect($run())->toEqualCanonicalizing([$this->poster->id, $this->posterElsewhere->id]);

    organizeRevoke(role: $this->role, directory: $this->open);
    expect($run())->toBe([$this->posterElsewhere->id]);
});

it('runs a shared search naming a board the runner cannot see as matching nothing, never wider', function () {
    // The owner's board, inside the search they share.
    $board = (int) $this->postJson(route('admin.dam.boards.store'), ['name' => 'Picks'])->json('data.id');
    $this->postJson(route('admin.dam.boards.assets.store', $board), ['asset_ids' => [$this->poster->id]])->assertOk();
    $shared = saveSearch(['name' => 'My picks', 'definition' => ['filter_labels' => [$board]], 'visibility' => 'shared']);

    $colleagueRole = organizeRole();
    organizeGrant(role: $colleagueRole, directory: $this->open);
    $headers = organizeApiHeaders($colleagueRole);

    $this->withHeaders($headers)->getJson(route('admin.api.dam.saved-searches.assets', $shared))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    expect(Label::query()->find($board)->kind)->toBe('board');
});

it('re-runs a saved search in the Library from its stored definition, never a hidden one', function () {
    $board = (int) $this->postJson(route('admin.dam.boards.store'), ['name' => 'Picks'])->json('data.id');
    $this->postJson(route('admin.dam.boards.assets.store', $board), ['asset_ids' => [$this->poster->id]])->assertOk();
    $mine = saveSearch(['name' => 'Posters', 'definition' => ['scope' => 'all', 'search' => 'poster']]);
    $shared = saveSearch(['name' => 'My picks', 'definition' => ['scope' => 'all', 'filter_labels' => [$board]], 'visibility' => 'shared']);
    $private = saveSearch(['name' => 'Private', 'definition' => ['scope' => 'all', 'search' => 'logo']]);

    // The stored definition replaces whatever filter the request carried; the viewer's sort and paging stay.
    expect(organizeListedIds(['saved_search_id' => $mine, 'search' => 'logo']))->toBe([$this->poster->id])
        ->and(organizeListedIds(['saved_search_id' => $shared]))->toBe([$this->poster->id]);

    organizeGrant(role: $this->role, directory: $this->other);
    expect(organizeListedIds(['saved_search_id' => $mine]))->toEqualCanonicalizing([$this->poster->id, $this->posterElsewhere->id]);

    // A colleague: the owner's board inside the shared search matches nothing; the private one answers like a missing one.
    $colleagueRole = organizeRole();
    organizeGrant(role: $colleagueRole, directory: $this->open);
    $colleague = Admin::factory()->create(['status' => 1, 'role_id' => $colleagueRole->id]);
    $this->actingAs($colleague, 'admin');
    config(['app.debug' => false]);

    expect(organizeListedIds(['saved_search_id' => $shared]))->toBe([]);
    organizeExpectSameAnswer(
        hidden: $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'saved_search_id' => $private])),
        missing: $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'saved_search_id' => $private + 100])),
        status: 404, what: 'run private search',
    );
});

it('pins a shared search only for its owner, not into every colleague\'s sidebar', function () {
    $shared = saveSearch(['name' => 'Team', 'definition' => ['search' => 'poster'], 'visibility' => 'shared', 'pinned' => true]);

    expect(collect($this->getJson(route('admin.dam.organize.index'))->json('saved_searches'))->firstWhere('id', $shared)['pinned'])->toBeTrue();

    $colleague = Admin::factory()->create(['status' => 1, 'role_id' => $this->role->id]);
    $this->actingAs($colleague, 'admin');

    expect(collect($this->getJson(route('admin.dam.organize.index'))->json('saved_searches'))->firstWhere('id', $shared)['pinned'])->toBeFalse();
});

it('refuses to save a search that points at a folder the viewer cannot see', function () {
    $this->postJson(route('admin.dam.saved-searches.store'), ['name' => 'Gone', 'definition' => ['search' => 'x', 'directory_id' => 99999999, 'scope' => 'directory']])
        ->assertUnprocessable();
});

it('runs save and rename under the owner\'s lock, and refuses a clash case-insensitively', function () {
    $first = saveSearch(['name' => 'Posters', 'definition' => ['search' => 'poster']]);
    $second = saveSearch(['name' => 'Logos', 'definition' => ['search' => 'logo']]);
    $key = OrganizationNameLock::anchorKey('search.'.$this->owner->id);

    expect(DB::table('dam_configuration')->where('key', $key)->exists())->toBeTrue();
    DB::table('dam_configuration')->where('key', $key)->delete();

    $this->putJson(route('admin.dam.saved-searches.update', $second), ['name' => 'POSTERS'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->putJson(route('admin.dam.saved-searches.update', $first), ['name' => 'Posters v2'])->assertOk();
    expect(DB::table('dam_configuration')->where('key', $key)->exists())->toBeTrue();
});

it('applies an edit to the row as stored, not to the copy the request loaded before it waited', function () {
    $id = saveSearch(['name' => 'Original', 'definition' => ['search' => 'poster']]);
    $service = app(SavedSearchService::class);
    // Two requests load the same row, then run one after the other.
    $staleOne = SavedSearch::query()->findOrFail($id);
    $staleTwo = SavedSearch::query()->findOrFail($id);

    $service->update(search: $staleOne, attributes: ['name' => 'Changed'], viewerId: $this->owner->id);
    // The second explicitly sets the name its stale copy already holds: it must still land, not be skipped as "unchanged".
    $service->update(search: $staleTwo, attributes: ['name' => 'Original'], viewerId: $this->owner->id);

    expect(SavedSearch::query()->find($id)->name)->toBe('Original');

    // And a stale copy of a row that was deleted meanwhile is a 404, not a resurrected write.
    $gone = SavedSearch::query()->findOrFail($id);
    SavedSearch::query()->whereKey($id)->delete();
    expect(fn () => $service->update(search: $gone, attributes: ['name' => 'Back'], viewerId: $this->owner->id))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses a stale rename that now clashes with a name taken meanwhile', function () {
    $id = saveSearch(['name' => 'Original', 'definition' => ['search' => 'poster']]);
    $service = app(SavedSearchService::class);
    $stale = SavedSearch::query()->findOrFail($id);

    $service->update(search: SavedSearch::query()->findOrFail($id), attributes: ['name' => 'Changed'], viewerId: $this->owner->id);
    saveSearch(['name' => 'Original', 'definition' => ['search' => 'logo']]);

    expect(fn () => $service->update(search: $stale, attributes: ['name' => 'original'], viewerId: $this->owner->id))
        ->toThrow(ValidationException::class);
    expect(SavedSearch::query()->find($id)->name)->toBe('Changed');
});

it('restores a field to the value it had when a stale copy was loaded, whatever changed it meanwhile', function (string $field, mixed $original, mixed $changed) {
    $id = saveSearch(['name' => 'Stale '.$field, 'definition' => ['search' => 'poster'], 'pinned' => false, 'visibility' => 'private', $field => $original]);
    $service = app(SavedSearchService::class);
    $initial = SavedSearch::query()->findOrFail($id)->{$field};
    $staleOne = SavedSearch::query()->findOrFail($id);
    $staleTwo = SavedSearch::query()->findOrFail($id);

    $service->update(search: $staleOne, attributes: [$field => $changed], viewerId: $this->owner->id);
    expect(SavedSearch::query()->findOrFail($id)->{$field})->not->toEqual($initial);

    // The second request's copy still holds the original: setting it back must be written, not skipped as "unchanged".
    $service->update(search: $staleTwo, attributes: [$field => $original], viewerId: $this->owner->id);

    expect(SavedSearch::query()->findOrFail($id)->{$field})->toEqual($initial);
})->with([
    'definition' => ['definition', ['search' => 'poster'], ['search' => 'logo']],
    'pinned'     => ['pinned', false, true],
    'visibility' => ['visibility', 'private', 'shared'],
]);
