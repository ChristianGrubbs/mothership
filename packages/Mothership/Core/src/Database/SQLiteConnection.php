<?php

namespace Mothership\Core\Database;

use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;

/**
 * The SQLite connection, registered through Connection::resolverFor() so a failed COMMIT rolls back.
 */
class SQLiteConnection extends BaseSQLiteConnection
{
    use RollsBackFailedCommits;
}
