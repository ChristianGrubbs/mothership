<?php

namespace Mothership\Core\Database;

use Closure;
use Illuminate\Database\DatabaseTransactionsManager;
use Throwable;

/**
 * Makes a failed top-level COMMIT inside DB::transaction() leave nothing behind.
 *
 * Laravel 13's handleCommitTransactionException() drops the transaction level to 0 and rethrows,
 * but on the last attempt it never rolls back. When COMMIT itself fails (a deferred constraint, a
 * throwing `committing` listener, a commit-time serialization failure), PDO is still inside the
 * transaction with its writes and locks; transactionLevel() reads 0, so a later DB::rollBack() is
 * a no-op. The transactions manager also keeps the failed transaction as current, so its
 * afterCommit callbacks run when the next, unrelated transaction commits.
 *
 * A COMMIT is only sent at level 1, so a failure here always ends the whole transaction: roll back
 * whatever PDO still holds, drop the manager's records (running afterRollBack callbacks), and fire
 * TransactionRolledBack so listeners such as AccessFenceLedger see the ending. The caller always
 * gets the original commit exception; a failure during this cleanup is reported, never rethrown.
 */
trait RollsBackFailedCommits
{
    /**
     * Handle an exception encountered when committing a transaction.
     *
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     *
     * @throws Throwable
     */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        try {
            parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);
        } catch (Throwable $thrown) {
            $this->discardFailedCommit();

            if ($thrown !== $e) {
                // The parent's own retry cleanup failed (e.g. its PDO rollback); keep the original.
                $this->reportCleanupFailure($thrown);
            }

            throw $e;
        }

        // Retrying: the parent rolled the PDO transaction back but left the manager's records.
        $this->discardFailedCommit();
    }

    /**
     * End the transaction whose COMMIT failed, on the server and in Laravel's bookkeeping.
     */
    protected function discardFailedCommit(): void
    {
        try {
            if ($this->getPdo()->inTransaction()) {
                $this->getPdo()->rollBack();
            }
        } catch (Throwable) {
            // A lost connection cannot be rolled back; the server ends that transaction itself.
        }

        $this->transactions = 0;

        if ($this->transactionsManager) {
            try {
                $this->transactionsManager->rollback($this->getName(), $this->transactions);
            } catch (Throwable $callbackFailure) {
                // An afterRollBack callback threw part-way; drop what the manager did not get to.
                $this->forgetManagerRecords($this->transactionsManager);

                $this->reportCleanupFailure($callbackFailure);
            }
        }

        try {
            $this->fireConnectionEvent('rollingBack');
        } catch (Throwable $listenerFailure) {
            $this->reportCleanupFailure($listenerFailure);
        }
    }

    /**
     * Remove every transaction record the manager still holds for this connection, without callbacks.
     */
    protected function forgetManagerRecords(DatabaseTransactionsManager $manager): void
    {
        $connection = $this->getName();

        Closure::bind(function () use ($connection): void {
            $this->currentTransaction[$connection] = null;

            $this->pendingTransactions = $this->pendingTransactions
                ->reject(fn ($transaction) => $transaction->connection == $connection)
                ->values();

            $this->committedTransactions = $this->committedTransactions
                ->reject(fn ($transaction) => $transaction->connection == $connection)
                ->values();
        }, $manager, DatabaseTransactionsManager::class)();
    }

    /**
     * Report a failure that happened while cleaning up after a failed commit; never throws.
     */
    protected function reportCleanupFailure(Throwable $e): void
    {
        try {
            if (function_exists('report')) {
                report($e);
            }
        } catch (Throwable) {
            // A failing reporter must not replace the commit exception the caller is about to get.
        }
    }
}
