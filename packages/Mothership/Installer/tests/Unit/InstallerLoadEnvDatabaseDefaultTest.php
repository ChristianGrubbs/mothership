<?php

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\OutputStyle;
use Mothership\Installer\Console\Commands\Installer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('Installer::loadEnvConfigAtRuntime DB default override (issue #797)', function () {
    it('overrides database.default to match the .env DB_CONNECTION at runtime', function () {
        // The test changes database.default (and the installer may change it again); whatever happens, put the original back
        // so a transaction teardown that follows runs against the connection the suite started with.
        $originalDefault = config('database.default');

        try {
            config(['database.default' => 'mysql']);

            // Stub getEnvAtRuntime via an anonymous subclass rather than writing
            // to base_path('.env'). Touching the shared on-disk .env in tests
            // would race against sibling workers in parallel mode (and against
            // anything else in this PHP process that reads .env), which is
            // exactly what bit us when this test was first written.
            $cmd = new #[Signature('mothership:install {--skip-env-check} {--skip-admin-creation} {--with-demo-data} {--with-packages=}')] class extends Installer
            {
                protected static function getEnvAtRuntime(string $key): string|bool
                {
                    return match ($key) {
                        'DB_CONNECTION' => 'pgsql',
                        'DB_HOST'       => '127.0.0.1',
                        'DB_PORT'       => '5432',
                        'DB_DATABASE'   => 'mothership',
                        'DB_USERNAME'   => 'postgres',
                        'DB_PASSWORD'   => 'postgres',
                        'DB_PREFIX'     => '',
                        'APP_ENV'       => 'local',
                        'APP_NAME'      => 'Mothership',
                        'APP_URL'       => 'http://localhost',
                        'APP_TIMEZONE'  => 'UTC',
                        'APP_LOCALE'    => 'en_US',
                        'APP_CURRENCY'  => 'USD',
                        default         => '',
                    };
                }
            };

            $cmd->setLaravel(app());
            $cmd->setInput(new ArrayInput([]));
            $cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

            // The installer re-reads the physical .env and purges the connection, so a guarded test lane (one admitted disposable
            // database target, see tests/Support/TestTargetGuard.php) refuses it outright: the expected behaviour there is the
            // refusal with the configuration untouched. Everywhere else the original expectation holds.
            $guardedLane = defined('MOTHERSHIP_GUARDED_TEST_LANE') || getenv('MOTHERSHIP_REQUIRE_TARGET_GUARD') === '1' || (string) getenv('MOTHERSHIP_TEST_TARGET_ALLOWLIST') !== '';

            if ($guardedLane) {
                expect(fn () => (new ReflectionMethod($cmd, 'loadEnvConfigAtRuntime'))->invoke($cmd))->toThrow(RuntimeException::class, 'guarded test lane');
                expect(config('database.default'))->toBe('mysql');

                return;
            }

            (new ReflectionMethod($cmd, 'loadEnvConfigAtRuntime'))->invoke($cmd);

            expect(config('database.default'))->toBe('pgsql');
        } finally {
            config(['database.default' => $originalDefault]);
        }
    });
});
