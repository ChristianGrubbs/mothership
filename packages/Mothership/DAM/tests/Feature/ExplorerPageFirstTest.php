<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

// The Library listing loads the page's ids first and the card columns second (perf pass 2026-10-19, T1).

// The page-id statement's opening, quoted and prefixed by the active connection (SQLite, PostgreSQL, prefixed MySQL).
function pageIdSqlPrefix(): string
{
    $grammar = DB::connection()->getQueryGrammar();

    return 'select '.$grammar->wrap('dam_assets.id').' from '.$grammar->wrapTable('dam_assets');
}

beforeEach(function () {
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'PageFirst '.uniqid()]);

    // Fixed attributes and times so the full card JSON is stable; ids are replaced by file names when frozen.
    $this->assets = collect([
        ['name' => 'middle.png', 'at' => 2, 'basis' => 'product', 'key' => 'k-b', 'size' => 300],
        ['name' => 'zebra.png', 'at' => 3, 'basis' => null, 'key' => null, 'size' => 100],
        ['name' => 'alpha.png', 'at' => 1, 'basis' => 'campaign', 'key' => 'k-a', 'size' => 200],
        ['name' => 'beta.png', 'at' => 4, 'basis' => 'product', 'key' => 'k-a', 'size' => 400],
    ])->mapWithKeys(function (array $spec) {
        $at = Carbon::parse('2026-01-10 12:00:00')->subDays($spec['at']);
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
            'file_name'   => $spec['name'], 'file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png',
            'path'        => 'assets/'.$spec['name'], 'file_size' => $spec['size'],
            'stack_basis' => $spec['basis'], 'stack_key' => $spec['key'],
        ]));
        Asset::withoutTimestamps(fn () => $asset->forceFill(['created_at' => $at, 'updated_at' => $at])->save());
        $asset->directories()->attach($this->folder->id);

        return [$spec['name'] => $asset];
    });
});

$url = fn (string $query) => '/admin/dam/explorer/data?directory_id='.test()->folder->id.'&'.$query;

/** The listing's cards as frozen JSON: asset ids replaced by file names, so the fixture does not depend on sequences. */
$frozen = function (array $json) {
    $byId = test()->assets->mapWithKeys(fn ($asset, $name) => [$asset->id => $name])->all();

    return json_encode(array_map(function (array $card) use ($byId) {
        $card['id'] = $byId[$card['id']];

        return $card;
    }, $json['assets']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
};

it('keeps the complete card JSON of the listing identical to the single-query shape', function (string $name, string $query) use ($url, $frozen) {
    $file = __DIR__.'/../Fixtures/explorer-page-first/'.$name.'.json';
    $actual = $frozen($this->getJson($url($query))->assertOk()->json());

    if (getenv('REGENERATE_EXPLORER_FIXTURES')) {
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $actual."\n");
    }

    expect($actual."\n")->toBe(file_get_contents($file));
})->with([
    'name-asc'            => ['name-asc', 'sort_by=name&sort_order=asc'],
    'name-desc-stack'     => ['name-desc-stack', 'sort_by=name&sort_order=desc&group_by=stack'],
    'created-desc'        => ['created-desc', 'sort_by=created_at&sort_order=desc'],
    'created-asc-stack'   => ['created-asc-stack', 'sort_by=created_at&sort_order=asc&group_by=stack'],
    'size-desc'           => ['size-desc', 'sort_by=size&sort_order=desc'],
    'page-2-of-3'         => ['page-2-of-3', 'sort_by=name&sort_order=asc&per_page=2&page=2'],
    'empty-page'          => ['empty-page', 'sort_by=name&sort_order=asc&page=9'],
]);

it('keeps the manual label position order and its member_position on the card', function () {
    $labelId = DB::table('dam_labels')->insertGetId(['name' => 'Page first', 'kind' => 'shared', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

    foreach (['middle.png' => 2, 'zebra.png' => 0, 'alpha.png' => 1] as $name => $position) {
        DB::table('dam_label_assets')->insert(['label_id' => $labelId, 'asset_id' => $this->assets[$name]->id, 'position' => $position, 'created_at' => now()]);
    }

    $json = $this->getJson('/admin/dam/explorer/data?scope=all&label_id='.$labelId.'&sort_by=position&sort_order=asc')->assertOk()->json();

    expect(array_column($json['assets'], 'file_name'))->toBe(['zebra.png', 'alpha.png', 'middle.png'])
        ->and(array_column($json['assets'], 'member_position'))->toBe([0, 1, 2]);
});

it('drops a card whose asset stops being listable between the id query and the card query', function () {
    $victim = $this->assets['middle.png'];
    $fired = false;

    // Right after the page-id statement returns, the asset is trashed (the same gap a draft, a merge or a revoked grant would open).
    DB::listen(function ($query) use ($victim, &$fired) {
        if (! $fired && str_starts_with($query->sql, pageIdSqlPrefix())) {
            $fired = true;
            DB::table('dam_assets')->where('id', $victim->id)->update(['deleted_at' => now()]);
        }
    });

    $json = $this->getJson('/admin/dam/explorer/data?directory_id='.$this->folder->id.'&sort_by=name&sort_order=asc')->assertOk()->json();

    expect($fired)->toBeTrue()
        ->and(array_column($json['assets'], 'file_name'))->toBe(['alpha.png', 'beta.png', 'zebra.png']);
});

it('drops a card that becomes unavailable between the two statements', function () {
    $victim = $this->assets['zebra.png'];
    $fired = false;

    DB::listen(function ($query) use ($victim, &$fired) {
        if (! $fired && str_starts_with($query->sql, pageIdSqlPrefix())) {
            $fired = true;
            DB::table('dam_assets')->where('id', $victim->id)->update(['availability' => Asset::AVAILABILITY_PENDING_REVIEW]);
        }
    });

    $json = $this->getJson('/admin/dam/explorer/data?directory_id='.$this->folder->id.'&include_unavailable=0&sort_by=name&sort_order=asc')->assertOk()->json();

    expect($fired)->toBeTrue()
        ->and(array_column($json['assets'], 'file_name'))->not->toContain('zebra.png');
});
