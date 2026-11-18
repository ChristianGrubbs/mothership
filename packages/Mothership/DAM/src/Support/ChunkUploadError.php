<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use RuntimeException;

final class ChunkUploadError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly bool $retryable,
        string $message,
        /** A people-facing refusal reason ({code, key, params}, see UploadRefusal), persisted for the status poll. */
        public readonly ?array $reason = null,
    ) {
        parent::__construct($message);
    }
}
