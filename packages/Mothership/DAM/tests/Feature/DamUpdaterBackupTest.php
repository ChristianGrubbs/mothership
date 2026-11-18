<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Models\Directory;

beforeEach(fn () => Storage::fake(Directory::getAssetDisk()));

it('builds a mysqldump argv containing every dam table', function () {
    // Re-describe the live default connection as MySQL rather than switching database.default:
    // the switch made DatabaseTransactions roll back the wrong connection at teardown, so the real
    // one kept its transaction open and the next test found the SQLite file locked.
    $default = config('database.default');

    config(["database.connections.{$default}" => array_merge(config("database.connections.{$default}"), [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'username' => 'root',
        'database' => 'mothership',
    ])]);

    $prefix = DB::getTablePrefix();

    $cmd = app(DamUpdater::class)->buildDumpCommand('/tmp/out.sql');

    expect($cmd[0])->toBe('mysqldump')
        ->and($cmd)->toContain($prefix.'dam_assets')
        ->and($cmd)->toContain($prefix.'wk_assets_action_request');
});

it('refuses to dump when no dam tables resolve', function () {
    expect(fn () => app(DamUpdater::class)->buildDumpCommand('/tmp/out.sql', []))
        ->toThrow(RuntimeException::class);
});

it('creates a unique backup dir', function () {
    $updater = app(DamUpdater::class);
    $a = $updater->backupDir('2026-07-03_10-00-00');
    $b = $updater->backupDir('2026-07-03_10-00-00');

    expect(is_dir($a))->toBeTrue()
        ->and($b)->not->toBe($a);

    @rmdir($a);
    @rmdir($b);
});
