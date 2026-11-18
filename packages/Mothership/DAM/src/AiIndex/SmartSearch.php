<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Mothership\DAM\Services\ExplorerSearchPredicate;
use Mothership\DAM\Support\SearchTerms;
use Mothership\User\Models\Admin;
use Throwable;

/**
 * Ranks the viewer's own Library candidates by words and meaning (spec §3). The candidate query already
 * carries every access rule and filter; this class only orders and narrows it, so it can never widen access.
 */
class SmartSearch
{
    private const EMBED_CACHE_SECONDS = 3600;

    public function __construct(private AiBridgeClient $bridge) {}

    /** @return list<array{id: int, match: array{ai: bool, page: ?int, snippet: ?string}}> */
    public function rank(?Admin $viewer, Builder $candidates, string $query): array
    {
        $this->requireViewer($viewer);
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $members = $this->stackMembers($candidates);
        [$lexicalIds, $wordHits] = $this->lexical(candidates: $candidates, query: $query, members: $members);
        $semantic = $this->semantic(candidates: $candidates, query: $query, members: $members);
        $fused = Rrf::fuse([$lexicalIds, array_keys($semantic)], k: (int) config('ai-index.rrf_k'));
        // Exact names are a navigation intent. Resolve only within the already authorised cards and their slots.
        $exact = $this->exactNames(candidates: $candidates, query: $query, members: $members);
        $ordered = array_values(array_unique([...$exact, ...array_keys($fused)]));
        $results = [];

        foreach ($ordered as $id) {
            $best = $semantic[$id] ?? null;
            $results[] = ['id' => (int) $id, 'match' => [
                'ai'      => ! isset($wordHits[$id]) && ! in_array($id, $exact, true),
                'page'    => $best['page'] ?? null,
                'snippet' => isset($best['page']) ? $this->snippet((string) $best['text']) : null,
            ]];
        }

        return $results;
    }

    /**
     * The caller must have authorised the seed for the viewer through AssetAccessDecision::for() (folder or list,
     * and availability), as ExplorerDataController does; this only ranks the viewer's candidates against it.
     *
     * @return list<array{id: int, match: array{ai: bool, page: ?int, snippet: ?string}}>
     */
    public function similar(?Admin $viewer, Builder $candidates, int $seedId): array
    {
        $this->requireViewer($viewer);
        $seed = DB::table('dam_ai_vectors')->where('asset_id', $seedId)->where('kind', 'summary')->value('vector');

        if (! is_string($seed)) {
            return [];
        }

        try {
            $seedVector = VectorCodec::decode($seed);
        } catch (InvalidArgumentException) {
            return [];
        }

        $scores = [];

        $this->vectorsFor($candidates, kind: 'summary')->each(function ($row) use ($seedId, $seedVector, &$scores) {
            if ((int) $row->asset_id === $seedId) {
                return;
            }

            try {
                $scores[(int) $row->asset_id] = VectorCodec::dot($seedVector, VectorCodec::decode($row->vector));
            } catch (InvalidArgumentException) {
                // One corrupt stored vector must not fail the search; the row is simply not a candidate.
            }
        });

        arsort($scores);

        return array_map(
            fn (int $id) => ['id' => $id, 'match' => ['ai' => true, 'page' => null, 'snippet' => null]],
            array_slice(array_keys($scores), 0, (int) config('ai-index.similar_limit'))
        );
    }

    private function requireViewer(?Admin $viewer): void
    {
        if (! $viewer) {
            // AccessListReader::roleBypass(null) is true: without a viewer the candidate query would be unfiltered.
            throw new LogicException('Smart search needs an authenticated viewer.');
        }
    }

    /** @param array<int, int> $members member id => authorised holder id */
    private function exactNames(Builder $candidates, string $query, array $members): array
    {
        // SQLite LOWER is ASCII-only; use the same Unicode comparison regardless of database locale.
        $normalized = mb_strtolower($query, 'UTF-8');
        $exact = [];
        $cards = (clone $candidates)->reorder()->select(['dam_assets.id', 'dam_assets.file_name'])->orderBy('dam_assets.id')->lazy(500);

        foreach ($cards as $card) {
            if (mb_strtolower((string) $card->file_name, 'UTF-8') === $normalized) {
                $exact[(int) $card->id] = true;
            }
        }

        if ($members !== []) {
            $slots = $this->memberRows(candidates: $candidates)->orderBy('id')->select(['id', 'file_name', 'merged_into_id'])->lazy(500);

            foreach ($slots as $slot) {
                if (mb_strtolower((string) $slot->file_name, 'UTF-8') === $normalized) {
                    $exact[(int) $slot->merged_into_id] = true;
                }
            }
        }

        return array_keys($exact);
    }

