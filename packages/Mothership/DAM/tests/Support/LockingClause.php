<?php

namespace Mothership\DAM\Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * What a locking read looks like on each driver, for the fence tests that prove one was taken.
 *
 * The fragments come from Laravel's own grammars rather than from memory
 * (Illuminate\Database\Query\Grammars\*::compileLock, and SqlServerGrammar::compileFrom for the table hint),
 * and AccessFenceLockingClauseTest holds them to that by compiling a locking query on each grammar.
 *
 * A check that recognised only PostgreSQL's syntax counted zero on MySQL, MariaDB and SQL Server, so a valid
 * fenced write failed those legs of the shared feature tests while passing the required one (Codex review of
 * PR #74 round 4, finding 4).
 */
final class LockingClause
{
    /** @var array<string, string> */
    public const PATTERNS = [
        // `for share` and `for update`, plus `for no key update` where a caller asks for it by name.
        'pgsql' => '/for (share|update|no key update)/',

        // MySqlGrammar emits `lock in share mode` for a shared lock; MariaDbGrammar inherits it.
        'mysql'   => '/(lock in share mode|for update)/',
        'mariadb' => '/(lock in share mode|for update)/',

        // No clause on the select at all: SqlServerGrammar::compileFrom adds a table hint instead.
        'sqlsrv' => '/with\(rowlock,/',
    ];

    /**
     * The pattern a locking read carries on the current connection's driver, or null where the driver emits
     * nothing to look for — SQLite, which serialises writers at the file level and compiles no lock clause. There
     * the statement itself is all there is to see, and the clause is required on the drivers that have one.
     */
    public static function pattern(?string $driver = null): ?string
    {
        return self::PATTERNS[$driver ?? DB::connection()->getDriverName()] ?? null;
    }

    /** Whether $sql took a locking read on the current driver. True on SQLite, which has no clause to carry. */
    public static function matches(string $sql, ?string $driver = null): bool
    {
        $pattern = self::pattern(driver: $driver);

        return $pattern === null || preg_match($pattern, $sql) === 1;
    }
}
