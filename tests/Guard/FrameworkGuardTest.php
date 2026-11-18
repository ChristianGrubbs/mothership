<?php

namespace Tests\Guard;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\ServiceProvider;
use Mothership\Core\Database\ConnectionResolvers;
use Mothership\Core\Database\PostgresConnection;
use Mothership\Core\Database\SQLiteConnection;
use Mothership\Installer\Console\Commands\Installer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GuardedConnector;
use Tests\Support\GuardedDatabase;
use Tests\Support\GuardedDatabaseManager;
use Tests\Support\TestTargetGuard;
use Tests\Support\TestTargetRefused;

require_once __DIR__.'/../Support/TestTargetGuard.php';
require_once __DIR__.'/../Support/GuardedDatabase.php';

/**
 * Framework-level guard tests: a real Illuminate Application and ConnectionFactory in a temporary base path, with a
 * spy in place of PostgresConnector and a fake live reader, so no PDO and no connection is ever created. These need
 * Composer autoload and the Laravel framework; the connection-free pure checks live in pure-harness.php.
 *
 *   vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Guard
 */
final class FrameworkGuardTest extends TestCase
{
    private string $root;

    private string $data;

    private array $allowlist;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/guard-fw-'.bin2hex(random_bytes(6));
        $this->data = $this->root.'/pgdata';
        mkdir($this->data, 0700, true);
        mkdir($this->root.'/worktree');
        file_put_contents($this->data.'/postmaster.pid', implode("\n", [1111, $this->data, 1700000000, 5433, '/tmp/sock', '127.0.0.1', '0', 'ready'])."\n");
        $this->allowlist = ['host' => '127.0.0.1', 'port' => 5433, 'database' => 'mothership_test', 'data_directory' => $this->data, 'worktree' => realpath($this->root.'/worktree')];
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
        ProviderSentinel::$mutation = null;
        ProviderSentinel::$registerMutation = null;
        ProviderSentinel::$registered = false;

