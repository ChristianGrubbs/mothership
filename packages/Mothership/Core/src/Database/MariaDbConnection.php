<?php

namespace Mothership\Core\Database;

use Illuminate\Database\MariaDbConnection as BaseMariaDbConnection;

/**
 * The MariaDB connection, registered through Connection::resolverFor() so a failed COMMIT rolls back.
 */
class MariaDbConnection extends BaseMariaDbConnection
{
    use RollsBackFailedCommits;
}
