<?php

namespace Mothership\DAM\Mcp;

/**
 * A library tool call that was refused, with the text the agent reads. LibraryTool::perform throws it, so the stdio
 * server and the REST route (LibraryToolController) give the same words for the same refusal; status is the HTTP
 * status the REST route answers with.
 */
final class LibraryRefusal extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
