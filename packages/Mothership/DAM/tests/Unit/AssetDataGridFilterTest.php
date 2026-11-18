<?php

use Mothership\DAM\DataGrids\Asset\AssetDataGrid;
use Mothership\DAM\Services\LibraryMediaFilter;

it('explains only known conflicting media and extension constraints', function ($media, $extension, $conflicts) {
    $result = LibraryMediaFilter::conflict(mediaType: $media, extension: $extension);
    if (! $conflicts) {
        expect($result)->toBeNull();

        return;
    }
    expect($result['filters'])->toBe(['media_type', 'extension'])
        ->and($result['message'])->toContain(strtoupper(trim($extension)))
        ->and($result['message'])->toContain('Remove either filter');
})->with([
    ['videos', 'png', true], ['videos', ' PNG ', true], ['images', 'png', false],
    ['images', 'svg', true], ['svgs', 'png', true], ['svgs', 'svg', false],
    ['models', 'gltf', false], ['videos', 'gltf', true], ['models', 'png', true],
    ['audio', 'mp3', false], ['images', 'mp3', true], ['videos', 'mov', false],
    ['images', 'pdf', true], ['videos', 'unknown-format', false],
    ['all', 'png', false], [null, 'png', false], ['videos', null, false],
]);

it('file_name filter uses LIKE matching so partial names return results', function () {
    $grid = app(AssetDataGrid::class);
    $grid->prepareColumns();
    $grid->setQueryBuilder();

    $qb = $grid->processRequestedFilters(['file_name' => ['copybook']]);

    $sql = strtolower($qb->toSql());

    expect($sql)->toContain('like');
});

it('file_name filter SQL does not include extra exact-match clause from fall-through', function () {
    $grid = app(AssetDataGrid::class);
    $grid->prepareColumns();
    $grid->setQueryBuilder();

    $qb = $grid->processRequestedFilters(['file_name' => ['copybook']]);

    $bindings = $qb->getBindings();

    $filterBindings = array_filter($bindings, fn ($b) => str_contains((string) $b, 'copybook'));

    expect(count($filterBindings))->toBe(1);
});
