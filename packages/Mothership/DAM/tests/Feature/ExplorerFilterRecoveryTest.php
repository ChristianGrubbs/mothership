<?php

declare(strict_types=1);

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->folder = Directory::create(['name' => 'Filter recovery '.uniqid()]);
    $this->image = Asset::withoutEvents(fn () => Asset::factory()->create([
        'file_name' => 'recovery.png', 'file_type' => 'image', 'extension' => 'png',
    ]));
    $this->image->directories()->attach($this->folder->id);
});

it('returns the real conflict metadata without clearing either constraint', function ($extension) {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'     => $this->folder->id,
        'media_type'       => 'videos',
        'filter_extension' => $extension,
    ]))->assertOk();

    expect($response->json('assets'))->toBe([])
        ->and($response->json('meta.total_assets'))->toBe(0)
        ->and($response->json('meta.filter_conflict.filters'))->toBe(['media_type', 'extension'])
        ->and($response->json('meta.filter_conflict.message'))->toBe('PNG files cannot match Videos. Remove either filter to see results.');
})->with(['png', ' PNG ']);

it('returns matching assets and no conflict for compatible normalized extensions', function ($extension) {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'     => $this->folder->id,
        'media_type'       => 'images',
        'filter_extension' => $extension,
    ]))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toBe([$this->image->id])
        ->and($response->json('meta.filter_conflict'))->toBeNull();
})->with(['png', ' PNG ', 'pn']);

it('does not call unknown or partial no-match extensions incompatible', function ($extension) {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'     => $this->folder->id,
        'media_type'       => 'videos',
        'filter_extension' => $extension,
    ]))->assertOk();

    expect($response->json('assets'))->toBe([])
        ->and($response->json('meta.filter_conflict'))->toBeNull();
})->with(['unknown-format', 'pn']);

it('keeps conflict metadata when All assets also returns folder tiles', function () {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'scope' => 'all', 'media_type' => 'videos', 'filter_extension' => 'png',
    ]))->assertOk();

    expect($response->json('assets'))->toBe([])
        ->and(array_column($response->json('directories'), 'id'))->toContain($this->folder->id)
        ->and($response->json('meta.filter_conflict.filters'))->toBe(['media_type', 'extension'])
        ->and($response->json('meta.filter_conflict.message'))->toBe('PNG files cannot match Videos. Remove either filter to see results.');
});
