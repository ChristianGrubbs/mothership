<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Runs 2026_09_11_000001_widen_dam_asset_fk_columns against stand-in tables under a
 * prefix the default connection does not have. Databases that ran the capture-profile
 * and favorites migrations before PR #105 keep INT columns referencing the BIGINT
 * dam_assets.id. Only PostgreSQL can hold that shape (MySQL refuses the FK with 3780),
 * so the widening case runs there; the already-BIGINT case runs on whichever driver the suite uses.
 */

const DAM_FK_PROBE_PREFIX = 'dfkpfx_';

const DAM_FK_PROBE_TABLES = ['dam_asset_favorites', 'dam_capture_groups', 'dam_assets'];

function damFkWidenMigration(): object
{
    return require base_path('packages/Mothership/DAM/src/Database/Migrations/2026_09_11_000001_widen_dam_asset_fk_columns.php');
}

function damFkProbeDropTables(): void
{
    foreach (DAM_FK_PROBE_TABLES as $table) {
        Schema::dropIfExists($table);
    }
}

function damFkProbeCreateTables(string $fkType): void
{
    Schema::create('dam_assets', function (Blueprint $table) {
        $table->id();
    });

    Schema::create('dam_capture_groups', function (Blueprint $table) use ($fkType) {
        $table->id();
        $table->{$fkType}('primary_asset_id')->nullable();
        $table->foreign('primary_asset_id')->references('id')->on('dam_assets')->nullOnDelete();
    });

    Schema::create('dam_asset_favorites', function (Blueprint $table) use ($fkType) {
        $table->unsignedInteger('admin_id');
        $table->{$fkType}('dam_asset_id');
        $table->primary(['admin_id', 'dam_asset_id']);
        $table->foreign('dam_asset_id')->references('id')->on('dam_assets')->cascadeOnDelete();
    });
}

function damFkOnDelete(string $table, string $column): ?string
{
    return collect(Schema::getForeignKeys($table))
        ->first(fn (array $fk) => $fk['columns'] === [$column])['on_delete'] ?? null;
}

function damFkNames(): array
{
    return collect(['dam_capture_groups', 'dam_asset_favorites'])
        ->flatMap(fn (string $table) => array_column(Schema::getForeignKeys($table), 'name'))
        ->sort()
        ->values()
        ->all();
}

beforeEach(function () {
    $this->originalPrefix = DB::connection()->getTablePrefix();

    DB::connection()->setTablePrefix($this->originalPrefix.DAM_FK_PROBE_PREFIX);

    damFkProbeDropTables();
});

afterEach(function () {
    // A failed assertion on PostgreSQL leaves the test transaction aborted;
    // the rollback discards these tables there, so a cleanup error is moot.
    rescue(fn () => damFkProbeDropTables(), report: false);

    DB::connection()->setTablePrefix($this->originalPrefix);
});

it('widens INT asset FKs to BIGINT and keeps their delete rules', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Only PostgreSQL accepts the pre-#105 INT-to-BIGINT foreign key.');
    }

    damFkProbeCreateTables(fkType: 'unsignedInteger');

    expect(Schema::getColumnType('dam_capture_groups', 'primary_asset_id'))->toBe('int4')
        ->and(Schema::getColumnType('dam_asset_favorites', 'dam_asset_id'))->toBe('int4');

    // The Laravel default names production carries; dropForeign([...]) must resolve to them.
    $names = damFkNames();
    $prefix = DB::connection()->getTablePrefix();

    expect($names)->toBe([
        $prefix.'dam_asset_favorites_dam_asset_id_foreign',
        $prefix.'dam_capture_groups_primary_asset_id_foreign',
    ]);

    damFkWidenMigration()->up();

    $nullable = collect(Schema::getColumns('dam_capture_groups'))->firstWhere('name', 'primary_asset_id')['nullable'];

    expect(damFkNames())->toBe($names)
        ->and(Schema::getColumnType('dam_capture_groups', 'primary_asset_id'))->toBe('int8')
        ->and(Schema::getColumnType('dam_asset_favorites', 'dam_asset_id'))->toBe('int8')
        ->and($nullable)->toBeTrue()
        ->and(damFkOnDelete(table: 'dam_capture_groups', column: 'primary_asset_id'))->toBe('set null')
        ->and(damFkOnDelete(table: 'dam_asset_favorites', column: 'dam_asset_id'))->toBe('cascade');

    // An id past the INT range now fits, and the re-added FKs still act on delete.
    $bigId = 3_000_000_000;

    DB::table('dam_assets')->insert(['id' => $bigId]);
    DB::table('dam_capture_groups')->insert(['primary_asset_id' => $bigId]);
    DB::table('dam_asset_favorites')->insert(['admin_id' => 1, 'dam_asset_id' => $bigId]);

    DB::table('dam_assets')->where('id', $bigId)->delete();

    expect(DB::table('dam_capture_groups')->value('primary_asset_id'))->toBeNull()
        ->and(DB::table('dam_asset_favorites')->count())->toBe(0);
});

it('leaves already-BIGINT asset FKs untouched', function () {
    damFkProbeCreateTables(fkType: 'unsignedBigInteger');

    $types = fn () => [
        Schema::getColumnType('dam_capture_groups', 'primary_asset_id'),
        Schema::getColumnType('dam_asset_favorites', 'dam_asset_id'),
    ];
    $before = $types();
    $names = damFkNames();

    DB::flushQueryLog();
    DB::enableQueryLog();

    damFkWidenMigration()->up();

    $ddl = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => preg_match('/^\s*(alter|drop|create)\b/i', $sql) === 1)
        ->values()
        ->all();

    DB::disableQueryLog();

    expect($ddl)->toBe([])
        ->and($types())->toBe($before)
        ->and(damFkNames())->toBe($names)
        ->and(damFkOnDelete(table: 'dam_capture_groups', column: 'primary_asset_id'))->toBe('set null')
        ->and(damFkOnDelete(table: 'dam_asset_favorites', column: 'dam_asset_id'))->toBe('cascade');
});
