<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mothership\Core\Database\RollsBackFailedCommits;

/**
 * A COMMIT that fails inside DB::transaction() must end the transaction everywhere (rev-J6).
 *
 * Laravel 13 drops the level to 0 and rethrows without rolling back, leaving PDO in a transaction
 * that still holds the writes and their locks, and leaving the failed transaction's afterCommit
 * callbacks to run on the next commit. These run on their own connection, cloned from the default
 * one: the test's DatabaseTransactions wrapper keeps the default connection above level 1, where
 * no COMMIT is ever sent. Temporary tables keep the rows to that connection's session.
 */
function failedCommitDriver(): string
{
    return config('database.connections.'.config('database.default').'.driver');
}

/** The session-scoped table name: SQL Server spells a local temporary table with a leading #. */
function failedCommitTable(string $name): string
{
    return failedCommitDriver() === 'sqlsrv' ? '#'.$name : $name;
}

function failedCommitConnection(): Connection
{
    config(['database.connections.failed_commit' => config('database.connections.'.config('database.default'))]);

    DB::purge('failed_commit');

    $connection = DB::connection('failed_commit');

    $connection->statement(failedCommitDriver() === 'sqlsrv'
        ? 'create table #failed_commit_parents (id integer primary key)'
        : 'create temporary table failed_commit_parents (id integer primary key)');

    return $connection;
}

/** Makes the next commit on the failed_commit connection fail from a `committing` listener. */
function failNextCommit(Throwable $failure): void
{
    $failed = false;

    Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$failed, $failure): void {
        if ($event->connectionName === 'failed_commit' && ! $failed) {
            $failed = true;

            throw $failure;
        }
    });
}

/**
 * Runs a transaction that writes a row, registers an afterCommit callback and then fails at COMMIT,
 * returning what the caller saw plus whether the callback ever ran (including on a later commit).
 */
function runFailingCommit(Connection $connection, Closure $write, ?Closure $inside = null, int $attempts = 1): array
{
    $callbackRan = false;
    $rolledBackEvents = 0;

    Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use (&$rolledBackEvents): void {
        if ($event->connectionName === 'failed_commit') {
            $rolledBackEvents++;
        }
    });

    $thrown = null;

    try {
        $connection->transaction(function (Connection $connection) use ($write, $inside, &$callbackRan): void {
            $write($connection);

            $connection->afterCommit(function () use (&$callbackRan): void {
                $callbackRan = true;
            });

            $inside && $inside($connection);
        }, $attempts);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $parents = failedCommitTable('failed_commit_parents');

    $state = [
        'thrown'           => $thrown,
        'level'            => $connection->transactionLevel(),
        'pdoInTransaction' => $connection->getPdo()->inTransaction(),
        'parents'          => $connection->table($parents)->count(),
        'rolledBackEvents' => $rolledBackEvents,
    ];

    // An unrelated transaction that commits must not run the failed one's callbacks.
    $connection->transaction(fn (Connection $connection) => $connection->table($parents)->insert(['id' => 2]));

    return $state + ['callbackRan' => $callbackRan];
}

function insertParent(Connection $connection): void
{
    $connection->table(failedCommitTable('failed_commit_parents'))->insert(['id' => 1]);
}

afterEach(function () {
    DB::purge('failed_commit');
});

it('resolves every driver to a connection that rolls back a failed commit', function (string $driver) {
    $connection = Connection::getResolver($driver)(fn () => null, 'db', '', ['driver' => $driver, 'name' => $driver]);

    expect(class_uses_recursive($connection))->toContain(RollsBackFailedCommits::class);
})->with(['pgsql', 'mysql', 'mariadb', 'sqlite', 'sqlsrv']);

it('rolls back when a committing listener throws', function () {
    $connection = failedCommitConnection();
    failNextCommit($failure = new RuntimeException('committing listener failed'));

    $state = runFailingCommit($connection, insertParent(...));

    expect($state['thrown'])->toBe($failure)
        ->and($state['level'])->toBe(0)
        ->and($state['pdoInTransaction'])->toBeFalse()
        ->and($state['parents'])->toBe(0)
        ->and($state['rolledBackEvents'])->toBe(1)
        ->and($state['callbackRan'])->toBeFalse();
});

