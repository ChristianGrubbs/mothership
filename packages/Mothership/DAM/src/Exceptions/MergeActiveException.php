<?php

declare(strict_types=1);

namespace Mothership\DAM\Exceptions;

use RuntimeException;

/**
 * P3b-1: a permanent delete refused because the asset takes part in a merge that is neither split nor finalized: a
 * merge source, or a destination holding merged files. Finalize (or split) the merge first; the trash keeps it.
 */
class MergeActiveException extends RuntimeException
{
    public function __construct(public readonly int $assetId)
    {
        parent::__construct('The asset takes part in an active merge; finalize or split it before deleting for good.');
    }
}
