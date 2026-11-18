<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use RuntimeException;

/** The Studio AI bridge is down, unset, or answered something unusable. Messages carry no payload data. */
class AiBridgeUnavailable extends RuntimeException {}
