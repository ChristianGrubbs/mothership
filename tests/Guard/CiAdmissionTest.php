<?php

namespace Tests\Guard;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\GuardedDatabase;
use Tests\Support\TestTargetGuard;
use Tests\Support\TestTargetRefused;

require_once __DIR__.'/../Support/TestTargetGuard.php';
require_once __DIR__.'/../Support/GuardedDatabase.php';

/**
 * The CI admission mode (service proof instead of a local allowlist), connection-free: a real Illuminate Application and
 * ConnectionFactory with a spy in place of PostgresConnector, so no PDO and no connection is ever created.
 *
 *   vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Guard/CiAdmissionTest.php
 */
final class CiAdmissionTest extends TestCase
{
    private const CONTAINER = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private string $root;

    private string $workspace;

    private string $temp;

    /** @var array<string, mixed> */
    private array $live;

    /** @var list<string> */
    private array $touchedEnv = [];

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/guard-ci-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/ws', 0700, true);
        mkdir($this->root.'/runner-temp', 0700);
        $this->workspace = realpath($this->root.'/ws');
        $this->temp = realpath($this->root.'/runner-temp');
        $this->live = ['database' => 'mothership_test_ci', 'host' => '172.18.0.2', 'port' => 5432, 'data_directory' => '/var/lib/postgresql/data', 'started_at' => 1760000000, 'system_identifier' => '7400000000000000001'];
        CiProviderSentinel::$registerMutation = null;
        CiProviderSentinel::$mutation = null;
        CiProviderSentinel::$registered = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $key) {
            putenv($key);
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isLink() || ! $f->isDir() ? unlink($f->getPathname()) : rmdir($f->getPathname());
        }
        rmdir($this->root);
        CiProviderSentinel::$registerMutation = null;
        CiProviderSentinel::$mutation = null;
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $this->touchedEnv[] = $key;
    }

    /** @return array<string, mixed> */
    private function proofBody(array $override = []): array
    {
        return $override + [
            'container_id'      => self::CONTAINER,
            'run_id'            => '9001',
            'run_attempt'       => '2',
            'workspace'         => $this->workspace,
            'host_port'         => 49153,
            'container_ips'     => ['172.18.0.2'],
            'database'          => 'mothership_test_ci',
            'data_directory'    => '/var/lib/postgresql/data',
            'tmpfs'             => true,
            'started_at'        => 1760000000,
            'system_identifier' => '7400000000000000001',
        ];
    }

    /**
     * Writes a proof the way the workflow does (private, under RUNNER_TEMP) and returns the matching runner environment.
     *
     * @return array<string, string>
     */
    private function env(array $proof = [], array $envOverride = [], int $mode = 0600): array
    {
        $path = $this->temp.'/proof.json';
        file_put_contents($path, json_encode($this->proofBody($proof)));
        chmod($path, $mode);

        return $envOverride + [
            TestTargetGuard::CI_PROOF_ENV        => $path,
            TestTargetGuard::CI_PROOF_SHA256_ENV => hash_file('sha256', $path),
            TestTargetGuard::CI_SERVICE_ID_ENV   => self::CONTAINER,
            TestTargetGuard::CI_SERVICE_PORT_ENV => '49153',
            'GITHUB_ACTIONS'                     => 'true',
            'GITHUB_RUN_ID'                      => '9001',
            'GITHUB_RUN_ATTEMPT'                 => '2',
            'GITHUB_WORKSPACE'                   => $this->workspace,
            'RUNNER_TEMP'                        => $this->temp,
        ];
    }

    private function load(array $env): array
    {
        return TestTargetGuard::loadCiProof(env: $env, basePath: $this->workspace);
    }

    public function test_a_fully_consistent_proof_is_admitted_as_the_ci_service(): void
    {
        $allowlist = $this->load($this->env());

        $this->assertSame('127.0.0.1', $allowlist['host']);
        $this->assertSame(49153, $allowlist['port']);
        $this->assertSame('mothership_test_ci', $allowlist['database']);
        $this->assertSame($this->workspace, $allowlist['worktree']);
    }

    public static function inconsistentEnvironments(): array
    {
        return [
            'wrong container id'            => [[], ['MOTHERSHIP_CI_SERVICE_ID' => 'b1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90']],
            'wrong service port env'        => [[], ['MOTHERSHIP_CI_SERVICE_PORT' => '5433']],
            'wrong host port in the proof'  => [['host_port' => 5433], []],
            'the standard port'             => [['host_port' => 5432], ['MOTHERSHIP_CI_SERVICE_PORT' => '5432']],
            'other database'                => [['database' => 'mothership_test'], []],
            'database by prefix only'       => [['database' => 'mothership_test_ci_other'], []],
            'wrong run id'                  => [[], ['GITHUB_RUN_ID' => '9002']],
            'wrong run attempt'             => [[], ['GITHUB_RUN_ATTEMPT' => '1']],
            'proof from another attempt'    => [['run_attempt' => '1'], []],
            'wrong workspace in the proof'  => [['workspace' => '/somewhere/else'], []],
            'tmpfs flag false'              => [['tmpfs' => false], []],
            'tmpfs flag as a string'        => [['tmpfs' => 'true'], []],
            'other data directory'          => [['data_directory' => '/var/lib/postgresql'], []],
            'container id not hex'          => [['container_id' => 'db'], ['MOTHERSHIP_CI_SERVICE_ID' => 'db']],
            'address not an IPv4'           => [['container_ips' => ['localhost']], []],
            'address not a list'            => [['container_ips' => '172.18.0.2'], []],
            'no address'                    => [['container_ips' => []], []],
            'no start time'                 => [['started_at' => 0], []],
            'system identifier not numeric' => [['system_identifier' => 'abc'], []],
            'actions variable missing'      => [[], ['GITHUB_ACTIONS' => '']],
            'actions variable false'        => [[], ['GITHUB_ACTIONS' => 'false']],
            'run id missing'                => [[], ['GITHUB_RUN_ID' => '']],
            'runner temp missing'           => [[], ['RUNNER_TEMP' => '']],
            'hash missing'                  => [[], ['MOTHERSHIP_CI_PROOF_SHA256' => '']],
            'hash of other bytes'           => [[], ['MOTHERSHIP_CI_PROOF_SHA256' => str_repeat('a', 64)]],
        ];
    }

    #[DataProvider('inconsistentEnvironments')]
    public function test_a_proof_that_disagrees_with_the_job_is_refused(array $proof, array $envOverride): void
    {
        $this->expectException(TestTargetRefused::class);
        $this->load($this->env($proof, $envOverride));
    }

    public function test_a_proof_altered_after_its_hash_was_taken_is_refused(): void
    {
        $env = $this->env();
        file_put_contents($env[TestTargetGuard::CI_PROOF_ENV], json_encode($this->proofBody(['host_port' => 5433])));

        $this->expectException(TestTargetRefused::class);
        $this->load($env);
    }

    public function test_a_proof_with_an_extra_field_is_refused(): void
    {
        $env = $this->env();
        $path = $env[TestTargetGuard::CI_PROOF_ENV];
        file_put_contents($path, json_encode($this->proofBody() + ['host' => '10.0.0.5']));
        $env[TestTargetGuard::CI_PROOF_SHA256_ENV] = hash_file('sha256', $path);

        $this->expectException(TestTargetRefused::class);
        $this->load($env);
    }

    public static function openModes(): array
    {
        return ['group readable' => [0640], 'world readable' => [0604], 'group writable' => [0620]];
    }

    #[DataProvider('openModes')]
    public function test_a_proof_open_to_group_or_others_is_refused(int $mode): void
    {
        $this->expectException(TestTargetRefused::class);
        $this->load($this->env(mode: $mode));
    }

    public function test_a_symlinked_or_non_canonical_proof_path_is_refused(): void
    {
        $env = $this->env();
        symlink($env[TestTargetGuard::CI_PROOF_ENV], $this->temp.'/link.json');

        foreach ([$this->temp.'/link.json', $this->temp.'/../runner-temp/proof.json'] as $path) {
            try {
                $this->load([TestTargetGuard::CI_PROOF_ENV => $path] + $env);
                $this->fail("{$path} must be refused");
            } catch (TestTargetRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_proof_inside_the_checkout_or_outside_runner_temp_is_refused(): void
    {
        $env = $this->env();

        foreach ([$this->workspace, $this->root] as $dir) {
            $moved = $dir.'/proof.json';
            copy($env[TestTargetGuard::CI_PROOF_ENV], $moved);
            chmod($moved, 0600);

            try {
                $this->load([TestTargetGuard::CI_PROOF_ENV => $moved] + $env);
                $this->fail("{$dir} must be refused");
            } catch (TestTargetRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ambient_actions_variables_without_a_proof_never_admit(): void
    {
        $env = $this->env();
        unset($env[TestTargetGuard::CI_PROOF_ENV], $env[TestTargetGuard::CI_PROOF_SHA256_ENV]);

        $this->expectException(TestTargetRefused::class);
        $this->load($env);
    }

    public function test_a_caller_set_target_string_is_not_an_admission_mode(): void
    {
        $this->setEnv('GITHUB_ACTIONS', 'true');
        $this->setEnv('MOTHERSHIP_CI_PG_TARGET', '127.0.0.1:5433/mothership_test_ci@/var/lib/postgresql/data');
        $this->setEnv(TestTargetGuard::REQUIRE_ENV, '1');
        putenv(TestTargetGuard::ALLOWLIST_ENV);
        putenv(TestTargetGuard::CI_PROOF_ENV);

        $this->assertFalse(TestTargetGuard::ciMode(), 'no proof, no CI mode');

        // The remaining mode is the local allowlist lane, which refuses without its reviewed file.
        $this->expectException(TestTargetRefused::class);
        TestTargetGuard::loadAllowlist(path: '', basePath: $this->workspace, expectedSha256: '');
    }

    public function test_a_proof_and_a_local_allowlist_together_are_ambiguous_and_refused(): void
    {
        $this->setEnv(TestTargetGuard::CI_PROOF_ENV, $this->temp.'/proof.json');
        $this->setEnv(TestTargetGuard::ALLOWLIST_ENV, '/somewhere/allowlist.json');

        $this->expectException(TestTargetRefused::class);
        TestTargetGuard::ciMode();
    }

    public function test_the_local_scratch_database_cannot_be_admitted_by_a_ci_proof(): void
    {
        // The scratch instance listens on 5433 with its own pid-file directory: the proof names a container address and the
        // standard container port, so a server that answers as that scratch instance fails the live identity check.
        $allowlist = $this->load($this->env());

        foreach ([
            ['host' => '127.0.0.1', 'port' => 5433, 'data_directory' => '/srv/scratch-pg'],
            ['started_at'        => 1770000000],
            ['system_identifier' => '7500000000000000009'],
            ['database'          => 'mothership_test'],
        ] as $answer) {
            try {
                TestTargetGuard::assertLive(row: $answer + $this->live, allowlist: $allowlist, postmaster: TestTargetGuard::assertPostmaster($allowlist));
                $this->fail('a different server must be refused: '.json_encode($answer));
            } catch (TestTargetRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_service_on_two_networks_is_admitted_on_either_of_its_own_addresses_only(): void
    {
        $allowlist = $this->load($this->env(['container_ips' => ['172.18.0.2', '172.17.0.5']]));
        $postmaster = TestTargetGuard::assertPostmaster($allowlist);

        foreach (['172.18.0.2', '172.17.0.5'] as $host) {
            TestTargetGuard::assertLive(row: ['host' => $host] + $this->live, allowlist: $allowlist, postmaster: $postmaster);
            $this->addToAssertionCount(1);
        }

        $this->expectException(TestTargetRefused::class);
        TestTargetGuard::assertLive(row: ['host' => '172.17.0.9'] + $this->live, allowlist: $allowlist, postmaster: $postmaster);
    }

    public function test_the_ci_live_query_adds_the_system_identifier_and_the_local_one_is_unchanged(): void
    {
        $this->assertSame(TestTargetGuard::LIVE_QUERY, TestTargetGuard::liveQuery(['host' => '127.0.0.1']));
        $this->assertStringContainsString('pg_control_system()', TestTargetGuard::liveQuery($this->load($this->env())));
    }

    /** @return array{0: Application, 1: CiSpyConnector} */
    private function app(array $providers, array $connection = []): array
    {
        $allowlist = $this->load($this->env());
        @mkdir($this->workspace.'/config');
        @mkdir($this->workspace.'/bootstrap/cache', 0700, true);
        file_put_contents($this->workspace.'/config/app.php', '<?php return ['."'providers' => ".var_export($providers, true).'];');
        $conn = $connection + ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '49153', 'database' => 'mothership_test_ci', 'username' => 'mothership', 'password' => 'mothership'];
        file_put_contents($this->workspace.'/config/database.php', '<?php return '.var_export(['default' => 'pgsql', 'connections' => ['pgsql' => $conn, 'other' => $conn, 'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']]], true).';');

        $app = new Application($this->workspace);
        $spy = new CiSpyConnector;
        GuardedDatabase::attach(
            app: $app,
            allowlist: $allowlist,
            probe: fn () => $this->live,
            pgsql: $spy,
            liveReader: fn () => $this->live,
        );

        return [$app, $spy];
    }

    private function bootstrap(Application $app): void
    {
        $app->bootstrapWith([LoadConfiguration::class, RegisterProviders::class, BootProviders::class]);
    }

    public function test_an_admitted_ci_service_boots_and_reaches_the_connector_only_through_the_guard(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, CiProviderSentinel::class]);
        $this->bootstrap($app);

        $app['db']->connection()->getPdo();
        $this->assertSame(1, $spy->connects);
        GuardedDatabase::assertIntact($app);
    }

    public static function wrongEffectiveConfig(): array
    {
        return [
            'the standard port' => [['port' => '5432']],
            'the scratch port'  => [['port' => '5433']],
            'another database'  => [['database' => 'mothership']],
            'another host'      => [['host' => '10.0.0.5']],
            'localhost name'    => [['host' => 'localhost']],
        ];
    }

    #[DataProvider('wrongEffectiveConfig')]
    public function test_configuration_that_is_not_the_proved_service_never_registers_a_provider_or_connects(array $connection): void
    {
        [$app, $spy] = $this->app([CiProviderSentinel::class], connection: $connection);

        try {
            $this->bootstrap($app);
            $this->fail('the guard must stop the bootstrap');
        } catch (TestTargetRefused) {
            $this->assertFalse(CiProviderSentinel::$registered, 'no provider may register before admission');
            $this->assertSame(0, $spy->connects);
        }
    }

    public function test_a_provider_that_connects_while_registering_is_already_behind_the_guard(): void
    {
        CiProviderSentinel::$registered = false;
        [$app, $spy] = $this->app([DatabaseServiceProvider::class, CiProviderSentinel::class]);
        // The server answers as another instance (restarted): the first connection a provider opens is refused after the connect.
        $this->live['started_at'] = 1760000999;
        CiProviderSentinel::$registerMutation = fn (ApplicationContract $app) => $app['db']->connection()->getPdo();

        try {
            $this->bootstrap($app);
            $this->fail('a connection to a server that differs from the proof must be refused');
        } catch (TestTargetRefused) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_reconnect_purge_and_aliases_after_admission_are_checked_again(): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class]);
        $this->bootstrap($app);
        $app['db']->connection()->getPdo();
        $this->assertSame(1, $spy->connects);

        // The server restarts: the same address answers with another postmaster start time.
        $this->live['started_at'] = 1760000500;
        $app['db']->purge();

        try {
            $app['db']->connection()->getPdo();
            $this->fail('a restarted server is not the proved instance');
        } catch (TestTargetRefused) {
            $this->addToAssertionCount(1);
        }

        $this->live['started_at'] = 1760000000;

        // An alias of the same config pointing at another host, and another driver, never reach the inner connector.
        $app['config']->set('database.connections.other.host', '10.0.0.5');
        foreach (['other', 'sqlite'] as $name) {
            try {
                $app['db']->connection($name)->getPdo();
                $this->fail("{$name} must be refused");
            } catch (TestTargetRefused) {
                $this->addToAssertionCount(1);
            }
        }

        // Re-pointing the admitted connection itself and reconnecting is refused too.
        $app['config']->set('database.connections.pgsql.port', '5432');
        $app['db']->purge();
        $before = $spy->connects;

        try {
            $app['db']->connection()->getPdo();
            $this->fail('a re-pointed connection must be refused');
        } catch (TestTargetRefused) {
            $this->assertSame($before, $spy->connects);
        }
    }

    public static function dsnAndSessionOptions(): array
    {
        return [
            'dsn injection'  => [['application_name' => 'x;host=10.0.0.5']],
            'search path'    => [['search_path' => 'public; drop schema public']],
            'connect_via'    => [['connect_via_port' => '5432']],
            'read split'     => [['read' => ['host' => '10.0.0.5'], 'write' => ['host' => '127.0.0.1']]],
        ];
    }

    #[DataProvider('dsnAndSessionOptions')]
    public function test_the_local_option_checks_apply_unchanged_to_the_ci_service(array $option): void
    {
        [$app, $spy] = $this->app([DatabaseServiceProvider::class], connection: $option);

        try {
            $this->bootstrap($app);
            $app['db']->connection()->getPdo();
            $this->fail('the option must be refused');
        } catch (TestTargetRefused) {
            $this->assertSame(0, $spy->connects);
        }
    }

    public function test_the_application_loads_the_ci_proof_and_attaches_the_guard_before_the_kernel_bootstraps(): void
    {
        $source = file_get_contents(__DIR__.'/../CreatesApplication.php');

        $this->assertGreaterThan(0, strpos($source, 'loadCiProof('));
        $this->assertLessThan(strpos($source, 'GuardedDatabase::attach('), strpos($source, 'loadCiProof('));
        $this->assertLessThan(strpos($source, '->make(Kernel::class)->bootstrap()'), strpos($source, 'GuardedDatabase::attach('));
    }
}

final class CiSpyConnector implements ConnectorInterface
{
    public int $connects = 0;

    public function connect(array $config)
    {
        $this->connects++;

        return new \stdClass;
    }
}

final class CiProviderSentinel extends ServiceProvider
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
