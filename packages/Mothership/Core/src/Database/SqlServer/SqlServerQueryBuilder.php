<?php

namespace Mothership\Core\Database\SqlServer;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Laravel's SqlServerGrammar has no insertOrIgnore compiler, and SQL Server has no
 * INSERT IGNORE. Without knowing the unique key the grammar cannot write a NOT EXISTS
 * guard, so this builder tries the plain insert and, when it hits a unique violation,
 * retries row by row and skips the duplicates. With XACT_ABORT off (SQL Server's default,
 * which nothing here changes) a duplicate-key error ends only its own statement, so a
 * surrounding transaction survives; with it on the transaction is gone, so the error stands.
 */
class SqlServerQueryBuilder extends Builder
{
    /**
     * Insert new records into the database while ignoring unique violations.
     *
     * @return int<0, max>
     */
    public function insertOrIgnore(array $values)
    {
        if (empty($values)) {
            return 0;
        }

        if (! is_array(array_first($values))) {
            $values = [$values];
        }

        try {
            $this->cloneForInsert()->insert($values);

            return count($values);
        } catch (UniqueConstraintViolationException $e) {
            // One duplicate rolls back the whole statement; fall through to row by row.
            $this->rethrowIfTransactionAborted($e);
        }

        $inserted = 0;

        foreach ($values as $row) {
            try {
                $this->cloneForInsert()->insert($row);

                $inserted++;
            } catch (UniqueConstraintViolationException) {
                // Already there: that is what insertOrIgnore asks for.
            }
        }

        return $inserted;
    }

    /**
     * Insert new records from a subquery while ignoring unique violations.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|string  $query
     * @return int
     */
    public function insertOrIgnoreUsing(array $columns, $query)
    {
        try {
            return $this->cloneForInsert()->insertUsing($columns, $query);
        } catch (UniqueConstraintViolationException $e) {
            // Fall back to the selected rows, one at a time.
            $this->rethrowIfTransactionAborted($e);
        }

        [$sql, $bindings] = $this->createSub($query);

        // From the write connection: a replica could lag behind the rows just refused.
        $rows = array_map(
            fn (object $row) => array_combine($columns, array_values((array) $row)),
            $this->connection->select($sql, $bindings, useReadPdo: false)
        );

        // Chunked to stay under SQL Server's 2100 parameters and 1000 rows per INSERT.
        $inserted = 0;

        foreach (array_chunk($rows, max(1, min(1000, intdiv(2000, max(1, count($columns)))))) as $chunk) {
            $inserted += $this->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * Under XACT_ABORT ON a failed statement rolls back the open transaction, and
     * retrying inside it would carry on as if nothing had happened. Bring Laravel's
     * transaction level down to match, best effort, and let the violation stand.
     */
    protected function rethrowIfTransactionAborted(UniqueConstraintViolationException $e): void
    {
        if ($this->connection->transactionLevel() === 0
            || (int) $this->connection->scalar('select @@options & 16384', useReadPdo: false) === 0) {
            return;
        }

        try {
            if ($this->connection instanceof Connection) {
                $this->connection->rollBack(0);
            }
        } catch (Throwable) {
            // The server has no transaction left to roll back; the violation is what matters.
        }

        throw $e;
    }

    /**
     * A fresh builder on the same table, so a failed attempt leaves nothing behind.
     */
    protected function cloneForInsert(): static
    {
        return $this->newQuery()->from($this->from);
    }
}
