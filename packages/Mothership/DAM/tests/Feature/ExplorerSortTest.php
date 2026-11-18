<?php

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Sortable '.uniqid()]);

    // Three assets and two subfolders with distinct creation times, deliberately out of name order.
    $this->assets = collect([
        ['name' => 'middle.png', 'at' => now()->subDays(2)],
        ['name' => 'zebra.png', 'at' => now()->subDays(3)],
        ['name' => 'alpha.png', 'at' => now()->subDay()],
    ])->map(function (array $spec) {
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['file_name' => $spec['name'], 'file_type' => 'image', 'extension' => 'png']));
        Asset::withoutTimestamps(fn () => $asset->forceFill(['created_at' => $spec['at'], 'updated_at' => $spec['at']])->save());
        $asset->directories()->attach($this->folder->id);

        return $asset;
    });

    foreach ([['older', now()->subDays(5)], ['newer', now()->subDays(4)]] as [$name, $at]) {
        $child = Directory::create(['name' => $name, 'parent_id' => $this->folder->id]);
        Directory::withoutTimestamps(fn () => $child->forceFill(['created_at' => $at, 'updated_at' => $at])->save());
    }
});

$names = fn ($response, string $key) => array_column($response->json($key), $key === 'assets' ? 'file_name' : 'name');

it('lists recently added first by default, for assets and folders alike', function () use ($names) {
    $response = $this->getJson('/admin/dam/explorer/data?directory_id='.$this->folder->id)->assertOk();

    expect($names($response, 'assets'))->toBe(['alpha.png', 'middle.png', 'zebra.png'])
        ->and($names($response, 'directories'))->toBe(['newer', 'older']);
});

it('accepts created_at in both directions and still honours the older sorts', function () use ($names) {
    $base = '/admin/dam/explorer/data?directory_id='.$this->folder->id;

    expect($names($this->getJson($base.'&sort_by=created_at&sort_order=asc')->assertOk(), 'assets'))->toBe(['zebra.png', 'middle.png', 'alpha.png'])
        ->and($names($this->getJson($base.'&sort_by=created_at&sort_order=desc')->assertOk(), 'directories'))->toBe(['newer', 'older'])
        ->and($names($this->getJson($base.'&sort_by=name&sort_order=asc')->assertOk(), 'assets'))->toBe(['alpha.png', 'middle.png', 'zebra.png'])
        ->and($names($this->getJson($base.'&sort_by=name&sort_order=desc')->assertOk(), 'assets'))->toBe(['zebra.png', 'middle.png', 'alpha.png']);

    $this->getJson($base.'&sort_by=deleted_at')->assertUnprocessable();
});
