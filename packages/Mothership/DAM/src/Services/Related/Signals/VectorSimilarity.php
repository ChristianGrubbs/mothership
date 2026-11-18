<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related\Signals;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Related\RelatedScope;
use Mothership\DAM\Services\Related\RelatedSignal;
use Mothership\DAM\Services\Related\RescoresCandidates;
use Mothership\DAM\Services\Related\SignalMatch;

/**
 * How alike the AI summary vectors are (the "Find similar" measure), only while smart search is on. New candidates
 * come from the newest `vector_scan_limit` visible summary vectors, never the whole table; the pool other signals
 * found is rescored too. Below `vector_min_cosine` a file does not count as looking alike.
 */
final class VectorSimilarity implements RelatedSignal, RescoresCandidates
{
    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array
    {
        $seedVector = $this->seedVector($seed);

        if ($seedVector === null) {
            return [];
        }

        $rows = DB::table('dam_ai_vectors')
            ->where('kind', 'summary')
            ->whereIn('asset_id', $scope->visible())
            ->whereNotIn('asset_id', $scope->exclude)
            ->orderByDesc('id')
            ->limit(max(1, (int) config('dam-related.vector_scan_limit', 2000)))
            ->get(['asset_id', 'vector']);

        $matches = $this->score(seedVector: $seedVector, rows: $rows, weight: $weight);
        uasort($matches, fn (SignalMatch $a, SignalMatch $b) => $b->score <=> $a->score);

        return array_slice($matches, 0, $scope->limit, preserve_keys: true);
    }

    public function rescore(Asset $seed, array $ids, RelatedScope $scope, float $weight): array
    {
        $seedVector = $ids === [] ? null : $this->seedVector($seed);

        if ($seedVector === null) {
            return [];
        }

        $rows = DB::table('dam_ai_vectors')->where('kind', 'summary')->whereIn('asset_id', $ids)
            ->whereIn('asset_id', $scope->visible())->get(['asset_id', 'vector']);

        return $this->score(seedVector: $seedVector, rows: $rows, weight: $weight);
    }

    public function reason(SignalMatch $match): string
    {
        return trans('dam::app.admin.dam.asset.related.why.vector');
    }

    /** @return list<float>|null */
    private function seedVector(Asset $seed): ?array
    {
        if (! config('ai-index.search_enabled')) {
            return null;
        }

        $encoded = DB::table('dam_ai_vectors')->where('asset_id', $seed->id)->where('kind', 'summary')->value('vector');

        try {
            return is_string($encoded) ? VectorCodec::decode($encoded) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  list<float>  $seedVector
     * @param  iterable<object>  $rows
     * @return array<int, SignalMatch>
     */
    private function score(array $seedVector, iterable $rows, float $weight): array
    {
        $minimum = (float) config('dam-related.vector_min_cosine', 0.75);
        $matches = [];

        foreach ($rows as $row) {
            try {
                $cosine = VectorCodec::dot($seedVector, VectorCodec::decode((string) $row->vector));
            } catch (InvalidArgumentException) {
                // One corrupt stored vector must not fail the ranking; that row is simply not a candidate.
                continue;
            }

            if ($cosine >= $minimum) {
                $matches[(int) $row->asset_id] = new SignalMatch(score: $weight * $cosine);
            }
        }

        return $matches;
    }
}
