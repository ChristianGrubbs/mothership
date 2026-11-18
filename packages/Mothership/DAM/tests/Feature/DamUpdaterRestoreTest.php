<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\DamTables;

/**
 * Store a content-addressed blob on the asset disk and record its file object.
 */
function damRestoreBlob(string $contents, bool $write = true): object
{
    $sha = hash('sha256', $contents);
    $path = Directory::ASSETS_DIRECTORY.'/.objects/'.substr($sha, 0, 2).'/'.$sha;

    if ($write) {
        Storage::disk(Directory::getAssetDisk())->put($path, $contents);
    }

    $id = DB::table('dam_file_objects')->insertGetId([
        'sha256'     => $sha,
        'size'       => strlen($contents),
        'disk'       => Directory::getAssetDisk(),
        'path'       => $path,
        'created_at' => now(),
    ]);

    return (object) ['id' => $id, 'path' => $path];
}

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());

    $this->backupName = 'restore-test-'.bin2hex(random_bytes(4));
    $this->backupDir = storage_path('dam-backups/'.$this->backupName);
    mkdir($this->backupDir, 0755, true);

    $this->disk = Storage::disk(Directory::getAssetDisk());
    $this->updater = app(DamUpdater::class);

    // The table replay is faked; its real PostgreSQL behaviour was checked by hand (PR #24).
    // Describe the live connection as MySQL so restore replays through the faked mysql client.
    $default = config('database.default');
    config(["database.connections.{$default}" => array_merge(config("database.connections.{$default}"), [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'username' => 'root',
        'database' => 'mothership',
    ])]);
    file_put_contents($this->backupDir.'/dam-tables.sql', '-- dump');
    Process::fake(["'mysql' *" => Process::result()]);
});

afterEach(fn () => File::deleteDirectory($this->backupDir));

it('dumps every DAM table, closed under foreign keys and parents first', function () {
    $prefix = DB::getTablePrefix();
    $unprefix = fn (string $table) => $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;

    $tables = array_map(fn (array $table) => $unprefix($table['name']), Schema::getTables());
    $owned = array_filter($tables, fn (string $table) => str_starts_with($table, 'dam_') || $table === 'wk_assets_action_request');

    // The one FK cycle (both sides nullable) is broken at dam_assets.
    $cycleBreaks = ['dam_assets' => ['dam_capture_groups']];
    $position = array_flip(DamTables::ALL);
    $unclosed = [];
    $outOfOrder = [];

    foreach ($tables as $table) {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $parent = $unprefix($foreignKey['foreign_table']);

            if (! isset($position[$parent]) || $parent === $table) {
                continue;
            }

            if (! isset($position[$table])) {
                $unclosed[] = "{$table} -> {$parent}";
            } elseif ($position[$parent] > $position[$table] && ! in_array($parent, $cycleBreaks[$table] ?? [], true)) {
                $outOfOrder[] = "{$table} -> {$parent}";
            }
        }
    }

    expect(array_values(array_diff($owned, DamTables::ALL)))->toBe([])
        ->and(array_values(array_diff(DamTables::ALL, $tables)))->toBe([])
        ->and($unclosed)->toBe([])
        ->and($outOfOrder)->toBe([]);
});

it('restores the asset tree to the snapshot and every restored file object resolves', function () {
    $kept = damRestoreBlob('kept original');
    $this->disk->put('assets/Photos/kept.jpg', 'kept original');
    $this->updater->archiveAssetFiles($this->backupDir);

    // After the backup a new upload lands and the kept blob is deleted. Rows written after the
    // backup vanish when the tables are replayed, so only their files are left to deal with.
    $this->disk->put('assets/Photos/newer.jpg', 'newer upload');
    $this->disk->put('assets/.objects/ne/newer-blob', 'newer upload');
    $this->disk->delete($kept->path);

    $result = $this->updater->restore($this->backupName);

    expect($this->disk->get($kept->path))->toBe('kept original')
        ->and($this->disk->exists('assets/Photos/kept.jpg'))->toBeTrue()
        ->and($this->disk->exists('assets/Photos/newer.jpg'))->toBeFalse()
        ->and($this->disk->exists('assets/.objects/ne/newer-blob'))->toBeFalse()
        ->and(is_file($result['previous'].'/Photos/newer.jpg'))->toBeTrue()
        ->and($result['checked'])->toBe(1)
        ->and($result['recovered'])->toBe(0)
        ->and($this->updater->verifyObjects()['missing'])->toBe([]);
});

it('takes a blob the archive lacks from the replaced tree by hash and fails on one it cannot find', function () {
    $this->disk->put('assets/Photos/a.jpg', 'a');
    $this->updater->archiveAssetFiles($this->backupDir);

    $late = damRestoreBlob('written while the archive was taken');
    $lost = damRestoreBlob('never reached disk', write: false);
    // Hash matches the replaced tree's bytes but the row records another size: a copy is not a recovery.
    $resized = damRestoreBlob('recorded at the wrong size');
    DB::table('dam_file_objects')->where('id', $resized->id)->update(['size' => 999]);

    try {
        $this->updater->restore($this->backupName);
        $this->fail('restore should refuse to finish with a file object that has no blob');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($lost->path)
            ->and($e->getMessage())->toContain($resized->path)
            ->and($e->getMessage())->not->toContain($late->path);
    }

    expect($this->disk->get($late->path))->toBe('written while the archive was taken')
        ->and(array_column($this->updater->verifyObjects()['missing'], 'id'))->toBe([$lost->id, $resized->id]);
});

it('leaves the live asset tree untouched when the tables fail to restore', function () {
    Process::fake(["'mysql' *" => Process::result(errorOutput: 'replay failed', exitCode: 1)]);

    $this->disk->put('assets/Photos/a.jpg', 'a');
    $this->updater->archiveAssetFiles($this->backupDir);
    $this->disk->put('assets/Photos/b.jpg', 'b');

    expect(fn () => $this->updater->restore($this->backupName))->toThrow(RuntimeException::class, 'DB restore failed');

    expect($this->disk->exists('assets/Photos/b.jpg'))->toBeTrue()
        ->and(glob($this->disk->path('.dam-restore-*')))->toBe([]);
});

it('refuses a backup whose database dump is missing before touching anything', function () {
    $this->disk->put('assets/Photos/a.jpg', 'a');
    $this->updater->archiveAssetFiles($this->backupDir);
    unlink($this->backupDir.'/dam-tables.sql');
    $this->disk->put('assets/Photos/b.jpg', 'b');

    expect(fn () => $this->updater->restore($this->backupName))->toThrow(RuntimeException::class, 'no database dump');

    expect($this->disk->exists('assets/Photos/b.jpg'))->toBeTrue()
        ->and(glob($this->disk->path('.dam-restore-*')))->toBe([]);
});
