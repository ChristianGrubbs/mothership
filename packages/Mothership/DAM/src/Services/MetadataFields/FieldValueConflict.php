<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

/**
 * A person's save named the values it started from, and someone else changed at least one of them since: nothing was
 * written. The editor answers 409 and asks the person to reload.
 */
class FieldValueConflict extends \RuntimeException
{
    /** @param  list<string>  $codes  the fields whose stored value no longer matches */
    public function __construct(public readonly array $codes)
    {
        parent::__construct('Field values changed since the editor opened: '.implode(', ', $codes));
    }
}
