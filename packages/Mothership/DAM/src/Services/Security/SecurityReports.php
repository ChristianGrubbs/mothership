<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/**
 * The two staff reports behind dam.reports.security (P1a): Login and access, and Operational health.
 *
 * Counts are folded in PHP over a streamed range rather than grouped by day in SQL, because a day expression
 * differs on each of the four supported databases and the volume (about ten staff, at most 90 days) is small.
 * Neither report reads a file name, an asset name or an asset id; failed rows are listed by their own id and
 * request id only (§3.6.8, §3.6.11).
 *
 * Operational health is scoped to the viewer before anything is counted (§3.6.8): unless their role reaches
 * every file, each status table is filtered by the same asset rule the Library lists by
 * (DirectoryPermissionService::applyAssetDirectoryGrantFilter), so a file they cannot open adds nothing to a
 * count and contributes no row id or request id. A status row not yet tied to a file is left out for them too.
 */
class SecurityReports
{
    public const RANGES = [7, 30, 90];

    public const DEFAULT_RANGE = 30;

    /**
     * Per status table: its status column, its short error-code column (if any), the statuses that mean failure,
     * and the column naming the asset the row is about (the access filter's subject).
     */
    public const STATUS_TABLES = [
        'dam_optimizations'  => ['status' => 'status', 'code' => 'error', 'time' => 'updated_at', 'failed' => ['failed', 'expired'], 'asset' => 'asset_id'],
        'dam_vectorizations' => ['status' => 'status', 'code' => 'error_code', 'time' => 'updated_at', 'failed' => ['failed'], 'asset' => 'source_asset_id'],
        'dam_renditions'     => ['status' => 'status', 'code' => null, 'time' => 'updated_at', 'failed' => ['failed'], 'asset' => 'dam_asset_id'],
        'dam_upload_batches' => ['status' => 'state', 'code' => null, 'time' => 'updated_at', 'failed' => ['failed'], 'asset' => 'asset_id'],
        'dam_chunk_uploads'  => ['status' => 'state', 'code' => 'failure_code', 'time' => 'updated_at', 'failed' => ['failed', 'expired'], 'asset' => 'asset_id'],
        'dam_ai_index'       => ['status' => 'status', 'code' => null, 'time' => 'updated_at', 'failed' => ['failed'], 'asset' => 'asset_id'],
    ];

    private const RECENT_FAILURES = 25;

    private const IDENTIFIER_ROWS = 20;

    public static function range(mixed $days): int
    {
        return in_array((int) $days, self::RANGES, true) ? (int) $days : self::DEFAULT_RANGE;
    }

    /**
     * @return array{
     *     daily: list<array{date: string, succeeded: int, failed: int, locked_out: int, logouts: int, active_admins: int, sessions: int}>,
     *     totals: array{succeeded: int, failed: int, locked_out: int, logouts: int},
     *     identifiers: list<array{prefix: string, failed: int, locked_out: int, known_account: bool, last: string}>
     * }
     */
    public function loginAccess(int $days): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $byDay = [];

