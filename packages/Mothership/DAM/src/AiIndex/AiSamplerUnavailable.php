<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use RuntimeException;

/**
 * The sampler could not look at the file for a reason that is about this machine or this moment (a tool that is not
 * installed, a timeout, a read error), not about the file. It is tried again, and is never recorded as "no picture".
 */
class AiSamplerUnavailable extends RuntimeException {}
