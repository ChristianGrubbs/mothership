<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Tests\DamConcurrencyTestCase;

/**
 * Codex review of PR #62, round 4: every list rebuild and every change of the access model is serialized on
 * the DAM_ACCESS_MODEL row (ADR 0010). A rebuild that has read the state holds the row FOR UPDATE until it
 * commits, so a switch's finalisation compare-and-set waits for it and can never activate the lists across
 * a rebuild.
 *
 * This case commits real rows (DamConcurrencyTestCase, no DatabaseTransactions wrapper), holds the row lock
 * from a second session, and bounds the wait with PostgreSQL's lock_timeout. Other drivers skip. tearDown puts
 * the state row back as it found it.
 */
final class AccessModelRowLockTest extends DamConcurrencyTestCase
{
    private const HOLDER = 'access_model_row_holder';

    private bool $prepared = false;

    private bool $hadRow = false;

    private ?string $previous = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The row-lock wait is bounded with PostgreSQL lock_timeout; other drivers skip.');
        }

        config(['database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection())]);

        $row = DB::table('dam_configuration')->where('key', AccessModel::KEY)->first();
        $this->hadRow = $row !== null;
        $this->previous = $row?->value;
        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        // A skipped case configured nothing to unwind.
        if (! $this->prepared) {
            parent::tearDown();

            return;
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $holder = DB::connection(self::HOLDER);

        while ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }

        DB::statement('RESET lock_timeout');

        $this->hadRow
            ? DB::table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => $this->previous])
            : DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();

        DB::purge(self::HOLDER);

        parent::tearDown();
    }

    public function test_on_postgresql_a_switch_finalisation_waits_for_the_state_row_lock_and_writes_nothing(): void
    {
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'pending:row-lock']);

        // A paused rebuild, just after its state read: it holds the state row FOR UPDATE.
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $this->assertSame('pending:row-lock', $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value'));

        // The switch's finalisation, as AccessSwitch runs it, from the default session.
        DB::statement("SET lock_timeout = '300ms'");
        $model = new AccessModel;
        $started = microtime(true);

        try {
            $model->transactionally(fn () => $model->compareAndSet(expected: 'pending:row-lock', value: AccessModel::LISTS));
            $this->fail('The finalisation did not wait for the state row lock.');
        } catch (QueryException $timedOut) {
            // lock_not_available: it waited for the holder and gave up, updating nothing.
            $this->assertSame('55P03', $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started, 'the finalisation did not wait for the lock');

        $holder->rollBack();

        $this->assertSame('pending:row-lock', DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'));
    }
}
