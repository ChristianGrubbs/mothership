<?php

namespace Mothership\DAM\Exceptions;

use RuntimeException;

/**
 * Thrown by DirectoryRepository::create()/update() when a caller that skipped its own
 * validation (form request, segment loop) still tries to create or rename a directory into a
 * reserved name. See ReservedDirectoryNames for why a leading `.` is refused.
 */
class DirectoryNameReservedException extends RuntimeException {}
