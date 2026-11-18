<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Related;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Support\RelatedLog;

/**
 * Related assets v1 (P4 D14): a live ranking from content signals (Config/related.php), computed per request and
 * never written as relationship edges. Uncached: every signal is one bounded, indexed query, and a cache would have
 * to re-check access on read anyway.
 *
 * Access comes first. The caller has already answered a seed the viewer cannot open with the 404 a missing id gets;
 * here every signal looks only among the assets the viewer's listing filter allows (AssetAccessDecision::
 * scopeListable(), without unavailable files), so a hidden asset is neither shown, counted nor used as a seed. The
 * seed, its stack members (the capture group) and its explicit relationships are excluded, so the list stays
 * distinct from the Files and Relationships tabs.
 */
final class RelatedAssets
{
    public function __construct(private readonly AssetAccessDecision $access) {}

    /**
     * @return list<array{id: int, file_name: string, title: ?string, display_name: string, folder: ?string, thumbnail_url: string, url: string, score: float, why: string, reasons: list<array{signal: string, count: int}>}>
     */
    public function for(Asset $seed, string $via, ?int $limit = null): array
    {
        $started = microtime(true);
        $limit = max(1, min($limit ?? (int) config('dam-related.limit', 8), (int) config('dam-related.max_limit', 24)));
        $context = ($via === AccessContext::VIA_API ? AccessContext::api(AccessContext::PURPOSE_LIST) : AccessContext::library(AccessContext::PURPOSE_LIST))
            ->withoutUnavailable();

        $visible = Asset::query()->select('dam_assets.id');
        $this->access->scopeListable($visible, $context);
        $scope = new RelatedScope(
            visibleIds: $visible->toBase(),
            exclude: $this->excluded($seed),
            limit: max(1, (int) config('dam-related.per_signal_limit', 50)),
        );

        $signals = $this->signals();
        $scores = [];
        $matches = [];
        $counts = [];

        foreach ($signals as $key => [$signal, $weight]) {
            $found = $signal->candidates($seed, $scope, $weight);
            $counts[$key] = count($found);

            foreach ($found as $id => $match) {
                $scores[$id] = ($scores[$id] ?? 0.0) + $match->score;
                $matches[$id][$key] = $match;
            }
        }

        foreach ($signals as $key => [$signal, $weight]) {
            if (! $signal instanceof RescoresCandidates) {
                continue;
            }

            $unscored = array_values(array_filter(array_keys($scores), fn (int $id) => ! isset($matches[$id][$key])));

            foreach ($signal->rescore($seed, $unscored, $scope, $weight) as $id => $match) {
                $scores[$id] += $match->score;
                $matches[$id][$key] = $match;
            }
        }

        uksort($scores, fn (int $a, int $b) => [$scores[$b], $b] <=> [$scores[$a], $a]);
        // A few spare candidates, so dropping look-alike cards (present()) still leaves a full panel.
        $ranked = array_slice(array_keys($scores), 0, $limit * 3);
        $items = array_slice($this->present(ids: $ranked, scope: $scope, scores: $scores, matches: $matches, signals: $signals), 0, $limit);

        RelatedLog::write(event: 'related.ranked', ctx: [
            'seed_id'    => (int) $seed->id,
            'via'        => $via,
            'excluded'   => count($scope->exclude),
            'pool'       => count($scores),
            'returned'   => count($items),
            'candidates' => array_values($counts),
            'ms'         => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $items;
    }

    /**
     * The seed, the other entries of its capture group (its stack members) and every asset it has an explicit
     * relationship with, in either direction.
     *
     * @return list<int>
     */
    private function excluded(Asset $seed): array
    {
        $ids = [(int) $seed->id];

        if ($seed->capture_group_id !== null) {
            $ids = array_merge($ids, DB::table('dam_assets')->where('capture_group_id', $seed->capture_group_id)->pluck('id')->all());
        }

        $ids = array_merge(
            $ids,
            DB::table('dam_asset_relationships')->where('from_asset_id', $seed->id)->pluck('to_asset_id')->all(),
            DB::table('dam_asset_relationships')->where('to_asset_id', $seed->id)->pluck('from_asset_id')->all(),
        );

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return array<string, array{0: RelatedSignal, 1: float}> */
    private function signals(): array
    {
        $signals = [];

        foreach ((array) config('dam-related.signals', []) as $key => $definition) {
            $signal = app($definition['class']);

            if ($signal instanceof RelatedSignal && (float) ($definition['weight'] ?? 0) > 0) {
                $signals[(string) $key] = [$signal, (float) $definition['weight']];
            }
        }

        return $signals;
    }

    /**
     * Cards for the ranked ids, re-read through the visible set once more, in rank order. The "why" names the
     * strongest reasons first, at most three.
     *
     * @param  list<int>  $ids
     * @param  array<int, float>  $scores
     * @param  array<int, array<string, SignalMatch>>  $matches
     * @param  array<string, array{0: RelatedSignal, 1: float}>  $signals
     * @return list<array<string, mixed>>
     */
    private function present(array $ids, RelatedScope $scope, array $scores, array $matches, array $signals): array
    {
        if ($ids === []) {
            return [];
        }

        $assets = Asset::query()->withDisplayName()->whereIn('dam_assets.id', $ids)->whereIn('dam_assets.id', $scope->visible())
            ->get()->keyBy('id');
        $folders = DB::table('dam_asset_directory')->join('dam_directories', 'dam_directories.id', '=', 'dam_asset_directory.directory_id')
            ->whereIn('dam_asset_directory.asset_id', $ids)->orderBy('dam_asset_directory.id')
            ->get(['dam_asset_directory.asset_id', 'dam_directories.name', 'dam_directories.id as directory_id'])->unique('asset_id')->keyBy('asset_id');
        $hashes = DB::table('dam_asset_phashes')->whereIn('asset_id', $ids)->whereNotNull('sha256')->pluck('sha256', 'asset_id');
        $items = [];
        $seen = [];

        foreach ($ids as $id) {
            $asset = $assets->get($id);

            if ($asset === null) {
                continue;
            }

            // The same bytes (checksum) in the same folder (by id, not label) are the same file twice: one card says it.
            // Without a stored checksum nothing proves two files identical, so they are never collapsed.
            if (isset($hashes[$id], $folders[$id])) {
                $twin = $hashes[$id].'|'.$folders[$id]->directory_id;

                if (isset($seen[$twin])) {
                    continue;
                }

                $seen[$twin] = true;
            }

            $reasons = $matches[$id];
            uasort($reasons, fn (SignalMatch $a, SignalMatch $b) => $b->score <=> $a->score);
            $why = [];

            foreach (array_slice($reasons, 0, 3, preserve_keys: true) as $key => $match) {
                $why[] = $signals[$key][0]->reason($match);
            }

            $items[] = [
                'id'            => (int) $asset->id,
                'file_name'     => (string) $asset->file_name,
                'title'         => $asset->title,
                'display_name'  => (string) $asset->display_name,
                'folder'        => isset($folders[$id]) ? (string) $folders[$id]->name : null,
                'thumbnail_url' => route('admin.dam.file.thumbnail', ['path' => urlencode((string) $asset->path)]),
                'url'           => route('admin.dam.assets.edit', $asset->id),
                'score'         => round($scores[$id], 3),
                'why'           => implode(' · ', $why),
                'reasons'       => array_values(array_map(
                    fn (string $key) => ['signal' => $key, 'count' => $reasons[$key]->count],
                    array_keys($reasons)
                )),
            ];
        }

        return $items;
    }
}
