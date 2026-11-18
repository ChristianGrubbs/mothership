<?php

namespace Tests\Guard;

use Illuminate\Events\EventServiceProvider;
use Illuminate\Log\Context\ContextServiceProvider;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Mothership\DAM\Tests\Feature\AutomationManualWriteRacePostgresTest;
use Mothership\DAM\Tests\Feature\MappingChunkCountsRacePostgresTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestTargetGuard;
use Tests\Support\TestTargetRefused;

require_once __DIR__.'/../Support/TestTargetGuard.php';
require_once __DIR__.'/../Support/GuardedDatabase.php';
require_once __DIR__.'/../CreatesApplication.php';
require_once __DIR__.'/../../packages/Mothership/DAM/tests/Feature/AutomationManualWriteRacePostgresTest.php';
require_once __DIR__.'/../../packages/Mothership/DAM/tests/Feature/MappingChunkCountsRacePostgresTest.php';

/**
 * The two committed PostgreSQL race classes demand an admitted lane. With EVERY guard variable omitted, the real
 * CreatesApplication lifecycle must refuse right after configuration loads: no provider registers, nothing resolves a
 * database manager or connector, so no connection can be attempted. Connection-free (the refusal precedes providers).
 *
 *   vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Guard/DemandedLaneTest.php
 */
final class DemandedLaneTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        $keys = [TestTargetGuard::REQUIRE_ENV, TestTargetGuard::ALLOWLIST_ENV, TestTargetGuard::ALLOWLIST_SHA256_ENV, TestTargetGuard::CI_PROOF_ENV, TestTargetGuard::CI_PROOF_SHA256_ENV, 'DB_CONNECTION', 'DB_URL', 'GITHUB_ACTIONS', 'MOTHERSHIP_CI_PG_TARGET', 'APP_CONFIG_CACHE'];

        foreach ($keys as $key) {
            $this->saved[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
    }

    /** @return array<string, mixed> */
    private function runChild(string $class, array $env): array
    {
        $clean = array_diff_key(getenv(), array_flip(array_keys($this->saved)));
        $env = $env + ['DB_CONNECTION' => 'pgsql', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/never-cached-'.bin2hex(random_bytes(4)).'.php'] + $clean;
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=0', __DIR__.'/demanded-lane-child.php', $class], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);

        return json_decode(trim($out), true) ?? ['refused' => false, 'error' => 'no result: '.$err.$out];
    }

    public static function demandingClasses(): array
    {
        $target = ['GITHUB_ACTIONS' => 'true', 'MOTHERSHIP_CI_PG_TARGET' => '127.0.0.1:5433/mothership_test_ci@/var/lib/postgresql/data'];

        return [
            'automation/manual write race'                => [AutomationManualWriteRacePostgresTest::class, []],
            'mapping chunk counts race'                   => [MappingChunkCountsRacePostgresTest::class, []],
            'ambient Actions and a nominal target string' => [AutomationManualWriteRacePostgresTest::class, $target],
            'ambient Actions, mapping class'              => [MappingChunkCountsRacePostgresTest::class, $target],
        ];
    }

    #[DataProvider('demandingClasses')]
    public function test_a_demanding_class_with_every_guard_variable_omitted_is_refused_before_any_provider(string $class, array $env): void
    {
        $this->assertTrue(constant($class.'::'.TestTargetGuard::DEMAND_CONSTANT));

        $result = $this->runChild($class, $env);

        $this->assertSame(true, $result['refused'], json_encode($result));
        // Provider sentinel: only the four base providers the Application constructor itself registers (no database access);
        // none of the configured application, package or database providers has registered.
        $this->assertSame([EventServiceProvider::class, LogServiceProvider::class, ContextServiceProvider::class, RoutingServiceProvider::class], $result['providers']);
        $this->assertFalse($result['db_resolved'], 'no database manager, so no connector attempt');
        $this->assertFalse($result['connector_bound']);
    }

    public function test_the_refusal_only_stops_postgresql_so_other_legs_still_skip(): void
    {
        foreach (['sqlite', 'mysql', 'mariadb', 'sqlsrv'] as $driver) {
            TestTargetGuard::refuseUnadmittedPostgres(['default' => 'x', 'connections' => ['x' => ['driver' => $driver, 'url' => null]]]);
            $this->addToAssertionCount(1);
        }
    }

    public static function notProvablyNonPostgres(): array
    {
        return [
            'pgsql'              => [['default' => 'pgsql', 'connections' => ['pgsql' => ['driver' => 'pgsql']]]],
            'sqlite with a url'  => [['default' => 'x', 'connections' => ['x' => ['driver' => 'sqlite', 'url' => 'postgresql://h/db']]]],
            'unknown driver'     => [['default' => 'x', 'connections' => ['x' => ['driver' => 'custom']]]],
            'missing connection' => [['default' => 'gone', 'connections' => []]],
            'no default'         => [['connections' => []]],
        ];
    }

    #[DataProvider('notProvablyNonPostgres')]
    public function test_anything_not_provably_non_postgresql_is_refused(array $database): void
    {
        $this->expectException(TestTargetRefused::class);
        TestTargetGuard::refuseUnadmittedPostgres($database);
    }
}
