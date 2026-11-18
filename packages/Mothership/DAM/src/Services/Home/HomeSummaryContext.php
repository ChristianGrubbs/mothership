<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;

/** Who Home is summarising for, and the moment that separates new from seen. */
final readonly class HomeSummaryContext
{
    public function __construct(
        public int $adminId,
        public ?CarbonImmutable $since,
        public CarbonImmutable $now,
    ) {}

    public function isNew(CarbonImmutable $moment): bool
    {
        return $this->since !== null && $moment->greaterThan($this->since);
    }
}
