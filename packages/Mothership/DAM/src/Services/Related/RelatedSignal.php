<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related;

use Mothership\DAM\Models\Asset;

/**
 * One reason two assets are related (P4 D14), and the extension point for new ones: a class implementing this and
 * listed in Config/related.php `signals` with a weight joins the ranking. Labels and collections plug in here once
 * they exist.
 *
 * candidates() must be one bounded, indexed lookup: at most $scope->limit assets, only among $scope->visible(), never
 * any id in $scope->exclude. It never sees a hidden asset, so a hidden asset can neither be shown nor counted.
 */
interface RelatedSignal
{
    /** @return array<int, SignalMatch> keyed by asset id */
    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array;

    /** The short "why" this signal contributes to a card, e.g. "3 shared tags". */
    public function reason(SignalMatch $match): string;
}
