<?php

namespace Mothership\DAM\Tests\Support;

use RuntimeException;

/**
 * A seam for proving that a committed test's teardown cleans up after a setUp that never finished.
 *
 * A setUp failure cannot be staged from inside the same process: the assertion about what the teardown restored
 * has to run after that teardown. So AccessCommittedTeardownTest runs the real test class in a child process
 * with DAM_TEST_INJECT_FAILURE set to the point it wants to break, and then inspects the database itself.
 *
 * The environment variable has to match the point exactly, so an unset or stale value cannot break a normal run,
 * and the value in the parent's Process call names the line it breaks.
 */
final class InjectedFailure
{
    public const ENV = 'DAM_TEST_INJECT_FAILURE';

    /** Throws when this process was asked to fail at $point, and does nothing otherwise. */
    public static function at(string $point): void
    {
        if (getenv(self::ENV) === $point) {
            throw new RuntimeException('injected test failure: '.$point);
        }
    }
}
