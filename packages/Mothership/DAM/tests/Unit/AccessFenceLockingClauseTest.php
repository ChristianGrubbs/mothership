<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Tests\Support\LockingClause;

/*
 * The fence tests prove a locking read of the DAM_ACCESS_MODEL row was taken by looking for the clause it
 * carries, and the clause is not the same on every driver: a check that knew only PostgreSQL's syntax counted
 * zero on MySQL, MariaDB and SQL Server, so a valid fenced write failed those legs while passing the required
 * one (Codex review of PR #74 round 4, finding 4).
 *
 * These cases compile the real query against each grammar, so the fragments in LockingClause are held to what
 * Laravel emits rather than to what anyone remembered. Grammars are pure, so this runs on every driver.
 */

it('recognises the locking read each grammar really emits', function (string $driver) {
    $grammar = fenceClauseGrammar($driver);
    $shared = fenceClauseSql($grammar, shared: true);
    $exclusive = fenceClauseSql($grammar, shared: false);

    expect(LockingClause::matches($shared, driver: $driver))->toBeTrue($driver.' shared: '.$shared)
        ->and(LockingClause::matches($exclusive, driver: $driver))->toBeTrue($driver.' exclusive: '.$exclusive);

    // And it is the clause doing the work, not the table name: the same query without a lock does not match —
    // except on SQLite, which compiles none, so there everything matches and the next case says so.
    if (LockingClause::pattern(driver: $driver) !== null) {
        expect(LockingClause::matches(fenceClauseSql($grammar, shared: null), driver: $driver))->toBeFalse($driver.' unlocked');
    }
})->with(['pgsql', 'mysql', 'mariadb', 'sqlsrv', 'sqlite']);

it('has no clause to require on SQLite, which compiles none', function () {
    expect(LockingClause::pattern(driver: 'sqlite'))->toBeNull()
        // So every statement matches there, which is why the committed PostgreSQL tests carry the requirement.
        ->and(LockingClause::matches('select * from "dam_configuration"', driver: 'sqlite'))->toBeTrue();
});

it('covers every driver this project installs on', function () {
    // Installer::DATABASE_DRIVERS, minus sqlite, which has its own documented exception above.
    expect(array_keys(LockingClause::PATTERNS))->toEqualCanonicalizing(['pgsql', 'mysql', 'mariadb', 'sqlsrv']);
});

/** Laravel's own grammar for $driver, on whatever connection this run has. Grammars compile; they do not query. */
function fenceClauseGrammar(string $driver): Grammar
{
    $connection = DB::connection();

    return match ($driver) {
        'pgsql'   => new PostgresGrammar($connection),
        'mysql'   => new MySqlGrammar($connection),
        'mariadb' => new MariaDbGrammar($connection),
        'sqlsrv'  => new SqlServerGrammar($connection),
        'sqlite'  => new SQLiteGrammar($connection),
    };
}

/** The state-row read AccessModel takes, compiled by $grammar: shared, exclusive, or with no lock at all. */
function fenceClauseSql(Grammar $grammar, ?bool $shared): string
{
    $query = (new Builder(DB::connection(), $grammar, new Processor))
        ->from('dam_configuration')
        ->where('key', AccessModel::KEY);

    if ($shared === true) {
        $query->sharedLock();
    } elseif ($shared === false) {
        $query->lockForUpdate();
    }

    return $query->toSql();
}
