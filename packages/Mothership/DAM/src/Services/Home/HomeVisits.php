<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Home;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records when a person opens Home in a browser. Opens close together count as one visit, so refreshing the page
 * does not clear what is new.
 */
class HomeVisits
{
    private const TABLE = 'dam_home_visits';

    public function record(int $adminId, CarbonImmutable $now): void
    {
        $gapMinutes = (int) config('dam-home.summary.visit_gap_minutes');
        $stamp = $now->toDateTimeString();

        DB::transaction(function () use ($adminId, $gapMinutes, $now, $stamp): void {
            $created = DB::table(self::TABLE)->insertOrIgnore([
                'admin_id'      => $adminId,
                'last_visit_at' => $stamp,
                'created_at'    => $stamp,
                'updated_at'    => $stamp,
            ]);
            if ($created > 0) {
                return;
            }

            $visit = DB::table(self::TABLE)->where('admin_id', $adminId)->lockForUpdate()->first();
            $lastVisit = CarbonImmutable::parse($visit->last_visit_at);
            // Requests can reach this lock out of order. An older open must not move the visit backward.
            if ($now->lessThan($lastVisit)) {
                return;
            }
            $sameVisit = $lastVisit->addMinutes($gapMinutes)->greaterThan($now);

            DB::table(self::TABLE)->where('admin_id', $adminId)->update([
                'previous_visit_at' => $sameVisit ? $visit->previous_visit_at : $visit->last_visit_at,
                'last_visit_at'     => $stamp,
                'updated_at'        => $stamp,
            ]);
        });
    }

    /** The moment Home measures "new" from, or null before this person has an earlier visit to measure from. */
    public function since(int $adminId): ?CarbonImmutable
    {
        $since = DB::table(self::TABLE)->where('admin_id', $adminId)->value('previous_visit_at');

        return $since === null ? null : CarbonImmutable::parse($since);
    }
}
