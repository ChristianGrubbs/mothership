<?php

declare(strict_types=1);

namespace Mothership\DAM\Mcp;

/**
 * A chunked library_save whose upload is not finished yet (LibraryOperations::saveChunked). The REST route answers
 * 202 with the upload's status, so the launcher sends the missing parts, completes the upload and asks again with
 * the same request_id. It never reaches an agent.
 */
final class LibraryUploadPending extends \RuntimeException
{
    /** @param  array<string, mixed>  $upload  ChunkUploadService::status() */
    public function __construct(public readonly array $upload)
    {
        parent::__construct('The upload is not finished yet.');
    }
}
