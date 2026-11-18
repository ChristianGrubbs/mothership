<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class TrashPurgeRacePostgresTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    private const PURGER = 's7_trash_purger';

    public static function races(): array
    {
        return [
            'service restore'            => [false, false, false],
            'service restore then trash' => [true, false, false],
            'command restore'            => [false, true, false],
            'command restore then trash' => [true, true, false],
            'service purge wins'         => [false, false, true],
            'command purge wins'         => [false, true, true],
        ];
    }

    #[DataProvider('races')]
    public function test_the_same_purge_invocation_rechecks_after_waiting_for_restore(bool $trashAgain, bool $command, bool $purgeWins): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two real PostgreSQL connections and row locks; SQLite cannot exercise this race.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'PostgreSQL acceptance requires pcntl and posix; missing extensions must fail the gate.');
        $this->assertStringStartsWith('mothership_test_', DB::connection()->getDatabaseName(), 'Committed race fixtures require a disposable test database.');
        $this->assertSame(0, Asset::onlyTrashed()->where('deleted_at', '<', now()->subDays(Asset::TRASH_RETENTION_DAYS))->count(), 'Refuse to purge unrelated expired rows.');
        config(['database.connections.'.self::PURGER => config('database.connections.'.DB::getDefaultConnection())]);
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();
        $folder = Directory::factory()->create();
        $asset = Asset::factory()->create(['path' => 'assets/'.$folder->generatePath().'/s7-race.txt', 'file_name' => 's7-race.txt']);
        $asset->directories()->attach($folder->id);
        $bytes = 's7-original-'.bin2hex(random_bytes(16));
        Storage::disk(Directory::getAssetDisk())->put($asset->path, $bytes);
        $asset->markGraphContentChanged()->save();
        $file = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
        $object = $file->fileObject;
        $this->assertNotNull($object);
        $blob = Storage::disk($object->disk)->get($object->path);
        $fileIds = $asset->files()->pluck('id')->all();
        $directoryIds = $asset->directories()->pluck('dam_directories.id')->all();
        app(AssetTrashService::class)->trash(asset: $asset, force: true);
        Asset::withTrashed()->whereKey($asset->id)->update(['deleted_at' => now()->subDays(Asset::TRASH_RETENTION_DAYS + 1)]);
        $resultDir = sys_get_temp_dir().'/s7-purge-'.bin2hex(random_bytes(8));
        mkdir($resultDir, 0700);
        $pid = null;
        // Neither process inherits an open database socket. Each opens its own after the fork.
        DB::purge();
        DB::purge(self::PURGER);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                try {
                    DB::setDefaultConnection(self::PURGER);
                    DB::statement("SET statement_timeout = '10000ms'");
                    file_put_contents($resultDir.'/pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                    $deadline = microtime(true) + 10;
                    while (! is_file($resultDir.'/go')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Holder never released the start barrier.');
                        }
                        usleep(10000);
                    }
                    if ($purgeWins) {
                        $candidate = Asset::onlyTrashed()->findOrFail($asset->id);
                        try {
                            app(AssetTrashService::class)->restore(asset: $candidate);
                            $result = ['restored' => true];
                        } catch (ModelNotFoundException) {
                            $result = ['restored' => false, 'missing' => Asset::withTrashed()->find($asset->id) === null];
                        }
                    } elseif ($command) {
                        $exit = Artisan::call('dam:trash:purge');
                        $result = ['exit' => $exit, 'counts' => json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
                    } else {
                        $result = ['exit' => 0, 'counts' => app(AssetTrashService::class)->purgeExpired()];
                    }
                    file_put_contents($resultDir.'/result', json_encode($result, JSON_THROW_ON_ERROR));
                } catch (Throwable $error) {
                    file_put_contents($resultDir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }

            DB::beginTransaction();
            Asset::withTrashed()->lockForUpdate()->findOrFail($asset->id);
            file_put_contents($resultDir.'/go', '1');
            $deadline = microtime(true) + 8;
            $waiting = false;
            do {
                if (is_file($resultDir.'/pid')) {
                    $backend = (int) file_get_contents($resultDir.'/pid');
                    $holder = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                    $this->assertNotSame($holder, $backend);
                    // pg_stat_activity is snapshotted for the rest of this transaction (PostgreSQL 15+): drop the snapshot so every poll sees the purger's current wait.
                    DB::select('select pg_stat_clear_snapshot()');
                    $activity = DB::selectOne('select wait_event_type, query, ? = any(pg_blocking_pids(pid)) as blocked_by_holder from pg_stat_activity where pid = ?', [$holder, $backend]);
                    $waiting = $activity?->wait_event_type === 'Lock' && $activity->blocked_by_holder && str_contains(strtolower($activity->query), 'for update');
                }
                if ($waiting || is_file($resultDir.'/error') || is_file($resultDir.'/result')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The losing connection must block on the asset row lock before the winner commits.');

            if ($purgeWins) {
                if ($command) {
                    $this->assertSame(0, Artisan::call('dam:trash:purge'));
                    $counts = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
                } else {
                    $counts = app(AssetTrashService::class)->purgeExpired();
                }
                $this->assertSame(['purged' => 1, 'skipped' => 0, 'failed' => 0], $counts);
            } else {
                $restored = app(AssetTrashService::class)->restore(asset: Asset::onlyTrashed()->findOrFail($asset->id));
                if ($trashAgain) {
                    app(AssetTrashService::class)->trash(asset: $restored, force: true);
                }
            }
            DB::commit();
            $deadline = microtime(true) + 12;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'Purger did not finish.');
            $result = json_decode(file_get_contents($resultDir.'/result'), true, flags: JSON_THROW_ON_ERROR);
            if ($purgeWins) {
                $this->assertSame(['restored' => false, 'missing' => true], $result);
                $this->assertNull(Asset::withTrashed()->find($asset->id));
                $this->assertSame(0, AssetFile::whereIn('id', $fileIds)->count());
                $this->assertSame(0, DB::table('dam_asset_directory')->where('asset_id', $asset->id)->count());
                $this->assertNull(FileObject::find($object->id));
                Storage::disk($object->disk)->assertMissing($object->path);

                return;
            }
            $this->assertSame(['exit' => 0, 'counts' => ['purged' => 0, 'skipped' => 1, 'failed' => 0]], $result);
            $survivor = Asset::withTrashed()->findOrFail($asset->id);
            $this->assertSame($fileIds, $survivor->files()->pluck('id')->all());
            $this->assertSame($directoryIds, $survivor->directories()->pluck('dam_directories.id')->all());
            $this->assertNotNull(FileObject::find($object->id));
            $this->assertSame($blob, Storage::disk($object->disk)->get($object->path));
            $this->assertSame($bytes, $blob);
            $this->assertSame($trashAgain, $survivor->trashed());
            if ($trashAgain) {
                $this->assertTrue($survivor->deleted_at->greaterThan(now()->subMinute()), 'A new trash date must start a new retention window.');
            } else {
                $this->assertNotNull(Asset::find($asset->id), 'A restored live asset must never be purged.');
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(self::PURGER);
            if ($remaining = Asset::withTrashed()->find($asset->id)) {
                app(AssetDeletionService::class)->delete(asset: $remaining, force: true);
            }
            $folder->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
            $this->assertNoCommittedResidue();
        }
    }
}
