<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

/**
 * The `origin=automation` context flag of the loop guard (roadmap §3.4). Mapping and rule writes run inside run();
 * every value change raised meanwhile carries `automation: true`, and the triggers ignore it, so a rule's own write
 * never fires a rule. A depth, not a boolean, so a nested run() cannot clear the flag of the one around it.
 */
final class AutomationOrigin
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
