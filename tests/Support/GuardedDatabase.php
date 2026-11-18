<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Mothership\Core\Database\ConnectionResolvers;

/**
 * Laravel glue for TestTargetGuard: admission at the one place every framework connection passes, the connector.
 *
 * ConnectionFactory::createConnector prefers a container binding `db.connector.{driver}`, so binding a guarded connector
 * for every driver right after configuration loads means no PDO can be created for ANY named connection, config
 * change, purge or reconnect without being checked against the allowlist first, whatever a provider does later.
 */
final class GuardedConnector implements ConnectorInterface
{
    /**
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     * @param  callable(object): array<string, mixed>|null  $liveReader  reads the LIVE_QUERY row from a fresh connection
     */
    public function __construct(
        private readonly array $allowlist,
        private readonly ?ConnectorInterface $inner,
        private readonly mixed $alive = null,
        private readonly mixed $liveReader = null,
    ) {}

    public function connect(array $config)
    {
        // Config and postmaster are proved before the inner connector can create a PDO.
        $postmaster = TestTargetGuard::assertBeforeConnect(config: $config, allowlist: $this->allowlist, alive: $this->alive);

        if ($this->inner === null) {
            throw new TestTargetRefused('Guarded test lane: no connector is admitted for this driver.');
        }

        // Session initialisation (search_path, timezone, isolation level, synchronous commit) is SQL: it runs only after the
        // server proved to be the admitted instance, never inside the inner connector on an unverified server.
        $session = array_intersect_key($config, array_flip(TestTargetGuard::SESSION_KEYS));
        $connection = $this->inner->connect(array_diff_key($config, $session));

        try {
            $row = ($this->liveReader ?? fn ($pdo) => (array) $pdo->query(TestTargetGuard::liveQuery($this->allowlist))->fetch(\PDO::FETCH_ASSOC))($connection);
        } catch (\Throwable) {
            throw new TestTargetRefused('Guarded test lane: the connected server could not be verified.');
        }

        TestTargetGuard::assertLive(row: $row, allowlist: $this->allowlist, postmaster: $postmaster);

        // The same steps PostgresConnector::connect runs, in its order, with the full configuration.
        foreach (['configureIsolationLevel', 'configureTimezone', 'configureSearchPath', 'configureSynchronousCommit'] as $step) {
            if (method_exists($this->inner, $step)) {
                (new \ReflectionMethod($this->inner, $step))->invoke($this->inner, $connection, $config);
            }
        }

        return $connection;
    }
}

/**
 * The database manager every connection in a guarded lane goes through. A provider cannot register a connection
 * resolver (DB::extend) that would build a connection without the connector guard, and a custom Connection resolver or
 * a replaced connection factory is refused at the moment a connection is made, before anything is opened.
 */
final class GuardedDatabaseManager extends DatabaseManager
{
    public function extend($name, callable $resolver)
    {
        throw new TestTargetRefused('Guarded test lane: DB::extend would build connections without the connector guard.');
    }

    protected function makeConnection($name)
    {
        GuardedDatabase::assertResolutionSafe(factory: $this->factory, extensions: $this->extensions);

        // ConnectionFactory::createConnection calls a static Connection resolver BEFORE any connector runs, so a resolver
        // (the project's sqlsrv wrapper included) must never be reached for a connection that is not the admitted
        // PostgreSQL one. configuration() applies the url parser first, so a url that changes the driver is seen here.
        if (($this->configuration($name)['driver'] ?? null) !== 'pgsql') {
            throw new TestTargetRefused('Guarded test lane: only the admitted PostgreSQL driver may make a connection.');
        }

        return parent::makeConnection($name);
    }
}

final class GuardedDatabase
{
    private const DRIVERS = ['pgsql', 'mysql', 'mariadb', 'sqlite', 'sqlsrv'];

    /** @var array<string, GuardedConnector> */
    private static array $installed = [];

    /**
     * Registers the whole guarded-lane hook order on an application that has not bootstrapped yet:
     *  1. after configuration loads: effective config, pid file and a read-only identity probe, then the connector guards;
     *  2. after providers register and after they boot: the guards must still be the ones in place.
     * The caller re-admits on the resolved connection once its bootstrap finishes.
     *
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     * @param  callable(array<string, mixed>): array<string, mixed>  $probe
     */
    public static function attach(Application $app, array $allowlist, callable $probe, ?ConnectorInterface $pgsql = null, mixed $alive = null, mixed $liveReader = null): void
    {
        $app['events']->listen('bootstrapped: '.LoadConfiguration::class, function (Application $app) use ($allowlist, $probe, $pgsql, $alive, $liveReader) {
            TestTargetGuard::admit(database: $app['config']->get('database'), allowlist: $allowlist, basePath: $app->basePath(), probe: $probe, alive: $alive);
            self::install(app: $app, allowlist: $allowlist, pgsql: $pgsql, alive: $alive, liveReader: $liveReader);
        });

        foreach ([RegisterProviders::class, BootProviders::class] as $bootstrapper) {
            $app['events']->listen('bootstrapped: '.$bootstrapper, fn (Application $app) => self::assertIntact($app));
        }
    }

