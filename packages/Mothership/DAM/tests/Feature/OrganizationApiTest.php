<?php

require_once __DIR__.'/../Support/OrganizationFixtures.php';

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\SavedSearch;

/*
 * P4 (REST parity): labels, boards, saved searches, sections and the Library listing's organization filters over
 * /api/v1/rest, for the API key's admin, through the same access seam as the web. A hidden file is never listed or
 * counted and answers like a missing one; a board is its owner's alone.
 */

beforeEach(function () {
    $this->open = Directory::factory()->create(['name' => 'Open']);
    $this->closed = Directory::factory()->create(['name' => 'Closed']);
    $this->role = organizeRole();
    organizeGrant(role: $this->role, directory: $this->open);
    $this->wide = organizeAsset($this->open, 'red-wide', size: [1600, 900]);
    $this->tall = organizeAsset($this->open, 'blue-tall', size: [900, 1600]);
    $this->video = organizeAsset($this->open, 'clip', ['file_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4']);
    $this->hidden = organizeAsset($this->closed, 'red-hidden', ['file_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4', 'meta_data' => ['ImageWidth' => 1600, 'ImageHeight' => 900]]);
    $this->headers = organizeApiHeaders($this->role);
});

function rest(string $method, string $route, array $parameters = [], array $data = [])
{
    return test()->withHeaders(test()->headers)->json($method, route($route, $parameters), $data);
}

function restIds(string $route, array $parameters = []): array
{
    return array_map('intval', array_column(rest('GET', $route, $parameters)->assertOk()->json('data'), 'id'));
}

it('manages shared labels, their files and their order over REST', function () {
    $parent = (int) rest('POST', 'admin.api.dam.labels.store', data: ['name' => 'Brand'])->assertCreated()->json('data.id');
    $child = (int) rest('POST', 'admin.api.dam.labels.store', data: ['name' => 'Logos', 'parent_id' => $parent])->assertCreated()->json('data.id');
    rest('PUT', 'admin.api.dam.labels.update', [$child], ['name' => 'Marks'])->assertOk()->assertJsonPath('data.name', 'Marks');

    rest('POST', 'admin.api.dam.labels.assets.store', [$child], ['asset_ids' => [$this->wide->id, $this->tall->id]])->assertOk()->assertJsonPath('data.added', 2);
    rest('PUT', 'admin.api.dam.labels.assets.update', [$child, $this->tall->id], ['position' => 0])->assertOk();

    $listing = rest('GET', 'admin.api.dam.labels.assets.index', [$child, 'sort_by' => 'position', 'sort_order' => 'asc'])->assertOk();
    expect(array_column($listing->json('data'), 'id'))->toBe([$this->tall->id, $this->wide->id])
        ->and(array_column($listing->json('data'), 'member_position'))->toBe([0, 1])
        ->and($listing->json('meta.organization'))->toMatchArray(['type' => 'label', 'id' => $child]);

    $tree = rest('GET', 'admin.api.dam.labels.index')->assertOk()->json('data');
    expect($tree[0])->toMatchArray(['name' => 'Brand', 'count' => 0])
        ->and($tree[0]['children'][0])->toMatchArray(['name' => 'Marks', 'count' => 2]);

    rest('DELETE', 'admin.api.dam.labels.assets.destroy', [$child], ['asset_ids' => [$this->wide->id]])->assertOk()->assertJsonPath('data.removed', 1);
    rest('DELETE', 'admin.api.dam.labels.destroy', [$parent])->assertOk()->assertJsonPath('data.deleted', 2);

    expect(Label::query()->count())->toBe(0)
        ->and(Asset::query()->whereKey([$this->wide->id, $this->tall->id])->count())->toBe(2);
});

it('keeps a board its owner\'s alone over REST', function () {
    $board = (int) rest('POST', 'admin.api.dam.boards.store', data: ['name' => 'Picks'])->assertCreated()->json('data.id');
    rest('POST', 'admin.api.dam.boards.assets.store', [$board], ['asset_ids' => [$this->wide->id]])->assertOk();
    expect(restIds('admin.api.dam.boards.assets.index', [$board]))->toBe([$this->wide->id])
        ->and(Label::query()->find($board)->owner_id)->toBe($this->apiCaller->id);

    // Another key's admin, with every permission.
    $this->headers = organizeApiHeaders($this->role);
    $missing = (int) Label::max('id') + 100;
    config(['app.debug' => false]);

    expect(rest('GET', 'admin.api.dam.boards.index')->assertOk()->json('data'))->toBe([]);

    foreach ([
        ['GET', 'admin.api.dam.boards.assets.index', fn ($id) => [$id], []],
        ['PUT', 'admin.api.dam.boards.update', fn ($id)            => [$id], ['name' => 'Mine']],
        ['DELETE', 'admin.api.dam.boards.destroy', fn ($id)        => [$id], []],
        ['POST', 'admin.api.dam.boards.assets.store', fn ($id)     => [$id], ['asset_ids' => [$this->tall->id]]],
        ['DELETE', 'admin.api.dam.boards.assets.destroy', fn ($id) => [$id], ['asset_ids' => [$this->wide->id]]],
        ['PUT', 'admin.api.dam.boards.assets.update', fn ($id)     => [$id, $this->wide->id], ['position' => 0]],
        ['GET', 'admin.api.dam.labels.assets.index', fn ($id)      => [$id], []],
        ['GET', 'admin.api.dam.library.assets', fn ($id)           => ['filter_labels' => [$id]], []],
        ['GET', 'admin.api.dam.library.assets', fn ($id)           => ['label_id' => $id], []],
    ] as [$method, $route, $parameters, $data]) {
        organizeExpectSameAnswer(rest($method, $route, $parameters($board), $data), rest($method, $route, $parameters($missing), $data), 404, $route);
    }

    expect(DB::table('dam_label_assets')->where('label_id', $board)->count())->toBe(1);
});

