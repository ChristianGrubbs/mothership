<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retention and the deletion contract (§3.6.7, operator decision Q1): raw events 90 days, daily counts 25 months,
 * search terms 30 days; and forgetting one admin. Deletes run in id batches so no statement holds a long lock.
 */
final class Retention
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly Rollups $rollups,
    ) {}

    /** @return array{events: int, counts: int, search_terms: int} rows deleted (or that would be, on a dry run) */
    public function prune(bool $dryRun = false): array
    {
        $rawCutoff = now()->startOfDay()->subDays((int) config('dam_events.retention.raw_days', 90));
        $countsCutoff = now()->startOfDay()->subMonthsNoOverflow((int) config('dam_events.retention.rollup_months', 25));
        $termsCutoff = now()->startOfDay()->subDays((int) config('dam_events.retention.search_terms_days', 30));

        return [
            'events'       => $this->deleteWhere(table: EventStore::TABLE, column: 'occurred_at', before: EventStore::timestamp($rawCutoff), dryRun: $dryRun),
            'counts'       => $this->deleteWhere(table: EventStore::COUNTS, column: 'day', before: $countsCutoff->toDateString(), dryRun: $dryRun),
            'search_terms' => $this->deleteWhere(table: SearchTerms::TABLE, column: 'day', before: $termsCutoff->toDateString(), dryRun: $dryRun),
        ];
    }

    /**
     * Removes one admin from analytics: their raw events and their search terms; then recomputes
     * the rollups of the days those events were on, which are all inside the raw window. Older rollups already carry
     * no actor and are left as they are (§3.6.7). Security events are a security control with their own retention
     * and are not touched here.
     *
     * @return array{events: int, search_terms: int, days_recomputed: int}
     */
    public function forgetAdmin(int $adminId, bool $dryRun = false): array
    {
        $key = (string) $adminId;
        $events = DB::table(EventStore::TABLE)->where('actor_type', AnalyticsActor::ADMIN)->where('actor_key', $key);
        $terms = DB::table(SearchTerms::TABLE)->where('actor_key', $key);
        $days = (clone $events)->orderBy('occurred_at')->pluck('occurred_at')
            ->map(fn ($at) => Carbon::parse($at)->toDateString())->unique()->values()->all();

        if ($dryRun) {
            return ['events' => (clone $events)->count(), 'search_terms' => (clone $terms)->count(), 'days_recomputed' => count($days)];
        }

        $deletedEvents = $this->deleteIds(query: $events, table: EventStore::TABLE);
        $deletedTerms = $this->deleteIds(query: $terms, table: SearchTerms::TABLE);
        // An opt-out is the person's standing choice, not data about them: it stays, so forgetting never restarts
        // collection.

        foreach ($days as $day) {
            $this->rollups->day(day: Carbon::parse($day));
        }

        return ['events' => $deletedEvents, 'search_terms' => $deletedTerms, 'days_recomputed' => count($days)];
    }

    /**
     * An asset deleted for good drops out of the raw events' subject (§3.6.7); its daily counts stay, under an id no
     * asset has any more, which every report already leaves out.
     */
    public function forgetAsset(int $assetId): int
    {
        return DB::table(EventStore::TABLE)->where('subject_type', 'asset')->where('subject_id', $assetId)
            ->update(['subject_id' => null]);
    }

    private function deleteWhere(string $table, string $column, string $before, bool $dryRun): int
    {
        $query = DB::table($table)->where($column, '<', $before);

        return $dryRun ? $query->count() : $this->deleteIds(query: $query, table: $table);
    }

    private function deleteIds($query, string $table): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::CHUNK);

        return $deleted;
    }
}
