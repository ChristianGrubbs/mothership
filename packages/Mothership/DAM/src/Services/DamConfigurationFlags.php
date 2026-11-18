<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Models\DamConfiguration;
use Throwable;

/**
 * The four boolean DAM settings kept in `dam_configuration` (DamConfiguration::KEY_MAP), as the config
 * values every DAM request applies. They used to cost a `Schema::hasTable` and a full-table read per request
 * (a static flag does not outlive an FPM request); now one cached map answers all of them.
 *
 * - Saving or deleting a DamConfiguration row forgets the map (model events), so a settings save is seen by the
 *   next request in every container sharing the cache store. The 60 s TTL bounds the two ways a stale map can
 *   outlive that: an edit made behind Eloquent's back (raw SQL), and a request that read the old rows just before
 *   a save and publishes them just after its forget().
 * - Install-time safety: while `dam_configuration` does not exist nothing is cached, so the request that follows
 *   the migration reads the real table; and a cache store that is itself unavailable (not yet migrated, Redis
 *   down) falls through to the database instead of failing the request.
 */
class DamConfigurationFlags
{
    public const CACHE_KEY = 'dam.configuration.flags';

    public const CACHE_SECONDS = 60;

    /** Applies the stored flags to the running config. */
    public function apply(): void
    {
        foreach ($this->flags() as $path => $enabled) {
            config([$path => $enabled]);
        }
    }

    /** @return array<string, bool> config path => value, only for settings that have a stored row */
    public function flags(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Fall through to the database.
        }

        if (! Schema::hasTable('dam_configuration')) {
            return [];
        }

        $flags = [];

        foreach (DamConfiguration::query()->whereIn('key', array_keys(DamConfiguration::KEY_MAP))->get(['key', 'value']) as $row) {
            $flags[DamConfiguration::KEY_MAP[$row->key]] = filter_var($row->value, FILTER_VALIDATE_BOOLEAN);
        }

        try {
            Cache::put(self::CACHE_KEY, $flags, self::CACHE_SECONDS);
        } catch (Throwable) {
            // An unavailable store only costs the query on the next request.
        }

        return $flags;
    }

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Nothing cached means nothing stale.
        }
    }
}
