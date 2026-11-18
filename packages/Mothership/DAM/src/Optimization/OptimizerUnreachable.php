<?php

namespace Mothership\DAM\Optimization;

use RuntimeException;

/**
 * The Studio Clop bridge could not be reached at all (connection refused, host unreachable, connect
 * timeout): no bytes were processed and nothing was produced. Kept distinct from a real optimization
 * failure so the user can be told the service is down rather than invited to retry a doomed run.
 * Like every other Optimizer failure the message is scrubbed; no host or transport detail is carried.
 */
class OptimizerUnreachable extends RuntimeException {}
