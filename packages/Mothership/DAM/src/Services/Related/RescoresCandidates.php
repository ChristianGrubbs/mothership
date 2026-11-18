<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related;

use Mothership\DAM\Models\Asset;

/**
 * A signal that can also score assets other signals found (P4 D14): the vector rescores the whole pool, so a file
 * found by its tags also counts how alike it looks. $ids are already within the viewer's visible set.
 */
interface RescoresCandidates
{
    /**
     * @param  list<int>  $ids
     * @return array<int, SignalMatch> keyed by asset id
     */
    public function rescore(Asset $seed, array $ids, RelatedScope $scope, float $weight): array;
}
