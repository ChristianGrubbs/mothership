<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Runs 2026_09_16_100000_update_id_to_ui_id_oauth_clients_on_sqlite against stand-in tables
 * under a prefix the default connection does not have. The CI SQLite schema is always fresh,
 * so oauth_clients is empty when the migration runs there; the case that needs its own proof
 * is a SQLite file that already holds integer-id clients and tokens pointing at them.
 */

const OAUTH_UUID_PROBE_PREFIX = 'ocupfx_';

const OAUTH_UUID_PROBE_TABLES = ['oauth_access_tokens', 'oauth_clients'];

function oauthClientsSqliteUuidMigration(): object
{
    return require base_path('packages/Mothership/AdminApi/src/Database/Migrations/2026_09_16_100000_update_id_to_ui_id_oauth_clients_on_sqlite.php');
}

function oauthUuidProbeDropTables(): void
{
    foreach (OAUTH_UUID_PROBE_TABLES as $table) {
        Schema::dropIfExists($table);
    }
}

function oauthUuidProbeCreateTables(string $idType): void
{
    Schema::create('oauth_clients', function (Blueprint $table) use ($idType) {
        $idType === 'uuid' ? $table->uuid('id')->primary() : $table->bigIncrements('id');
        $table->string('name');
        $table->boolean('revoked');
    });

    Schema::create('oauth_access_tokens', function (Blueprint $table) {
        $table->string('id', 100)->primary();
        $table->unsignedBigInteger('client_id');
    });
}

beforeEach(function () {
    $this->originalPrefix = DB::connection()->getTablePrefix();

    DB::connection()->setTablePrefix($this->originalPrefix.OAUTH_UUID_PROBE_PREFIX);

    oauthUuidProbeDropTables();
});

afterEach(function () {
    rescue(fn () => oauthUuidProbeDropTables(), report: false);

    DB::connection()->setTablePrefix($this->originalPrefix);
});

it('converts an integer oauth_clients id on SQLite and keeps existing clients and their tokens', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Only SQLite was left with an integer oauth_clients id.');
    }

    oauthUuidProbeCreateTables(idType: 'integer');

    DB::table('oauth_clients')->insert(['id' => 7, 'name' => 'legacy', 'revoked' => false]);
    DB::table('oauth_access_tokens')->insert(['id' => 'legacy-token', 'client_id' => 7]);

    // The defect itself: the rowid alias refuses a Passport 13 UUID.
    expect(fn () => DB::table('oauth_clients')->insert(['id' => (string) Str::uuid(), 'name' => 'uuid', 'revoked' => false]))
        ->toThrow(QueryException::class, 'datatype mismatch');

    oauthClientsSqliteUuidMigration()->up();

    $uuid = (string) Str::uuid();

    DB::table('oauth_clients')->insert(['id' => $uuid, 'name' => 'uuid', 'revoked' => false]);
    DB::table('oauth_access_tokens')->insert(['id' => 'uuid-token', 'client_id' => $uuid]);

    $clientByToken = fn (string $token) => DB::table('oauth_access_tokens')
        ->join('oauth_clients', 'oauth_clients.id', '=', 'oauth_access_tokens.client_id')
        ->where('oauth_access_tokens.id', $token)
        ->value('oauth_clients.name');

    expect(Schema::getColumnType('oauth_clients', 'id'))->toBe('varchar')
        ->and(collect(Schema::getIndexes('oauth_clients'))->firstWhere('primary', true)['columns'])->toBe(['id'])
        ->and($clientByToken('legacy-token'))->toBe('legacy')
        ->and($clientByToken('uuid-token'))->toBe('uuid')
        ->and(DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
});

it('leaves an oauth_clients id that is already a uuid untouched', function () {
    oauthUuidProbeCreateTables(idType: 'uuid');

    $definition = fn () => Schema::getColumns('oauth_clients');
    $before = $definition();

    oauthClientsSqliteUuidMigration()->up();

    expect($definition())->toBe($before);
});
