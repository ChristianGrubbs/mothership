<?php

namespace Mothership\Admin\Exceptions;

use RuntimeException;

/**
 * An access decision the rules do not allow. The message is written for the person who made the decision,
 * at the shell or on Settings > Users.
 */
class AccessDecisionRefused extends RuntimeException {}
