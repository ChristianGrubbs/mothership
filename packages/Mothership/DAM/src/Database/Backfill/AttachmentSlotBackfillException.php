<?php

namespace Mothership\DAM\Database\Backfill;

use RuntimeException;

class AttachmentSlotBackfillException extends RuntimeException
{
    /** @param  array<string, mixed>  $report */
    public function __construct(public readonly array $report)
    {
        parent::__construct('Attachment slot backfill did not reconcile: '.implode('; ', $report['violations']));
    }
}
