<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily counts from the raw events (§3.6.4, §3.6.5): one `rollup` row per day and slice in dam_event_counts_daily.
 * Recomputing a day deletes that day's rollup rows and writes them again in one transaction, so a run is
 * idempotent, can be repeated for any range, and absorbs events that arrived late. Count-only (`aggregate`) rows are
 * never touched: they have no raw events to come from.
 *
 * Per slice: events (excluding suspected bots), counted (an actor's repeat on the same subject inside the event's
 * window is not counted again: a refresh, a retried download), actors (distinct actors that day), nojs (a link
 * visit whose page never reported a preview through the collector, §3.6.4) and bots. Slices carry a role and an
 * actor type, never an actor, so a rollup cannot name anyone once the raw rows are gone (§3.6.7).
 */
final class Rollups
{
    private const MAX_BINDINGS = 2000;

    /** @return int the rollup rows written for the day */
    public function day(Carbon $day): int
    {
        $from = $day->copy()->startOfDay();
        $to = $from->copy()->addDay();
        $slices = [];
        $repeats = [];
        $openedTokens = [];
        $visitTokens = [];

        $rows = DB::table(EventStore::TABLE)
            ->where('occurred_at', '>=', EventStore::timestamp($from))
            ->where('occurred_at', '<', EventStore::timestamp($to))
            ->select(['id', 'name', 'occurred_at', 'actor_type', 'actor_key', 'actor_role_id', 'surface', 'subject_type', 'subject_id', 'attachment_id', 'share_id', 'outcome', 'props', 'suspected_bot'])
            ->lazyById(1000);

        foreach ($rows as $row) {
            $props = $row->props === null ? [] : (array) json_decode((string) $row->props, true);
            $slice = [
                'name'         => (string) $row->name,
                'outcome'      => (string) $row->outcome,
                'actor_type'   => (string) $row->actor_type,
                'role_id'      => (int) $row->actor_role_id,
                'surface'      => (string) $row->surface,
                'subject_type' => (string) $row->subject_type,
                'subject_id'   => (int) $row->subject_id,
                'share_id'     => (int) $row->share_id,
                'detail'       => DamEvents::detail(props: $props),
            ];
            $key = implode('|', $slice);
            $slices[$key] ??= $slice + ['events' => 0, 'counted' => 0, 'actors' => [], 'nojs' => 0, 'bots' => 0];

            if ((bool) $row->suspected_bot) {
                $slices[$key]['bots']++;

                continue;
            }

            $slices[$key]['events']++;
            $slices[$key]['actors'][(string) $row->actor_key] = true;
            $repeats[$key][$row->actor_key.'|'.$row->attachment_id][] = Carbon::parse($row->occurred_at)->getTimestamp();

            if ($row->name === DamEvent::PreviewOpened->value && isset($props['view_token'])) {
                $openedTokens[(string) $props['view_token']] = true;
            }

            // Only a file link's page reports a preview; a folder link's page lists files and never does.
            if ($row->name === DamEvent::ShareVisited->value && isset($props['view_token']) && ($props['share_type'] ?? null) === 'asset') {
                $visitTokens[$key][] = (string) $props['view_token'];
            }
        }

        foreach ($slices as $key => $slice) {
            $window = EventRegistry::exists($slice['name']) ? EventRegistry::window($slice['name']) : null;
            $slices[$key]['counted'] = $window === null
                ? $slice['events']
                : array_sum(array_map(fn (array $times) => self::countedWithin(times: $times, windowSeconds: $window * 60), $repeats[$key] ?? []));
            $slices[$key]['nojs'] = count(array_filter($visitTokens[$key] ?? [], fn (string $token) => ! isset($openedTokens[$token])));
            $slices[$key]['actors'] = count($slice['actors']);
        }

        $insert = array_map(fn (array $slice) => ['day' => $from->toDateString()] + $slice + ['source' => EventStore::SOURCE_ROLLUP], array_values($slices));

        DB::transaction(function () use ($from, $insert): void {
            DB::table(EventStore::COUNTS)->where('day', $from->toDateString())->where('source', EventStore::SOURCE_ROLLUP)->delete();

            foreach ($insert === [] ? [] : array_chunk($insert, max(1, intdiv(self::MAX_BINDINGS, count($insert[0])))) as $batch) {
                DB::table(EventStore::COUNTS)->insert($batch);
            }
        });

        return count($insert);
    }

    /**
     * Recomputes every day from $from to $to inclusive.
     *
     * @return array<string, int> rows written per day
     */
    public function range(Carbon $from, Carbon $to): array
    {
        $written = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $written[$day->toDateString()] = $this->day(day: $day);
        }

        return $written;
    }

    /**
     * How many of one actor's events on one subject count: an event counts unless the same actor's previous event
     * there is within the window, the rule DamEvents applies when the event is served (EventStore::isRepeat).
     *
     * @param  list<int>  $times
     */
    public static function countedWithin(array $times, int $windowSeconds): int
    {
        sort($times);
        $counted = 0;
        $previous = null;

        foreach ($times as $time) {
            if ($previous === null || $time - $previous > $windowSeconds) {
                $counted++;
            }

            $previous = $time;
        }

        return $counted;
    }
}
