<?php

namespace Mothership\Core\Database\SqlServer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SqlServerGrammar;
use Illuminate\Support\Fluent;

/**
 * SQL Server treats NULLs as equal in a unique index, so a nullable unique column takes
 * one NULL and refuses the second. PostgreSQL, MySQL and SQLite let any number of rows
 * hold NULL there. A unique index with a nullable key column is therefore filtered to
 * rows where those columns are not NULL, which is the same rule the other drivers apply.
 */
class SqlServerSchemaGrammar extends SqlServerGrammar
{
    /**
     * Compile a unique key command.
     *
     * @return string
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        $nullable = array_values(array_filter(
            $command->columns,
            fn (string $column) => $this->isNullable($blueprint, $column)
        ));

        $filter = $nullable === []
            ? ''
            : ' where '.implode(' and ', array_map(fn (string $column) => $this->wrap($column).' is not null', $nullable));

        return sprintf('create unique index %s on %s (%s)%s%s',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->columnize($command->columns),
            $filter,
            $command->online ? ' with (online = on)' : ''
        );
    }

    /**
     * Whether a column is nullable: from its definition when this blueprint adds or
     * changes it, otherwise from the live table.
     */
    protected function isNullable(Blueprint $blueprint, string $column): bool
    {
        foreach (array_reverse($blueprint->getColumns()) as $definition) {
            if ($definition->name === $column) {
                return (bool) $definition->nullable;
            }
        }

        // wrapTable() puts the prefix on the table, not on a schema in front of it.
        return (bool) $this->connection->scalar(
            "select columnproperty(object_id(?), ?, 'AllowsNull')",
            [$this->connection->getQueryGrammar()->wrapTable($blueprint->getTable()), $column],
            useReadPdo: false
        );
    }
}
