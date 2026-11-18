<?php

namespace Mothership\DAM\Exceptions;

use InvalidArgumentException;

/**
 * A FileObject may only name a content-addressed blob (ADR 0009). Raised when a save would record any other
 * locator, such as a Library path that a folder move could later stale.
 */
class NonContentAddressedLocator extends InvalidArgumentException
{
    public function __construct(public readonly ?string $path, public readonly ?string $sha256)
    {
        parent::__construct("FileObject locator [{$path}] is not the content-addressed object path of [{$sha256}].");
    }
}
