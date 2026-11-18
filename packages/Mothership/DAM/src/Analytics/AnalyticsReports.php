<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\User\Models\Admin;

/**
 * The P1b dashboards (§3.6.9): Asset engagement (T2), Workflow friction v1 (T6), the Feature adoption baseline (T4)
 * and the session timeline (T3). Every query goes through ReportScope first, so a subject the viewer cannot see now
 * is never counted, summed or named (§3.6.8). Totals are of "assets you can see".
 *
 * Engagement reads the daily counts; friction, adoption and journeys read the raw events, which cover the longest
 * range offered (90 days). Raw rows are folded in PHP, as the security reports do: the volume is small (about ten
 * staff) and a day expression differs on each supported database.
 */
final class AnalyticsReports
{
    public const RANGES = [7, 30, 90];

    public const DEFAULT_RANGE = 30;

    private const TOP = 20;

    private const SESSIONS = 50;

    /** Feature => how to recognise one use of it in a raw event. */
    public const FEATURES = ['search', 'smart_search', 'find_similar', 'filters', 'preview', 'download', 'upload', 'share', 'optimize', 'convert'];

    public static function range(mixed $days): int
    {
        return in_array((int) $days, self::RANGES, true) ? (int) $days : self::DEFAULT_RANGE;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function window(int $days): array
    {
        return [now()->startOfDay()->subDays($days - 1), now()->endOfDay()];
    }

    /**
     * T2 at asset-entry level, plus links: views (counted client previews), viewer-days, downloads and link visits
     * per asset, the busiest links, the daily trend, and how many assets the viewer can see had no view at all.
     *
     * @return array<string, mixed>
     */
    public function engagement(Admin $viewer, int $days): array
    {
        [$from, $to] = self::window($days);
        $scope = new ReportScope(viewer: $viewer);
        $names = [DamEvent::PreviewOpened->value, DamEvent::PreviewServed->value, DamEvent::DownloadServed->value, DamEvent::ShareVisited->value];

        $counts = fn (string $source) => $scope->apply(
            DB::table(EventStore::COUNTS)->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->where('source', $source)->whereIn('name', $names),
            table: EventStore::COUNTS,
            none: '',
        );

        $perAsset = [];
        $empty = ['views' => 0, 'viewer_days' => 0, 'downloads' => 0, 'previews' => 0, 'visits' => 0];
        $totals = $empty;
        $rows = $counts(EventStore::SOURCE_ROLLUP)
            ->groupBy('subject_type', 'subject_id', 'name')
            ->select(['subject_type', 'subject_id', 'name'])
            ->selectRaw('SUM(counted) AS counted, SUM(actors) AS actors')
            ->get();

        // Totals take every visible row: a folder link's visits and zips, and a zip of a selection, have no file.
        foreach ($rows as $row) {
            $add = match ($row->name) {
                DamEvent::PreviewOpened->value  => ['views' => (int) $row->counted, 'viewer_days' => (int) $row->actors],
                DamEvent::DownloadServed->value => ['downloads' => (int) $row->counted],
                DamEvent::PreviewServed->value  => ['previews' => (int) $row->counted],
                DamEvent::ShareVisited->value   => ['visits' => (int) $row->counted],
                default                         => [],
            };

            foreach ($add as $measure => $value) {
                $totals[$measure] += $value;

                if ($row->subject_type === 'asset') {
                    $perAsset[(int) $row->subject_id] ??= $empty;
                    $perAsset[(int) $row->subject_id][$measure] += $value;
                }
            }
        }

        uasort($perAsset, fn (array $a, array $b) => [$b['views'], $b['downloads'], $b['visits']] <=> [$a['views'], $a['downloads'], $a['visits']]);
        $top = array_slice($perAsset, 0, self::TOP, true);
        $labels = Asset::query()->whereIn('dam_assets.id', array_keys($top) ?: [0])->select(['dam_assets.id', 'dam_assets.file_name'])->withDisplayName()->get()
            ->mapWithKeys(fn (Asset $asset) => [(int) $asset->id => (string) $asset->display_name])->all();

        $visibleAssets = DB::query()->fromSub($scope->visibleAssets(), 'visible')->count();
        $viewed = count(array_filter($perAsset, fn (array $entry) => $entry['views'] > 0));

        return [
            'totals'       => $totals,
            'unrefined'    => (int) $counts(EventStore::SOURCE_AGGREGATE)->sum('events'),
            'assets'       => array_map(fn (int $id, array $entry) => ['id' => $id, 'label' => $labels[$id] ?? null] + $entry, array_keys($top), $top),
            'links'        => $this->links(scope: $scope, from: $from, to: $to),
            'daily'        => $this->daily(counts: $counts(EventStore::SOURCE_ROLLUP), from: $from, to: $to),
            'visible'      => $visibleAssets,
            'never_viewed' => max(0, $visibleAssets - $viewed),
        ];
    }

    /**
     * Workflow friction v1 (§3.6.4 "workflow completion"): the upload and share funnels and search outcomes.
     * Searches are settled searches (search.submitted); search.served, one per page fetched while typing, is shown
     * only as requests served.
     * Completion is distinct operations with a success over distinct operations with an attempt; a retry never adds
     * an operation.
     *
     * @return array<string, mixed>
     */
    public function friction(Admin $viewer, int $days): array
    {
        [$from, $to] = self::window($days);
        $upload = ['dialogs' => 0, 'abandoned' => 0, 'attempts' => 0, 'attempted' => [], 'succeeded' => [], 'failed' => [], 'codes' => []];
        $share = ['dialogs' => 0, 'abandoned' => 0, 'created' => 0, 'revoked' => 0, 'visited' => []];
        $search = ['submitted' => 0, 'zero' => 0, 'opened' => 0, 'served' => 0];

        foreach ($this->raw(viewer: $viewer, from: $from, to: $to, names: [
            DamEvent::UploadDialogOpened, DamEvent::UploadDialogAbandoned, DamEvent::UploadAttempted, DamEvent::UploadSucceeded, DamEvent::UploadFailed,
            DamEvent::ShareDialogOpened, DamEvent::ShareDialogAbandoned, DamEvent::ShareCreated, DamEvent::ShareRevoked, DamEvent::ShareVisited,
            DamEvent::SearchServed, DamEvent::SearchSubmitted, DamEvent::SearchResultOpened,
        ]) as $row) {
            $props = $row->props === null ? [] : (array) json_decode((string) $row->props, true);
            $uploadId = (string) ($props['upload_id'] ?? '');

            match ($row->name) {
                DamEvent::UploadDialogOpened->value    => $upload['dialogs']++,
                DamEvent::UploadDialogAbandoned->value => $upload['abandoned']++,
                DamEvent::UploadAttempted->value       => [$upload['attempts']++, $upload['attempted'][$uploadId] = true],
                DamEvent::UploadSucceeded->value       => $upload['succeeded'][$uploadId] = true,
                DamEvent::UploadFailed->value          => [$upload['failed'][$uploadId] = true, $upload['codes'][(string) ($props['error_code'] ?? 'unknown')] = ($upload['codes'][(string) ($props['error_code'] ?? 'unknown')] ?? 0) + 1],
                DamEvent::ShareDialogOpened->value     => $share['dialogs']++,
                DamEvent::ShareDialogAbandoned->value  => $share['abandoned']++,
                DamEvent::ShareCreated->value          => $share['created']++,
                DamEvent::ShareRevoked->value          => $share['revoked']++,
                DamEvent::ShareVisited->value          => $share['visited'][(int) $row->share_id] = true,
                DamEvent::SearchServed->value          => $search['served']++,
                DamEvent::SearchSubmitted->value       => [$search['submitted']++, $search['zero'] += (int) ($props['result_count'] ?? 1) === 0 ? 1 : 0],
                DamEvent::SearchResultOpened->value    => $search['opened']++,
                default                                => null,
            };
        }

        // An upload that failed and then succeeded on a retry has one terminal row, now a success.
        $attempted = count($upload['attempted']);
        arsort($upload['codes']);

        return [
            'upload' => [
                'dialogs'    => $upload['dialogs'],
                'abandoned'  => $upload['abandoned'],
                'uploads'    => $attempted,
                'attempts'   => $upload['attempts'],
                'succeeded'  => count($upload['succeeded']),
                'failed'     => count($upload['failed']),
                'completion' => $attempted === 0 ? null : (int) round(100 * count(array_intersect_key($upload['succeeded'], $upload['attempted'])) / $attempted),
                'codes'      => $upload['codes'],
            ],
            'share' => [
                'dialogs'   => $share['dialogs'],
                'abandoned' => $share['abandoned'],
                'created'   => $share['created'],
                'revoked'   => $share['revoked'],
                'visited'   => count($share['visited']),
            ],
            'search' => $search,
            'terms'  => app(SearchTerms::class)->report(viewer: $viewer, from: $from, to: $to),
            'zero'   => app(SearchTerms::class)->report(viewer: $viewer, from: $from, to: $to, zeroResultsOnly: true),
        ];
    }

    /**
     * The Feature adoption baseline (T4): per feature, distinct staff who used it, uses, and how many came back on a
     * second day; and distinct staff per week. Narrowed to one role, an actor count under k shows as suppressed, so a
     * small role cannot single anyone out (§3.6.7).
     *
     * @return array<string, mixed>
     */
    public function adoption(Admin $viewer, int $days, ?int $roleId): array
    {
        [$from, $to] = self::window($days);
        $features = array_fill_keys(self::FEATURES, ['actors' => [], 'uses' => 0, 'days' => []]);
        $weeks = [];

        $rows = $this->raw(viewer: $viewer, from: $from, to: $to, names: [
            DamEvent::SearchSubmitted, DamEvent::SearchServed, DamEvent::FilterApplied, DamEvent::PreviewOpened, DamEvent::DownloadServed, DamEvent::UploadAttempted,
            DamEvent::ShareCreated, DamEvent::OptimizationSucceeded, DamEvent::OptimizationFailed, DamEvent::VectorizationSucceeded, DamEvent::VectorizationFailed,
        ], adminsOnly: true, roleId: $roleId);

        foreach ($rows as $row) {
            $props = $row->props === null ? [] : (array) json_decode((string) $row->props, true);
            $feature = match ($row->name) {
                // A search counts once it settles (typing pauses also fetch); Find similar has no search box.
                DamEvent::SearchSubmitted->value                                            => ($props['smart'] ?? false) ? 'smart_search' : 'search',
                DamEvent::SearchServed->value                                               => ($props['similar'] ?? false) ? 'find_similar' : null,
                DamEvent::FilterApplied->value                                              => 'filters',
                DamEvent::PreviewOpened->value                                              => 'preview',
                DamEvent::DownloadServed->value                                             => 'download',
                DamEvent::UploadAttempted->value                                            => 'upload',
                DamEvent::ShareCreated->value                                               => 'share',
                DamEvent::OptimizationSucceeded->value, DamEvent::OptimizationFailed->value => 'optimize',
                default                                                                     => 'convert',
            };
            if ($feature === null) {
                continue;
            }

            $at = Carbon::parse($row->occurred_at);
            $features[$feature]['actors'][$row->actor_key] = true;
            $features[$feature]['uses']++;
            $features[$feature]['days'][$row->actor_key][$at->toDateString()] = true;
            $weeks[$at->copy()->startOfWeek(Carbon::MONDAY)->toDateString()][$feature][$row->actor_key] = true;
        }

        $k = (int) config('dam_events.k', 3);
        $suppress = fn (int $actors) => $roleId !== null && $actors > 0 && $actors < $k ? null : $actors;
        ksort($weeks);

        return [
            'features' => array_map(fn (array $feature) => [
                'actors'  => $suppress(count($feature['actors'])),
                'uses'    => $roleId !== null && count($feature['actors']) > 0 && count($feature['actors']) < $k ? null : $feature['uses'],
                'repeat'  => $suppress(count(array_filter($feature['days'], fn (array $days) => count($days) >= 2))),
            ], $features),
            'weeks' => array_map(fn (array $week) => array_map(fn (string $feature) => $suppress(count($week[$feature] ?? [])), array_combine(self::FEATURES, self::FEATURES)), $weeks),
            'k'     => $k,
        ];
    }

    /**
     * Recent sessions with at least one event the viewer can see, newest first: who (a pseudonym unless the viewer
     * may identify), when, and how many visible events.
     *
     * @return list<array{session_id: string, actor: string, started: string, ended: string, events: int}>
     */
    public function sessions(Admin $viewer, int $days, bool $identify): array
    {
        [$from, $to] = self::window($days);
        $scope = new ReportScope(viewer: $viewer);
        $rows = $scope->apply(DB::table(EventStore::TABLE), table: EventStore::TABLE, none: null)
            ->whereNotNull('session_id')
            ->whereBetween('occurred_at', [EventStore::timestamp($from), EventStore::timestamp($to)])
            ->groupBy('session_id', 'actor_type', 'actor_key')
            ->select(['session_id', 'actor_type', 'actor_key'])
            ->selectRaw('MIN(occurred_at) AS started, MAX(occurred_at) AS ended, COUNT(*) AS events')
            ->orderByDesc('ended')
            ->limit(self::SESSIONS)
            ->get();
        $names = $this->adminNames(rows: $rows, identify: $identify);

        return $rows->map(fn ($row) => [
            'session_id' => (string) $row->session_id,
            'actor'      => $this->actorLabel(type: (string) $row->actor_type, key: (string) $row->actor_key, names: $names),
            'started'    => (string) $row->started,
            'ended'      => (string) $row->ended,
            'events'     => (int) $row->events,
        ])->all();
    }

    /**
     * One session's ordered actions (T3, §3.6.5): client events by their sequence number, server events placed by
     * their time between them, the clock only breaking ties. An event on a subject the viewer cannot see is dropped,
     * not renamed. Search shows words and results, never text; a link shows its id, never its token. Gaps in the
     * client sequence are reported.
     *
     * @return array{actor: ?string, events: list<array<string, mixed>>, gaps: int}
     */
    public function timeline(Admin $viewer, string $sessionId, bool $identify): array
    {
        $scope = new ReportScope(viewer: $viewer);
        $rows = $scope->apply(DB::table(EventStore::TABLE), table: EventStore::TABLE, none: null)
            ->where('session_id', $sessionId)
            ->orderBy('id')
            ->get(['id', 'name', 'occurred_at', 'seq', 'actor_type', 'actor_key', 'surface', 'subject_type', 'subject_id', 'share_id', 'outcome', 'request_id', 'duration_ms', 'props', 'emitter']);

        if ($rows->isEmpty()) {
            return ['actor' => null, 'events' => [], 'gaps' => 0];
        }

        $ordered = self::order(rows: $rows->all());
        $assetIds = $rows->where('subject_type', 'asset')->pluck('subject_id')->filter()->unique()->values()->all();
        $labels = Asset::query()->whereIn('dam_assets.id', $assetIds ?: [0])->select(['dam_assets.id', 'dam_assets.file_name'])->withDisplayName()->get()
            ->mapWithKeys(fn (Asset $asset) => [(int) $asset->id => (string) $asset->display_name])->all();
        $names = $this->adminNames(rows: $rows, identify: $identify);
        // A member seen through a link the viewer may not see (another owner's collection, a deleted link) keeps its event, not the link.
        $visibleShares = $scope->visibleShares()->whereIn('id', $rows->pluck('share_id')->filter()->unique()->values()->all() ?: [0])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $seqs = $rows->pluck('seq')->filter(fn ($seq) => $seq !== null)->map(fn ($seq) => (int) $seq)->unique()->sort()->values();
        $gaps = $seqs->isEmpty() ? 0 : ($seqs->last() - $seqs->first() + 1) - $seqs->count();

        return [
            'actor'  => $this->actorLabel(type: (string) $rows->first()->actor_type, key: (string) $rows->first()->actor_key, names: $names),
            'events' => array_map(function ($row) use ($labels, $visibleShares) {
                $props = $row->props === null ? [] : (array) json_decode((string) $row->props, true);

                return [
                    'seq'        => $row->seq === null ? null : (int) $row->seq,
                    'at'         => (string) $row->occurred_at,
                    'name'       => (string) $row->name,
                    'outcome'    => (string) $row->outcome,
                    'emitter'    => (string) $row->emitter,
                    'surface'    => $row->surface,
                    'duration'   => $row->duration_ms === null ? null : (int) $row->duration_ms,
                    'subject'    => $row->subject_type === 'asset' && $row->subject_id !== null ? ['id' => (int) $row->subject_id, 'label' => $labels[(int) $row->subject_id] ?? null] : null,
                    'share_id'   => $row->share_id !== null && in_array((int) $row->share_id, $visibleShares, true) ? (int) $row->share_id : null,
                    'request_id' => $row->outcome === EventRegistry::OUTCOME_FAILURE ? $row->request_id : null,
                    'detail'     => self::summary(name: (string) $row->name, props: $props),
                ];
            }, $ordered),
            'gaps'   => max(0, $gaps),
        ];
    }

    /**
     * Client events in seq order; each server event goes before the first client event that happened after it.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    public static function order(array $rows): array
    {
        $client = array_values(array_filter($rows, fn ($row) => $row->seq !== null));
        $server = array_values(array_filter($rows, fn ($row) => $row->seq === null));
        usort($client, fn ($a, $b) => [(int) $a->seq, (string) $a->occurred_at, (int) $a->id] <=> [(int) $b->seq, (string) $b->occurred_at, (int) $b->id]);
        usort($server, fn ($a, $b) => [(string) $a->occurred_at, (int) $a->id] <=> [(string) $b->occurred_at, (int) $b->id]);
        $ordered = [];

        foreach ($client as $event) {
            while ($server !== [] && (string) $server[0]->occurred_at < (string) $event->occurred_at) {
                $ordered[] = array_shift($server);
            }

            $ordered[] = $event;
        }

        return [...$ordered, ...$server];
    }

    /** The only facts a timeline row adds to its name: counts and codes, never text (§3.6.7, §3.6.8). */
    public static function summary(string $name, array $props): array
    {
        return array_filter([
            'words'       => $props['words'] ?? null,
            'results'     => $props['result_count'] ?? null,
            'smart'       => isset($props['smart']) ? ($props['smart'] ? 'yes' : 'no') : null,
            'similar'     => ! empty($props['similar']) ? 'yes' : null,
            'tab'         => $props['tab'] ?? null,
            'filter'      => $props['filter'] ?? null,
            'via'         => $props['via'] ?? null,
            'error_code'  => $props['error_code'] ?? null,
            'attempt'     => $props['attempt'] ?? null,
            'metric'      => isset($props['metric']) ? $props['metric'].' '.($props['value'] ?? '') : null,
            'measurement' => isset($props['metric']) ? ($props['measurement'] ?? 'legacy-proxy') : null,
            'route'       => $props['route'] ?? null,
            'kind'        => $props['kind'] ?? null,
            'error_type'  => $props['error_type'] ?? null,
            'source_kind' => $props['source_kind'] ?? null,
            'bundle'      => $props['bundle'] ?? null,
            'line'        => $props['line'] ?? null,
            'column'      => $props['column'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Raw events of these names in the window, restricted to subjects the viewer can see, excluding suspected bots.
     *
     * @param  list<DamEvent>  $names
     * @return iterable<object>
     */
    private function raw(Admin $viewer, Carbon $from, Carbon $to, array $names, bool $adminsOnly = false, ?int $roleId = null): iterable
    {
        $scope = new ReportScope(viewer: $viewer);

        return $scope->apply(DB::table(EventStore::TABLE), table: EventStore::TABLE, none: null)
            ->whereIn('name', array_map(fn (DamEvent $event) => $event->value, $names))
            ->whereBetween('occurred_at', [EventStore::timestamp($from), EventStore::timestamp($to)])
            ->where('suspected_bot', false)
            ->when($adminsOnly, fn ($query) => $query->where('actor_type', AnalyticsActor::ADMIN))
            ->when($roleId !== null, fn ($query) => $query->where('actor_role_id', $roleId))
            ->select(['id', 'name', 'occurred_at', 'actor_key', 'share_id', 'props'])
            ->lazyById(1000);
    }

    /**
     * Links whose target the viewer can see, busiest first: counted visits, visitor-days, downloads through the
     * link, visits whose page never reported a view (no JavaScript), and count-only page loads.
     *
     * @return list<array<string, mixed>>
     */
    private function links(ReportScope $scope, Carbon $from, Carbon $to): array
    {
        $rows = $scope->applyShares($scope->apply(
            DB::table(EventStore::COUNTS)->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->where('share_id', '>', 0)->whereIn('name', [DamEvent::ShareVisited->value, DamEvent::DownloadServed->value, DamEvent::PreviewOpened->value]),
            table: EventStore::COUNTS,
            none: '',
        ), table: EventStore::COUNTS)->groupBy('share_id', 'name', 'source')
            ->select(['share_id', 'name', 'source'])
            ->selectRaw('SUM(counted) AS counted, SUM(actors) AS actors, SUM(nojs) AS nojs, SUM(events) AS events')
            ->get();
        $links = [];

        foreach ($rows as $row) {
            $entry = $links[(int) $row->share_id] ?? ['visits' => 0, 'visitor_days' => 0, 'views' => 0, 'downloads' => 0, 'nojs' => 0, 'unrefined' => 0];

            if ($row->source === EventStore::SOURCE_AGGREGATE) {
                $entry['unrefined'] += $row->name === DamEvent::ShareVisited->value ? (int) $row->events : 0;
            } else {
                match ($row->name) {
                    DamEvent::ShareVisited->value   => [$entry['visits'] += (int) $row->counted, $entry['visitor_days'] += (int) $row->actors, $entry['nojs'] += (int) $row->nojs],
                    DamEvent::DownloadServed->value => $entry['downloads'] += (int) $row->counted,
                    default                         => $entry['views'] += (int) $row->counted,
                };
            }

            $links[(int) $row->share_id] = $entry;
        }

        uasort($links, fn (array $a, array $b) => [$b['visits'] + $b['unrefined'], $b['downloads']] <=> [$a['visits'] + $a['unrefined'], $a['downloads']]);
        $links = array_slice($links, 0, self::TOP, true);
        $shares = Share::query()->whereIn('id', array_keys($links) ?: [0])->get(['id', 'name', 'share_type', 'target_id', 'revoked_at', 'expires_at'])->keyBy('id');
        $assetLabels = Asset::query()->whereIn('dam_assets.id', $shares->where('share_type', Share::TYPE_ASSET)->pluck('target_id')->all() ?: [0])
            ->select(['dam_assets.id', 'dam_assets.file_name'])->withDisplayName()->get()
            ->mapWithKeys(fn (Asset $asset) => [(int) $asset->id => (string) $asset->display_name])->all();
        $folderLabels = DB::table('dam_directories')->whereIn('id', $shares->where('share_type', Share::TYPE_DIRECTORY)->pluck('target_id')->all() ?: [0])->pluck('name', 'id')->all();
        $collectionLabels = DB::table('dam_collections')->whereIn('id', $shares->where('share_type', Share::TYPE_COLLECTION)->pluck('target_id')->all() ?: [0])->pluck('name', 'id')->all();

        return array_map(function (int $id, array $entry) use ($shares, $assetLabels, $folderLabels, $collectionLabels) {
            $share = $shares->get($id);
            $target = match ($share?->share_type) {
                null                    => null,
                Share::TYPE_ASSET       => $assetLabels[$share->target_id] ?? null,
                Share::TYPE_COLLECTION  => $collectionLabels[$share->target_id] ?? null,
                default                 => $folderLabels[$share->target_id] ?? null,
            };

            return ['id' => $id, 'name' => $share?->name, 'type' => $share?->share_type, 'target' => $target, 'active' => $share?->isActive() ?? false] + $entry;
        }, array_keys($links), $links);
    }

    /** @return list<array{date: string, views: int, downloads: int, visits: int}> */
    private function daily($counts, Carbon $from, Carbon $to): array
    {
        $byDay = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $byDay[$day->toDateString()] = ['date' => $day->toDateString(), 'views' => 0, 'downloads' => 0, 'visits' => 0];
        }

        foreach ($counts->groupBy('day', 'name')->select(['day', 'name'])->selectRaw('SUM(counted) AS counted')->get() as $row) {
            $date = Carbon::parse($row->day)->toDateString();
            $measure = match ($row->name) {
                DamEvent::PreviewOpened->value  => 'views',
                DamEvent::DownloadServed->value => 'downloads',
                DamEvent::ShareVisited->value   => 'visits',
                default                         => null,
            };

            if ($measure !== null && isset($byDay[$date])) {
                $byDay[$date][$measure] += (int) $row->counted;
            }
        }

        return array_values(array_reverse($byDay));
    }

    /** @return array<string, string> admin id => name, only when the viewer may identify */
    private function adminNames($rows, bool $identify): array
    {
        if (! $identify) {
            return [];
        }

        $ids = collect($rows)->where('actor_type', AnalyticsActor::ADMIN)->pluck('actor_key')->map(fn ($key) => (int) $key)->unique()->values()->all();

        return Admin::query()->whereIn('id', $ids ?: [0])->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])->all();
    }

    /** @param array<string, string> $names */
    private function actorLabel(string $type, string $key, array $names): string
    {
        return $type === AnalyticsActor::ADMIN && isset($names[$key]) ? $names[$key] : Pseudonym::for(actorType: $type, actorKey: $key);
    }
}
