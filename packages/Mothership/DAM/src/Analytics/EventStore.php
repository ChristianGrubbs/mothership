<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Support\AnalyticsLog;

/**
 * The three writes analytics makes (§3.6.5), each in its own savepoint so a failure inside a caller's transaction
 * on PostgreSQL never aborts that transaction:
 *
 * - raw events, deduplicated on event_id: a resent beacon or a replayed attempt inserts nothing;
 * - a terminal event, one row per operation: a later success replaces a failure, nothing replaces a success;
 * - a count-only increment, for the aggregate-only path, with no row and no key.
 *
 * Every write is portable to the four supported databases: no ON CONFLICT, no MERGE, no JSON operators.
 */
final class EventStore
{
    public const TABLE = 'dam_events';

    public const COUNTS = 'dam_event_counts_daily';

    public const SOURCE_ROLLUP = 'rollup';

    public const SOURCE_AGGREGATE = 'aggregate';

    /** Bound values per insert: under SQL Server's 2,100-parameter limit (a row binds one per column). */
    private const MAX_BINDINGS = 2000;

    public function __construct(private readonly PosthogOutbox $posthog) {}

    /** Stored with milliseconds, so events in one second keep their order. */
    public static function timestamp(Carbon $at): string
    {
        return $at->format('Y-m-d H:i:s.v');
    }

    /**
     * Inserts the rows whose event_id is new, in as few statements as the binding limit allows, and returns the
     * ids it inserted. One lookup finds the ids already stored; a race that stores one in between falls back to
     * row-by-row, each duplicate ignored.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public function insertRaw(array $rows): array
    {
        $rows = array_values(array_column($rows, null, 'event_id'));

        if ($rows === []) {
            return [];
        }

        $existing = DB::table(self::TABLE)->whereIn('event_id', array_column($rows, 'event_id'))->pluck('event_id')->flip();
        $fresh = array_values(array_filter($rows, fn (array $row) => ! isset($existing[$row['event_id']])));

        if ($fresh === []) {
            return [];
        }

        try {
            DB::transaction(function () use ($fresh): void {
                foreach (array_chunk($fresh, max(1, intdiv(self::MAX_BINDINGS, count($fresh[0])))) as $batch) {
                    DB::table(self::TABLE)->insert($batch);
                    $this->stageExport(rows: $batch);
                }
            });

            return array_column($fresh, 'event_id');
        } catch (UniqueConstraintViolationException) {
            $inserted = [];

            foreach ($fresh as $row) {
                try {
                    DB::transaction(function () use ($row): void {
                        DB::table(self::TABLE)->insert($row);
                        $this->stageExport(rows: [$row]);
                    });
                    $inserted[] = $row['event_id'];
                } catch (UniqueConstraintViolationException) {
                    // Stored by a concurrent request between the lookup and the insert: the dedupe working.
                }
            }

            return $inserted;
        }
    }

    /**
     * The one terminal row of an operation (§3.6.5): inserted the first time; a later outcome replaces it only while
     * it is not yet a success, so a retry that succeeds after a failure turns it into a success and a stale failure
     * reported after a success changes nothing.
     *
     * @param  array<string, mixed>  $row
     */
    public function writeTerminal(array $row): void
    {
        DB::transaction(function () use ($row): void {
            $stored = DB::table(self::TABLE)->where('event_id', $row['event_id'])->lockForUpdate()->first(['id', 'outcome']);

            if ($stored === null) {
                try {
                    DB::transaction(function () use ($row): void {
                        DB::table(self::TABLE)->insert($row);
                        $this->stageExport(rows: [$row]);
                    });

                    return;
                } catch (UniqueConstraintViolationException) {
                    $stored = DB::table(self::TABLE)->where('event_id', $row['event_id'])->lockForUpdate()->first(['id', 'outcome']);
                }
            }

            if ($stored === null || $stored->outcome === EventRegistry::OUTCOME_SUCCESS) {
                return;
            }

            DB::table(self::TABLE)->where('id', $stored->id)->update(array_diff_key($row, array_flip(['event_id', 'session_id', 'seq'])));
            $this->stageExport(rows: [$row]);
        });
    }

