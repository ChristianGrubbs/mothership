<?php

namespace Mothership\Core\Helpers\Database;

use Closure;
use Illuminate\Support\Facades\DB;

class DatabaseSequenceHelper
{
    /**
     * Run inserts that carry explicit values for a table's `id` column.
     *
     * SQL Server refuses an explicit value in an identity column unless IDENTITY_INSERT
     * is ON for that table, and only one table per session may have it ON, so wrap one
     * table at a time. SQL Server moves the identity seed past the inserted ids itself,
     * as fixSequences() does by hand on PostgreSQL. Other drivers just run the callback.
     */
    public static function withIdentityInsert(string $table, Closure $callback): mixed
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return $callback();
        }

        $wrapped = DB::getQueryGrammar()->wrapTable($table);

        // Unprepared: a SET inside a prepared (sp_executesql) batch ends with that batch.
        DB::unprepared("SET IDENTITY_INSERT {$wrapped} ON");

        try {
            return $callback();
        } finally {
            DB::unprepared("SET IDENTITY_INSERT {$wrapped} OFF");
        }
    }

    /**
     * Fix sequence for one or more tables in PostgreSQL.
     * Table names should be provided without table prefix table prefix will be added here
     */
    public static function fixSequences(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tablePrefix = DB::getTablePrefix();

        foreach ($tables as $table) {
            static::fixSequence($table, $tablePrefix);
        }
    }

    public static function fixSequence(string $table, ?string $tablePrefix = null): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tablePrefix ??= DB::getTablePrefix();

        $tableName = $tablePrefix.$table;

        $sequence = "{$tableName}_id_seq";

        DB::statement("
            SELECT setval(
                '{$sequence}',
                (SELECT COALESCE(MAX(id), 0) + 1 FROM {$tableName}),
                false
            )
        ");
    }
}