    private function memberRows(Builder $candidates): \Illuminate\Database\Query\Builder
    {
        return DB::table('dam_assets')
            ->whereIn('merged_into_id', (clone $candidates)->toBase()->select('dam_assets.id'))
            ->whereNull('deleted_at');
    }

    /**
     * Files merged into a candidate (a stack's slots), keyed to the card that lists them. A merge source is never
     * listed itself (AssetAccessDecision::scopeNotMerged) but keeps its own description, so without this a stack
     * is found only by its cover's words and meaning. The holder is the candidate, so this cannot widen access.
     *
     * @return array<int, int> member id => holder id
     */
    private function stackMembers(Builder $candidates): array
    {
        return $this->memberRows(candidates: $candidates)
            ->pluck('merged_into_id', 'id')
            ->mapWithKeys(fn ($holder, $member) => [(int) $member => (int) $holder])
            ->all();
    }

    /**
     * @param  array<int, int>  $members  member id => holder id (stackMembers)
     * @return array{0: list<int>, 1: array<int, true>} ordered ids, and ids today's word match also finds
     */
    private function lexical(Builder $candidates, string $query, array $members = []): array
    {
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $wordQuery = clone $candidates;
        ExplorerSearchPredicate::apply(query: $wordQuery, search: $query, likeOperator: $likeOperator);
        $wordHits = array_fill_keys(array_map('intval', $wordQuery->pluck('dam_assets.id')->all()), true);
        $scores = array_map(fn () => 1.0, $wordHits);
        $searched = fn ($q) => $q->where(fn ($ids) => $ids->whereIn('asset_id', (clone $candidates)->toBase()->select('dam_assets.id'))
            ->when($members !== [], fn ($ids) => $ids->orWhereIn('asset_id', $this->memberRows(candidates: $candidates)->select('id'))));

        if (config('ai-index.lexical') !== 'like' && DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::table('dam_ai_index')->tap($searched)
                ->whereRaw("(search_tsv @@ websearch_to_tsquery('english', unaccent(?)) OR word_similarity(unaccent(?), lexical_text) >= ?)", [$query, $query, (float) config('ai-index.trigram_threshold')])
                ->selectRaw("asset_id, ts_rank_cd(search_tsv, websearch_to_tsquery('english', unaccent(?))) + word_similarity(unaccent(?), lexical_text) AS score", [$query, $query])
                ->get();
        } else {
            $likeRows = DB::table('dam_ai_index')->tap($searched);
            SearchTerms::apply($likeRows, $query, fn ($w, string $pattern) => $w->whereRaw(SearchTerms::likeSql('lexical_text'), [$pattern]));
            $rows = $likeRows->selectRaw('asset_id, 0.5 AS score')->get();
        }

        // A card's own words add to its file-name hit; a stack slot's words count once, as the card's best slot.
        $slotScores = [];

        foreach ($rows as $row) {
            $id = (int) $row->asset_id;

            if (isset($members[$id])) {
                $slotScores[$members[$id]] = max($slotScores[$members[$id]] ?? 0.0, (float) $row->score);
            } else {
                $scores[$id] = ($scores[$id] ?? 0.0) + (float) $row->score;
            }
        }

        foreach ($slotScores as $holder => $score) {
            $scores[$holder] = max($scores[$holder] ?? 0.0, $score);
        }

        uksort($scores, fn ($a, $b) => [$scores[$b], $b] <=> [$scores[$a], $a]);

        return [array_keys($scores), $wordHits];
    }

