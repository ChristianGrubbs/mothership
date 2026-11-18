<?php

declare(strict_types=1);

namespace Mothership\DAM\Exceptions;

use RuntimeException;

/**
 * A 3D capture could not be written under its lock: the lock was held by someone else for the whole wait, the cache
 * that holds locks was unreachable, or the lease was lost (or ran past its deadline) before the capture was published.
 * Nothing was written; the same request can be made again.
 */
class CaptureLockUnavailable extends RuntimeException {}
