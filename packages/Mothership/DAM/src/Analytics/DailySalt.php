<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The salt a link visitor's key is made with (§3.6.2): random, one per server day, shared by every process through
 * the cache, and gone from it shortly after the day ends. Nothing ever writes it anywhere else, so once it expires
 * yesterday's visitor keys cannot be recomputed or linked to today's: uniques are per day, per link, approximate.
 */
final class DailySalt
{
    private const PREFIX = 'dam:analytics:salt:';

    /** Kept a few minutes past midnight, so a request that started on the old day still finds its salt. */
    private const GRACE_MINUTES = 10;

    /** Null when the cache cannot hold one: the caller then counts the visit on the count-only path. */
    public function forDay(Carbon $day): ?string
    {
        $key = self::PREFIX.$day->toDateString();

        try {
            // add() is atomic: the first process to need today's salt makes it, every other one reads that one.
            Cache::add($key, bin2hex(random_bytes(32)), $day->copy()->endOfDay()->addMinutes(self::GRACE_MINUTES));
            $salt = Cache::get($key);
        } catch (Throwable) {
            return null;
        }

        return is_string($salt) && $salt !== '' ? $salt : null;
    }

    /** HMAC(daily salt, link ‖ IP/24 ‖ browser family); null when there is no salt to make it with. */
    public function visitorKey(int $shareId, ?string $truncatedIp, ?string $browserFamily, ?Carbon $day = null): ?string
    {
        $salt = $this->forDay($day ?? now());

        return $salt === null ? null : hash_hmac('sha256', $shareId.'|'.$truncatedIp.'|'.$browserFamily, $salt);
    }
}