    /**
     * @param  array<int, int>  $members  member id => holder id (stackMembers)
     * @return array<int, array{score: float, page: ?int, text: ?string}> best first, at or above min_cosine
     */
    private function semantic(Builder $candidates, string $query, array $members = []): array
    {
        if (! $this->bridge->ready()) {
            return [];
        }

        try {
            [$queryVector, $fromCache] = $this->queryVector(query: $query);
        } catch (AiBridgeUnavailable) {
            return [];
        }

        $minimum = (float) config('ai-index.min_cosine');
        $best = [];
        $unavailable = false;

        $this->vectorsFor($candidates, kind: null, members: $members)->each(function ($row) use (&$queryVector, &$fromCache, &$unavailable, $query, $minimum, $members, &$best) {
            try {
                $stored = VectorCodec::decode($row->vector);

                // A cached query vector from another model or dimension scores 0 against everything. Check the
                // first decodable stored vector once; on a mismatch re-embed through the bridge (never re-checked).
                if ($fromCache) {
                    $fromCache = false;

                    if (count($stored) !== count($queryVector)) {
                        try {
                            [$queryVector] = $this->queryVector(query: $query, refresh: true);
                        } catch (AiBridgeUnavailable) {
                            // The same answer as an unavailable bridge before the scan: no semantic matches.
                            $unavailable = true;

                            return false;
                        }
                    }
                }

                $score = VectorCodec::dot($queryVector, $stored);
            } catch (InvalidArgumentException) {
                return;
            }

            // A stack slot's match is the card's: the card is what the viewer can open.
            $id = $members[(int) $row->asset_id] ?? (int) $row->asset_id;

            if ($score >= $minimum && $score > ($best[$id]['score'] ?? -1.0)) {
                $best[$id] = ['score' => $score, 'page' => $row->kind === 'pdf_page' ? (int) $row->page : null, 'text' => $row->text];
            }
        });

        if ($unavailable) {
            return [];
        }

        uasort($best, fn ($a, $b) => $b['score'] <=> $a['score']);

        // An absolute floor alone lets most of a themed library through (median query scores ran 0.32-0.48
        // on the Studio corpus, 2026-10-01), so meaning matches must also sit near the best one.
        $top = $best === [] ? 0.0 : reset($best)['score'];
        $floor = max($minimum, $top - (float) config('ai-index.semantic_window'));

        return array_slice(
            array_filter($best, fn (array $hit) => $hit['score'] >= $floor),
            0,
            (int) config('ai-index.semantic_limit'),
            preserve_keys: true
        );
    }

    /**
     * The normalized query embedding, and whether it came from the cache. It depends only on the model and the
     * text, so a page, sort or filter change with the same term reuses it; it carries no access decision (that
     * stays in the candidates). The key holds a hash, never the raw query. A cached value is trusted only when it
     * is a non-empty list of finite numbers with a direction; anything else is forgotten and re-embedded. The TTL
     * is short so a reindex or model change leaves a stale vector for at most an hour, and semantic() also checks
     * the dimension against the stored vectors. An unavailable bridge is not cached, and a cache store failure
     * just calls the bridge. $refresh skips the cache read and replaces the entry.
     *
     * @return array{0: list<float>, 1: bool} vector, fromCache
     *
     * @throws AiBridgeUnavailable
     */
    private function queryVector(string $query, bool $refresh = false): array
    {
        $key = 'ai-index:embed:'.hash('sha256', implode("\0", [$this->bridge->embedModel(), 'query', $query]));

        try {
            $cached = $refresh ? null : Cache::get($key);

            if ($cached !== null) {
                $valid = self::validCachedVector(value: $cached);

                if ($valid !== null) {
                    return [$valid, true];
                }
            }

            Cache::forget($key);
        } catch (Throwable) {
            // Fall through to the bridge.
        }

        $vector = $this->bridge->embed(texts: [$query], kind: 'query', timeout: (float) config('ai-index.query_embed_timeout'))[0];

        try {
            Cache::put($key, $vector, self::EMBED_CACHE_SECONDS);
        } catch (Throwable) {
            // An unavailable store only costs one more embed call.
        }

        return [$vector, false];
    }

    /** @return list<float>|null the normalized vector, or null when the cached value is not a usable embedding */
    private static function validCachedVector(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $component) {
            if ((! is_int($component) && ! is_float($component)) || ! is_finite((float) $component)) {
                return null;
            }
        }

        try {
            return VectorCodec::normalize(vector: $value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param  array<int, int>  $members  member id => holder id (stackMembers) */
    private function vectorsFor(Builder $candidates, ?string $kind, array $members = [])
    {
        return DB::table('dam_ai_vectors')
            ->where(fn ($ids) => $ids->whereIn('asset_id', (clone $candidates)->toBase()->select('dam_assets.id'))
                ->when($members !== [], fn ($ids) => $ids->orWhereIn('asset_id', $this->memberRows(candidates: $candidates)->select('id'))))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->orderBy('id')
            ->select(['asset_id', 'kind', 'page', 'vector', 'text'])
            ->lazy(500);
    }

    private function snippet(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 180 ? mb_substr($text, 0, 180).'…' : $text;
    }
}
