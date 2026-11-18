<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

/** Reciprocal Rank Fusion: score(id) = Σ 1 / (k + rank), rank from 1. */
final class Rrf
{
    /**
     * @param  list<list<int>>  $rankings  each list ordered best first
     * @return array<int, float> id => fused score, best first; ties keep first appearance
     */
    public static function fuse(array $rankings, int $k = 60): array
    {
        $scores = [];
        $first = [];
        $sequence = 0;

        foreach ($rankings as $ranking) {
            foreach (array_values($ranking) as $index => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $index + 1);
                $first[$id] ??= $sequence++;
            }
        }

        uksort($scores, fn ($a, $b) => [$scores[$b], $first[$a]] <=> [$scores[$a], $first[$b]]);

        return $scores;
    }
}
