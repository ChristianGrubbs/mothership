<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Database\Connection;

/**
 * Which transactions hold the `DAM_ACCESS_MODEL` row, per connection (ADR 0010's fence, sharing slice 2a).
 *
 * This is bookkeeping for *detection*. It answers two questions — "is this the outermost transaction the
 * application opened?", for DirectoryTreeLock's hold, and "did this writer's caller take the fence?", for
 * AccessListWriter's report — and it is never what makes a write safe. The write is safe because
 * AccessListWriter::fenced() takes the hold itself, every time, through AccessModel::holding(). A stale record
 * can therefore cost an unreported unfenced caller, never an unfenced write.
 *
 * Bound as a singleton, and deliberately not part of the scoped AccessModel: scoped instances are rebuilt
 * between requests and queue jobs, and more than one can exist in a process, while a connection's transactions
 * outlive all of that. Keeping the state here means resolving an AccessModel inside an open transaction loses
 * nothing, and every AccessModel in the process reads the same ledger.
 *
 * Two derived quantities, never counted:
 *
 * 1. **The baseline** — the physical transaction level the connection sat at before the application opened its
 *    outermost transaction. Captured the first time a beginning is seen with no baseline recorded, and dropped
 *    when the level returns to it or below. It is 0 in production and 1 under Laravel's DatabaseTransactions
 *    test wrapper, whose transaction fires no event and is nobody's business here; nothing else in this
 *    codebase opens a transaction the DAM does not, so a deeper baseline does not arise. The ledger is a
 *    singleton created when the DAM provider registers, so it sees the connection before any request or job
 *    transaction opens.
 * 2. **The application's depth** — the physical level minus that baseline, recomputed on every question. It is
 *    never kept by per-event increments: `rollBack($toLevel)` drops several levels and fires one event, and
 *    Laravel resets the level with no event at all when a commit or a rollback loses the connection
 *    (Database\Concerns\ManagesTransactions::handleCommitTransactionException and handleRollBackException), so
 *    a counter drifts and then reports an ordinary writer as unfenced (Codex review of PR #74 round 3,
 *    finding 1).
 *
 * Holds are keyed to the physical level they were taken at, and to the PDO instance, and are reconciled on
 * every signal the connection gives:
 * - a transaction beginning at level L means nothing can still be held at L or deeper, whatever ended it and
 *   however — which is also how an afterCommit callback's transaction is stopped from inheriting one, since
 *   Laravel runs those callbacks before it fires TransactionCommitted;
 * - a rollback releases every lock above the level on its event, however many levels that is;
 * - a commit down to the baseline ends every hold, and a commit above it moves each hold down one level,
 *   because a released savepoint hands the locks it took to its parent;
 * - a new PDO's transactions are its own.
 */
final class AccessFenceLedger
{
    /** @var array<string, int> the level each connection sat at before the application's outermost transaction */
    private array $baseline = [];

    /** @var array<string, list<int>> the physical levels at which the row is held */
    private array $holds = [];

    /** @var array<string, int|null> the PDO each connection's holds were taken on */
    private array $pdo = [];

    /** One transaction began on $connection. */
    public function began(Connection $connection): void
    {
        $name = $connection->getName();
        $level = $connection->transactionLevel();

        $this->prune(connection: $name, above: $level - 1);

        $this->baseline[$name] ??= $level - 1;
    }

    /** One transaction committed, leaving $connection at its current level. */
    public function committed(Connection $connection): void
    {
        $name = $connection->getName();
        $level = $connection->transactionLevel();

        if ($level <= ($this->baseline[$name] ?? 0)) {
            $this->reset(connection: $name);

            return;
        }

        if (isset($this->holds[$name])) {
            $this->holds[$name] = array_map(fn (int $held): int => min($held, $level), $this->holds[$name]);
        }
    }

    /** One transaction rolled back, leaving $connection at its current level. */
    public function rolledBack(Connection $connection): void
    {
        $this->prune(connection: $connection->getName(), above: $connection->transactionLevel());
    }

    /**
     * Whether the transaction just opened on $connection is the outermost one the application opened.
     *
     * Derived from the level and the baseline, so a multi-level rollback and an ending that fired no event both
     * leave it correct.
     */
    public function outermost(Connection $connection): bool
    {
        return $connection->transactionLevel() === ($this->baseline[$connection->getName()] ?? 0) + 1;
    }

    /** Whether the current transaction on $connection is recorded as holding the state row. */
    public function holds(Connection $connection): bool
    {
        $name = $connection->getName();

        if (($this->holds[$name] ?? []) === []) {
            return false;
        }

        // Nothing is held outside a transaction, and a reconnect's transactions are its own.
        if ($connection->transactionLevel() === 0 || ($this->pdo[$name] ?? null) !== $this->pdoId($connection)) {
            $this->reset(connection: $name);

            return false;
        }

        return true;
    }

    /** Records that the transaction the caller is in now holds the state row, at its own physical level. */
    public function record(Connection $connection): void
    {
        $name = $connection->getName();
        $pdo = $this->pdoId($connection);

        if (($this->pdo[$name] ?? null) !== $pdo) {
            unset($this->holds[$name]);
            $this->pdo[$name] = $pdo;
        }

        $this->holds[$name][] = max(1, $connection->transactionLevel());
    }

    /**
     * Drops every hold deeper than $above. When $above has fallen to the baseline, the application's outermost
     * transaction is over and the baseline goes with the holds.
     */
    private function prune(string $connection, int $above): void
    {
        if ($above <= ($this->baseline[$connection] ?? 0)) {
            $this->reset(connection: $connection);

            return;
        }

        if (($this->holds[$connection] ?? []) === []) {
            return;
        }

        $held = array_values(array_filter($this->holds[$connection], fn (int $hold): bool => $hold <= $above));

        if ($held === []) {
            unset($this->holds[$connection], $this->pdo[$connection]);

            return;
        }

        $this->holds[$connection] = $held;
    }

    private function reset(string $connection): void
    {
        unset($this->baseline[$connection], $this->holds[$connection], $this->pdo[$connection]);
    }

    /** The identity of the connection's current PDO, or null when it has none open. */
    private function pdoId(Connection $connection): ?int
    {
        $pdo = $connection->getRawPdo();

        return $pdo === null ? null : spl_object_id($pdo);
    }
}
