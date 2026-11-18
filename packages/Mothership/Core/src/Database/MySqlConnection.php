<?php

namespace Mothership\Core\Database;

use Illuminate\Database\MySqlConnection as BaseMySqlConnection;

/**
 * The MySQL connection, registered through Connection::resolverFor() so a failed COMMIT rolls back.
 */
class MySqlConnection extends BaseMySqlConnection
{
    use RollsBackFailedCommits;
}
