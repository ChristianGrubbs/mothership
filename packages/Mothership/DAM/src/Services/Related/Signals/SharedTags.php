<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related\Signals;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Related\RelatedScope;
use Mothership\DAM\Services\Related\RelatedSignal;
use Mothership\DAM\Services\Related\SignalMatch;

/**
 * Shared tags, IDF-weighted: each shared tag adds ln(1 + N / df), N being the assets the viewer may list and df those
 * of them carrying the tag. Both counts run over the visible set, so a hidden asset never shifts a weight. One grouped
 * query over dam_asset_tag's (tag_id, asset_id) index returns the top candidates.
 */
final class SharedTags implements RelatedSignal
{
    private const MAX_SEED_TAGS = 50;

    public function candidates(Asset $seed, RelatedScope $scope, float $weight): array
    {
        $tagIds = DB::table('dam_asset_tag')->where('asset_id', $seed->id)->orderBy('tag_id')->limit(self::MAX_SEED_TAGS)
            ->pluck('tag_id')->map(fn ($id) => (int) $id)->all();

        if ($tagIds === []) {
            return [];
        }

        $visibleCount = DB::query()->fromSub($scope->visible(), 'visible_assets')->count();
        $frequencies = DB::table('dam_asset_tag')->whereIn('tag_id', $tagIds)->whereIn('asset_id', $scope->visible())
            ->groupBy('tag_id')->select('tag_id', DB::raw('COUNT(*) as frequency'))->pluck('frequency', 'tag_id');

        $cases = [];

        foreach ($tagIds as $tagId) {
            $idf = log(1 + $visibleCount / max(1, (int) ($frequencies[$tagId] ?? 0)));
            // Integers and a formatted float only: nothing user-supplied reaches the SQL text.
            $cases[] = sprintf('WHEN tag_id = %d THEN %.6F', $tagId, $idf * $weight);
        }

        $rows = DB::table('dam_asset_tag')
            ->whereIn('tag_id', $tagIds)
            ->whereIn('asset_id', $scope->visible())
            ->whereNotIn('asset_id', $scope->exclude)
            ->groupBy('asset_id')
            ->select('asset_id', DB::raw('SUM(CASE '.implode(' ', $cases).' ELSE 0 END) as score'), DB::raw('COUNT(*) as shared'))
            ->orderByDesc('score')
            ->orderByDesc('asset_id')
            ->limit($scope->limit)
            ->get();

        $matches = [];

        foreach ($rows as $row) {
            $matches[(int) $row->asset_id] = new SignalMatch(score: (float) $row->score, count: (int) $row->shared);
        }

        return $matches;
    }

    public function reason(SignalMatch $match): string
    {
        return trans_choice('dam::app.admin.dam.asset.related.why.tags', $match->count, ['count' => $match->count]);
    }
}