it('rolls back when a deferred constraint fails at commit', function () {
    if (! in_array(failedCommitDriver(), ['pgsql', 'sqlite'], true)) {
        $this->markTestSkipped('Deferrable foreign keys exist on PostgreSQL and SQLite only.');
    }

    $connection = failedCommitConnection();

    $connection->statement('create temporary table failed_commit_children (id integer primary key, parent_id integer references failed_commit_parents (id) deferrable initially deferred)');

    $state = runFailingCommit($connection, function (Connection $connection): void {
        insertParent($connection);
        $connection->table('failed_commit_children')->insert(['id' => 1, 'parent_id' => 999]);
    });

    expect($state['thrown'])->toBeInstanceOf(PDOException::class)
        ->and($state['level'])->toBe(0)
        ->and($state['pdoInTransaction'])->toBeFalse()
        ->and($state['parents'])->toBe(0)
        ->and($connection->table('failed_commit_children')->count())->toBe(0)
        ->and($state['rolledBackEvents'])->toBe(1)
        ->and($state['callbackRan'])->toBeFalse();
});

it('keeps the commit exception and clears the manager when cleanup callbacks and listeners throw', function () {
    $connection = failedCommitConnection();
    failNextCommit($failure = new RuntimeException('committing listener failed'));

    Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
        if ($event->connectionName === 'failed_commit') {
            throw new RuntimeException('rolled-back listener failed');
        }
    });

    $state = runFailingCommit(
        $connection,
        insertParent(...),
        fn (Connection $connection) => $connection->afterRollBack(fn () => throw new RuntimeException('afterRollBack callback failed')),
    );

    expect($state['thrown'])->toBe($failure)
        ->and($state['level'])->toBe(0)
        ->and($state['pdoInTransaction'])->toBeFalse()
        ->and($state['parents'])->toBe(0)
        ->and($state['callbackRan'])->toBeFalse();
});

it('drops the failed attempt\'s callbacks when a commit-time deadlock is retried', function () {
    $connection = failedCommitConnection();
    failNextCommit(new RuntimeException('Deadlock found when trying to get lock'));

    $callbacks = 0;

    $connection->transaction(function (Connection $connection) use (&$callbacks): void {
        insertParent($connection);

        $connection->afterCommit(function () use (&$callbacks): void {
            $callbacks++;
        });
    }, attempts: 2);

    expect($callbacks)->toBe(1)
        ->and($connection->transactionLevel())->toBe(0)
        ->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->table(failedCommitTable('failed_commit_parents'))->count())->toBe(1);
});

it('keeps the commit exception and clears the manager when reporting a cleanup failure throws', function () {
    $connection = failedCommitConnection();
    failNextCommit($failure = new RuntimeException('committing listener failed'));

    // A reporter that fails outright, as a broken log channel would.
    app()->instance(ExceptionHandler::class, new class implements ExceptionHandler
    {
        public function report(Throwable $e)
        {
            throw new RuntimeException('report failed');
        }

        public function shouldReport(Throwable $e)
        {
            return true;
        }

        public function render($request, Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e) {}
    });

    $state = runFailingCommit(
        $connection,
        insertParent(...),
        fn (Connection $connection) => $connection->afterRollBack(fn () => throw new RuntimeException('afterRollBack callback failed')),
    );

    expect($state['thrown'])->toBe($failure)
        ->and($state['level'])->toBe(0)
        ->and($state['pdoInTransaction'])->toBeFalse()
        ->and($state['callbackRan'])->toBeFalse();
});

it('keeps the commit exception when the retry path cannot roll back', function () {
    if (failedCommitDriver() !== 'sqlite') {
        $this->markTestSkipped('The failing-rollback PDO stand-in is an in-memory SQLite handle.');
    }

    // A PDO whose next rollBack() fails once, as a transport error during the parent's retry cleanup would.
    $pdo = new class('sqlite::memory:') extends PDO
    {
        public bool $failNextRollBack = false;

        public function rollBack(): bool
        {
            if ($this->failNextRollBack) {
                $this->failNextRollBack = false;

                throw new PDOException('rollback transport failure');
            }

            return parent::rollBack();
        }
    };

    $connection = failedCommitConnection();
    $connection->setPdo($pdo);
    $connection->statement('create temporary table failed_commit_parents (id integer primary key)');

    $deadlock = new RuntimeException('Deadlock found when trying to get lock');
    $failed = false;

    Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$failed, $deadlock, $pdo): void {
        if ($event->connectionName === 'failed_commit' && ! $failed) {
            $failed = true;
            $pdo->failNextRollBack = true;

            throw $deadlock;
        }
    });

    $state = runFailingCommit($connection, insertParent(...), attempts: 2);

    expect($state['thrown'])->toBe($deadlock)
        ->and($state['level'])->toBe(0)
        ->and($state['pdoInTransaction'])->toBeFalse()
        ->and($state['parents'])->toBe(0)
        ->and($state['rolledBackEvents'])->toBe(1)
        ->and($state['callbackRan'])->toBeFalse();
});
