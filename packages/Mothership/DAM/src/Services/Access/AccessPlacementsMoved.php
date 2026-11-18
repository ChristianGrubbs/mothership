<?php

namespace Mothership\DAM\Services\Access;

use RuntimeException;

/**
 * A writer locked a file's placement folders at stage 3, and by the time it had locked the file itself at
 * stage 4 those placements had changed (ADR 0010's lock order, sharing slice 2a).
 *
 * Inheriting from the new set would mean asking for a folder row after an asset row, which is the one order
 * that deadlocks with a cascade. AccessListWriter::fencedAndTreeLocked() rolls the transaction back and runs
 * it once more from the start instead; this escapes only when the second attempt is beaten as well.
 */
class AccessPlacementsMoved extends RuntimeException
{
    /**
     * @param  list<int>  $locked  the placement folders the writer held
     * @param  list<int>  $found  the placement folders it saw once the files were locked
     */
    public function __construct(public readonly array $locked, public readonly array $found)
    {
        parent::__construct(sprintf(
            'The files moved between folders while their access list was being written: %d folder(s) were held, %d are current. Nothing was written.',
            count($locked),
            count($found)
        ));
    }
}
