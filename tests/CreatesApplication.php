<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\Support\GuardedDatabase;
use Tests\Support\TestTargetGuard;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * In a guarded lane (phpunit.mothership.xml) the target is admitted before anything can reach a database; see
     * GuardedDatabase::attach for the hook order. After bootstrap the resolved Laravel connection is admitted once more.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $allowlist = null;

        if (TestTargetGuard::required()) {
            TestTargetGuard::markLane();

            // Second admission mode, same lifecycle: the CI service proof stands in for the local allowlist file.
            $allowlist = TestTargetGuard::ciMode()
                ? TestTargetGuard::loadCiProof(env: TestTargetGuard::ciEnvironment(), basePath: $app->basePath())
                : TestTargetGuard::loadAllowlist(
                    path: (string) getenv(TestTargetGuard::ALLOWLIST_ENV),
                    basePath: $app->basePath(),
                    expectedSha256: (string) getenv(TestTargetGuard::ALLOWLIST_SHA256_ENV),
                );

            GuardedDatabase::attach(app: $app, allowlist: $allowlist, probe: fn (array $connection) => GuardedDatabase::probe(connection: $connection, query: TestTargetGuard::liveQuery($allowlist)));
        }

        // A class that demands an admitted lane (the committed PostgreSQL race tests) cannot reach providers unguarded when
        // every guard mode was omitted: the effective driver is judged right after configuration loads.
        if ($allowlist === null && defined(static::class.'::'.TestTargetGuard::DEMAND_CONSTANT) && constant(static::class.'::'.TestTargetGuard::DEMAND_CONSTANT) === true) {
            $app['events']->listen('bootstrapped: '.LoadConfiguration::class, fn (Application $app) => TestTargetGuard::refuseUnadmittedPostgres($app['config']->get('database')));
        }

        $app->make(Kernel::class)->bootstrap();

        if ($allowlist !== null) {
            GuardedDatabase::assertIntact($app);

            TestTargetGuard::admit(
                database: $app['config']->get('database'),
                allowlist: $allowlist,
                basePath: $app->basePath(),
                probe: fn () => (array) $app['db']->connection(TestTargetGuard::CONNECTION)->selectOne(TestTargetGuard::liveQuery($allowlist)),
            );
        }

        return $app;
    }
}
