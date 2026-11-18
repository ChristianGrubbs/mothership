<?php

namespace Mothership\DAM\Media;

use RuntimeException;

/**
 * Thrown by a PanoramaWorker::run() implementation when the caller's
 * $cancelled closure reports a cooperative cancellation request. The queue
 * job maps this to Rendition::STATUS_CANCELLED rather than STATUS_FAILED.
 */
final class PanoramaCancelled extends RuntimeException {}
