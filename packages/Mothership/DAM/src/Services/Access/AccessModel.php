<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\DamConfiguration;
use Throwable;

/**
 * The rollout switch between today's folder grants and the owner-controlled access lists (ADR 0010).
 *
 * The `DAM_ACCESS_MODEL` row in dam_configuration holds one of three states:
 * - absent or `grants`: reads use the grants
 * - `pending:<run>`: a switch to lists is under way, or died unverified; reads still use the grants
 * - `lists`: reads use the lists
 *
 * Only an exact `lists` reads the lists, so every failure falls back to the grants. The row is read
 * straight from the table, not through the DAM middleware's KEY_MAP: that middleware runs only on `dam`
 * routes and casts every value to a boolean, so queued jobs, the REST API and the public routes would
 * otherwise see a different model. The binding is scoped, so reads resolve the model once per request or
 * queue job.
 *
 * Every list rebuild and every change of state runs in transactionally(), which locks this row first; the
 * state itself changes only through compareAndSet().
 */
class AccessModel
{
    public const KEY = 'DAM_ACCESS_MODEL';

    public const GRANTS = 'grants';

    public const LISTS = 'lists';

    /** The prefix of the value a switch to lists holds until it is verified. */
    public const PENDING = 'pending:';

    /** The cache lock dam:access:rebuild and dam:access:switch share. */
    public const LOCK = 'dam-access-model';

    /** How long the lock outlives a run that died before releasing it. */
    public const LOCK_SECONDS = 3600;

    protected ?string $current = null;

    public function current(): string
    {
        return $this->current ??= self::modelFor(raw: $this->raw());
    }

    public function lists(): bool
    {
        return $this->current() === self::LISTS;
    }

    /**
     * The model reads use for a stored state: the lists only for exactly `lists`, the grants for anything
     * else, a missing row, a pending switch or an unknown value included.
     */
    public static function modelFor(?string $raw): string
    {
        return $raw === self::LISTS ? self::LISTS : self::GRANTS;
    }

    /**
     * The row's value as stored, read fresh: null (no row), `grants`, `lists`, `pending:<run>`, or
     * anything else, which reads as grants. For the commands and the report; reads go through current().
     */
    public function raw(): ?string
    {
        return DamConfiguration::find(self::KEY)?->value;
    }

    /**
     * A new `pending:<run>` value, unique to one switch run.
     */
    public static function pending(): string
    {
        return self::PENDING.Str::uuid();
    }

    /**
     * Moves the row from $expected (null: no value) to $value, but only while it still holds $expected: one
     * conditional update, inside transactionally(), which has already locked the row. False means another
     * run changed the model first, and this run has lost the switch.
     */
    public function compareAndSet(?string $expected, string $value): bool
    {
        $row = DB::table('dam_configuration')->where('key', self::KEY);
        $row = $expected === null ? $row->whereNull('value') : $row->where('value', $expected);

        $moved = $row->update(['value' => $value]) === 1;

        $this->flush();

        return $moved;
    }

    public function flush(): void
    {
        $this->current = null;
    }

    /**
     * The one exclusion boundary for rebuilding the lists and switching models: $callback runs as the only
     * rebuild or switch, and receives the lock so it can check it still holds it. The lock is a cache lock,
     * shared by every process that shares the cache store (Redis on the Studio). A run that dies without
     * releasing it leaves it to expire after $seconds.
     *
     * This lock only saves wasted concurrent work. Correctness comes from the state row: transactionally()
     * locks it before any list write or change of state, and compareAndSet() fences every change. So
     * correctness never depends on how long this lock lasts.
     *
     * @template T
     *
     * @param  callable(Lock): T  $callback
     * @return T
     *
     * @throws LockTimeoutException at once when another rebuild or switch holds the lock
     */
    public function exclusively(callable $callback, int $seconds = self::LOCK_SECONDS): mixed
    {
        $lock = Cache::lock(self::LOCK, $seconds);

        if (! $lock->get()) {
            throw new LockTimeoutException('Another dam:access:rebuild or dam:access:switch is running; nothing was written. Run this again once it finishes (a lock left by a run that died expires within an hour).');
        }

        try {
            return $callback($lock);
        } finally {
            // Releases only a lock this run still owns, never one another run has taken since.
            $lock->release();
        }
    }