        $resolvers = new \ReflectionProperty(Connection::class, 'resolvers');
        $resolvers->setValue(null, array_diff_key($resolvers->getValue(), array_flip(['pgsql', 'mysql', 'mariadb', 'sqlite', 'custom'])));
    }

    private function live(array $o = []): array
    {
        return $o + ['database' => 'mothership_test', 'host' => '127.0.0.1', 'port' => 5433, 'data_directory' => $this->data, 'started_at' => 1700000000];
    }

    private function spy(): SpyConnector
    {
        return new SpyConnector;
    }

    /** @return array{0: Application, 1: SpyConnector} */
    private function app(array $providers, array $connection = [], array $override = []): array
    {
        $base = $this->allowlist['worktree'];
        @mkdir($base.'/config');
        @mkdir($base.'/bootstrap/cache', 0700, true);
        file_put_contents($base.'/config/app.php', '<?php return ['."'providers' => ".var_export($providers, true).'];');
        $conn = $connection + ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5433', 'database' => 'mothership_test', 'username' => 'u', 'password' => 'p'];
        file_put_contents($base.'/config/database.php', '<?php return '.var_export(['default' => 'pgsql', 'connections' => ['pgsql' => $conn, 'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'], 'mysql' => ['driver' => 'mysql', 'host' => '10.0.0.9', 'database' => 'prod']]], true).';');

        $app = new Application($base);
        $spy = $this->spy();
        GuardedDatabase::attach(
            app: $app,
            allowlist: $this->allowlist,
            probe: fn () => $this->live(),
            pgsql: $spy,
            alive: fn () => true,
            liveReader: fn () => $this->live($override),
        );

        return [$app, $spy];
    }

    private function bootstrap(Application $app): void
    {
        $app->bootstrapWith([LoadConfiguration::class, RegisterProviders::class, BootProviders::class]);
    }

    public function test_the_guard_fires_after_configuration_and_before_any_provider_registers(): void
    {
        [$app, $spy] = $this->app([ProviderSentinel::class], connection: ['port' => '5432']);

        try {
            $this->bootstrap($app);
            $this->fail('the guard must stop the bootstrap');
        } catch (TestTargetRefused) {
            $this->assertFalse(ProviderSentinel::$registered, 'no provider may register before admission');
            $this->assertSame('5432', $app['config']->get('database.connections.pgsql.port'), 'the guard saw the effective configuration');
            $this->assertSame(0, $spy->connects);
        }
    }

    public function test_an_admitted_application_boots_and_reaches_the_connector_only_through_the_guard(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        $this->bootstrap($app);

        $this->assertTrue(ProviderSentinel::$registered);
        $app['db']->connection()->getPdo();
        $this->assertSame(1, $spy->connects);
        GuardedDatabase::assertIntact($app);
    }

    public static function providerTimeMutations(): array
    {
        return [
            'another port'               => [fn ($app) => $app['config']->set('database.connections.pgsql.port', '5432')],
            'another database'           => [fn ($app) => $app['config']->set('database.connections.pgsql.database', 'mothership')],
            'another host'               => [fn ($app) => $app['config']->set('database.connections.pgsql.host', '10.0.0.5')],
            'connect_via_database'       => [fn ($app) => $app['config']->set('database.connections.pgsql.connect_via_database', 'mothership')],
            'connect_via_port'           => [fn ($app) => $app['config']->set('database.connections.pgsql.connect_via_port', '5432')],
            'url'                        => [fn ($app) => $app['config']->set('database.connections.pgsql.url', 'postgresql://10.0.0.5:5432/mothership')],
            'read/write split'           => [fn ($app) => $app['config']->set('database.connections.pgsql', ['read' => ['host' => '10.0.0.5'], 'write' => ['host' => '127.0.0.1']] + $app['config']->get('database.connections.pgsql'))],
            'unix socket'                => [fn ($app) => $app['config']->set('database.connections.pgsql.unix_socket', '/tmp/other')],
            'default switched to sqlite' => [fn ($app) => $app['config']->set('database.default', 'sqlite')],
            'default switched to mysql'  => [fn ($app) => $app['config']->set('database.default', 'mysql')],
        ];
    }

    #[DataProvider('providerTimeMutations')]
    public function test_a_provider_that_changes_configuration_and_connects_never_reaches_the_connector(callable $mutate): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        ProviderSentinel::$mutation = function (Application $app) use ($mutate) {
            $mutate($app);
            $app['db']->purge();
            $app['db']->connection()->getReadPdo();
            $app['db']->connection()->getPdo();
        };

        try {
            $this->bootstrap($app);
            $this->fail('the connection must be refused');
        } catch (TestTargetRefused) {
            $this->assertSame(0, $spy->connects, 'no inner connector (and so no PDO) was reached');
        }
    }

    public function test_alternate_named_connections_purge_and_reconnect_are_all_checked_at_resolution(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class]);
        $this->bootstrap($app);

        foreach (['sqlite', 'mysql'] as $name) {
            try {
                $app['db']->connection($name)->getPdo();
                $this->fail("{$name} must be refused");
            } catch (TestTargetRefused) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $spy->connects);

        $app['db']->connection()->getPdo();
        $this->assertSame(1, $spy->connects);

        $app['config']->set('database.connections.pgsql.port', '5432');
        $app['db']->purge();
        $this->expectException(TestTargetRefused::class);

        try {
            $app['db']->connection()->getPdo();
        } finally {
            $this->assertSame(1, $spy->connects, 'the recreated connection never reached the connector');
        }
    }

    public function test_reconnect_after_the_postmaster_dies_is_refused(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class]);
        $this->bootstrap($app);
        $app['db']->connection()->getPdo();

        unlink($this->data.'/postmaster.pid');
        $app['db']->purge();
        $this->expectException(TestTargetRefused::class);

        try {
            $app['db']->connection()->getPdo();
        } finally {
            $this->assertSame(1, $spy->connects);
        }
    }

    public function test_a_server_that_answers_as_another_instance_is_refused_after_the_connect(): void
    {
        [$app] = $this->app([DatabaseServiceProvider::class], override: ['data_directory' => '/var/lib/postgresql']);
        $this->bootstrap($app);

        $this->expectException(TestTargetRefused::class);
        $app['db']->connection()->getPdo();
    }

    public function test_the_connector_guard_sees_the_merged_read_write_configuration(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class]);
        $this->bootstrap($app);
        $factory = new ConnectionFactory($app);
        $config = ['driver' => 'pgsql', 'database' => 'mothership_test', 'port' => '5433', 'read' => ['host' => '10.0.0.5'], 'write' => ['host' => '127.0.0.1']];

        try {
            $factory->make($config, 'rw')->getReadPdo();
            $this->fail('the read replica must be refused');
        } catch (TestTargetRefused) {
            $this->assertSame(0, $spy->connects);
        }
    }

    public function test_replacing_a_connector_the_factory_or_adding_a_resolver_is_detected(): void
    {
        foreach ([
            fn (Application $app) => $app->singleton('db.connector.pgsql', fn () => new SpyConnector),
            fn (Application $app) => $app->singleton('db.connector.custom', fn () => new SpyConnector),
            fn (Application $app) => $app->singleton('db.factory', fn ($app) => new class($app) extends ConnectionFactory {}),
            fn (Application $app) => $app['db']->extend('custom', fn () => null),
        ] as $i => $tamper) {
            [$app] = $this->app([DatabaseServiceProvider::class]);
            $this->bootstrap($app);
            $app['db'];
            GuardedDatabase::assertIntact($app);

            try {
                $tamper($app);
                GuardedDatabase::assertIntact($app);
                $this->fail("tamper {$i} must be detected");
            } catch (TestTargetRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_provider_that_rebinds_the_connector_during_boot_fails_the_bootstrap(): void
    {
        [$app] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        ProviderSentinel::$mutation = fn (Application $app) => $app->singleton('db.connector.pgsql', fn () => new SpyConnector);

        $this->expectException(TestTargetRefused::class);
        $this->bootstrap($app);
    }

    public static function providerBypasses(): array
    {
        return [
            'rebind the pgsql connector'        => [fn (Application $app, $rogue) => $app->singleton('db.connector.pgsql', fn () => $rogue), 'boot'],
            'instance() the pgsql connector'    => [fn (Application $app, $rogue) => $app->instance('db.connector.pgsql', $rogue), 'boot'],
            'bind a connector for a new driver' => [function (Application $app, $rogue) {
                $app['config']->set('database.connections.pgsql.driver', 'custom');
                $app->singleton('db.connector.custom', fn () => $rogue);
                Connection::resolverFor('custom', fn () => $rogue);
            }, 'boot'],
            'replace the connection factory'    => [fn (Application $app, $rogue) => $app->singleton('db.factory', fn ($app) => new class($app) extends ConnectionFactory {}), 'register'],
            'DB::extend a pgsql resolver'       => [fn (Application $app, $rogue) => $app['db']->extend('pgsql', fn () => $rogue), 'boot'],
            'DB::extend by driver name'         => [fn (Application $app, $rogue) => $app['db']->extend('custom', fn () => $rogue), 'boot'],
            'Connection::resolverFor pgsql'     => [fn (Application $app, $rogue) => Connection::resolverFor('pgsql', fn () => $rogue), 'boot'],
            'Connection::resolverFor sqlite'    => [fn (Application $app, $rogue) => Connection::resolverFor('sqlite', fn () => $rogue), 'boot'],
        ];
    }

    #[DataProvider('providerBypasses')]
    public function test_a_provider_that_bypasses_the_connector_is_refused_at_that_statement_or_at_resolution_and_nothing_connects(callable $bypass, string $phase): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        $rogue = new SpyConnector;
        $attempted = false;
        $mutation = function (Application $app) use ($bypass, $rogue, &$attempted) {
            $bypass($app, $rogue);
            $attempted = true;
            // The provider connects and writes straight after rebinding; this must never get through.
            $app['db']->purge();
            $app['db']->connection()->getPdo();
        };

        if ($phase === 'register') {
            ProviderSentinel::$registerMutation = $mutation;
        } else {
            ProviderSentinel::$mutation = $mutation;
        }

        try {
            $this->bootstrap($app);
            $this->fail('the bypass must be refused');
        } catch (TestTargetRefused) {
            $this->assertSame(0, $rogue->connects, 'the replacement connector was never used');
            $this->assertSame(0, $spy->connects, 'the guarded inner connector was never reached');
        }
    }

    public function test_the_legitimate_sqlsrv_connection_resolver_is_still_allowed(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        ProviderSentinel::$mutation = function () {
            Connection::resolverFor('sqlsrv', fn () => null);
        };
        $this->bootstrap($app);
        $app['db']->connection()->getPdo();

        $this->assertSame(1, $spy->connects);
        (new \ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, []);
    }

    public function test_the_projects_failed_commit_resolvers_are_allowed_and_still_connect_through_the_guard(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        ProviderSentinel::$mutation = function () {
            ConnectionResolvers::register();
        };
        $this->bootstrap($app);
        $app['db']->connection()->getPdo();

        $this->assertInstanceOf(PostgresConnection::class, $app['db']->connection());
        $this->assertSame(1, $spy->connects, 'the project connection wraps the PDO the guarded connector made');
    }

    public static function projectResolverReplacements(): array
    {
        // Each rogue records that it ran and connects through its own spy, so either would show.
        return [
            'a rogue pgsql resolver after the project ones' => [fn (\stdClass $ran, SpyConnector $rogue) => Connection::resolverFor('pgsql', function () use ($ran, $rogue) {
                $ran->ran = true;
                $rogue->connect([]);
            })],
            'an identical-looking but different closure' => [fn (\stdClass $ran, SpyConnector $rogue) => Connection::resolverFor(
                'sqlite',
                function ($connection, $database, $prefix, $config) use ($ran, $rogue) {
                    $ran->ran = true;
                    $rogue->connect([]);

                    return new SQLiteConnection($connection, $database, $prefix, $config);
                }
            )],
        ];
    }

    #[DataProvider('projectResolverReplacements')]
    public function test_a_resolver_replacing_a_project_one_is_refused_and_never_runs(callable $replace): void
    {
        foreach (['at resolution' => true, 'at the bootstrap integrity check' => false] as $phase => $connects) {
            [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
            $ran = new \stdClass;
            $ran->ran = false;
            $rogue = new SpyConnector;
            ProviderSentinel::$mutation = function (Application $app) use ($replace, $ran, $rogue, $connects) {
                ConnectionResolvers::register();
                $replace($ran, $rogue);

                if ($connects) {
                    $app['db']->purge();
                    $app['db']->connection()->getPdo();
                }
            };

            try {
                $this->bootstrap($app);
                $this->fail("the replaced resolver must be refused {$phase}");
            } catch (TestTargetRefused) {
                $this->assertFalse($ran->ran, "the replacement resolver never ran ({$phase})");
                $this->assertSame(0, $rogue->connects, "the replacement never connected ({$phase})");
                $this->assertSame(0, $spy->connects, "the guarded inner connector was never reached ({$phase})");
            }

            (new \ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, []);
        }
    }

    public function test_the_admitted_resolver_identities_have_no_state_to_overwrite(): void
    {
        // Admission compares against closures held in a function-local static; a static property could be rewritten
        // through reflection to admit a rogue closure, so there must be none.
        $this->assertSame([], (new \ReflectionClass(ConnectionResolvers::class))->getStaticProperties());
        $this->assertSame([], (new \ReflectionClass(ConnectionResolvers::class))->getProperties());
    }

    public static function managerAndResolverBypasses(): array
    {
        return [
            'instance() an ordinary DatabaseManager'     => ['boot', function (Application $app, \stdClass $ran) {
                $rogue = new DatabaseManager($app, $app['db.factory']);
                $rogue->extend('pgsql', function () use ($ran) {
                    $ran->ran = true;
                });
                $app->instance('db', $rogue);
            }],
            'bind() an ordinary DatabaseManager (neutralised by the extender)' => ['neutralised', function (Application $app, \stdClass $ran) {
                $app->singleton('db', function ($app) use ($ran) {
                    $rogue = new DatabaseManager($app, $app['db.factory']);
                    $rogue->extend('pgsql', function () use ($ran) {
                        $ran->ran = true;
                    });

                    return $rogue;
                });
            }],
            'instance() an ordinary DatabaseManager while registering' => ['register', function (Application $app, \stdClass $ran) {
                $rogue = new DatabaseManager($app, $app['db.factory']);
                $rogue->extend('pgsql', function () use ($ran) {
                    $ran->ran = true;
                });
                $app->instance('db', $rogue);
            }],
            'rogue sqlsrv resolver, sqlsrv connection'   => ['boot', function (Application $app, \stdClass $ran) {
                Connection::resolverFor('sqlsrv', function () use ($ran) {
                    $ran->ran = true;
                });
                $app['config']->set('database.connections.sqlsrv', ['driver' => 'sqlsrv', 'host' => '10.0.0.9', 'database' => 'prod']);
                $app['db']->connection('sqlsrv')->getPdo();
            }],
            'rogue sqlsrv resolver, default switched'    => ['boot', function (Application $app, \stdClass $ran) {
                Connection::resolverFor('sqlsrv', function () use ($ran) {
                    $ran->ran = true;
                });
                $app['config']->set('database.connections.sqlsrv', ['driver' => 'sqlsrv', 'host' => '10.0.0.9', 'database' => 'prod']);
                $app['config']->set('database.default', 'sqlsrv');
            }],
            'url that turns pgsql into sqlsrv'           => ['boot', function (Application $app, \stdClass $ran) {
                Connection::resolverFor('sqlsrv', function () use ($ran) {
                    $ran->ran = true;
                });
                $app['config']->set('database.connections.pgsql.url', 'sqlsrv://u:p@10.0.0.9:1433/prod');
            }],
            'alias for a non-pgsql connection'           => ['boot', function (Application $app, \stdClass $ran) {
                Connection::resolverFor('sqlsrv', function () use ($ran) {
                    $ran->ran = true;
                });
                $app['config']->set('database.connections.alias_of_prod', ['driver' => 'sqlsrv', 'host' => '10.0.0.9', 'database' => 'prod']);
                $app['config']->set('database.default', 'alias_of_prod');
            }],
        ];
    }

    #[DataProvider('managerAndResolverBypasses')]
    public function test_a_replaced_manager_or_a_rogue_resolver_never_executes_and_never_connects(string $phase, callable $bypass): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        $ran = new \stdClass;
        $ran->ran = false;
        $mutation = function (Application $app) use ($bypass, $ran) {
            $bypass($app, $ran);
            // Straight after the bypass the provider connects and writes.
            $app['db']->purge();
            $app['db']->connection()->getPdo();
        };

        if ($phase === 'register') {
            ProviderSentinel::$registerMutation = $mutation;
        } else {
            ProviderSentinel::$mutation = $mutation;
        }

        try {
            $this->bootstrap($app);

            // A manager built by a rebinding is wrapped by the db extender, so it ends up guarded rather than refused.
            $this->assertSame('neutralised', $phase, 'the bypass must be refused');
            $this->assertInstanceOf(GuardedDatabaseManager::class, $app['db']);
            $this->assertFalse($ran->ran, 'the rogue manager extension never executed');
            $this->assertSame(1, $spy->connects, 'only the guarded connector was used');
        } catch (TestTargetRefused) {
            $this->assertFalse($ran->ran, 'no rogue manager extension or resolver executed');
            $this->assertSame(0, $spy->connects, 'the guarded connector was never reached either');
        }
    }

    public function test_the_admitted_pgsql_connection_still_works_next_to_the_projects_sqlsrv_resolver(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, ProviderSentinel::class]);
        ProviderSentinel::$mutation = function () {
            Connection::resolverFor('sqlsrv', fn () => null);
        };
        $this->bootstrap($app);

        $this->assertInstanceOf(GuardedDatabaseManager::class, $app['db']);
        $app['db']->connection()->getPdo();
        $this->assertSame(1, $spy->connects);
        (new \ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, []);
    }

    public static function maliciousDsnOptions(): array
    {
        return [
            'sslmode host injection' => [['sslmode' => 'disable;host=10.0.0.9;port=5432']],
            'sslmode newline'        => [['sslmode' => "prefer\n"]],
            'charset injection'      => [['charset' => "utf8';host=10.0.0.9"]],
            'application_name'       => [['application_name' => "x';host=10.0.0.9;a='"]],
            'sslcert'                => [['sslcert' => '/tmp/c.pem']],
            'sslkey'                 => [['sslkey' => '/tmp/k.pem']],
            'sslrootcert'            => [['sslrootcert' => '/tmp/ca.pem;host=10.0.0.9']],
            'PDO options'            => [['options' => [\PDO::ATTR_PERSISTENT => true]]],
            'search_path injection'  => [['search_path' => 'public; select 1']],
            'timezone injection'     => [['timezone' => "UTC'; select 1"]],
        ];
    }

    #[DataProvider('maliciousDsnOptions')]
    public function test_malicious_dsn_or_session_options_never_reach_the_inner_connector(array $option): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class]);
        $this->bootstrap($app);
        $app['config']->set('database.connections.pgsql', $option + $app['config']->get('database.connections.pgsql'));
        $app['db']->purge();

        try {
            $app['db']->connection()->getPdo();
            $this->fail('the option must be refused');
        } catch (TestTargetRefused) {
            $this->assertSame(0, $spy->connects);
        }
    }

    public function test_the_same_options_in_the_initial_configuration_are_refused_before_any_provider(): void
    {
        foreach (['sslmode' => 'disable;host=10.0.0.9;port=5432', 'sslcert' => '/tmp/c.pem'] as $key => $value) {
            [$app, $spy] = $this->app([ProviderSentinel::class], connection: [$key => $value]);

            try {
                $this->bootstrap($app);
                $this->fail('refused');
            } catch (TestTargetRefused) {
                $this->assertFalse(ProviderSentinel::$registered);
                $this->assertSame(0, $spy->connects);
            }
        }
    }

    public function test_session_initialisation_runs_only_after_the_identity_check_and_with_the_full_configuration(): void
    {
        $log = new \ArrayObject;
        $inner = new SessionSpyConnector($log);
        $base = $this->allowlist['worktree'];
        $guard = new GuardedConnector($this->allowlist, $inner, fn () => true, function () use ($log) {
            $log[] = 'identity';

            return $this->live();
        });
        $config = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5433', 'database' => 'mothership_test', 'charset' => 'utf8', 'sslmode' => 'prefer', 'search_path' => 'public', 'timezone' => 'UTC', 'isolation_level' => 'read committed', 'synchronous_commit' => 'off', 'username' => 'u', 'password' => 'p'];

        $guard->connect($config);

        $this->assertSame(['connect', 'identity', 'isolation', 'timezone', 'search_path', 'synchronous_commit'], $log->getArrayCopy());
        $this->assertSame(array_diff_key($config, array_flip(TestTargetGuard::SESSION_KEYS)), $inner->connectedWith, 'the inner connector never sees a session-SQL option');
        $this->assertSame($config, $inner->sessionConfig, 'session steps replay with the exact admitted configuration');
    }

    public function test_a_wrong_server_runs_no_session_sql_at_all(): void
    {
        $log = new \ArrayObject;
        $guard = new GuardedConnector($this->allowlist, new SessionSpyConnector($log), fn () => true, fn () => $this->live(['data_directory' => '/elsewhere']));

        try {
            $guard->connect(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5433', 'database' => 'mothership_test', 'search_path' => 'public', 'timezone' => 'UTC']);
            $this->fail('refused');
        } catch (TestTargetRefused) {
            $this->assertSame(['connect'], $log->getArrayCopy());
        }
    }

    public static function installerHelpers(): array
    {
        return [
            'handle'                  => ['handle', []],
            'checkForEnvFile'         => ['checkForEnvFile', []],
            'createEnvFile'           => ['createEnvFile', []],
            'loadEnvConfigAtRuntime'  => ['loadEnvConfigAtRuntime', []],
            'envUpdate'               => ['envUpdate', ['APP_KEY', 'sentinel']],
            'envDefault'              => ['envDefault', ['APP_KEY', 'x']],
            'getEnvAtRuntime'         => ['getEnvAtRuntime', ['APP_KEY']],
            'runProcess'              => ['runProcess', [['/usr/bin/touch', 'PROCESS_SENTINEL']]],
            'markInstalled'           => ['markInstalled', []],
            'resolvedDatabaseEnv'     => ['resolvedDatabaseEnv', []],
            'seedSampleProducts'      => ['seedSampleProducts', []],
            'createAdminCredentials'  => ['createAdminCredentials', []],
            'installOptionalPackages' => ['installOptionalPackages', [['x']]],
        ];
    }

    #[DataProvider('installerHelpers')]
    public function test_every_installer_entry_point_refuses_before_any_env_key_storage_cache_or_process_effect(string $method, array $args): void
    {
        // Distinct roots: the env root (application base path, holding the sentinel .env/storage) is NOT the allowlisted database worktree.
        $envRoot = $this->root.'/env-root';
        mkdir($envRoot.'/storage/framework/cache', 0700, true);
        mkdir($envRoot.'/bootstrap/cache', 0700, true);
        file_put_contents($envRoot.'/.env', "APP_KEY=sentinel\nDB_DATABASE=sentinel\n");
        file_put_contents($envRoot.'/.env.example', "EXAMPLE=1\n");

        $before = $this->snapshot($this->root);
        $app = new Application($envRoot);
        TestTargetGuard::markLane();

        $installer = new Installer;
        $reflection = new \ReflectionMethod($installer, $method);
        $args = array_map(fn ($a) => $a === 'PROCESS_SENTINEL' ? $envRoot.'/process-ran' : (is_array($a) ? array_map(fn ($x) => $x === 'PROCESS_SENTINEL' ? $envRoot.'/process-ran' : $x, $a) : $a), $args);

        try {
            $reflection->isStatic() ? $reflection->invoke(null, ...$args) : $reflection->invoke($installer, ...$args);
            $this->fail("{$method} must refuse");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('guarded test lane', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshot($this->root), "{$method} left no trace under either root");
        $this->assertFileDoesNotExist($envRoot.'/process-ran');
        $this->assertFileDoesNotExist($envRoot.'/storage/installed');
        $this->assertSame($app->basePath(), $envRoot);
    }

    /** @return array<string, string> path => content hash, for every file and directory below the root */
    private function snapshot(string $root): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[$f->getPathname()] = hash_file('sha256', $f->getPathname());
        }
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $out[$dir.'/'] = 'dir';
        }
        ksort($out);

        return $out;
    }

    public function test_the_application_wires_the_guard_before_the_kernel_bootstraps(): void
    {
        $source = file_get_contents(__DIR__.'/../CreatesApplication.php');

        $this->assertLessThan(strpos($source, '->make(Kernel::class)->bootstrap()'), strpos($source, 'GuardedDatabase::attach('));
    }

    public function test_the_guarded_lane_never_reaches_the_key_generator_in_the_bootstrap_file(): void
    {
        $source = file_get_contents(__DIR__.'/../bootstrap.php');

        $this->assertLessThan(strpos($source, 'openssl_pkey_new'), strpos($source, 'exit(1)'), 'the refusal precedes key generation');
    }
}

