<?php

namespace Mothership\Core\Database;

use Closure;
use Illuminate\Database\Connection;

/**
 * Registers the project's connection classes through Connection::resolverFor(), so a failed COMMIT
 * rolls back on every driver (see RollsBackFailedCommits).
 *
 * Each resolver only wraps the PDO the framework's connector made; it never opens one of its own.
 * The guarded test lane admits a resolver only when it is one of these exact closures, without
 * running it. They are built once per process from the CONNECTIONS constant and held in a
 * function-local static: there is no property to overwrite, and reflection can read a function's
 * static variables but cannot replace them.
 */
final class ConnectionResolvers
{
    /** @var array<string, class-string<Connection>> */
    public const CONNECTIONS = [
        'pgsql'   => PostgresConnection::class,
        'mysql'   => MySqlConnection::class,
        'mariadb' => MariaDbConnection::class,
        'sqlite'  => SQLiteConnection::class,
    ];

    public static function register(): void
    {
        foreach (self::resolvers() as $driver => $resolver) {
            Connection::resolverFor($driver, $resolver);
        }
    }

    /**
     * Whether $resolver is the project's own closure for $driver.
     */
    public static function isRegistered(string $driver, mixed $resolver): bool
    {
        $resolvers = self::resolvers();

        return isset($resolvers[$driver]) && $resolvers[$driver] === $resolver;
    }

    /**
     * @return array<string, Closure>
     */
    private static function resolvers(): array
    {
        static $resolvers = null;

        return $resolvers ??= array_map(
            fn (string $class) => fn ($connection, $database, $prefix, $config) => new $class($connection, $database, $prefix, $config),
            self::CONNECTIONS,
        );
    }
}