    /**
     * One DB transaction, serialized on the state row. Its first statements create the row as `grants` if
     * it is missing, then lock it FOR UPDATE until the transaction ends. $callback receives the value read
     * under that lock, and any exception rolls all of it back.
     *
     * Every list rebuild and every change of state runs in here, so none can commit across another: a
     * rebuild can never straddle a switch's activation. Lock order: this row, then the directory tree lock,
     * then the generated role groups.
     *
     * @template T
     *
     * @param  callable(?string): T  $callback
     * @return T
     */
    public function transactionally(callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($callback) {
                $this->flush();

                // The FOR UPDATE hold is stronger than the shared one, so it fences a list write too: a
                // rebuild writes the lists inside here (AccessListRebuilder), through the same writers.
                $raw = $this->lockRow();
                $this->recordHold();

                return $callback($raw);
            });
        } catch (Throwable $exception) {
            // A value the rollback undid must not linger in this request's cache.
            $this->flush();

            throw $exception;
        }
    }

    /**
     * One DB transaction whose first statement holds the state row FOR SHARE, for a writer that maintains
     * the access lists rather than rebuilding them or changing the state (sharing slices 2 and 3).
     *
     * Several such writers run together, and every one of them waits for a rebuild or a switch, which hold
     * the same row FOR UPDATE through transactionally(). So no list write can commit across an activation.
     * $callback receives the value read under the hold; it decides from AccessModel::modelFor(), the same
     * mapping current() uses, so `pending:<run>` and any unknown value count as grants.
     *
     * A missing row is `grants` and $callback receives null. The row is never created here: creation, with
     * its savepoint and unique-race handling, belongs to transactionally(), and a shared hold cannot
     * serialise two creators. Lock order: this row, then the directory tree lock, then the subtree rows.
     *
     * @template T
     *
     * @param  callable(?string): T  $callback
     * @return T
     */
    public function sharedly(callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($callback) {
                $this->flush();

                return $this->holdingRow($callback);
            });
        } catch (Throwable $exception) {
            // A value the rollback undid must not linger in this request's cache.
            $this->flush();

            throw $exception;
        }
    }

    /**
     * ADR 0010's fence for a caller that has already opened its own transaction: DirectoryTreeLock::run(),
     * whose transaction is the outermost one of every folder create, rename, move and delete, and of every
     * job that wraps its whole body in Directory::withTreeLock().
     *
     * The rule: the hold is taken only when this is the outermost transaction the application opened. A
     * nested run() takes nothing, and that is the point — either an enclosing taker already holds the row,
     * in which case there is nothing to add, or nobody does, in which case the enclosing transaction is
     * genuinely unfenced and AccessListWriter::fenced() must report it rather than this call quietly
     * covering for it. A hold taken at a savepoint inside a caller that has already locked rows is the
     * reverse of the order dam:access:switch takes them in, which is the deadlock the order exists to
     * prevent.
     *
     * "Outermost" is this class's own application depth, not transactionLevel(), so it means the same thing
     * under Laravel's DatabaseTransactions wrapper as without it — see $transactions. The one place where
     * Laravel itself used to open a transaction ahead of run(), and only in tests, is closed at the source:
     * DAM's Eloquent builder takes the tree lock before createOrFirst() can open its savepoint.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function fencing(callable $callback): mixed
    {
        if (! $this->ledger()->outermost(DB::connection())) {
            return $callback();
        }

        return $this->holdingRow(fn () => $callback());
    }

    /**
     * Holds the state row FOR SHARE inside a transaction the caller has already opened, and runs $callback
     * under that hold with the value read under it.
     *
     * A missing row is no fence, whatever the value would have read as: PostgreSQL locks no row that is not
     * there, so $callback runs with nothing held and nothing recorded. Migration 2026_09_26_000001 seeds the
     * row as `grants`, so this is the state of a database that has not run that migration yet;
     * AccessListWriter::fenced() then takes the late, exclusive path, which creates the row.
     *
     * @template T
     *
     * @param  callable(?string): T  $callback
     * @return T
     */
    public function holdingRow(callable $callback): mixed
    {
        $row = $this->shareRow();

        if ($row === null) {
            return $callback(null);
        }

        $this->recordHold();

        return $callback($row->value);
    }

    /**
     * ADR 0010's fence, taken rather than trusted: $callback runs inside a transaction that certainly holds
     * the `DAM_ACCESS_MODEL` row, whatever the bookkeeping believed.
     *
     * This is what AccessListWriter::fenced() calls before every list write, and it is what makes the write
     * safe. The three cases:
     * - a transaction is open and the row exists: one idempotent FOR SHARE read, which costs nothing when
     *   this transaction already holds the row — a FOR UPDATE it holds included — and which is a real lock
     *   when a stale record only claimed it did;
     * - the row does not exist: lockRow() creates it in a savepoint and holds it FOR UPDATE, because there
     *   is nothing to share until the row is there;
     * - no transaction is open: one is opened, so the body is atomic with the hold rather than a string of
     *   separately committed statements.
     *
     * A caller that fenced itself first — every caller that should — finds this read waiting for nothing, so
     * "the state row before any other row" still holds for them. A caller that did not is reported by
     * AccessListWriter::fenced() from the bookkeeping, and then runs under the hold this took.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function holding(callable $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $this->holdingInside($callback);
        }

        try {
            return DB::transaction(function () use ($callback) {
                $this->flush();

                return $this->holdingInside($callback);
            });
        } catch (Throwable $exception) {
            // A value the rollback undid must not linger in this request's cache.
            $this->flush();

            throw $exception;
        }
    }

    /**
     * holding()'s body, for a transaction that is already open.
     *
     * Sets current() from the value this hold's own locking read observed, for the duration of $callback,
     * on every path — including when a transaction was already open, where holding() itself never calls
     * flush(). A plain, unlocked current() call from inside $callback would otherwise risk answering from a
     * REPEATABLE READ connection's opening snapshot: shareRow()/lockRow() are locking reads, which
     * lockRow()'s own contract already documents as returning the latest committed row "even under
     * REPEATABLE READ" — a later plain SELECT on the same transaction has no such guarantee, and
     * $this->current, once cached, short-circuits current() before it would even try one (Codex re-review of
     * PR #76, finding 1).
     *
     * The cache is flushed again once $callback returns, success or failure: this hold's own answer is
     * trustworthy only while the lock it came from is held, and every real write to the row already flushes
     * through compareAndSet(). Leaving the value cached past the hold would instead go stale for any caller
     * that changes the row directly and reads current() again afterwards without holding anything itself —
     * exactly what fenced()'s own callers and this class's tests do throughout the rest of the DAM suite.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function holdingInside(callable $callback): mixed
    {
        $row = $this->shareRow();
        $raw = $row !== null ? $row->value : $this->lockRow();

        $this->current = self::modelFor($raw);
        $this->recordHold();

        try {
            return $callback();
        } finally {
            $this->flush();
        }
    }

    /**
     * Whether the current transaction on the current connection is recorded as holding the state row.
     *
     * Detection only — see AccessFenceLedger. It is read before a list write to decide whether the caller
     * fenced itself, never to decide whether the write needs a lock.
     */
    public function holdsRow(): bool
    {
        return $this->ledger()->holds(DB::connection());
    }

    /** Records that the transaction the caller is in now holds the state row. */
    protected function recordHold(): void
    {
        $this->ledger()->record(DB::connection());
    }

    /**
     * The process-wide fence bookkeeping. Resolved rather than injected, so every AccessModel in the process
     * reads the same ledger however it was built, and a scoped instance rebuilt between jobs inherits the
     * connection state its predecessor left.
     */
    protected function ledger(): AccessFenceLedger
    {
        return app(AccessFenceLedger::class);
    }

    /**
     * Holds the state row FOR SHARE and returns it, or null when there is no row to hold. PostgreSQL, MySQL
     * and SQL Server take a real shared row lock; SQLite serialises writers anyway.
     *
     * The row rather than its value, because "no row" and "a row whose value is null" are the same fence to
     * a reader of the value and opposite fences to the lock manager: the first locks nothing.
     */
    protected function shareRow(): ?object
    {
        return DB::table('dam_configuration')->where('key', self::KEY)->sharedLock()->first(['value']);
    }

    /**
     * Locks the state row FOR UPDATE and returns its value, first creating it as `grants` if it is missing.
     *
     * - The insert runs in a savepoint. A creator racing this one then costs only the savepoint, where
     *   PostgreSQL would otherwise abort the whole transaction, and the locked read sees the winner's row.
     * - Not insertOrIgnore(): SQL Server's grammar has no compiler for it.
     * - Locking reads return the latest committed row even under REPEATABLE READ.
     */
    protected function lockRow(): ?string
    {
        $locked = fn () => DB::table('dam_configuration')->where('key', self::KEY)->lockForUpdate()->first(['value']);

        $row = $locked();

        if ($row === null) {
            try {
                DB::transaction(fn () => DB::table('dam_configuration')->insert(['key' => self::KEY, 'value' => self::GRANTS]));
            } catch (UniqueConstraintViolationException) {
                // Another process created the row first; the locked read below returns its value.
            }

            $row = $locked();
        }

        return $row?->value;
    }
}
