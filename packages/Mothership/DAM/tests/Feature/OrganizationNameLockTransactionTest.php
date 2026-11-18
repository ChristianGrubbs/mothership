<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Support\OrganizationNameLock;
use Mothership\DAM\Support\OrganizationNameLockTimeout;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What a name lock timeout does to the transaction around it, on the transaction bookkeeping Laravel owns. Deliberately
 * NOT DatabaseTransactions (DamConcurrencyTestCase), so "no transaction" is a real level 0: marker rows are committed
 * for real and removed afterwards.
 *
 *  - At the top level the timeout is a 409 and nothing the call wrote is left.
 *  - Nested in a caller's DB::transaction it propagates as an OrganizationNameLockTimeout, so the caller's transaction
 *    rolls back whole (its own writes and the call's), the level is back to 0 and an afterCommit callback registered by
 *    the failed call never runs.
 */
final class OrganizationNameLockTransactionTest extends DamConcurrencyTestCase
{
    private const MESSAGE = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::table('dam_configuration')->where('key', 'like', 'revj5.%')->orWhere('key', 'like', 'organize.lock.revj5%')->delete();

        parent::tearDown();
    }

    public function test_a_top_level_timeout_is_a_409_with_nothing_written(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        try {
            OrganizationNameLock::run(scope: 'revj5', callback: function (): void {
                DB::table('dam_configuration')->insert(['key' => 'revj5.inner', 'value' => null]);

                throw $this->timeout();
            });
            $this->fail('The timeout must be an error.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->markers());
    }

    public function test_a_nested_timeout_aborts_the_whole_outer_transaction(): void
    {
        $afterCommit = false;

        try {
            DB::transaction(function () use (&$afterCommit): void {
                DB::table('dam_configuration')->insert(['key' => 'revj5.outer', 'value' => null]);

                OrganizationNameLock::run(scope: 'revj5', callback: function () use (&$afterCommit): void {
                    DB::table('dam_configuration')->insert(['key' => 'revj5.inner', 'value' => null]);
                    DB::afterCommit(function () use (&$afterCommit): void {
                        $afterCommit = true;
                    });

                    throw $this->timeout();
                });
            });
            $this->fail('The timeout must reach the caller.');
        } catch (OrganizationNameLockTimeout $error) {
            $this->assertInstanceOf(QueryException::class, $this->root($error));
        }

        $this->assertSame(0, DB::transactionLevel(), 'Laravel must have unwound every level.');
        $this->assertSame([], $this->markers(), 'Both the outer and the failed call\'s writes must be gone.');
        $this->assertFalse($afterCommit, 'A callback registered by the failed call must never run.');
    }

    private function timeout(): QueryException
    {
        $previous = new PDOException(self::MESSAGE);
        $previous->errorInfo = ['HY000', 1205, self::MESSAGE];

        return new QueryException('testing', 'select 1', [], $previous);
    }

    private function root(\Throwable $error): \Throwable
    {
        while ($error->getPrevious() !== null && ! $error->getPrevious() instanceof QueryException) {
            $error = $error->getPrevious();
        }

        return $error->getPrevious() ?? $error;
    }

    /** @return list<string> */
    private function markers(): array
    {
        return DB::table('dam_configuration')->where('key', 'like', 'revj5.%')->orderBy('key')->pluck('key')->all();
    }
}
