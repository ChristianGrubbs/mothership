<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related;

/** What one signal says about one candidate: its weighted score, how many things matched, and a label for the "why". */
final class SignalMatch
{
    public function __construct(
        public readonly float $score,
        public readonly int $count = 1,
        public readonly ?string $label = null,
    ) {}
}