    /**
     * @param  array{host: string, port: int, database: string, data_directory: string, worktree: string}  $allowlist
     */
    public static function install(Application $app, array $allowlist, ?ConnectorInterface $pgsql = null, mixed $alive = null, mixed $liveReader = null): void
    {
        self::$installed = [];

        foreach (self::DRIVERS as $driver) {
            $guard = new GuardedConnector($allowlist, $driver === 'pgsql' ? ($pgsql ?? new PostgresConnector) : null, $alive, $liveReader);
            self::$installed[$driver] = $guard;
            $key = "db.connector.{$driver}";
            $app->singleton($key, static fn () => $guard);
            $app->make($key);

            // Resolved bindings call back when rebound, so a provider replacing the guard fails at that statement, and any
            // resolution that yields something else fails before connect() can be called on it.
            $refuse = static function ($resolved) use ($guard, $driver) {
                if ($resolved !== $guard) {
                    throw new TestTargetRefused("Guarded test lane: the {$driver} connector guard was replaced.");
                }
            };
            $app->rebinding($key, static fn ($app, $new) => $refuse($new));
            $app->resolving($key, static fn ($resolved) => $refuse($resolved));
        }

        $app->resolving('db.factory', static function ($factory) {
            if ($factory::class !== ConnectionFactory::class) {
                throw new TestTargetRefused('Guarded test lane: the connection factory was replaced.');
            }
        });

        // The manager itself: extenders wrap whatever is built, and anything swapped in with instance()/bind() afterwards, or
        // resolved some other way, is refused at that statement or at resolution, before it can make a connection.
        $app->extend('db', static fn ($manager, $app) => new GuardedDatabaseManager($app, $app['db.factory']));
        $requireManager = static function ($manager) {
            if (! $manager instanceof GuardedDatabaseManager) {
                throw new TestTargetRefused('Guarded test lane: the database manager was replaced by an unguarded one.');
            }
        };
        $app->rebinding('db', static fn ($app, $new) => $requireManager($new));
        $app->resolving('db', static fn ($manager) => $requireManager($manager));
    }

    /**
     * Run by the manager before it makes any connection.
     *
     * @param  array<string, mixed>  $extensions
     */
    public static function assertResolutionSafe(object $factory, array $extensions): void
    {
        if ($factory::class !== ConnectionFactory::class) {
            throw new TestTargetRefused('Guarded test lane: the connection factory was replaced.');
        }

        if ($extensions !== []) {
            throw new TestTargetRefused('Guarded test lane: a custom connection resolver bypasses the connector guard.');
        }

        foreach (self::unadmittedResolvers() as $driver) {
            throw new TestTargetRefused("Guarded test lane: a Connection resolver for {$driver} bypasses the connector guard.");
        }
    }

    /**
     * Drivers whose Connection resolver is not one the project registered.
     *
     * The project registers a sqlsrv resolver (CoreServiceProvider), whose connector the guard refuses, and the
     * failed-commit resolvers of ConnectionResolvers, which only wrap the PDO the guarded connector made. Those are
     * recognised by closure identity, without running them. Any other resolver could build a connection around its
     * own PDO.
     *
     * @return list<string>
     */
    private static function unadmittedResolvers(): array
    {
        $resolvers = (new \ReflectionProperty(Connection::class, 'resolvers'))->getValue();

        return array_values(array_filter(
            array_keys($resolvers),
            fn (string $driver) => $driver !== 'sqlsrv' && ! ConnectionResolvers::isRegistered($driver, $resolvers[$driver]),
        ));
    }

    /**
     * The guard must still be what the factory will use: no provider rebound a connector, swapped the factory, or
     * registered a custom connection resolver that never reaches a connector.
     */
    public static function assertIntact(Application $app): void
    {
        if (self::$installed === []) {
            throw new TestTargetRefused('Guarded test lane: the connector guard is not installed.');
        }

        foreach (self::$installed as $driver => $guard) {
            if (! $app->bound("db.connector.{$driver}") || $app->make("db.connector.{$driver}") !== $guard) {
                throw new TestTargetRefused("Guarded test lane: the {$driver} connector guard was replaced.");
            }
        }

        foreach (array_keys($app->getBindings()) as $abstract) {
            if (str_starts_with($abstract, 'db.connector.') && ! isset(self::$installed[substr($abstract, 13)])) {
                throw new TestTargetRefused('Guarded test lane: an unguarded connector was registered.');
            }
        }

        if ($app->bound('db.factory') && $app->make('db.factory')::class !== ConnectionFactory::class) {
            throw new TestTargetRefused('Guarded test lane: the connection factory was replaced.');
        }

        if ($app->resolved('db')) {
            $manager = $app->make('db');

            if (! $manager instanceof GuardedDatabaseManager) {
                throw new TestTargetRefused('Guarded test lane: the database manager is not the guarded one.');
            }
        }

        if (self::unadmittedResolvers() !== []) {
            throw new TestTargetRefused('Guarded test lane: a Connection resolver bypasses the connector guard.');
        }

        $eloquent = Model::getConnectionResolver();

        if ($eloquent !== null && ! $eloquent instanceof GuardedDatabaseManager) {
            throw new TestTargetRefused('Guarded test lane: Eloquent resolves connections through an unguarded manager.');
        }
    }

    /**
     * Identity probe from the effective configuration, used before any provider exists; the identity SELECT is the first and only statement. Credentials stay here.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    public static function probe(array $connection, string $query = TestTargetGuard::LIVE_QUERY): array
    {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']);

        try {
            $pdo = new \PDO($dsn, (string) ($connection['username'] ?? ''), (string) ($connection['password'] ?? ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);

            return (array) $pdo->query($query)->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            throw new TestTargetRefused('Guarded test lane: the admitted instance could not be verified; refusing to run.');
        }
    }
}
