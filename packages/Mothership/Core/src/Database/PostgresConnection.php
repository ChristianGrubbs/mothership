<?php

namespace Mothership\Core\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

/**
 * The PostgreSQL connection, registered through Connection::resolverFor() so a failed COMMIT rolls back.
 */
class PostgresConnection extends BasePostgresConnection
{
    use RollsBackFailedCommits;
}