it('saves, lists, changes and re-runs saved searches over REST', function () {
    $id = (int) rest('POST', 'admin.api.dam.saved-searches.store', data: ['name' => 'Wide', 'definition' => ['filter_orientation' => 'landscape']])->assertCreated()->json('data.id');

    expect(SavedSearch::query()->find($id)->visibility)->toBe('private')
        ->and(rest('GET', 'admin.api.dam.saved-searches.index')->json('data.0'))->toMatchArray(['id' => $id, 'mine' => true])
        ->and(restIds('admin.api.dam.saved-searches.assets', [$id]))->toBe([$this->wide->id]);

    rest('PUT', 'admin.api.dam.saved-searches.update', [$id], ['definition' => ['filter_orientation' => 'portrait']])->assertOk();
    expect(restIds('admin.api.dam.saved-searches.assets', [$id]))->toBe([$this->tall->id]);

    rest('DELETE', 'admin.api.dam.saved-searches.destroy', [$id])->assertOk();
    expect(SavedSearch::query()->count())->toBe(0);
});

it('manages sections and their pins over REST', function () {
    $section = (int) rest('POST', 'admin.api.dam.sections.store', data: ['name' => 'Motion', 'rule' => ['media_types' => ['videos']], 'default_sort' => 'position:asc'])->assertCreated()->json('data.id');
    rest('POST', 'admin.api.dam.sections.pins.store', [$section], ['asset_ids' => [$this->wide->id, $this->tall->id]])->assertOk();
    rest('PUT', 'admin.api.dam.sections.pins.update', [$section, $this->tall->id], ['position' => 0])->assertOk();

    $listing = rest('GET', 'admin.api.dam.sections.assets', [$section])->assertOk();
    expect(array_column($listing->json('data'), 'id'))->toBe([$this->tall->id, $this->wide->id, $this->video->id])
        ->and($listing->json('meta.sort'))->toBe('position:asc')
        ->and(rest('GET', 'admin.api.dam.sections.index')->json('data.0'))->toMatchArray(['id' => $section, 'count' => 3]);

    rest('DELETE', 'admin.api.dam.sections.pins.destroy', [$section], ['asset_ids' => [$this->wide->id]])->assertOk();
    rest('PUT', 'admin.api.dam.sections.update', [$section], ['name' => 'Moving'])->assertOk()->assertJsonPath('data.name', 'Moving');
    expect(restIds('admin.api.dam.sections.assets', [$section]))->toBe([$this->tall->id, $this->video->id]);
    rest('DELETE', 'admin.api.dam.sections.destroy', [$section])->assertOk();
});

it('filters the REST Library listing by orientation, favorites and labels, alone and with a search', function () {
    $label = (int) rest('POST', 'admin.api.dam.labels.store', data: ['name' => 'Hero'])->json('data.id');
    rest('POST', 'admin.api.dam.labels.assets.store', [$label], ['asset_ids' => [$this->wide->id, $this->video->id]])->assertOk();
    DB::table('dam_asset_favorites')->insert(['admin_id' => $this->apiCaller->id, 'dam_asset_id' => $this->tall->id, 'created_at' => now()]);
    DB::table('dam_asset_favorites')->insert(['admin_id' => $this->apiCaller->id, 'dam_asset_id' => $this->hidden->id, 'created_at' => now()]);

    expect(restIds('admin.api.dam.library.assets', ['filter_orientation' => 'landscape']))->toBe([$this->wide->id])
        ->and(restIds('admin.api.dam.library.assets', ['filter_favorites' => 1]))->toBe([$this->tall->id])
        ->and(restIds('admin.api.dam.library.assets', ['filter_labels' => [$label]]))->toEqualCanonicalizing([$this->wide->id, $this->video->id])
        ->and(restIds('admin.api.dam.library.assets', ['filter_labels' => [$label], 'search' => 'clip']))->toBe([$this->video->id])
        ->and(restIds('admin.api.dam.library.assets', ['filter_orientation' => 'landscape', 'search' => 'red']))->toBe([$this->wide->id])
        ->and(restIds('admin.api.dam.library.assets', ['filter_favorites' => 1, 'search' => 'blue']))->toBe([$this->tall->id]);
});

