<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

use RuntimeException;

/** An adopt run whose counts do not reconcile; thrown inside the transaction so nothing is kept. */
class FieldAdoptionException extends RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('The field adoption does not reconcile.');
    }
}
