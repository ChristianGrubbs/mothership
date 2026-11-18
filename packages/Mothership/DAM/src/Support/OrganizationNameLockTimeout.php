<?php

namespace Mothership\DAM\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * A name lock timed out while the caller was already inside a transaction (see OrganizationNameLock). It must reach
 * the caller's own DB::transaction so the whole transaction rolls back; it is never a result to catch and commit
 * past. If it reaches HTTP the person still gets the 409 "try again" answer.
 */
final class OrganizationNameLockTimeout extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(trans('dam::app.admin.organize.busy'), 409, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
