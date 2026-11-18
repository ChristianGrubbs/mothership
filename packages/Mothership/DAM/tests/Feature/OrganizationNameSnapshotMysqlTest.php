<?php

namespace Mothership\DAM\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Organization\LabelService;
use Mothership\DAM\Services\Organization\SectionService;
use Mothership\DAM\Support\OrganizationNameLock;
use Mothership\DAM\Support\OrganizationNameLockTimeout;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The organization name lock on MySQL and MariaDB InnoDB, where REPEATABLE READ answers a transaction's plain reads
 * from the snapshot its first read took (UX eval rev-J3 findings 1 and 2):
 *
 *  - A request inside an outer transaction (every web request that wraps one) reads, then waits for the scope lock
 *    while another session creates or renames a name and commits. Once it has the lock its uniqueness check must see
 *    that name: the check is a locking read, which InnoDB answers from the latest committed rows whatever the
 *    snapshot. A plain read would pass the check and store a second name.
 *  - The same request, when the holder outlasts `dam.organize.lock_wait`, gets the timeout. Laravel reports the driver's
 *    lock wait timeout there as a DeadlockException wrapping the QueryException; it must still be recognised, and the
 *    session's innodb_lock_wait_timeout must be back where it was.
 *
 *  - A call that times out nested in a caller's transaction aborts that whole transaction: the timeout propagates as an
 *    OrganizationNameLockTimeout and the caller's DB::transaction rolls back its own writes and the call's (InnoDB
 *    rolls back only the failed statement, and Laravel leaves the call's savepoint, so catching it and committing would
 *    keep the call's writes). At the top level a timeout is a 409 and writes nothing.
 *  - With separate read and write connections the lock wait timeout is read from, and put back on, the write session.
 *
 * The first writer's transaction is open (holding the lock) while the second, in a forked process with its own
 * connection and its snapshot already taken, is observed in LOCK WAIT. Commits real rows (no DatabaseTransactions
 * wrapper) and removes everything above the ids each table started at. MySQL and MariaDB only: PostgreSQL's READ
 * COMMITTED re-reads every statement (OrganizationNameRacePostgresTest), and SQLite has one writer.
 */
final class OrganizationNameSnapshotMysqlTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    private const SECOND = 'revj3_second_writer';

    private const SPLIT = 'revj4_split';

    private string $defaultConnection = '';

    /** Set once setUp has admitted the driver; a skipped run (any other driver) never reaches a query or a write, here or in tearDown. */
    private bool $admitted = false;

    /** Children before parents. */
    private const TABLES = ['dam_labels', 'dam_saved_searches'];

    /** @var array<string, int> */
    private array $maxIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The REPEATABLE READ snapshot cases run on MySQL and MariaDB; PostgreSQL has OrganizationNameRacePostgresTest.');
        }

        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'MySQL acceptance requires pcntl and posix; missing extensions must fail the gate.');
        $this->assertContains(config('database.connections.'.DB::getDefaultConnection().'.host'), ['127.0.0.1', 'localhost'], 'Committed race fixtures never run against a remote database.');
        $this->defaultConnection = DB::getDefaultConnection();
        $this->assertSame('REPEATABLE-READ', DB::selectOne('select @@transaction_isolation as level')->level);
        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        $this->recordResidueBaseline();

        foreach (self::TABLES as $table) {
            $this->maxIds[$table] = (int) DB::table($table)->max('id');
        }

        $this->admitted = true;
    }

    protected function tearDown(): void
    {
        if (! $this->admitted) {
            parent::tearDown();

            return;
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach (self::TABLES as $table) {
            if (isset($this->maxIds[$table])) {
                DB::table($table)->where('id', '>', $this->maxIds[$table])->delete();
            }
        }

        DB::table('dam_configuration')->where('key', 'like', 'organize.lock.%')->orWhere('key', 'like', 'revj4.%')->delete();
        DB::purge(self::SECOND);
        DB::setDefaultConnection($this->defaultConnection);
        DB::purge(self::SPLIT);
        $this->assertNoCommittedResidue();

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function winners(): array
    {
        return [
            'a create, then a create of the same name'      => ['create'],
            'a create, then a rename to the same name'      => ['rename'],
            'a create at the root, then a move to the root' => ['move'],
            'a section create, then a section rename'       => ['section'],
        ];
    }

    #[DataProvider('winners')]
    public function test_a_waiting_outer_transaction_sees_the_name_committed_after_its_snapshot(string $case): void
    {
        $labels = app(LabelService::class);
        $sections = app(SectionService::class);
        $folder = $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Folder'], viewerId: null);
        $draft = $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Draft'], viewerId: null);
        $plan = $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Plan', 'parent_id' => $folder->id], viewerId: null);
        $video = $sections->create(['name' => 'Video'], null);
        // The scope's anchor row exists (committed), so the second writer waits on the row lock itself.
        OrganizationNameLock::run(scope: 'label.shared.shared', callback: fn () => null);
        OrganizationNameLock::run(scope: 'section', callback: fn () => null);

        $this->race(
            second: fn () => $this->inOuterTransaction(match ($case) {
                'create'  => fn () => app(LabelService::class)->create(kind: Label::KIND_SHARED, attributes: ['name' => 'report'], viewerId: null),
                'rename'  => fn () => app(LabelService::class)->update(Label::query()->findOrFail($draft->id), ['name' => 'REPORT'], null),
                'move'    => fn () => app(LabelService::class)->update(Label::query()->findOrFail($plan->id), ['parent_id' => null], null),
                'section' => fn () => app(SectionService::class)->update(SavedSearch::query()->findOrFail($video->id), ['name' => 'print'], null),
            }),
            first: fn () => match ($case) {
                'create', 'rename' => $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Report'], viewerId: null),
                'move'             => $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'plan'], viewerId: null),
                'section'          => $sections->create(['name' => 'Print'], null),
            },
            result: function (array $second) use ($case, $draft, $plan, $folder, $video): void {
                $this->assertSame(['invalid' => ['name'], 'wait_restored' => true], $second, 'The second writer passed its name check from the snapshot it took before waiting.');

                match ($case) {
                    'create'  => $this->assertSame(1, Label::query()->whereRaw('LOWER(name) = ?', ['report'])->count()),
                    'rename'  => $this->assertSame('Draft', Label::query()->findOrFail($draft->id)->name),
                    'move'    => $this->assertSame($folder->id, (int) Label::query()->findOrFail($plan->id)->parent_id),
                    'section' => $this->assertSame('Video', SavedSearch::query()->findOrFail($video->id)->name),
                };
            },
        );
    }

    public function test_a_holder_that_outlasts_the_wait_aborts_the_outer_transaction(): void
    {
        $labels = app(LabelService::class);

        $this->race(
            second: function (): array {
                config(['dam.organize.lock_wait' => 1]);

                return $this->inOuterTransaction(fn () => app(LabelService::class)->create(kind: Label::KIND_SHARED, attributes: ['name' => 'late'], viewerId: null));
            },
            first: fn () => $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Early'], viewerId: null),
            result: function (array $second): void {
                $this->assertSame(['timeout' => true, 'wait_restored' => true], $second);
                $this->assertSame(['Early'], Label::query()->pluck('name')->all());
            },
            commitOnResult: true,
        );
    }

    /** @return array<string, array{0: bool}> */
    public static function nesting(): array
    {
        return ['inside the caller\'s transaction' => [true], 'at the top level' => [false]];
    }

    #[DataProvider('nesting')]
    public function test_a_timeout_keeps_nothing_of_the_failed_call(bool $nested): void
    {
        DB::table('dam_configuration')->insert(['key' => 'revj4.held', 'value' => null]);

        $this->race(
            second: function () use ($nested): array {
                config(['dam.organize.lock_wait' => 1]);
                $outcome = [];
                $call = function (): void {
                    OrganizationNameLock::run(scope: 'label.shared.shared', callback: function (): void {
                        // The call writes, then waits on a row the first writer holds, and runs out of time.
                        DB::table('dam_configuration')->insert(['key' => 'revj4.inner', 'value' => null]);
                        DB::table('dam_configuration')->where('key', 'revj4.held')->lockForUpdate()->first();
                    });
                };

                try {
                    if ($nested) {
                        DB::transaction(function () use ($call): void {
                            DB::table('dam_configuration')->insert(['key' => 'revj4.outer', 'value' => null]);
                            $call();
                        });
                    } else {
                        $call();
                    }
                } catch (OrganizationNameLockTimeout) {
                    $outcome['timeout'] = true;
                } catch (HttpException $error) {
                    $outcome['status'] = $error->getStatusCode();
                }

                return $outcome + ['level' => DB::transactionLevel()];
            },
            first: fn () => DB::table('dam_configuration')->where('key', 'revj4.held')->lockForUpdate()->first(),
            result: function (array $second) use ($nested): void {
                $this->assertSame($nested ? ['timeout' => true, 'level' => 0] : ['status' => 409, 'level' => 0], $second);
                $this->assertSame(
                    ['revj4.held'],
                    DB::table('dam_configuration')->where('key', 'like', 'revj4.%')->orderBy('key')->pluck('key')->all(),
                    'Neither the caller\'s writes nor the failed call\'s may survive the timeout.',
                );
            },
            commitOnResult: true,
        );
    }

    public function test_the_lock_wait_timeout_is_read_from_and_put_back_on_the_write_session(): void
    {
        $base = config('database.connections.'.$this->defaultConnection);
        config(['database.connections.'.self::SPLIT => $base + ['read' => ['host' => [$base['host']]], 'write' => ['host' => [$base['host']]], 'sticky' => false]]);
        DB::setDefaultConnection(self::SPLIT);
        DB::purge(self::SPLIT);

        $wait = fn (bool $reader) => (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as wait', [], useReadPdo: $reader)->wait;
        DB::statement('SET SESSION innodb_lock_wait_timeout = 17');
        $this->assertSame([17, 50], [$wait(false), $wait(true)], 'The fixture needs a reader whose session differs from the writer\'s.');

        OrganizationNameLock::run(scope: 'section', callback: fn () => null);
        $this->assertSame(17, $wait(false), 'A successful call must put the writer\'s value back, not the reader\'s.');

        try {
            OrganizationNameLock::run(scope: 'section', callback: fn () => throw new \RuntimeException('refused'));
        } catch (\RuntimeException) {
        }
        $this->assertSame(17, $wait(false), 'A failed call must put the writer\'s value back, not the reader\'s.');
    }

    /**
     * Runs $work the way a request does: inside an outer transaction whose first plain read fixes the snapshot.
     *
     * @return array<string, mixed>
     */
    private function inOuterTransaction(Closure $work): array
    {
        $before = (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as wait')->wait;
        DB::beginTransaction();
        DB::table('dam_labels')->count();

        try {
            $work();
            $outcome = ['ok' => true];
        } catch (ValidationException $error) {
            $outcome = ['invalid' => array_keys($error->errors())];
        } catch (OrganizationNameLockTimeout) {
            $outcome = ['timeout' => true];
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        return $outcome + ['wait_restored' => (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as wait')->wait === $before];
    }

    /**
     * $first runs in a transaction this process keeps open; $second runs in a forked process with its own connection
     * and must be seen in LOCK WAIT on a lock $first holds before $first commits. $first commits as soon as it sees the
     * wait, or, with $commitOnResult, only once $second has finished (so a timeout can run out).
     */
    private function race(Closure $second, Closure $first, Closure $result, bool $commitOnResult = false): void
    {
        $resultDir = sys_get_temp_dir().'/revj3-race-'.bin2hex(random_bytes(8));
        mkdir($resultDir, 0700);
        $pid = null;
        // Neither process inherits an open database socket. Each opens its own after the fork.
        DB::purge();
        DB::purge(self::SECOND);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                try {
                    DB::setDefaultConnection(self::SECOND);
                    file_put_contents($resultDir.'/pid', (string) DB::selectOne('select connection_id() as id')->id);
                    $deadline = microtime(true) + 10;
                    while (! is_file($resultDir.'/go')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('The first writer never released the start barrier.');
                        }
                        usleep(10000);
                    }
                    file_put_contents($resultDir.'/result', json_encode($second(), JSON_THROW_ON_ERROR));
                } catch (Throwable $error) {
                    file_put_contents($resultDir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }

            DB::beginTransaction();
            $first();
            file_put_contents($resultDir.'/go', '1');

            $deadline = microtime(true) + 10;
            $waiting = false;
            do {
                if (is_file($resultDir.'/pid')) {
                    $thread = (int) file_get_contents($resultDir.'/pid');
                    // A row lock the second connection is waiting for (performance_schema.data_lock_waits names the requesting thread).
                    $waiting = DB::selectOne('select count(*) as n from performance_schema.data_lock_waits w join performance_schema.threads t on t.thread_id = w.requesting_thread_id where t.processlist_id = ?', [$thread])->n > 0;
                }
                if ($waiting || is_file($resultDir.'/error') || is_file($resultDir.'/result')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second writer must wait on the scope lock the first holds, before the first commits: '.(is_file($resultDir.'/result') ? 'it finished without waiting: '.file_get_contents($resultDir.'/result') : (is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'it did not wait')));

            if ($commitOnResult) {
                $deadline = microtime(true) + 15;
                while (! is_file($resultDir.'/result') && ! is_file($resultDir.'/error') && microtime(true) < $deadline) {
                    usleep(10000);
                }
            }
            DB::commit();

            $deadline = microtime(true) + 15;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The second writer did not finish.');
            $result(json_decode(file_get_contents($resultDir.'/result'), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(self::SECOND);
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
        }
    }
}