    /**
     * One more count on the aggregate-only path (§3.6.7). The slice carries no actor key, session, request id or
     * prop, so nothing written here can be joined back into a journey.
     *
     * @param  array{day: string, name: string, outcome: string, actor_type: string, surface?: string, subject_type?: string, subject_id?: int, share_id?: int, detail?: string}  $slice
     */
    public function increment(array $slice, int $events = 1, bool $bot = false): void
    {
        $key = [
            'day'          => $slice['day'],
            'name'         => $slice['name'],
            'outcome'      => $slice['outcome'],
            'actor_type'   => $slice['actor_type'],
            'role_id'      => 0,
            'surface'      => $slice['surface'] ?? '',
            'subject_type' => $slice['subject_type'] ?? '',
            'subject_id'   => $slice['subject_id'] ?? 0,
            'share_id'     => $slice['share_id'] ?? 0,
            'detail'       => $slice['detail'] ?? '',
            'source'       => self::SOURCE_AGGREGATE,
        ];
        $column = $bot ? 'bots' : 'events';

        DB::transaction(function () use ($key, $column, $events): void {
            if (DB::table(self::COUNTS)->where($key)->increment($column, $events) > 0) {
                return;
            }

            try {
                DB::transaction(fn () => DB::table(self::COUNTS)->insert($key + [$column => $events]));
            } catch (UniqueConstraintViolationException) {
                DB::table(self::COUNTS)->where($key)->increment($column, $events);
            }
        });
    }

    /** Optional export never rolls back authoritative local analytics. */
    private function stageExport(array $rows): void
    {
        try {
            DB::transaction(fn () => $this->posthog->stage(rows: $rows));
        } catch (\Throwable $exception) {
            AnalyticsLog::write(level: 'error', event: 'posthog.stage_failed', ctx: ['exception' => $exception::class]);
        }
    }

    /**
     * Whether the actor already did this to this subject inside the window before $at (§3.6.4): a refresh of a link
     * or a preview, or a repeated download. The rollup applies the same rule to the raw rows.
     */
    public function isRepeat(string $name, string $actorKey, ?string $subjectType, ?int $subjectId, ?int $shareId, ?int $attachmentId, Carbon $at, int $windowMinutes): bool
    {
        return DB::table(self::TABLE)
            ->where('name', $name)
            ->where('actor_key', $actorKey)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('share_id', $shareId)
            ->where('attachment_id', $attachmentId)
            ->where('occurred_at', '>=', self::timestamp($at->copy()->subMinutes($windowMinutes)))
            ->where('occurred_at', '<=', self::timestamp($at))
            ->exists();
    }

    /**
     * The actor each of these sessions was first seen with, so a session id is never reused across actors (§3.6.2).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, string>
     */
    public function sessionOwners(array $sessionIds): array
    {
        return $this->owners(sessionIds: $sessionIds, column: 'session_id');
    }

    /** @param list<string> $sessionIds @return array<string, string> */
    public function replaySessionOwners(array $sessionIds): array
    {
        return $this->owners(sessionIds: $sessionIds, column: 'replay_session_id');
    }

    /** @param list<string> $sessionIds @return array<string, string> */
    private function owners(array $sessionIds, string $column): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $owners = [];

        // Distinct pairs, not every row: ingest never stores a second actor on a session, so there is one per id.
        $pairs = DB::table(self::TABLE)->whereIn($column, array_values(array_unique($sessionIds)))
            ->select([$column, 'actor_key'])->distinct()->get();

        foreach ($pairs as $row) {
            $owners[$row->{$column}] ??= (string) $row->actor_key;
        }

        return $owners;
    }
}
