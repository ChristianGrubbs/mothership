<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use RuntimeException;

/**
 * Codex review of PR #30: on MySQL the tree lock was a GET_LOCK released inside run()'s transaction callback, so
 * a waiting writer could take it and read the tree before the holder's rows committed. The lock now lives exactly
 * as long as the outermost transaction on every driver (ADR 0004, note 2026-09-23).
 *
 * These cases commit real rows (DamConcurrencyTestCase, no DatabaseTransactions wrapper) and look from a second
 * session — the view a waiting writer has: whether it could take the lock, and whether it can see the rows.
 */
final class DirectoryTreeLockCommitTest extends DamConcurrencyTestCase
{
    private const PROBE = 'directory_tree_lock_probe';

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
            $this->markTestSkipped('SQLite serializes writers at the file level; there is no second session to probe.');
        }

        config(['database.connections.'.self::PROBE => config('database.connections.'.DB::getDefaultConnection())]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach (array_reverse($this->created) as $id) {
            Directory::find($id)?->delete();
        }

        DB::purge(self::PROBE);

        parent::tearDown();
    }

    public function test_run_keeps_the_lock_until_its_own_transaction_commits(): void
    {
        $root = $this->directory('Tree lock commit '.Str::uuid());

        app(DirectoryTreeLock::class)->run(function () use ($root) {
            $child = $this->directory('child', $root);

            $this->assertFalse($this->otherSessionCanLock(), 'another session took the tree lock mid-write');
            $this->assertFalse($this->otherSessionSees($child), 'the row is visible before commit');
        });

        $this->assertTrue($this->otherSessionCanLock(), 'the lock outlived its transaction');
        $this->assertTrue($this->otherSessionSees(Directory::where('parent_id', $root->id)->firstOrFail()));
    }

    public function test_a_callers_transaction_keeps_the_lock_until_it_commits(): void
    {
        $root = $this->directory('Tree lock caller '.Str::uuid());

        DB::beginTransaction();
        $this->fenceThisTransaction();
        $child = $this->directory('child', $root);

        // run() has returned inside Directory::save(); the rows are still uncommitted, so the lock must be too.
        $this->assertFalse($this->otherSessionCanLock(), 'the lock was freed before the caller committed');
        $this->assertFalse($this->otherSessionSees($child));

        DB::commit();

        $this->assertTrue($this->otherSessionCanLock(), 'the lock outlived the caller transaction');
        $this->assertTrue($this->otherSessionSees($child));
    }

    public function test_a_callers_rollback_frees_the_lock_and_discards_the_rows(): void
    {
        $root = $this->directory('Tree lock rollback '.Str::uuid());

        DB::beginTransaction();
        $this->fenceThisTransaction();
        $child = Directory::create(['name' => 'rolled back', 'parent_id' => $root->id]);
        $this->assertFalse($this->otherSessionCanLock());
        DB::rollBack();

        $this->assertTrue($this->otherSessionCanLock(), 'a rolled-back caller left the lock held on its session');
        $this->assertFalse($this->otherSessionSees($child));
        $this->assertFalse(Directory::isBroken());
    }

    public function test_a_second_writer_waits_for_the_lock_and_fails_as_busy_on_mysql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Lock-wait timeouts map to the busy error only on MySQL/MariaDB.');
        }

        $probe = $this->probe();
        $probe->beginTransaction();
        $probe->table(DirectoryTreeLock::TABLE)->where('id', 1)->lockForUpdate()->first();
        $previousTimeout = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t;
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(DirectoryTreeLock::class)->run(fn () => $this->fail('ran while another session held the lock'));
            $this->fail('run() did not wait for the lock');
        } catch (RuntimeException $e) {
            $this->assertSame('The directory tree is busy; try again shortly.', $e->getMessage());
        } finally {
            $probe->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$previousTimeout);
        }

        $this->assertTrue($this->otherSessionCanLock());
    }

    public function test_a_tree_write_in_an_after_commit_callback_takes_the_lock_again(): void
    {
        $root = $this->directory('Tree lock callback '.Str::uuid());
        $heldDuringCallbackWrite = null;

        app(DirectoryTreeLock::class)->run(function () use ($root, &$heldDuringCallbackWrite) {
            $this->directory('child', $root);

            // Fires inside transaction() after the commit, when the database no longer holds the lock.
            DB::afterCommit(function () use ($root, &$heldDuringCallbackWrite) {
                Directory::withTreeLock(function () use ($root, &$heldDuringCallbackWrite) {
                    $this->directory('after commit', $root);
                    $heldDuringCallbackWrite = ! $this->otherSessionCanLock();
                });
            });
        });

        $this->assertTrue($heldDuringCallbackWrite, 'a tree write in an afterCommit callback re-entered a lock that was already gone');
        $this->assertTrue($this->otherSessionCanLock());
        $this->assertFalse(Directory::isBroken());
    }

    public function test_a_tree_write_in_an_after_rollback_callback_takes_the_lock_again(): void
    {
        $root = $this->directory('Tree lock rollback callback '.Str::uuid());
        $heldDuringCallbackWrite = null;

        try {
            app(DirectoryTreeLock::class)->run(function () use ($root, &$heldDuringCallbackWrite) {
                Directory::create(['name' => 'discarded', 'parent_id' => $root->id]);

                // Fires inside transaction() after the rollback, when the database no longer holds the lock.
                DB::afterRollBack(function () use ($root, &$heldDuringCallbackWrite) {
                    Directory::withTreeLock(function () use ($root, &$heldDuringCallbackWrite) {
                        $this->directory('after rollback', $root);
                        $heldDuringCallbackWrite = ! $this->otherSessionCanLock();
                    });
                });

                throw new RuntimeException('abort the locked body');
            });
            $this->fail('run() swallowed the exception');
        } catch (RuntimeException $e) {
            $this->assertSame('abort the locked body', $e->getMessage());
        }

        $this->assertTrue($heldDuringCallbackWrite, 'a tree write in an afterRollBack callback re-entered a lock that was already gone');
        $this->assertTrue($this->otherSessionCanLock());
        $this->assertSame(0, Directory::where('parent_id', $root->id)->where('name', 'discarded')->count());
        $this->assertFalse(Directory::isBroken());
    }

    public function test_a_lock_wait_timeout_inside_a_callers_transaction_unwinds_it_as_a_concurrency_error(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Lock-wait timeouts are a MySQL/MariaDB path.');
        }

        $probe = $this->probe();
        $probe->beginTransaction();
        $probe->table(DirectoryTreeLock::TABLE)->where('id', 1)->lockForUpdate()->first();
        $previousTimeout = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS t')->t;
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            DB::beginTransaction();

            try {
                app(DirectoryTreeLock::class)->run(fn () => $this->fail('ran while another session held the lock'));
                $this->fail('run() did not wait for the lock');
            } catch (DeadlockException $e) {
                // Not translated to "busy": the caller's transaction may already be gone on the server
                // (innodb_rollback_on_timeout), so it must unwind as Laravel unwinds any concurrency error.
                $this->assertSame(1, DB::transactionLevel(), 'only run()\'s own level was unwound');
            }

            DB::rollBack();
        } finally {
            $probe->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$previousTimeout);
        }

        $root = $this->directory('Tree lock after timeout '.Str::uuid());
        $this->assertTrue($this->otherSessionSees($root), 'the connection is usable again after the timeout');
    }

    public function test_a_missing_lock_row_fails_closed_on_mysql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL/MariaDB lock through the row.');
        }

        DB::table(DirectoryTreeLock::TABLE)->where('id', 1)->delete();

        try {
            Directory::create(['name' => 'Tree lock missing row '.Str::uuid()]);
            $this->fail('a tree write ran without its lock row');
        } catch (RuntimeException $e) {
            $this->assertSame('The directory tree lock row is missing; run the database migrations.', $e->getMessage());
        } finally {
            DB::table(DirectoryTreeLock::TABLE)->insertOrIgnore(['id' => 1]);
        }

        $this->assertSame(0, Directory::where('name', 'like', 'Tree lock missing row %')->count());
    }

    /**
     * Takes ADR 0010's hold on the `DAM_ACCESS_MODEL` row as this transaction's first statement, which is what a
     * caller that opens its own transaction and then writes the tree has to do (sharing slice 2a).
     *
     * These two cases need a transaction they control, so they cannot go through AccessModel::sharedly(), which
     * owns the one it opens; holdingRow() is the same hold taken inside a transaction the caller already has.
     * Without it the folder create below is exactly the unfenced caller AccessListWriter reports, and it threw
     * AccessFenceMissing on CI's PostgreSQL and MySQL legs (run 36213570523) while the SQLite suite never ran
     * this file at all.
     */
    private function fenceThisTransaction(): void
    {
        app(AccessModel::class)->holdingRow(fn () => null);
    }

    private function directory(string $name, ?Directory $parent = null): Directory
    {
        $directory = Directory::create(['name' => $name, 'parent_id' => $parent?->id]);
        $this->created[] = $directory->id;

        return $directory;
    }

    private function probe(): Connection
    {
        return DB::connection(self::PROBE);
    }

    private function otherSessionCanLock(): bool
    {
        $probe = $this->probe();
        $probe->beginTransaction();

        try {
            if ($probe->getDriverName() === 'pgsql') {
                return filter_var(
                    $probe->selectOne('SELECT pg_try_advisory_xact_lock(?) AS ok', [DirectoryTreeLock::KEY])->ok,
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            try {
                $probe->select('SELECT id FROM '.$probe->getTablePrefix().DirectoryTreeLock::TABLE.' WHERE id = 1 FOR UPDATE NOWAIT');

                return true;
            } catch (QueryException) {
                return false;
            }
        } finally {
            $probe->rollBack();
        }
    }

    private function otherSessionSees(Directory $directory): bool
    {
        return $this->probe()->table('dam_directories')->where('id', $directory->id)->exists();
    }
}
