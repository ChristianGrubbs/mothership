<?php

/*
 * dam:storage:backup, the backup step of the reclaim gate: DamUpdater's table dump and asset archive, taken
 * together under one name. The dump client is faked the way the DamUpdater tests fake it; tar runs for real.
 */

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\TarListing;

beforeEach(function () {
    $this->before = app(DamUpdater::class)->listBackups();

    // Re-describe the live connection as MySQL, as the DamUpdater tests do, so the dump runs through a faked
    // client instead of switching database.default.
    $default = config('database.default');
    config(["database.connections.{$default}" => array_merge(config("database.connections.{$default}"), [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'username' => 'root',
        'database' => 'mothership',
    ])]);
});

afterEach(function () {
    foreach (array_diff(app(DamUpdater::class)->listBackups(), $this->before) as $name) {
        File::deleteDirectory(storage_path('dam-backups/'.$name));
    }
});

it('writes the table dump and the asset archive as one named pair and prints it', function () {
    Storage::fake(Directory::getAssetDisk());
    Storage::disk(Directory::getAssetDisk())->put('assets/Root/kept.txt', 'kept');
    // The faked client writes the file it was asked for; tar is not faked.
    Process::fake(["'mysqldump' *" => function (PendingProcess $process) {
        $command = $process->command;
        file_put_contents($command[array_search('-r', $command, true) + 1], "-- dump\n");

        return Process::result();
    }]);

    $exit = Artisan::call('dam:storage:backup');
    $printed = json_decode(strtok(Artisan::output(), "\n"), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($printed['name'])->toMatch('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(-\d+)?$/')
        ->and(array_values(array_diff(app(DamUpdater::class)->listBackups(), $this->before)))->toBe([$printed['name']])
        ->and($printed['sql'])->toBe(storage_path("dam-backups/{$printed['name']}/dam-tables.sql"))
        ->and($printed['files'])->toBe(storage_path("dam-backups/{$printed['name']}/dam-files.tgz"))
        ->and($printed['sql_bytes'])->toBe(8)
        ->and($printed['files_bytes'])->toBe(filesize($printed['files']))
        ->and(collect(iterator_to_array(TarListing::entries($printed['files']), false))->firstWhere('name', 'assets/Root/kept.txt'))
        ->toBe(['name' => 'assets/Root/kept.txt', 'size' => 4, 'type' => 'file']);

    // The name it prints is the one dam:storage:reclaim --backup takes.
    Artisan::call('dam:storage:reclaim', ['--backup' => $printed['name']]);
    $reclaim = json_decode(strtok(Artisan::output(), "\n"), true, 512, JSON_THROW_ON_ERROR);

    expect($reclaim['refused'])->toBeNull()
        ->and($reclaim['backup'])->toMatchArray(['name' => $printed['name'], 'dump_bytes' => 8, 'covered' => true]);
});

it('refuses an asset disk that is not local and writes nothing', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS]);
    Storage::fake(Directory::ASSETS_DISK_AWS);
    Process::fake();

    $this->artisan('dam:storage:backup')
        ->expectsOutputToContain('needs a local asset disk')
        ->assertExitCode(1);

    Process::assertNothingRan();
    expect(app(DamUpdater::class)->listBackups())->toBe($this->before);
});

it('exits 1 when the dump fails', function () {
    Storage::fake(Directory::getAssetDisk());
    Storage::disk(Directory::getAssetDisk())->put('assets/Root/kept.txt', 'kept');
    Process::fake(["'mysqldump' *" => Process::result(errorOutput: 'access denied', exitCode: 2)]);

    $this->artisan('dam:storage:backup')
        ->expectsOutputToContain('Backup failed: Database dump failed: access denied')
        ->assertExitCode(1);
});
