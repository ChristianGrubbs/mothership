<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;

beforeEach(fn () => $this->loginAsAdmin());

it('uses metadata titles without changing filenames across browsing and previews', function () {
    $dir = Directory::factory()->create(['parent_id' => null]);
    $asset = Asset::factory()->create(['file_name' => 'IMG_001.jpg', 'title' => 'Old title']);
    $asset->directories()->attach($dir->id);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Title', 'value' => '  Summer exhibit  ']);
    expect($asset->display_name)->toBe('Summer exhibit');
    $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $dir->id, 'search' => 'exhibit']))
        ->assertOk()->assertJsonPath('assets.0.display_name', 'Summer exhibit')->assertJsonPath('assets.0.file_name', 'IMG_001.jpg');
    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()
        ->assertJsonPath('asset.display_name', 'Summer exhibit')->assertJsonPath('asset.file_name', 'IMG_001.jpg');
    $this->getJson(route('admin.dam.asset_picker.get_assets', ['assetIds' => (string) $asset->id]))
        ->assertOk()->assertJsonPath('0.display_name', 'Summer exhibit');
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonPath('display_name', 'Summer exhibit');
    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->assertSee('Summer exhibit');
    $this->getJson(route('admin.dam.assets.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonFragment(['display_name' => 'Summer exhibit']);
});

it('falls back through stored title embedded title and original filename', function () {
    foreach ([
        ['title' => ' Stored title ', 'meta_data' => null, 'expected' => 'Stored title'],
        ['title' => null, 'meta_data' => ['Title' => 'Embedded title'], 'expected' => 'Embedded title'],
        ['title' => ' ', 'meta_data' => ['title' => 'lowercase title'], 'expected' => 'lowercase title'],
        ['title' => ' ', 'meta_data' => ['Title' => ' '], 'expected' => 'original.jpg'],
    ] as $case) {
        $expected = $case['expected'];
        unset($case['expected']);
        $asset = Asset::factory()->create(['file_name' => 'original.jpg', ...$case]);
        AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'title', 'value' => ' ']);
        expect($asset->display_name)->toBe($expected);
        expect(Asset::withDisplayName()->find($asset->id)->display_name)->toBe($expected);
    }
});

it('sorts paginated browsing by the displayed title and retains filename search', function () {
    $dir = Directory::factory()->create(['parent_id' => null]);
    foreach (['aaa.jpg' => 'Zebra', 'zzz.jpg' => 'Apple'] as $filename => $title) {
        $asset = Asset::factory()->create(['file_name' => $filename, 'title' => $title]);
        $asset->directories()->attach($dir->id);
    }
    // sort_by is explicit: since #150 the default order is created_at desc ("Recently added").
    $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $dir->id, 'per_page' => 1, 'sort_by' => 'name']))
        ->assertOk()->assertJsonPath('assets.0.display_name', 'Apple')->assertJsonPath('meta.total_assets', 2);
    $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $dir->id, 'search' => 'aaa.jpg']))
        ->assertOk()->assertJsonPath('assets.0.display_name', 'Zebra');
});

it('serializes a page of resolved titles without per-file queries', function () {
    Asset::factory()->count(3)->create(['title' => 'Display title']);
    $assets = Asset::withDisplayName()->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $assets->toArray();
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();
});
