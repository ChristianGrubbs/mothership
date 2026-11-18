<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Carbon\CarbonImmutable;

/** The default NamingContext: no calendar and no gazetteer, so a name comes from the file alone. */
final class NullNamingContext implements NamingContext
{
    public function eventsAround(CarbonImmutable $capturedAt): array
    {
        return [];
    }

    public function placeOf(array $metadata, int $assetId): array
    {
        return ['', ''];
    }
}
