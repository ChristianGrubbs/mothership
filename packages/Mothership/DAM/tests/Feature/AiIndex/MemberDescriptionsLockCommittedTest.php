<?php

namespace Mothership\DAM\Tests\Feature\AiIndex;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mothership\DAM\AiIndex\MediaSample;
use Mothership\DAM\AiIndex\MediaSampler;
use Mothership\DAM\AiIndex\MemberDescriptions;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * MemberDescriptions::describe() keeps no lock while the bridge works, then takes the graph lock and re-checks that the
 * very same revision is still a current member before it saves. MemberDescriptionsTest changes the file from inside the
 * request, which cannot show either half: that is one connection. Here a second, independent connection (the promotion
 * or replacement in flight) makes the change, on PostgreSQL only, where lockedForGraph()'s FOR NO KEY UPDATE and
 * lockForUpdate() are real. Waits are bounded with lock_timeout so a broken lock order fails fast instead of hanging CI.
 *
 * 1. The second connection takes the asset row lock and commits a detach while the bridge is answering. It can only get
 *    the lock if describe() holds none during the network call, and the detach having committed, describe() must answer
 *    409 and store no caption for bytes that are no longer a current member.
 * 2. The second connection holds ONLY the asset row lock, uncommitted, before describe() starts (no file or slot row, so no
 *    other lock can stand in for it). The bridge still answers (nothing was locked to read), but the write must wait for that
 *    lock, and nothing is saved. Dropping lockedForGraph() from describe() makes it save over the holder and this case fail.
 * 3. A real forked process runs describe(); a holder keeps the asset lock until pg_stat_activity shows the child blocked on it
 *    (observed, not timed), a detach commits on another connection meanwhile, then the lock is released. The same describe()
 *    resumes, re-checks under its lock, answers 409 and saves nothing. Every wait is bounded and a stuck child is killed.
 */
final class MemberDescriptionsLockCommittedTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'member_description_lock_holder';

    private const TIMEOUT_MS = 300;

    private const WAITED_SECONDS = 0.25;

    /** PostgreSQL's lock_not_available: the session waited for the holder and gave up. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    private bool $prepared = false;

    private ?Asset $stack = null;

    private ?AssetFile $member = null;

    /** @var (callable(): void)|null */
    private $duringBridge = null;

    private int $bridgeCalls = 0;

    private ?string $resultDir = null;

    /** The forked describe() while it is outstanding; null once reaped, so a recycled pid is never signalled. */
    private ?int $child = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('lockedForGraph() and lockForUpdate() are no-ops on SQLite and the waits are bounded with PostgreSQL lock_timeout; other drivers skip.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are not available in this PHP build.');
        }

        $this->resultDir = sys_get_temp_dir().'/dam-member-lock-'.bin2hex(random_bytes(6));
        mkdir($this->resultDir);

        config(['database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection()), 'ai-index.api' => 'http://ai.test']);
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();

        $this->stack = Asset::factory()->create(['file_name' => 'cover.svg', 'extension' => 'svg', 'mime_type' => 'image/svg+xml', 'file_type' => 'image']);
        $cover = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'label' => 'cover.svg']);
        $cover->fileObject->update(['mime_type' => 'image/svg+xml']);
        $this->member = AssetFile::factory()->create(['dam_asset_id' => $this->stack->id, 'role' => 'alternate', 'label' => 'member.png']);
        $this->member->fileObject->update(['mime_type' => 'image/png', 'size' => 7]);
        Storage::disk($this->member->fileObject->disk)->put($this->member->fileObject->path, 'fixture');

        $sampler = Mockery::mock(MediaSampler::class)->makePartial();
        $sampler->shouldReceive('sampleLocalFile')->andReturnUsing(fn ($asset, $file) => new MediaSample(images: ['png'], pages: [], kind: 'image', sha256: $file->fileObject->sha256));
        $this->app->instance(MediaSampler::class, $sampler);

        Http::fake(['ai.test/caption' => function (Request $request) {
            $this->bridgeCalls++;
            ($this->duringBridge)?->__invoke();

            return Http::response(['caption' => 'A blue wordmark on white.', 'tags' => [], 'keywords' => ['logo'], 'visible_text' => '', 'model' => 'm', 'prompt_version' => 'p']);
        }, '*' => Http::response([], 200)]);

        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        $this->terminateChild();

        if ($this->resultDir !== null && is_dir($this->resultDir)) {
            array_map('unlink', glob($this->resultDir.'/*') ?: []);
            @rmdir($this->resultDir);
        }

        if (! $this->prepared) {
            try {
                $this->cleanUpCommittedFixtures();
            } finally {
                parent::tearDown();
            }

            return;
        }

        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $holder = DB::connection(self::HOLDER);

            while ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }

            DB::statement('RESET lock_timeout');
            DB::purge(self::HOLDER);
        } finally {
            $this->cleanUpCommittedFixtures();
            parent::tearDown();
        }
    }

    public function test_a_change_committed_while_the_bridge_answers_is_refused_and_nothing_waits_on_describe(): void
    {
        $this->duringBridge = function () {
            // describe() is between its read and its write: no transaction of its own is open on this connection...
            $this->assertSame(0, DB::transactionLevel(), 'describe() held a transaction open across the bridge call');

            // ...and no lock of its own is held, or this takes the asset row lock only after waiting out the timeout.
            $holder = DB::connection(self::HOLDER);
            $holder->statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
            $holder->beginTransaction();
            $holder->table('dam_assets')->where('id', $this->stack->id)->lock('for no key update')->first(['id']);
            $holder->table('dam_asset_files')->where('id', $this->member->id)->update(['is_current' => false]);
            $holder->commit();
        };

        try {
            app(MemberDescriptions::class)->describe($this->stack, $this->member->id);
            $this->fail('describe() saved a caption for a file that stopped being a current member while the bridge answered');
        } catch (HttpException $stale) {
            $this->assertSame(409, $stale->getStatusCode());
        }

        $this->assertSame(1, $this->bridgeCalls);
        $this->assertSame(0, DB::table('dam_ai_member_descriptions')->where('asset_id', $this->stack->id)->count(), 'a caption was stored for bytes that are no longer a current member');
    }

    public function test_the_write_waits_for_the_asset_lock_instead_of_reading_around_it(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // Only the asset row, as lockedForGraph() takes it: no file or slot row is locked, so the wait below can only be this one.
        // Without lockedForGraph() describe() would go on to lock the (free) slot and file rows and save over the holder.
        $holder->table('dam_assets')->where('id', $this->stack->id)->lock('for no key update')->first(['id']);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
        $started = microtime(true);

        try {
            app(MemberDescriptions::class)->describe($this->stack, $this->member->id);
            $this->fail("describe() did not wait for the holder's asset lock");
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'describe() did not wait for the lock');
        // Reading and describing needed no lock: the bridge was reached while the holder still held its own.
        $this->assertSame(1, $this->bridgeCalls);
        $this->assertSame(0, DB::table('dam_ai_member_descriptions')->where('asset_id', $this->stack->id)->count());
    }

    public function test_a_detach_committed_while_the_write_waits_for_the_lock_is_refused_by_the_same_describe(): void
    {
        $this->raceWithAForkedDescribe(function (int $backend) {
            $this->waitUntilBlockedOnALock($backend);

            // The detach the child is racing against: it touches only the file row, which the holder's asset lock never covers, so it
            // commits at once while the child is still waiting, long after the child's own unlocked read saw the file as current.
            DB::table('dam_asset_files')->where('id', $this->member->id)->update(['is_current' => false]);

            // Releases the asset lock: the same, already-running describe() resumes, and must see the detach under its own lock.
            DB::connection(self::HOLDER)->commit();
            $this->reap();
        });

        $error = is_file($this->resultDir.'/child-error') ? file_get_contents($this->resultDir.'/child-error') : null;
        $this->assertNull($error, "the forked describe() threw: {$error}");
        $this->assertFileDoesNotExist($this->resultDir.'/child-saved', 'the same describe() saved a caption for a file detached while it waited');
        $this->assertSame('409', is_file($this->resultDir.'/child-status') ? file_get_contents($this->resultDir.'/child-status') : null, 'the resumed describe() did not answer 409');
        $this->assertSame(0, DB::table('dam_ai_member_descriptions')->where('asset_id', $this->stack->id)->count(), 'a caption was stored for bytes that are no longer a current member');
    }

    public function test_a_failure_in_the_parent_still_ends_the_forked_child_and_releases_the_holder(): void
    {
        $pid = null;

        try {
            $this->raceWithAForkedDescribe(function (int $backend) use (&$pid) {
                $pid = $this->child;
                $this->waitUntilBlockedOnALock($backend);

                throw new RuntimeException('injected parent failure while the child waits');
            });
            $this->fail('the injected failure did not surface');
        } catch (RuntimeException $injected) {
            $this->assertSame('injected parent failure while the child waits', $injected->getMessage());
        }

        $this->assertNotNull($pid);
        $this->assertNull($this->child, 'the child was left outstanding');
        $this->assertFalse(posix_kill($pid, 0), 'the forked child is still running after the parent failed');
        $this->assertSame(0, DB::connection(self::HOLDER)->transactionLevel(), 'the holder was left open');
        $this->assertSame(0, DB::table('dam_ai_member_descriptions')->where('asset_id', $this->stack->id)->count());
    }

    /**
     * Holds the asset row lock, forks a real second process that runs one describe(), and hands the child's backend pid to
     * $parent. Whatever $parent does (or throws), the child is ended and reaped and the holder rolled back before this returns.
     *
     * @param  callable(int): void  $parent
     */
    private function raceWithAForkedDescribe(callable $parent): void
    {
        $holder = DB::connection(self::HOLDER);

        try {
            $holder->beginTransaction();
            $holder->table('dam_assets')->where('id', $this->stack->id)->lock('for no key update')->first(['id']);

            DB::purge();

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed.');
            }

            if ($pid === 0) {
                $this->runChild();
            }

            $this->child = $pid;
            DB::reconnect();

            $parent($this->waitForBackendPid());
        } finally {
            $this->terminateChild();

            while ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
        }
    }

    /** The forked process's whole life: everything inside the guard, and it never returns into PHPUnit (no inherited teardown, no holder rollback). */
    private function runChild(): never
    {
        try {
            DB::purge();
            DB::reconnect();

            $backend = (string) (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
            // Published whole: written aside, then renamed into place, so the parent never reads a partial value.
            file_put_contents($this->resultDir.'/child-pid.tmp', $backend);
            rename($this->resultDir.'/child-pid.tmp', $this->resultDir.'/child-pid');

            try {
                app(MemberDescriptions::class)->describe($this->stack, $this->member->id);
                file_put_contents($this->resultDir.'/child-saved', '1');
            } catch (HttpException $stale) {
                file_put_contents($this->resultDir.'/child-status', (string) $stale->getStatusCode());
            }
        } catch (Throwable $e) {
            @file_put_contents($this->resultDir.'/child-error', $e::class.': '.$e->getMessage());
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }
    }

    /** The child's backend pid, once it is a whole positive integer; bounded, and a child that never reports fails the test. */
    private function waitForBackendPid(): int
    {
        $deadline = microtime(true) + 10.0;

        do {
            $text = @file_get_contents($this->resultDir.'/child-pid');

            if (is_string($text) && ctype_digit($text) && (int) $text > 0) {
                return (int) $text;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('the forked child never reported its backend pid');
    }

    /** Waits (bounded) until pg_stat_activity shows the backend genuinely blocked on a lock; never a timing guess. */
    private function waitUntilBlockedOnALock(int $backendPid): void
    {
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            if (DB::selectOne("select 1 as found from pg_stat_activity where pid = ? and wait_event_type = 'Lock'", [$backendPid])) {
                return;
            }

            usleep(20000);
        }

        $this->fail('never observed the forked describe() waiting on the asset row lock (pg_stat_activity)');
    }

    /** Waits for the child to exit on its own, bounded; one that will not is killed. Marks it reaped. */
    private function reap(): void
    {
        $deadline = microtime(true) + 15.0;

        while ($this->child !== null) {
            if (pcntl_waitpid($this->child, $status, WNOHANG) !== 0) {
                $this->child = null;

                return;
            }

            if (microtime(true) > $deadline) {
                $this->terminateChild();
                $this->fail('the forked child did not exit');
            }

            usleep(20000);
        }
    }

    /** Idempotent: kills and reaps the child if it is still outstanding, and forgets its pid so it can never be signalled again. */
    private function terminateChild(): void
    {
        if ($this->child === null) {
            return;
        }

        $pid = $this->child;
        $this->child = null;

        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }
}