        for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
            $byDay[$day->toDateString()] = ['succeeded' => 0, 'failed' => 0, 'locked_out' => 0, 'logouts' => 0, 'admins' => [], 'sessions' => []];
        }

        $counters = [
            SecurityEvent::LOGIN_SUCCEEDED  => 'succeeded',
            SecurityEvent::LOGIN_FAILED     => 'failed',
            SecurityEvent::LOGIN_LOCKED_OUT => 'locked_out',
            SecurityEvent::LOGOUT_SUCCEEDED => 'logouts',
        ];
        $identifiers = [];

        $rows = DB::table(SecurityEventRecorder::TABLE)
            ->where('occurred_at', '>=', $from)
            ->select(['id', 'occurred_at', 'event', 'actor_admin_id', 'identifier_hash', 'session_hash', 'ctx'])
            ->lazyById(1000);

        foreach ($rows as $row) {
            $date = Carbon::parse($row->occurred_at)->toDateString();

            if (! isset($byDay[$date])) {
                continue;
            }

            if (isset($counters[$row->event])) {
                $byDay[$date][$counters[$row->event]]++;
            }

            if ($row->event === SecurityEvent::LOGIN_SUCCEEDED && $row->actor_admin_id !== null) {
                $byDay[$date]['admins'][(int) $row->actor_admin_id] = true;
            }

            if ($row->actor_admin_id !== null && $row->session_hash !== null) {
                $byDay[$date]['sessions'][$row->session_hash] = true;
            }

            if (in_array($row->event, [SecurityEvent::LOGIN_FAILED, SecurityEvent::LOGIN_LOCKED_OUT], true)) {
                $prefix = $row->identifier_hash === null ? '' : substr($row->identifier_hash, 0, 8);
                $entry = $identifiers[$prefix] ?? ['prefix' => $prefix, 'failed' => 0, 'locked_out' => 0, 'known_account' => false, 'last' => ''];
                $entry[$row->event === SecurityEvent::LOGIN_FAILED ? 'failed' : 'locked_out']++;
                $entry['known_account'] = $entry['known_account'] || (bool) (json_decode((string) $row->ctx, true)['account_exists'] ?? false);
                $entry['last'] = max($entry['last'], $date);
                $identifiers[$prefix] = $entry;
            }
        }

        $daysOut = [];

        foreach (array_reverse($byDay, true) as $date => $day) {
            $daysOut[] = [
                'date'          => $date,
                'succeeded'     => $day['succeeded'],
                'failed'        => $day['failed'],
                'locked_out'    => $day['locked_out'],
                'logouts'       => $day['logouts'],
                'active_admins' => count($day['admins']),
                'sessions'      => count($day['sessions']),
            ];
        }

        usort($identifiers, fn (array $a, array $b) => [$b['failed'] + $b['locked_out'], $b['last']] <=> [$a['failed'] + $a['locked_out'], $a['last']]);

        return [
            'daily'       => $daysOut,
            'totals'      => [
                'succeeded'  => array_sum(array_column($daysOut, 'succeeded')),
                'failed'     => array_sum(array_column($daysOut, 'failed')),
                'locked_out' => array_sum(array_column($daysOut, 'locked_out')),
                'logouts'    => array_sum(array_column($daysOut, 'logouts')),
            ],
            'identifiers' => array_slice($identifiers, 0, self::IDENTIFIER_ROWS),
        ];
    }

    /**
     * @return array{
     *     statuses: list<array{table: string, status: string, code: ?string, count: int}>,
     *     failures: list<array{table: string, id: string, status: string, code: ?string, at: string, request_id: ?string}>,
     *     events: list<array{event: string, count: int}>,
     *     scoped: bool
     * }
     */
    public function operationalHealth(int $days, Admin $viewer): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $permissions = (new DirectoryPermissionService)->forAdmin($viewer);
        $scoped = ! $permissions->bypass();
        $statuses = [];
        $failures = [];

        foreach (self::STATUS_TABLES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $code = $columns['code'];
            // Applied to both queries before any grouping: an inaccessible file is never counted or listed.
            $visible = fn ($query) => $permissions->applyAssetDirectoryGrantFilter($query, column: $table.'.'.$columns['asset']);
            $grouped = DB::table($table)->where($columns['time'], '>=', $from)->tap($visible)
                ->select(array_values(array_filter([$columns['status'].' as status_value', $code ? $code.' as code_value' : null])))
                ->selectRaw('count(*) as aggregate')
                ->groupBy(array_values(array_filter([$columns['status'], $code])))
                ->orderBy($columns['status'])
                ->get();

            foreach ($grouped as $row) {
                $statuses[] = ['table' => $table, 'status' => (string) $row->status_value, 'code' => $code ? $row->code_value : null, 'count' => (int) $row->aggregate];
            }

            $recent = DB::table($table)->where($columns['time'], '>=', $from)->tap($visible)
                ->whereIn($columns['status'], $columns['failed'])
                ->orderByDesc($columns['time'])
                ->limit(self::RECENT_FAILURES)
                ->get(array_values(array_filter(['id', $columns['status'], $code, $columns['time'], 'http_request_id'])));

            foreach ($recent as $row) {
                $failures[] = [
                    'table'      => $table,
                    'id'         => (string) $row->id,
                    'status'     => (string) $row->{$columns['status']},
                    'code'       => $code ? $row->{$code} : null,
                    'at'         => (string) $row->{$columns['time']},
                    'request_id' => $row->http_request_id,
                ];
            }
        }

        $events = DB::table(SecurityEventRecorder::TABLE)->where('occurred_at', '>=', $from)
            ->selectRaw('event, count(*) as aggregate')->groupBy('event')->orderBy('event')->get()
            ->map(fn ($row) => ['event' => (string) $row->event, 'count' => (int) $row->aggregate])->all();

        return ['statuses' => $statuses, 'failures' => $failures, 'events' => $events, 'scoped' => $scoped];
    }
}
