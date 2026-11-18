<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/** What ingest made of a batch: accepted (204, with a renewed token when one is due) or refused (403). */
final class IngestResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly int $stored,
        public readonly ?string $reason,
        public readonly ?string $renewal,
    ) {}

    public static function accepted(int $stored, ?string $renewal = null): self
    {
        return new self(accepted: true, stored: $stored, reason: null, renewal: $renewal);
    }

    public static function refused(string $reason): self
    {
        return new self(accepted: false, stored: 0, reason: $reason, renewal: null);
    }
}