it('needs the route\'s API permission', function () {
    $this->headers = organizeApiHeaders($this->role, permissions: ['api.dam.labels', 'api.dam.labels.get']);

    rest('GET', 'admin.api.dam.labels.index')->assertOk();
    rest('POST', 'admin.api.dam.labels.store', data: ['name' => 'Nope'])->assertForbidden();
    rest('GET', 'admin.api.dam.sections.index')->assertForbidden();
    rest('GET', 'admin.api.dam.library.assets')->assertForbidden();
});

it('leaves a hidden file out of every organization listing and count, on the web and over REST', function () {
    // Someone who can open the hidden clip labelled, pinned and favorited it; this viewer cannot open it.
    $label = (int) rest('POST', 'admin.api.dam.labels.store', data: ['name' => 'Mixed'])->json('data.id');
    $board = (int) rest('POST', 'admin.api.dam.boards.store', data: ['name' => 'Mine'])->json('data.id');
    $section = (int) rest('POST', 'admin.api.dam.sections.store', data: ['name' => 'Motion', 'rule' => ['media_types' => ['videos']]])->json('data.id');
    $search = (int) rest('POST', 'admin.api.dam.saved-searches.store', data: ['name' => 'Red', 'definition' => ['search' => 'red']])->json('data.id');
    rest('POST', 'admin.api.dam.labels.assets.store', [$label], ['asset_ids' => [$this->wide->id]])->assertOk();
    foreach ([$label, $board] as $id) {
        DB::table('dam_label_assets')->insert(['label_id' => $id, 'asset_id' => $this->hidden->id, 'position' => 5, 'created_at' => now()]);
    }
    DB::table('dam_section_pins')->insert(['section_id' => $section, 'asset_id' => $this->hidden->id, 'position' => 0, 'created_at' => now()]);
    DB::table('dam_asset_favorites')->insert(['admin_id' => $this->apiCaller->id, 'dam_asset_id' => $this->hidden->id, 'created_at' => now()]);

    $rest = [
        'admin.api.dam.labels.index'          => fn () => rest('GET', 'admin.api.dam.labels.index')->json('data.0.count'),
        'admin.api.dam.labels.assets.index'   => fn () => restIds('admin.api.dam.labels.assets.index', [$label]),
        'admin.api.dam.boards.index'          => fn () => rest('GET', 'admin.api.dam.boards.index')->json('data.0.count'),
        'admin.api.dam.boards.assets.index'   => fn () => restIds('admin.api.dam.boards.assets.index', [$board]),
        'admin.api.dam.sections.index'        => fn () => rest('GET', 'admin.api.dam.sections.index')->json('data.0.count'),
        'admin.api.dam.sections.assets'       => fn () => restIds('admin.api.dam.sections.assets', [$section]),
        'admin.api.dam.saved-searches.assets' => fn () => restIds('admin.api.dam.saved-searches.assets', [$search]),
        'admin.api.dam.library.assets'        => fn () => restIds('admin.api.dam.library.assets', ['filter_favorites' => 1]),
    ];
    $expected = [
        'admin.api.dam.labels.index'          => 1, 'admin.api.dam.labels.assets.index' => [$this->wide->id],
        'admin.api.dam.boards.index'          => 0, 'admin.api.dam.boards.assets.index' => [],
        'admin.api.dam.sections.index'        => 1, 'admin.api.dam.sections.assets' => [$this->video->id],
        'admin.api.dam.saved-searches.assets' => [$this->wide->id], 'admin.api.dam.library.assets' => [],
    ];

    foreach ($rest as $route => $read) {
        expect($read())->toBe($expected[$route], $route);
    }

    // The saved-search list carries definitions only, never an asset.
    expect(json_encode(rest('GET', 'admin.api.dam.saved-searches.index')->json('data')))->not->toContain((string) $this->hidden->id);

    // The web sidebar: the same viewer, signed in.
    $this->actingAs($this->apiCaller, 'admin');
    $organize = $this->getJson(route('admin.dam.organize.index', ['asset_ids' => [$this->hidden->id]]))->assertOk();
    expect(collect($organize->json('labels'))->firstWhere('id', $label))->toMatchArray(['count' => 1, 'holds' => 0])
        ->and(collect($organize->json('boards'))->firstWhere('id', $board))->toMatchArray(['count' => 0, 'holds' => 0])
        ->and(collect($organize->json('sections'))->firstWhere('id', $section))->toMatchArray(['count' => 1, 'pins' => 0])
        ->and($organize->json('favorites'))->toBe(0)
        ->and(organizeListedIds(['label_id' => $label]))->toBe([$this->wide->id])
        ->and(organizeListedIds(['section_id' => $section]))->toBe([$this->video->id])
        ->and(organizeListedIds(['filter_favorites' => 1]))->toBe([]);
});
