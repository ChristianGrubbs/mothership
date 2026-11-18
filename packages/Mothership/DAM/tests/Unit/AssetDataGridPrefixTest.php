<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\DataGrids\Asset\AssetDataGrid;
use Mothership\DAM\Services\DirectoryPermissionService;

it('asset datagrid prefixes raw MIN expressions with current connection prefix', function () {
    // prepareQueryBuilder() resolves directory access with real queries, which cannot run under
    // the stand-in prefix below: no `db_` tables exist on an unprefixed database. The access
    // snapshot is fixed here so only the SQL under test is built.
    $permissions = $this->partialMock(DirectoryPermissionService::class);
    $permissions->shouldReceive('viewableIds')->andReturn([1]);
    $permissions->shouldReceive('bypass')->andReturn(true);

    $originalPrefix = DB::getTablePrefix();
    $testPrefix = $originalPrefix !== '' ? $originalPrefix : 'db_';

    if ($originalPrefix === '') {
        DB::connection()->setTablePrefix($testPrefix);
    }

    try {
        $sql = app(AssetDataGrid::class)->prepareQueryBuilder()->toSql();

        expect($sql)
            ->toContain($testPrefix.'dam_directories')
            ->and($sql)->toContain($testPrefix.'dam_asset_directory')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_directories\./i')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_asset_directory\./i');
    } finally {
        if ($originalPrefix === '') {
            DB::connection()->setTablePrefix($originalPrefix);
        }
    }
});
