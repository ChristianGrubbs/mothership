<?php

namespace Mothership\Core\Database\SqlServer;

use Illuminate\Database\SqlServerConnection as BaseSqlServerConnection;
use Mothership\Core\Database\RollsBackFailedCommits;

/**
 * The sqlsrv connection, registered through Connection::resolverFor() so that only
 * SQL Server gets it. It swaps in a query builder that can insertOrIgnore() and a
 * schema grammar whose unique indexes ignore NULLs, as on the other drivers. Like every
 * driver here, a failed COMMIT rolls back (RollsBackFailedCommits).
 */
class SqlServerConnection extends BaseSqlServerConnection
{
    use RollsBackFailedCommits;

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): SqlServerSchemaGrammar
    {
        return new SqlServerSchemaGrammar($this);
    }

    /**
     * Get a new query builder instance.
     */
    public function query(): SqlServerQueryBuilder
    {
        return new SqlServerQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }
}
