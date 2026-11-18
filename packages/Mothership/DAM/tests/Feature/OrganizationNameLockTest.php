<?php

use Illuminate\Database\QueryException;
use Mothership\DAM\Support\OrganizationNameLock;
use Mothership\DAM\Support\OrganizationNameLockTimeout;

/*
 * The name lock is a database row lock held by the writing transaction (the two-connection proof is
 * OrganizationNameRacePostgresTest). What SQLite can show: a lock-wait timeout from the driver becomes a 409 with a
 * retry message (top level: OrganizationNameLockTransactionTest; nested: an OrganizationNameLockTimeout) and every other
 * database error passes through untouched.
 */

function lockError(string $state, ?int $driverCode = null, string $message = 'lock wait'): QueryException
{
    $previous = new PDOException($message);
    $previous->errorInfo = [$state, $driverCode, $message];

    return new QueryException('testing', 'select 1', [], $previous);
}

it('rethrows a PostgreSQL lock timeout nested in a transaction as an OrganizationNameLockTimeout', function () {
    $run = fn () => OrganizationNameLock::run(scope: 'section', callback: fn () => throw lockError('55P03'));

    expect($run)->toThrow(OrganizationNameLockTimeout::class);
});

it('rethrows the real MySQL wording nested in a transaction, wrapping the original and rendering a 409', function () {
    // Inside a transaction (the test's own) Laravel reports the driver's concurrency error as a DeadlockException that
    // only wraps the QueryException; the timeout must still be recognised, and it must propagate, not be mapped.
    $message = 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction';
    $seen = null;

    try {
        OrganizationNameLock::run(scope: 'section', callback: function () use ($message, &$seen) {
            $seen = DB::transactionLevel();

            throw lockError('HY000', 1205, $message);
        });
    } catch (OrganizationNameLockTimeout $error) {
    }

    expect($error ?? null)->toBeInstanceOf(OrganizationNameLockTimeout::class)
        ->and($seen)->toBeGreaterThan(1)
        ->and($error->getPrevious())->not->toBeNull()
        ->and($error->render()->getStatusCode())->toBe(409)
        ->and($error->render()->getData(true)['message'])->toBe(trans('dam::app.admin.organize.busy'));
});

it('rolls back with the transaction, so a failed change leaves no anchor row behind', function () {
    $before = DB::table('dam_configuration')->count();

    try {
        OrganizationNameLock::run(scope: 'rollback-check', callback: fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(DB::transactionLevel())->toBe(1)
        ->and(DB::table('dam_configuration')->count())->toBe($before);
});
