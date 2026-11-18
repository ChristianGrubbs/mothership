<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Runs 2026_09_02_000003_add_asset_graph_indexes up and down under a table
 * prefix the default connection does not have, against stand-in tables that
 * carry only the columns the migration touches, plus the real cascading foreign
 * key on dam_asset_files.dam_asset_id. The CI MySQL leg installs with
 * DB_PREFIX=wk_, where unprefixed raw SQL failed with "Table ... doesn't exist",
 * and a STORED generated column over that cascading key failed with 1215.
 */

const ASSET_GRAPH_PROBE_PREFIX = 'agpfx_';

const ASSET_GRAPH_PROBE_TABLES = [
    'dam_file_deletion_tombstones',
    'dam_asset_relationships',
    'dam_asset_files',
    'dam_renditions',
    'dam_file_objects',
    'dam_assets',
];

function compoundAssetIndexesMigration(): object
{
    return require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_09_02_000003_add_asset_graph_indexes.php');
}

function compoundAssetProbeDropTables(): void
{
    foreach (ASSET_GRAPH_PROBE_TABLES as $table) {
        Schema::dropIfExists($table);
    }
}

function compoundAssetProbeInsertFile(int $assetId, string $role): void
{
    DB::table('dam_asset_files')->insert(['dam_asset_id' => $assetId, 'file_object_id' => $assetId, 'role' => $role]);
}

beforeEach(function () {
    $this->originalPrefix = DB::connection()->getTablePrefix();
    $this->prefix = $this->originalPrefix.ASSET_GRAPH_PROBE_PREFIX;

    DB::connection()->setTablePrefix($this->prefix);

    compoundAssetProbeDropTables();

    Schema::create('dam_assets', function (Blueprint $table) {
        $table->id();
    });

    Schema::create('dam_asset_relationships', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('to_asset_id');
    });

    Schema::create('dam_asset_files', function (Blueprint $table) {
        $table->id();
        $table->foreignId('dam_asset_id')->constrained('dam_assets')->cascadeOnDelete();
        $table->unsignedBigInteger('file_object_id');
        $table->string('role');
    });

    Schema::create('dam_renditions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('dam_asset_id');
        $table->unsignedBigInteger('output_file_object_id')->nullable();
    });

    Schema::create('dam_file_objects', function (Blueprint $table) {
        $table->id();
        $table->string('disk');
        $table->string('path');
    });
});

afterEach(function () {
    // A failed assertion on PostgreSQL leaves the test transaction aborted;
    // the rollback discards these tables there, so a cleanup error is moot.
    rescue(fn () => compoundAssetProbeDropTables(), report: false);

    DB::connection()->setTablePrefix($this->originalPrefix);
});

it('creates the one-original index on the prefixed table', function () {
    compoundAssetIndexesMigration()->up();

    $index = collect(Schema::getIndexes('dam_asset_files'))
        ->firstWhere('name', $this->prefix.'dam_asset_files_one_original_per_asset');

    expect($index)->not->toBeNull()
        ->and($index['unique'])->toBeTrue()
        ->and(Schema::hasTable('dam_file_deletion_tombstones'))->toBeTrue();
});

it('allows one original per asset under a prefix', function () {
    compoundAssetIndexesMigration()->up();

    DB::table('dam_assets')->insert([['id' => 1], ['id' => 2]]);

    compoundAssetProbeInsertFile(assetId: 1, role: 'original');
    compoundAssetProbeInsertFile(assetId: 1, role: 'derivative');
    compoundAssetProbeInsertFile(assetId: 1, role: 'derivative');
    compoundAssetProbeInsertFile(assetId: 2, role: 'original');

    // A savepoint keeps the PostgreSQL test transaction usable after the violation.
    $duplicate = fn () => compoundAssetProbeInsertFile(assetId: 1, role: 'original');
    $attempt = DB::getDriverName() === 'pgsql' ? fn () => DB::transaction($duplicate) : $duplicate;

    expect($attempt)->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('dam_asset_files')->where('role', 'original')->count())->toBe(2);

    DB::table('dam_assets')->where('id', 1)->delete();

    expect(DB::table('dam_asset_files')->where('dam_asset_id', 1)->count())->toBe(0)
        ->and(DB::table('dam_asset_files')->where('dam_asset_id', 2)->count())->toBe(1);
});

it('rolls the migration back cleanly under a prefix', function () {
    $migration = compoundAssetIndexesMigration();

    $migration->up();
    $migration->down();

    $names = array_column(Schema::getIndexes('dam_asset_files'), 'name');

    expect($names)->not->toContain($this->prefix.'dam_asset_files_one_original_per_asset')
        ->and($names)->not->toContain($this->prefix.'dam_asset_files_file_object_id_index')
        ->and(Schema::hasColumn('dam_asset_files', 'current_original_asset_id'))->toBeFalse()
        ->and(Schema::hasTable('dam_file_deletion_tombstones'))->toBeFalse();
});
