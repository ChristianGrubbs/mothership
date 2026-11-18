<?php

namespace Mothership\DAM\Services\Access;

use RuntimeException;

/**
 * `dam:access:switch lists` compared the rebuilt lists with the grants and found failing differences.
 * Thrown inside the switch's transaction, so the whole switch, rebuild included, rolls back (ADR 0010).
 */
class AccessSwitchRefused extends RuntimeException
{
    /**
     * @param  array{failures: int, failing: list<array{admin_id: int, item: string, id: int, difference: string}>}  $report
     */
    public function __construct(public readonly array $report)
    {
        parent::__construct(sprintf(
            'Refused: %d failing differences between the grants and the lists. Nothing was written; reads stay on grants.',
            $report['failures']
        ));
    }
}
