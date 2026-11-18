<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\User\Models\Admin;

/**
 * The minimized search-term store (§3.6.7). Text is kept only here, normalized, for signed-in admins, 30 days, and
 * a query that looks like it names a person, a secret or a file the searcher cannot see is discarded whole: only
 * its result count reaches the event. A term is shown in a report only once enough distinct admins used it inside
 * that report's own slice, so narrowing a filter cannot isolate one person.
 */
final class SearchTerms
{
    public const TABLE = 'dam_search_terms';

    private const DISCARD = [
        // An email address.
        '/[^\s@]+@[^\s@]+\.[^\s@]+/u',
        // A URL or a bare domain.
        '/(?:[a-z][a-z0-9+.-]*:\/\/|\bwww\.)|\b[a-z0-9-]+\.(?:com|org|net|edu|gov|io|co|uk|au|nz|us|ca)\b/iu',
        // A phone number: eight or more digits with the usual separators.
        '/\+?\d(?:[\s().-]*\d){7,}/u',
        // An id: a run of six or more digits.
        '/\d{6,}/u',
        // A token: a 16+ character run that mixes letters and digits, or any 24+ character run.
        '/\b(?=[a-z0-9]*\d)(?=[a-z0-9]*[a-z])[a-z0-9]{16,}\b|\b[a-z0-9]{24,}\b/iu',
    ];

    public static function normalize(string $query): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($query))), 0, 100);
    }

    /** Whether the text alone rules a query out; the hidden-file-name rule needs the searcher (keep()). */
    public static function looksSensitive(string $term): bool
    {
        foreach (self::DISCARD as $pattern) {
            if (preg_match($pattern, $term) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores the terms an admin searched, dropping any that match a pattern above or the name of a file this admin
     * cannot see (with or without its extension). One query for the whole batch.
     *
     * @param  list<array{term: string, result_count: int, at: Carbon}>  $searches
     */
    public function keep(Admin $admin, array $searches): int
    {
        $searches = array_values(array_filter(
            array_map(fn (array $search) => ['term' => self::normalize($search['term'])] + $search, $searches),
            fn (array $search) => $search['term'] !== '' && ! self::looksSensitive($search['term']),
        ));

        if ($searches === []) {
            return 0;
        }

        $hidden = $this->hiddenFileNames(admin: $admin, terms: array_values(array_unique(array_column($searches, 'term'))));
        $rows = [];

        foreach ($searches as $search) {
            if (isset($hidden[$search['term']])) {
                continue;
            }

            $rows[] = [
                'term_norm'    => $search['term'],
                'actor_key'    => (string) $admin->id,
                'role_id'      => $admin->role_id === null ? null : (int) $admin->role_id,
                'day'          => $search['at']->toDateString(),
                'result_count' => $search['result_count'],
                'created_at'   => now(),
            ];
        }

        if ($rows !== []) {
            DB::transaction(fn () => DB::table(self::TABLE)->insert($rows));
        }

        return count($rows);
    }

    /**
     * Terms in the slice, each with its search count and whether any search returned nothing. A term used by fewer
     * than k distinct admins inside this very slice comes back as null text: "(suppressed)". A term naming a file
     * the report's viewer cannot see is left out altogether, as the hidden file itself would be (§3.6.8).
     *
     * @return list<array{term: ?string, searches: int, zero_results: int}>
     */
    public function report(Admin $viewer, Carbon $from, Carbon $to, ?int $roleId = null, bool $zeroResultsOnly = false, int $limit = 20): array
    {
        $k = (int) config('dam_events.k', 3);
        $terms = [];

        $rows = DB::table(self::TABLE)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->when($roleId !== null, fn ($query) => $query->where('role_id', $roleId))
            ->when($zeroResultsOnly, fn ($query) => $query->where('result_count', 0))
            ->orderBy('id')
            ->get(['term_norm', 'actor_key', 'result_count']);

        foreach ($rows as $row) {
            $entry = $terms[$row->term_norm] ?? ['actors' => [], 'searches' => 0, 'zero_results' => 0];
            $entry['actors'][$row->actor_key] = true;
            $entry['searches']++;
            $entry['zero_results'] += (int) $row->result_count === 0 ? 1 : 0;
            $terms[$row->term_norm] = $entry;
        }

        $shown = [];
        $suppressed = ['term' => null, 'searches' => 0, 'zero_results' => 0];
        $hidden = $terms === [] ? [] : $this->hiddenFileNames(admin: $viewer, terms: array_map('strval', array_keys($terms)));

        foreach ($terms as $term => $entry) {
            if (isset($hidden[(string) $term])) {
                continue;
            }

            if (count($entry['actors']) >= $k) {
                $shown[] = ['term' => (string) $term, 'searches' => $entry['searches'], 'zero_results' => $entry['zero_results']];
            } else {
                $suppressed['searches'] += $entry['searches'];
                $suppressed['zero_results'] += $entry['zero_results'];
            }
        }

        usort($shown, fn (array $a, array $b) => [$b['searches'], $a['term']] <=> [$a['searches'], $b['term']]);
        $shown = array_slice($shown, 0, $limit);

        return $suppressed['searches'] > 0 ? [...$shown, $suppressed] : $shown;
    }

    /** @param list<string> $terms @return array<string, true> */
    private function hiddenFileNames(Admin $admin, array $terms): array
    {
        $visible = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($visible, AccessContext::forAdmin(admin: $admin, purpose: AccessContext::PURPOSE_LIST));

        // A term equal to a hidden file's name, or to its name before the extension. A wildcard the term itself
        // carries only widens the match, which discards more, never less. Chunked: two bindings per term.
        $names = [];

        foreach (array_chunk($terms, 400) as $chunk) {
            array_push($names, ...DB::table('dam_assets')
                ->whereNotIn('id', $visible)
                ->where(function ($query) use ($chunk): void {
                    $query->whereIn(DB::raw('LOWER(file_name)'), $chunk);

                    foreach ($chunk as $term) {
                        $query->orWhere(DB::raw('LOWER(file_name)'), 'like', $term.'.%');
                    }
                })
                ->pluck('file_name')
                ->all());
        }

        $hidden = [];

        foreach ($names as $name) {
            $lower = mb_strtolower((string) $name);
            $hidden[$lower] = true;
            $hidden[pathinfo($lower, PATHINFO_FILENAME)] = true;
        }

        return $hidden;
    }
}
