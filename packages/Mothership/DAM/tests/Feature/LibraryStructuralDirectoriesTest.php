<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\LibraryDirectoryPresentation;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->root = Directory::whereNull('parent_id')->where('name', 'Root')->orderBy('id')->first()
        ?? Directory::create(['name' => 'Root', 'parent_id' => null]);
    $this->wrapper = Directory::create(['name' => 'Imported Library', 'parent_id' => $this->root->id]);
    $this->folder = Directory::create(['name' => 'Banners', 'parent_id' => $this->wrapper->id]);
    $this->userRoot = Directory::create(['name' => 'Root', 'parent_id' => $this->folder->id]);
    $this->userWrapper = Directory::create(['name' => 'Imported Library', 'parent_id' => $this->folder->id]);
    $this->unrelated = Directory::create(['name' => 'Unrelated', 'parent_id' => null]);

    $importedAsset = Asset::factory()->create(['legacy_ref' => 'structural-marker']);
    $importedAsset->directories()->attach($this->folder->id);
});

it('marks only the canonical structural ancestry while retaining original ids and children', function () {
    $response = $this->getJson(route('admin.dam.directory.index'))->assertOk();
    $roots = collect($response->json('data'));
    expect($roots->firstWhere('id', $this->root->id)['is_structural_wrapper'])->toBeTrue();
    expect($roots->firstWhere('id', $this->unrelated->id)['is_structural_wrapper'])->toBeFalse();
    $children = $this->getJson(route('admin.dam.directory.children', ['id' => $this->root->id]))->assertOk()->json('data');
    expect(collect($children)->firstWhere('id', $this->wrapper->id)['is_structural_wrapper'])->toBeTrue();
    $children = $this->getJson(route('admin.dam.directory.children', ['id' => $this->folder->id]))->assertOk()->json('data');
    expect(collect($children)->pluck('is_structural_wrapper')->all())->toBe([false, false]);
});

it('removes wrapper labels by id from search and explorer breadcrumbs but keeps user names', function () {
    $response = $this->getJson(route('admin.dam.directory.search', ['q' => 'Imported Library']))->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([$this->userWrapper->id]);
    expect($response->json('data.0.path_names'))->toBe(['Banners', 'Imported Library']);
    $response = $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $this->userRoot->id]))->assertOk();
    expect(array_column($response->json('breadcrumb'), 'name'))->toBe(['Banners', 'Root']);
});

it('keeps wrapper assets accessible through all assets and applies grants', function () {
    foreach ([$this->root, $this->wrapper, $this->folder] as $folder) {
        $asset = Asset::factory()->create(['file_name' => 'wrapper-fixture.jpg']);
        $asset->directories()->attach($folder->id);
    }
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => 'wrapper-fixture']))->assertOk()->assertJsonPath('meta.total_assets', 3);
    actAsPlaybackRestrictedAdmin($this->folder, ['dam.asset.view']);
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => 'wrapper-fixture']))->assertOk()->assertJsonPath('meta.total_assets', 1);
});

it('retains lazy pagination beyond one hundred children under wrappers', function () {
    foreach (range(1, 105) as $number) {
        Directory::create(['name' => sprintf('Nested %03d', $number), 'parent_id' => $this->wrapper->id]);
    }
    $first = $this->getJson(route('admin.dam.directory.children', ['id' => $this->wrapper->id, 'limit' => 100]))->assertOk();
    $next = $this->getJson(route('admin.dam.directory.children', ['id' => $this->wrapper->id, 'limit' => 100, 'offset' => 100]))->assertOk();
    expect($first->json('data'))->toHaveCount(100);
    expect($first->json('has_more'))->toBeTrue();
    expect($next->json('data'))->toHaveCount(6);
    expect($next->json('has_more'))->toBeFalse();
    expect(collect([...$first->json('data'), ...$next->json('data')])->unique('id'))->toHaveCount(106);
});

it('keeps an empty structural root identifiable without inventing folders', function () {
    $empty = Directory::create(['name' => 'Empty unrelated root', 'parent_id' => null]);
    $response = $this->getJson(route('admin.dam.directory.index'))->assertOk();
    expect(collect($response->json('data'))->firstWhere('id', $empty->id)['children'])->toBe([]);
    expect(collect($response->json('data'))->firstWhere('id', $empty->id)['is_structural_wrapper'])->toBeFalse();
});

it('memoizes structural identity across service resolutions only within the current request', function () {
    $originalRequest = request();
    $this->app->instance('request', Request::create('/library-first'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $first = app(LibraryDirectoryPresentation::class)->structuralIds();
        $queries = count(DB::getQueryLog());
        $second = app(LibraryDirectoryPresentation::class)->structuralIds();
        expect($second)->toBe($first);
        expect(count(DB::getQueryLog()))->toBe($queries);
        expect($queries)->toBe(2);

        $this->app->instance('request', Request::create('/library-next'));
        $next = app(LibraryDirectoryPresentation::class)->structuralIds();
        expect($next)->toBe($first);
        expect(count(DB::getQueryLog()))->toBe($queries * 2);
    } finally {
        DB::disableQueryLog();
        $this->app->instance('request', $originalRequest);
    }
});
