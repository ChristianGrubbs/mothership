<?php

declare(strict_types=1);

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Campaign', 'parent_id' => null]);
    $this->child = Directory::create(['name' => 'Designs', 'parent_id' => $this->folder->id]);
    $this->grandchild = Directory::create(['name' => 'Final', 'parent_id' => $this->child->id]);
    $this->outside = Directory::create(['name' => 'Other', 'parent_id' => null]);

    foreach (['folder', 'child', 'grandchild', 'outside'] as $name) {
        $asset = Asset::factory()->create(['file_name' => "campaign-{$name}.jpg", 'extension' => 'jpg']);
        $asset->directories()->attach($this->{$name}->id);
        $this->{$name.'Asset'} = $asset;
    }
    // One upload belongs to two folders; recursive results must not duplicate it.
    $this->folderAsset->directories()->attach($this->child->id);
});

it('keeps browsing search and filters inside the selected folder by default', function (array $filters, bool $listsChild) {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $this->folder->id,
        ...$filters,
    ]))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toBe([$this->folderAsset->id]);
    expect($response->json('meta.total_assets'))->toBe(1);
    // A search narrows the subfolders by name too (QA finding F11): "Designs" never matches "campaign".
    expect(array_column($response->json('directories'), 'id'))->toBe($listsChild ? [$this->child->id] : []);
})->with([
    'browse'           => [[], true],
    'search'           => [['search' => 'campaign'], false],
    'filter'           => [['filter_extension' => 'jpg'], true],
]);

it('includes all descendants once when enabled and keeps immediate folders visible', function (array $filters, bool $listsChild) {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'       => $this->folder->id,
        'include_subfolders' => 1,
        ...$filters,
    ]))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toEqualCanonicalizing([
        $this->folderAsset->id, $this->childAsset->id, $this->grandchildAsset->id,
    ]);
    expect($response->json('meta.total_assets'))->toBe(3);
    // A search narrows the subfolders by name too (QA finding F11): "Designs" never matches "campaign".
    expect(array_column($response->json('directories'), 'id'))->toBe($listsChild ? [$this->child->id] : []);
})->with([
    'browse'           => [[], true],
    'search'           => [['search' => 'campaign'], false],
    'filter'           => [['filter_extension' => 'jpg'], true],
]);

it('lists only the subfolders whose name matches the search', function () {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $this->folder->id,
        'search'       => 'DESIGN',
    ]))->assertOk();

    expect(array_column($response->json('directories'), 'id'))->toBe([$this->child->id]);
    expect($response->json('meta.total_directories'))->toBe(1);
});

it('paginates recursive uploads without hiding folder navigation', function () {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'       => $this->folder->id,
        'include_subfolders' => 1,
        'per_page'           => 2,
        'page'               => 2,
    ]))->assertOk();

    expect($response->json('assets'))->toHaveCount(1);
    expect($response->json('meta.total_assets'))->toBe(3);
    expect($response->json('meta.last_page'))->toBe(2);
    expect(array_column($response->json('directories'), 'id'))->toBe([$this->child->id]);
});

it('honors explicit off even with search and rejects invalid scope values', function () {
    $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'       => $this->folder->id,
        'include_subfolders' => 0,
        'search'             => 'campaign',
    ]))->assertOk()->assertJsonPath('meta.total_assets', 1);

    $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'       => $this->folder->id,
        'include_subfolders' => 'invalid',
    ]))->assertUnprocessable()->assertJsonValidationErrors('include_subfolders');
});

it('keeps equal sort values in a stable order across recursive pages', function () {
    foreach ([$this->folderAsset, $this->childAsset, $this->grandchildAsset] as $asset) {
        $asset->update(['file_name' => 'same-name.jpg']);
    }

    $ids = [];
    foreach ([1, 2, 3] as $page) {
        $response = $this->getJson(route('admin.dam.explorer.index', [
            'directory_id'       => $this->folder->id,
            'include_subfolders' => 1,
            'per_page'           => 1,
            'page'               => $page,
            // Explicit: the default sort is created_at (#150), under which these three are only
            // "equal" when the fixtures share a second; one CI run on PR #192 did not.
            'sort_by'            => 'name',
            'sort_order'         => 'desc',
        ]))->assertOk();
        $ids[] = $response->json('assets.0.id');
    }

    expect($ids)->toBe([$this->folderAsset->id, $this->childAsset->id, $this->grandchildAsset->id]);
});

it('only includes uploads from granted folders when viewing an ancestor', function () {
    actAsPlaybackRestrictedAdmin($this->grandchild, ['dam.asset.view']);

    $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $this->folder->id,
    ]))->assertOk()->assertJsonPath('meta.total_assets', 0);

    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'       => $this->folder->id,
        'include_subfolders' => 1,
    ]))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toBe([$this->grandchildAsset->id]);
    expect(array_column($response->json('directories'), 'id'))->toBe([$this->child->id]);
});
