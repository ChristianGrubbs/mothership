<?php

namespace Mothership\DAM\Support;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Labels, boards, saved searches and sections refuse a name already taken in their scope, but that check is a read
 * followed by a write. Two requests in flight at once (a double click, a retry, parallel clients) both pass it, so
 * every operation that can change a name, or the scope its uniqueness is judged in (create, rename, move), runs its
 * check and its write inside one transaction that holds a row lock on a per-scope anchor until it commits.
 * NULL parents and case-insensitive names make a plain unique index unsafe across every database this fork supports
 * (and rows that already differ only by case would make adding one fail), so the lock is the same on all of them.
 *
 * The anchor is a `dam_configuration` row per scope, the table AccessModel already uses for its own lock row, and a
 * row nothing else references (so, unlike locking the owner's admin row, it blocks no foreign-key check). It is a
 * database lock, not a cache lease: it cannot expire before the commit that makes the new names visible, and it is
 * released by the commit or rollback itself, even if the process dies. A caller already inside a transaction keeps
 * the lock until that outer transaction ends.
 *
 * A holder that does not finish within `dam.organize.lock_wait` seconds is a lock timeout: PostgreSQL by lock_timeout,
 * MySQL and MariaDB by innodb_lock_wait_timeout. Both limits are set for this call only and put back afterwards (read
 * from, and written to, the write connection), so a nested call does not shorten the waits of the transaction it runs
 * in. Laravel owns the transaction bookkeeping (DB::transaction), and what a timeout becomes depends on where the call
 * runs:
 *
 *  - At the top level (every production caller: the controllers run outside any transaction) Laravel has fully rolled
 *    the call back by the time the timeout reaches here, and the caller answers 409 with nothing written.
 *  - Nested in a caller's transaction a timeout aborts that whole transaction. InnoDB rolls back only the failed
 *    statement, and Laravel does not roll back the call's savepoint for a concurrency error, so the call's earlier
 *    writes would survive a caller that caught the error and committed. The timeout is therefore rethrown as an
 *    OrganizationNameLockTimeout, never mapped or swallowed here: it carries the 409 message and renders as a 409, and
 *    the caller's own DB::transaction rolls back both its writes and the call's. A caller must not catch it and commit.
 *
 * The services' name checks are locking reads (lockForUpdate), because on MySQL REPEATABLE READ a plain read answers
 * from the snapshot the outer transaction took before it waited, and would miss the name the winner just committed.
 */
final class OrganizationNameLock
{
    private const LOCK_TIMEOUT_STATE = '55P03';

    private const MYSQL_LOCK_WAIT_TIMEOUT = 1205;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(string $scope, Closure $callback): mixed
    {
        $nested = DB::transactionLevel() > 0;
        $seconds = max(1, (int) config('dam.organize.lock_wait', 5));
        $session = self::limitInnodbWait(seconds: $seconds);

        try {
            return DB::transaction(function () use ($scope, $callback, $seconds) {
                $previous = self::take(scope: $scope, seconds: $seconds);
                $result = $callback();

                if ($previous !== null) {
                    // A nested call's SET LOCAL would otherwise outlive it inside the outer transaction. (A failed call
                    // is rolled back to its savepoint, which puts the value back by itself.)
                    DB::select('select set_config(?, ?, true)', ['lock_timeout', $previous], useReadPdo: false);
                }

                return $result;
            });
        } catch (QueryException|DeadlockException $error) {
            if (! self::isLockTimeout($error)) {
                throw $error;
            }

            if ($nested) {
                throw new OrganizationNameLockTimeout(previous: $error);
            }

            // Someone else is changing names in this scope right now; the caller can simply try again.
            abort(409, trans('dam::app.admin.organize.busy'));
        } finally {
            if ($session !== null) {
                // The write connection: a split read/write setup must not restore the value from the read replica's session.
                DB::statement('SET SESSION innodb_lock_wait_timeout = '.$session);
            }
        }
    }

    /** The anchor row's key for one scope (a person's boards, the shared labels, a person's searches, the sections). */
    public static function anchorKey(string $scope): string
    {
        return 'organize.lock.'.$scope;
    }

    /** MySQL and MariaDB: the session's wait for this call; returns the value to put back, null elsewhere. */
    private static function limitInnodbWait(int $seconds): ?int
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $previous = (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as wait', [], useReadPdo: false)->wait;
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.$seconds);

        return $previous;
    }

    /** Takes the anchor row's lock; on PostgreSQL returns the lock_timeout it replaced, to put back after a nested call. */
    private static function take(string $scope, int $seconds): ?string
    {
        $previous = null;

        if (DB::getDriverName() === 'pgsql') {
            $previous = (string) DB::selectOne("select current_setting('lock_timeout') as value", [], useReadPdo: false)->value;
            DB::statement('SET LOCAL lock_timeout = '.($seconds * 1000));
        }

        $key = self::anchorKey(scope: $scope);
        // Created on first use; a concurrent first use waits on this insert's commit, then takes the row below.
        DB::table('dam_configuration')->insertOrIgnore(['key' => $key, 'value' => null]);
        DB::table('dam_configuration')->where('key', $key)->lockForUpdate()->first(['key']);

        return $previous;
    }

    private static function isLockTimeout(Throwable $error): bool
    {
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            if ($link instanceof QueryException
                && ((string) $link->getCode() === self::LOCK_TIMEOUT_STATE
                    || ($link->errorInfo[0] ?? null) === self::LOCK_TIMEOUT_STATE
                    || (int) ($link->errorInfo[1] ?? 0) === self::MYSQL_LOCK_WAIT_TIMEOUT)) {
                return true;
            }

            if (str_contains($link->getMessage(), 'Lock wait timeout exceeded')) {
                return true;
            }
        }

        return false;
    }
}
