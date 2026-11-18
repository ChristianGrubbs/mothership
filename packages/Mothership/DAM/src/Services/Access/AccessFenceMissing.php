<?php

namespace Mothership\DAM\Services\Access;

use RuntimeException;

/**
 * A list write reached AccessListWriter in a transaction that does not hold the DAM_ACCESS_MODEL row
 * (ADR 0010's fence, sharing slice 2a). Thrown only in tests, where a missed or mis-ordered hold is a
 * defect to fix; in production the write logs `access_fence_missing` and takes the hold late.
 */
class AccessFenceMissing extends RuntimeException
{
    public const REMEDY = 'Open that transaction through AccessModel::sharedly() (or Directory::withTreeLock(), which fences the outermost tree write), so its first statement holds the DAM_ACCESS_MODEL row FOR SHARE before any other row.';

    public function __construct(public readonly string $writer)
    {
        parent::__construct(sprintf(
            'AccessListWriter::%s() ran without the DAM_ACCESS_MODEL row held FOR SHARE. %s',
            $writer,
            self::REMEDY
        ));
    }
}
