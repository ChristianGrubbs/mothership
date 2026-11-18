<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * P4 (D11): the `popularity` sort key, offered only behind DAM_POPULARITY_SORT_ENABLED. The score is defined in the
 * roadmap (§3.6.10: distinct viewer-days and downloader-days over 90 days, owner and automation excluded) and is
 * computed from the P1b analytics aggregates, which do not exist yet. Until they do every asset scores 0, so the
 * order is the tie-break alone: newest first. Nothing estimates or fakes a number meanwhile, and the Library says so
 * beside the option.
 */
final class PopularityScore
{
    /** The score every asset has until P1b analytics land. */
    public const UNTIL_ANALYTICS = 0;

    public static function enabled(): bool
    {
        return (bool) config('dam.popularity_sort_enabled');
    }

    /** All scores are equal (0), so only the tie-break orders; `$direction` will apply once scores exist. */
    public static function orderBy(Builder $query, string $direction): void
    {
        $query->orderBy('dam_assets.created_at', 'desc');
    }
}
