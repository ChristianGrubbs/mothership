<?php

namespace Mothership\DAM\Services\Access;

use RuntimeException;

/**
 * A switch run lost the model to another run: its compare-and-set affected no row, or it no longer holds
 * the switch lock. Thrown inside a transaction, so the run's own writes roll back, and it writes nothing
 * more (ADR 0010).
 */
class AccessSwitchSuperseded extends RuntimeException
{
    public function __construct(public readonly bool $lockLost = false)
    {
        parent::__construct($lockLost
            ? 'Superseded: this run no longer holds the switch lock (it expired, or another run took it), so it wrote nothing more.'
            : 'Superseded: another run changed the access model during this switch, so this run wrote nothing more.');
    }
}
