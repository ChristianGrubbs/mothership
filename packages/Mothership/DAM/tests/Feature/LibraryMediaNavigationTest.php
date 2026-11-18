<?php

declare(strict_types=1);

use Mothership\DAM\DataGrids\Asset\AssetDataGrid;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\LibraryMediaFilter;

beforeEach(function () {
    // The explorer's default order is created_at desc, then id (#150). The page-2 assertions below
    // rely on the id tie-break, which only applies when every fixture shares one created_at; a run
    // that crossed a second boundary mid-loop failed on CI (PR #192, 2026-09-17).
    $this->freezeTime();
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Media campaign', 'parent_id' => null]);
    $this->other = Directory::create(['name' => 'Other campaign', 'parent_id' => null]);
    $this->mediaIds = [];
    foreach (['images' => ['image', 'jpg'], 'svgs' => ['image', 'SVG'], 'videos' => ['video', 'mp4'], 'audio' => ['audio', 'mp3'], 'models' => ['document', 'GLB']] as $type => [$fileType, $extension]) {
        foreach (range(1, 3) as $number) {
            $asset = Asset::factory()->create(['file_name' => "campaign-{$type}-{$number}.{$extension}", 'file_type' => $fileType, 'extension' => $extension]);
            $asset->directories()->attach($number === 3 ? $this->other->id : $this->folder->id);
            $this->mediaIds[$type][] = $asset->id;
        }
    }
});

it('filters the complete global explorer result before counts and pagination', function (string $type) {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'scope' => 'all', 'search' => 'campaign', 'media_type' => $type, 'per_page' => 2, 'page' => 2,
    ]))->assertOk()->assertJsonPath('meta.total_assets', 3)->assertJsonPath('meta.last_page', 2);
    expect(array_column($response->json('assets'), 'id'))->toBe([$this->mediaIds[$type][2]]);
})->with(['images', 'svgs', 'videos', 'audio', 'models']);

it('combines media and folder filters in both explorer and datagrid', function (string $type) {
    $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $this->folder->id, 'search' => 'campaign', 'media_type' => $type,
    ]))->assertOk()->assertJsonPath('meta.total_assets', 2);
    request()->merge(['media_type' => $type]);
    $grid = app(AssetDataGrid::class);
    // DataGrid::prepare() order: prepareQueryBuilder()'s addFilter() calls only qualify columns that
    // already exist. Reversed, the search runs on a bare `file_name`, which a table prefix turns
    // into `wk_file_name` (MySQL leg, DB_PREFIX=wk_).
    $grid->prepareColumns();
    $grid->setQueryBuilder();
    $query = $grid->processRequestedFilters(['directory_id' => [$this->folder->id], 'all' => ['campaign']]);
    expect($query->get()->pluck('id')->all())->toEqualCanonicalizing(array_slice($this->mediaIds[$type], 0, 2));
})->with(['images', 'svgs', 'videos', 'audio', 'models']);

it('files every model extension, and nothing else, under the 3D chip', function () {
    $models = [];
    // Spelled out, not read from the constant, so dropping a format from the filter fails here.
    $supported = ['glb', 'gltf', 'stl', '3mf', 'obj', 'fbx', 'usdz'];
    expect(LibraryMediaFilter::MODEL_EXTENSIONS)->toEqualCanonicalizing($supported);
    foreach ($supported as $extension) {
        $model = Asset::factory()->create(['file_name' => "shape-model.{$extension}", 'file_type' => 'document', 'extension' => strtoupper($extension)]);
        $model->directories()->attach($this->folder->id);
        $models[] = $model->id;
    }
    foreach (['pdf', null] as $extension) {
        Asset::factory()->create(['file_name' => 'shape-model.'.($extension ?? 'bin'), 'file_type' => 'document', 'extension' => $extension])
            ->directories()->attach($this->folder->id);
    }

    $response = $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => 'shape-model', 'media_type' => 'models', 'per_page' => 50]))->assertOk();
    expect(array_column($response->json('assets'), 'id'))->toEqualCanonicalizing($models);
});

it('paginates datagrid media matches before exposing totals', function () {
    request()->merge(['media_type' => 'svgs']);
    $query = app(AssetDataGrid::class)->prepareQueryBuilder();
    $page = $query->orderBy('dam_assets.id')->paginate(2, ['*'], 'page', 2);
    expect($page->total())->toBe(3);
    expect($page->items())->toHaveCount(1);
    expect($page->items()[0]->id)->toBe($this->mediaIds['svgs'][2]);
});

it('searches all accessible folders while withholding ungranted matches', function () {
    actAsPlaybackRestrictedAdmin($this->other, ['dam.asset.view']);
    $response = $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => 'campaign']))->assertOk();
    expect(array_column($response->json('assets'), 'id'))->toEqualCanonicalizing(array_values(array_map(fn ($ids) => $ids[2], $this->mediaIds)));
    expect($response->json('meta.total_assets'))->toBe(count($this->mediaIds));
});

it('accepts zero as a literal search and rejects unknown media types', function () {
    $asset = Asset::factory()->create(['file_name' => '0.jpg', 'file_type' => 'image', 'extension' => 'jpg']);
    $asset->directories()->attach($this->folder->id);
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => '0']))->assertOk()->assertJsonPath('meta.total_assets', 1);
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'media_type' => 'other']))->assertUnprocessable();
});

it('serves the explorer Library when nothing configures it', function (): void {
    $config = require base_path('packages/Mothership/DAM/src/Config/dam.php');

    expect($config['explorer']['enabled'])->toBeTrue('Drag-select and folder selection only exist in the explorer Library');
});

it('renders only the selected Library grid and preserves its shared controls', function (bool $explorer): void {
    config(['dam.explorer.enabled' => $explorer]);
    $html = $this->get(route('admin.dam.assets.index', ['scope' => 'all', 'search' => 'campaign', 'media_type' => 'audio']))->assertOk()->getContent();
    expect(str_contains($html, ':src="datagridSource"'))->toBe(! $explorer, 'Only the classic mode needs the DAM datagrid');
    expect(str_contains($html, ':ignore-stored-state="explicitNavigation"'))->toBe(! $explorer, 'Classic fresh-navigation binding remains intact');
    expect(str_contains($html, 'id="v-dam-upload-template"'))->toBe(! $explorer);
    expect(str_contains($html, 'id="v-dam-datagrid-template"'))->toBe(! $explorer);
    expect(str_contains($html, 'id="v-dam-gallery-folders-template"'))->toBe(! $explorer);
    expect(str_contains($html, 'id="v-dam-tab-template"'))->toBe($explorer);
    foreach (['v-dam-drop-upload-template', 'v-dam-grid-preview-modal-template', 'v-dam-folder-picker-template', 'v-gallery-table-template', 'v-dam-asset-picker-template'] as $template) {
        expect(substr_count($html, 'id="'.$template.'"'))->toBe(1);
    }
    // ADR 0006: the 3D thumbnail runner is Library-mode-independent. It was once explorer-only, so
    // uploads in the classic Library never got a thumbnail; exactly one must mount in either mode.
    expect(substr_count($html, '<v-dam-model-thumbnail-runner>'))->toBe(1);
    expect(substr_count($html, "app.component('v-dam-model-thumbnail-runner'"))->toBe(1);
    expect(str_contains($html, "this.\$emitter.on('dam:model-thumbnails-finished'"))->toBe(! $explorer, 'Classic datagrid must reload after a capture run');
    expect(str_contains($html, 'data-dam-generate-model-thumbnails'))->toBe(! $explorer, 'Classic toolbar backfill control');
})->with([true, false]);
