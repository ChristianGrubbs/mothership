<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related;

use Illuminate\Database\Query\Builder;

/**
 * Where a signal may look (P4 D14): the ids of the assets the viewer may list (the access seam's listing filter,
 * applied once), the ids that must never come back (the seed, its stack members and its explicit relationships),
 * and how many candidates one signal may return.
 */
final class RelatedScope
{
    /** @param  list<int>  $exclude */
    public function __construct(
        private readonly Builder $visibleIds,
        public readonly array $exclude,
        public readonly int $limit,
    ) {}

    /** A fresh copy of the visible-id subquery (`select dam_assets.id …`), for whereIn(). */
    public function visible(): Builder
    {
        return clone $this->visibleIds;
    }
}
