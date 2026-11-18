<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\MetadataMapping;
use Mothership\DAM\Services\Automation\MetadataMappingService;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Tests\Support\TestTargetGuard;
use Throwable;

/**
 * A mapping run's totals are read-modify-written by every chunk, and chunks run in parallel workers. `addChunkCounts`
 * reads the totals under a row lock and writes them in the same transaction, so two chunks that finish together add to
 * each other's totals instead of overwriting them.
 *
 * Needs two real PostgreSQL connections: the parent's transaction is open (uncommitted, holding the mapping row lock)
 * while a forked process, on its own connection, has called `addChunkCounts` and is observed blocked on that lock.
 * The totals start nonzero, so a lost update shows as a sum that is short by exactly one side's increment.
 */
final class MappingChunkCountsRacePostgresTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    /** Read by Tests\CreatesApplication before the kernel boots: on PostgreSQL this class never boots providers unadmitted. */
    public const DEMANDS_GUARDED_LANE = true;

    private const WORKER = 's8_chunk_worker';

    public function test_two_chunks_that_finish_together_add_to_the_totals_instead_of_overwriting_them(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two real PostgreSQL connections and row locks; SQLite cannot exercise this race.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'PostgreSQL acceptance requires pcntl and posix; missing extensions must fail the gate.');
        $this->assertTrue(defined(TestTargetGuard::LANE_CONSTANT), 'Committed race fixtures run only in a guarded lane: the local allowlist (phpunit.mothership.xml) or the CI service proof (tests/Support/TestTargetGuard.php); both admit before providers boot.');
        $this->assertContains(config('database.connections.'.DB::getDefaultConnection().'.host'), ['127.0.0.1', 'localhost'], 'Committed race fixtures never run against a remote database.');
        config(['database.connections.'.self::WORKER => config('database.connections.'.DB::getDefaultConnection())]);
        $this->recordResidueBaseline();

        $initial = ['assets' => 3, 'written' => 10, 'skipped' => 5, 'refused' => 2];
        $mapping = MetadataMapping::query()->create([
            'name'            => 's8 chunk totals '.bin2hex(random_bytes(4)),
            'source_key'      => 'exif:s8',
            'target_type'     => 'field',
            'target_id'       => 1,
            'last_run_counts' => $initial,
        ]);

        $parentAdds = ['written' => 7, 'skipped' => 1, 'refused' => 4];
        $workerAdds = ['written' => 20, 'skipped' => 6, 'refused' => 8];

        $resultDir = sys_get_temp_dir().'/s8-chunk-'.bin2hex(random_bytes(8));
        mkdir($resultDir, 0700);
        $pid = null;
        // Neither process inherits an open database socket. Each opens its own after the fork.
        DB::purge();
        DB::purge(self::WORKER);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                try {
                    DB::setDefaultConnection(self::WORKER);
                    DB::statement("SET statement_timeout = '15000ms'");
                    file_put_contents($resultDir.'/pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                    $deadline = microtime(true) + 10;
                    while (! is_file($resultDir.'/go')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('The parent never released the start barrier.');
                        }
                        usleep(10000);
                    }
                    app(MetadataMappingService::class)->addChunkCounts(mappingId: (int) $mapping->id, counts: $workerAdds);
                    file_put_contents($resultDir.'/result', 'done');
                } catch (Throwable $error) {
                    file_put_contents($resultDir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }

            // The parent's chunk takes the mapping row lock and stays open until the worker is waiting on it.
            DB::beginTransaction();
            app(MetadataMappingService::class)->addChunkCounts(mappingId: (int) $mapping->id, counts: $parentAdds);
            file_put_contents($resultDir.'/go', '1');

            $deadline = microtime(true) + 10;
            $waiting = false;
            do {
                if (is_file($resultDir.'/pid')) {
                    $backend = (int) file_get_contents($resultDir.'/pid');
                    $holder = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                    $this->assertNotSame($holder, $backend);
                    // pg_stat_activity is snapshotted for the rest of this transaction: drop the snapshot so every poll sees the current wait.
                    DB::select('select pg_stat_clear_snapshot()');
                    $activity = DB::selectOne('select wait_event_type, query, ? = any(pg_blocking_pids(pid)) as blocked_by_holder from pg_stat_activity where pid = ?', [$holder, $backend]);
                    $waiting = $activity?->wait_event_type === 'Lock' && $activity->blocked_by_holder && str_contains(strtolower($activity->query), 'for update');
                }
                if ($waiting || is_file($resultDir.'/error') || is_file($resultDir.'/result')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second chunk must wait on the mapping row lock the first holds, before the first commits: '.(is_file($resultDir.'/result') ? 'it finished without waiting' : (is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'it did not wait')));
            DB::commit();

            $deadline = microtime(true) + 15;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The second chunk did not finish.');

            $totals = (array) MetadataMapping::query()->findOrFail($mapping->id)->last_run_counts;
            ksort($totals);
            $expected = [
                'assets'  => $initial['assets'],
                'refused' => $initial['refused'] + $parentAdds['refused'] + $workerAdds['refused'],
                'skipped' => $initial['skipped'] + $parentAdds['skipped'] + $workerAdds['skipped'],
                'written' => $initial['written'] + $parentAdds['written'] + $workerAdds['written'],
            ];
            $this->assertSame($expected, $totals, 'Both chunks\' increments must be in the totals; a lost update would leave one side short.');
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(self::WORKER);
            MetadataMapping::query()->whereKey($mapping->id)->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
            $this->assertNoCommittedResidue();
        }
    }
}