final class SessionSpyConnector implements ConnectorInterface
{
    public array $connectedWith = [];

    public array $sessionConfig = [];

    public function __construct(private readonly \ArrayObject $log) {}

    public function connect(array $config)
    {
        $this->log[] = 'connect';
        $this->connectedWith = $config;

        return new \stdClass;
    }

    protected function configureIsolationLevel($connection, array $config)
    {
        $this->log[] = 'isolation';
        $this->sessionConfig = $config;
    }

    protected function configureTimezone($connection, array $config)
    {
        $this->log[] = 'timezone';
    }

    protected function configureSearchPath($connection, array $config)
    {
        $this->log[] = 'search_path';
    }

    protected function configureSynchronousCommit($connection, array $config)
    {
        $this->log[] = 'synchronous_commit';
    }
}

final class SpyConnector implements ConnectorInterface
{
    public int $connects = 0;

    public function connect(array $config)
    {
        $this->connects++;

        return new \stdClass;
    }
}

final class ProviderSentinel extends ServiceProvider
{
    public static bool $registered = false;

    public static mixed $mutation = null;

    public static mixed $registerMutation = null;

    public function register(): void
    {
        self::$registered = true;

        if (self::$registerMutation !== null) {
            (self::$registerMutation)($this->app);
        }
    }

    public function boot(): void
    {
        if (self::$mutation !== null) {
            (self::$mutation)($this->app);
        }
    }
}
