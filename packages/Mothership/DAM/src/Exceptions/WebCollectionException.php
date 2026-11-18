<?php

namespace Mothership\DAM\Exceptions;

use RuntimeException;

/**
 * A refused collection write. $reason is one of name-taken | name-invalid | too-deep | cycle |
 * missing-parent and doubles as the message and the lang key suffix
 * (dam::app.admin.dam.web-pages.collections.<reason>). Not `$code`: Exception::$code is an int.
 */
class WebCollectionException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $limit = null)
    {
        parent::__construct($reason);
    }
}
