<?php

declare(strict_types=1);

namespace Mothership\DAM\Helpers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Database\Seeders\DirectoryTableSeeder;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\DamTables;
use Mothership\DAM\Support\RequestId;

class DamUpdater
{
    public const TRACKED = ['dam_assets', 'dam_directories', 'dam_tags'];

    public function countRows(): array
    {
        $counts = [];
        foreach (self::TRACKED as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function verify(array $before): array
    {
        $after = $this->countRows();
        $dropped = [];

        foreach ($before as $table => $count) {
            if (($after[$table] ?? 0) < $count) {
                $dropped[] = $table;
            }
        }

        return ['ok' => $dropped === [], 'before' => $before, 'after' => $after, 'dropped' => $dropped];
    }

    public function backupDir(string $timestamp): string
    {
        $base = storage_path('dam-backups/'.$timestamp);
        $dir = $base;
        $i = 1;
        while (! @mkdir($dir, 0755, true)) {
            if (! is_dir($dir)) {
                throw new \RuntimeException("Cannot create backup directory: {$dir}");
            }
            $dir = $base.'-'.$i++;
        }

        return $dir;
    }

    private function existingTables(): array
    {
        $tables = array_filter(DamTables::ALL, fn ($t) => Schema::hasTable($t));

        $migrations = $this->migrationsTable();

        if (Schema::hasTable($migrations)) {
            $tables[] = $migrations;
        }

        return array_values($tables);
    }

    private function migrationsTable(): string
    {
        $migrations = config('database.migrations', 'migrations');

        if (is_array($migrations)) {
            return (string) ($migrations['table'] ?? 'migrations');
        }

        return (string) $migrations;
    }

    public function buildDumpCommand(string $sqlPath, ?array $tables = null): array
    {
        $tables ??= DamTables::ALL;

        if ($tables === []) {
            throw new \RuntimeException('Refusing to dump: no DAM tables resolved, which would export the entire database.');
        }

        $prefix = DB::getTablePrefix();

        if ($prefix !== '') {
            $tables = array_map(fn ($t) => $prefix.$t, $tables);
        }

        $conn = config('database.connections.'.config('database.default'));
        $driver = $conn['driver'] ?? 'mysql';

        if ($driver === 'pgsql') {

            return array_merge(
                ['pg_dump', '-c', '--if-exists', '-h', (string) $conn['host'], '-p', (string) $conn['port'],
                    '-U', (string) $conn['username'], '-d', (string) $conn['database'], '-f', $sqlPath],
                $this->flag('-t', $tables),
            );
        }

        return array_merge(
            ['mysqldump', '-h'.$conn['host'], '-P'.$conn['port'], '-u'.$conn['username'], $conn['database']],
            $tables,
            ['-r', $sqlPath],
        );
    }

    private function flag(string $flag, array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[] = $flag;
            $out[] = $v;
        }

        return $out;
    }

    public function archiveAssetFiles(string $backupDir): ?string
    {
        $disk = Directory::getAssetDisk();

        if ($disk === Directory::ASSETS_DISK_AWS) {
            return null;
        }

        if (! Storage::disk($disk)->exists(Directory::ASSETS_DIRECTORY)) {
            return null;
        }

        $root = Storage::disk($disk)->path('');
        $tgz = $backupDir.'/dam-files.tgz';

        $result = Process::timeout(0)->run(['tar', '-czf', $tgz, '-C', $root, Directory::ASSETS_DIRECTORY]);
        if (! $result->successful()) {
            throw new \RuntimeException('Asset file backup failed: '.$result->errorOutput());
        }

        return $tgz;
    }

    public function backup(string $timestamp): array
    {
        $dir = $this->backupDir($timestamp);
        $sql = $dir.'/dam-tables.sql';

        $result = Process::env($this->dbEnv())->timeout(0)->run($this->buildDumpCommand($sql, $this->existingTables()));
        if (! $result->successful()) {
            throw new \RuntimeException('Database dump failed: '.$result->errorOutput());
        }

        return ['dir' => $dir, 'sql' => $sql, 'files' => $this->archiveAssetFiles($dir)];
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    public function publish(): void
    {
        Artisan::call('vendor:publish', ['--tag' => 'dam-config', '--force' => true]);
        Artisan::call('vendor:publish', ['--tag' => 'dam-defaults']);
        Artisan::call('db:seed', ['--class' => DirectoryTableSeeder::class, '--force' => true]);
    }

    public function clearCaches(): void
    {
        Artisan::call('optimize:clear');
    }

    private function dbEnv(): array
    {
        $conn = config('database.connections.'.config('database.default'));
        $driver = $conn['driver'] ?? 'mysql';
        $pass = (string) ($conn['password'] ?? '');

        return $driver === 'pgsql' ? ['PGPASSWORD' => $pass] : ['MYSQL_PWD' => $pass];
    }

    public function listBackups(): array
    {
        $base = storage_path('dam-backups');
        if (! is_dir($base)) {
            return [];
        }
        $dirs = array_values(array_filter(
            scandir($base),
            fn ($d) => $d !== '.' && $d !== '..' && is_dir($base.'/'.$d),
        ));
        rsort($dirs);

        return $dirs;
    }

    /**
     * Restore the DAM tables and the asset tree as one consistent pair.
     *
     * The archive is extracted to a staging directory first, so a corrupt archive or a full disk
     * fails before anything live changes. After the tables are replayed, the staged tree replaces
     * the live one whole, so files written after the backup do not survive into the snapshot;
     * the replaced tree is kept under the returned `previous` path for the operator to delete.
     * Every file object on a local disk must then resolve to an intact blob. A blob the archive
     * lacks is taken from the replaced tree when its sha256 matches, otherwise restore throws.
     *
     * @return array{previous: ?string, checked: int, recovered: int}
     */
    public function restore(string $timestamp): array
    {

        if (! preg_match('/^[\w-]+$/', $timestamp)) {
            throw new \RuntimeException("Invalid backup name: {$timestamp}");
        }

        $dir = storage_path('dam-backups/'.$timestamp);
        if (! is_dir($dir)) {
            throw new \RuntimeException("No backup found: {$timestamp}");
        }

        // Files without their tables would pair historical blobs with current rows.
        $sql = $dir.'/dam-tables.sql';
        if (! is_file($sql) || filesize($sql) === 0) {
            throw new \RuntimeException("Backup {$timestamp} has no database dump; refusing to restore its files alone.");
        }

        $runId = $timestamp.'-'.bin2hex(random_bytes(4));
        $this->log($runId, 'info', 'restore.start', ['backup' => $timestamp]);

        $staging = $this->extractAssetFiles(tgz: $dir.'/dam-files.tgz', runId: $runId);

        try {
            $this->restoreDatabase($sql);
        } catch (\Throwable $e) {
            if ($staging !== null) {
                File::deleteDirectory($staging);
            }
            $this->log($runId, 'error', 'restore.db_failed', ['error' => $e->getMessage()]);

            throw $e;
        }

        $this->log($runId, 'info', 'restore.db_restored', []);

        $previous = $staging === null ? null : $this->swapAssetTree(staging: $staging, runId: $runId);

        $verified = $this->verifyObjects();
        $unresolved = $previous === null
            ? $verified['missing']
            : $this->recoverFromPrevious(missing: $verified['missing'], previous: $previous);
        $recovered = count($verified['missing']) - count($unresolved);

        $this->log($runId, $unresolved === [] ? 'info' : 'error', 'restore.objects_verified', [
            'checked'           => $verified['checked'],
            'recovered'         => $recovered,
            'unresolved'        => count($unresolved),
            'unresolved_sample' => array_map(fn (object $o) => (int) $o->id, array_slice($unresolved, 0, 20)),
        ]);

        if ($unresolved !== []) {
            $sample = array_map(fn (object $o) => $o->disk.':'.$o->path, array_slice($unresolved, 0, 20));

            throw new \RuntimeException(sprintf(
                'Restore wrote the tables and files, but %d file object(s) have no intact blob: %s.%s',
                count($unresolved),
                implode(', ', $sample),
                $previous === null ? '' : ' The replaced asset tree is kept at '.$previous.'.',
            ));
        }

        return [
            'previous'  => $previous,
            'checked'   => $verified['checked'],
            'recovered' => $recovered,
        ];
    }

    /**
     * Check that every file object on a local disk has its blob, at the recorded size.
     *
     * @return array{checked: int, missing: list<object>}
     */
    public function verifyObjects(): array
    {
        if (! Schema::hasTable('dam_file_objects')) {
            return ['checked' => 0, 'missing' => []];
        }

        $checked = 0;
        $missing = [];

        DB::table('dam_file_objects')
            ->select(['id', 'disk', 'path', 'size', 'sha256'])
            ->lazyById(1000)
            ->each(function (object $object) use (&$checked, &$missing): void {
                if (config("filesystems.disks.{$object->disk}.driver") !== 'local') {
                    return;
                }

                $checked++;
                $storage = Storage::disk($object->disk);

                if (! $storage->exists($object->path) || $storage->size($object->path) !== (int) $object->size) {
                    $missing[] = $object;
                }
            });

        return ['checked' => $checked, 'missing' => $missing];
    }

    private function extractAssetFiles(string $tgz, string $runId): ?string
    {
        if (! is_file($tgz)) {
            return null;
        }

        $staging = Storage::disk(Directory::getAssetDisk())->path('.dam-restore-'.$runId);

        if (! @mkdir($staging, 0755, true)) {
            throw new \RuntimeException("Cannot create restore staging directory: {$staging}");
        }

        $r = Process::timeout(0)->run(['tar', '-xzf', $tgz, '-C', $staging]);

        if (! $r->successful() || ! is_dir($staging.'/'.Directory::ASSETS_DIRECTORY)) {
            File::deleteDirectory($staging);

            throw new \RuntimeException('Asset file restore failed: '.$r->errorOutput());
        }

        $this->log($runId, 'info', 'restore.files_staged', ['staging' => $staging]);

        return $staging;
    }

    private function restoreDatabase(string $sql): void
    {
        $conn = config('database.connections.'.config('database.default'));
        $driver = $conn['driver'] ?? 'mysql';

        if ($driver === 'pgsql') {
            // One transaction: without it a failing statement leaves every earlier DROP applied,
            // so an aborted restore strips live foreign keys and tables instead of changing nothing.
            $cmd = ['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-h', (string) $conn['host'], '-p', (string) $conn['port'],
                '-U', (string) $conn['username'], '-d', (string) $conn['database'], '-f', $sql];
            $r = Process::env($this->dbEnv())->timeout(0)->run($cmd);
        } else {

            $cmd = ['mysql', '-h'.$conn['host'], '-P'.$conn['port'], '-u'.$conn['username'], $conn['database']];
            $r = Process::env($this->dbEnv())->timeout(0)->input(fopen($sql, 'r'))->run($cmd);
        }

        if (! $r->successful()) {
            throw new \RuntimeException('DB restore failed: '.$r->errorOutput());
        }
    }

    /**
     * Swap the staged tree in for the live one; returns where the replaced tree now lives, or
     * null when there was no live tree to replace.
     *
     * Each rename is atomic but the pair is not: the swap_begin log record names all three paths
     * so an interrupted swap (live path missing) can be finished or undone by hand.
     */
    private function swapAssetTree(string $staging, string $runId): ?string
    {
        $live = Storage::disk(Directory::getAssetDisk())->path(Directory::ASSETS_DIRECTORY);
        $staged = $staging.'/'.Directory::ASSETS_DIRECTORY;
        $previous = is_dir($live) ? $staging.'/previous-'.Directory::ASSETS_DIRECTORY : null;

        $this->log($runId, 'info', 'restore.swap_begin', ['live' => $live, 'staged' => $staged, 'previous' => $previous]);

        if ($previous !== null && ! @rename($live, $previous)) {
            throw new \RuntimeException("The tables are restored but the live asset tree could not be moved aside: {$live}. The restored tree is at {$staged}.");
        }

        if (! @rename($staged, $live)) {
            if ($previous !== null && ! @rename($previous, $live)) {
                throw new \RuntimeException("The tables are restored but {$live} is now missing: the restored tree could not be moved in and the previous tree could not be moved back. Move {$staged} (restored) or {$previous} (previous) to {$live}.");
            }

            throw new \RuntimeException("The tables are restored but the restored asset tree could not be moved into place; the live tree is unchanged. The restored tree is at {$staged}.");
        }

        if ($previous === null) {
            @rmdir($staging);
        }

        return $previous;
    }

    /**
     * Copy each missing blob back from the replaced tree when its bytes hash to the recorded sha256.
     *
     * @param  list<object>  $missing
     * @return list<object> the objects still unresolved
     */
    private function recoverFromPrevious(array $missing, string $previous): array
    {
        $prefix = Directory::ASSETS_DIRECTORY.'/';
        $unresolved = [];

        foreach ($missing as $object) {
            $candidate = $previous.'/'.substr($object->path, strlen($prefix));

            if ($object->disk !== Directory::getAssetDisk()
                || ! str_starts_with($object->path, $prefix)
                || ! is_file($candidate)
                || hash_file('sha256', $candidate) !== $object->sha256) {
                $unresolved[] = $object;

                continue;
            }

            $target = Storage::disk($object->disk)->path($object->path);
            File::ensureDirectoryExists(dirname($target));

            if (! @copy($candidate, $target) || filesize($target) !== (int) $object->size) {
                $unresolved[] = $object;
            }
        }

        return $unresolved;
    }

    private function log(string $runId, string $level, string $event, array $ctx): void
    {
        $record = [
            'ts'     => now()->toISOString(),
            'run_id' => $runId,
            'level'  => $level,
            'event'  => $event,
            'loc'    => 'DamUpdater::restore',
            'ctx'    => RequestId::ctx($ctx),
        ];

        @file_put_contents(storage_path('logs/dam-restore.jsonl'), json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
