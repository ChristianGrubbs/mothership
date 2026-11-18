<?php

namespace Mothership\DAM\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Support\RequestId;
use RuntimeException;
use Throwable;

/**
 * The one lock every change to the dam_directories nested set runs under (ADR 0004).
 *
 * kalnoy/nestedset keeps `_lft`/`_rgt` consistent by shifting other rows on every insert, move and
 * delete. Two writers interleaving those shifts — a web request and a queue worker, or two workers —
 * leave duplicate intervals and wrong parents, which is exactly how the production tree broke on
 * 2026-09-13. Locking each row is not enough because the shifts touch rows the writer never loaded, so
 * the whole tree is serialized instead, always with a lock the database frees only when the outermost
 * transaction commits or rolls back: a waiting writer never reads the tree before the holder's rows
 * commit, and a crash can never leave the lock held. PostgreSQL takes a transaction-scoped advisory lock;
 * MySQL/MariaDB lock the single `dam_directory_tree_lock` row `FOR UPDATE` (a session-scoped GET_LOCK
 * needed a manual release that ran before commit — note 2026-09-23); SQLite already serializes writers at
 * the file level.
 *
 * `run()` is re-entrant per connection: a job that takes the lock explicitly (so it can read the tree it
 * is about to change under that same lock) still saves models whose own `save()` calls `run()` again.
 *
 * Since sharing slice 2a it also carries ADR 0010's fence: when `run()` opens the outermost transaction,
 * its first statement holds the `DAM_ACCESS_MODEL` row FOR SHARE, before the tree lock and so before any
 * folder or asset row, so the access lists a tree write maintains can never commit across a switch's
 * activation. Nothing about the lock itself changes.
 */
class DirectoryTreeLock
{
    /** Shared with the one-off production repair of 2026-09-15; never reuse it for another purpose. */
    public const KEY = 74190001;

    public const TABLE = 'dam_directory_tree_lock';

    /** InnoDB "Lock wait timeout exceeded". */
    private const MYSQL_LOCK_WAIT_TIMEOUT = 1205;

    /** @var array<string,int> */
    private array $depth = [];

    public function __construct(private AccessModel $model) {}

    public function run(callable $callback): mixed
    {
        $connection = DB::connection();
        $name = $connection->getName();

        if (($this->depth[$name] ?? 0) > 0) {
            return $callback();
        }

        try {
            return $connection->transaction(function () use ($connection, $callback, $name) {
                // Held only while the body runs: Laravel fires afterCommit/afterRollBack callbacks inside
                // transaction() once the database has already dropped the lock, so a tree write there must
                // take it again rather than re-enter.
                $this->depth[$name] = 1;

                try {
                    // ADR 0010's fence, before the tree lock and so before every folder and asset row this
                    // body touches: every bare folder create, rename and delete goes through Directory's
                    // save()/delete(), and every copy, move and delete job wraps its whole body in
                    // Directory::withTreeLock(), so this one hold fences the lists they maintain. It is
                    // taken only when this transaction is the outermost one the application opened — see
                    // AccessModel::fencing().
                    return $this->model->fencing(function () use ($connection, $callback) {
                        $this->acquire($connection);

                        return $callback();
                    });
                } finally {
                    unset($this->depth[$name]);
                }
            });
        } catch (Throwable $e) {
            // Laravel has already unwound a lock-wait timeout as a concurrency error (MySQL may have rolled back
            // the whole transaction). Only when run() owned that transaction is "busy" the whole story; inside a
            // caller's transaction its DeadlockException must keep unwinding the caller the same way.
            if ($connection->transactionLevel() === 0 && $this->isLockWaitTimeout($e)) {
                throw new RuntimeException('The directory tree is busy; try again shortly.', 0, $e);
            }

            throw $e;
        }
    }

    public function held(): bool
    {
        return ($this->depth[DB::connection()->getName()] ?? 0) > 0;
    }

    private function acquire(Connection $connection): void
    {
        $started = microtime(true);
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $connection->statement('SELECT pg_advisory_xact_lock(?)', [self::KEY]);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->lockRow($connection);
        }

        $this->record('lock.acquired', [
            'driver'  => $driver,
            'wait_ms' => (int) round((microtime(true) - $started) * 1000),
            'level'   => $connection->transactionLevel(),
        ]);
    }

    private function lockRow(Connection $connection): void
    {
        if ($connection->table(self::TABLE)->where('id', 1)->lockForUpdate()->first() === null) {
            // Migration 2026_09_21_000000 seeds the row. Recreating it here would put the lock inside a
            // transaction that can roll back and take the lock with it while the caller carries on.
            throw new RuntimeException('The directory tree lock row is missing; run the database migrations.');
        }
    }

    private function isLockWaitTimeout(Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === self::MYSQL_LOCK_WAIT_TIMEOUT) {
                return true;
            }
        }

        return false;
    }

    private function record(string $event, array $context): void
    {
        if (! config('app.debug')) {
            return;
        }

        $record = [
            'ts'     => now()->toIso8601String(),
            'run_id' => request()->attributes->get('directory_tree_run_id') ?? (string) Str::uuid(),
            'level'  => 'debug',
            'event'  => $event,
            'loc'    => self::class,
            'ctx'    => RequestId::ctx($context),
        ];
        request()->attributes->set('directory_tree_run_id', $record['run_id']);

        @file_put_contents(storage_path('logs/directory-tree.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
