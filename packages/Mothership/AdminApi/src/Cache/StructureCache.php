<?php

namespace Mothership\AdminApi\Cache;

use Illuminate\Support\Facades\Cache;

/**
 * Versioned response cache for the slow-changing structure entities served
 * by the REST API (attributes, families, channels, locales, ...).
 *
 * Writes never delete response keys: model events bump the group's version,
 * which rotates every response key built from it; superseded keys simply
 * expire by TTL. This works on any cache store (no tag support required)
 * and stays Octane-safe (no state beyond the shared cache).
 */
class StructureCache
{
    public const VERSION_KEY_PREFIX = 'api:structure:ver:';

    public const RESPONSE_KEY_PREFIX = 'api:structure:resp:';

    /**
     * Every cacheable structure group; bumpAll() rotates each of them.
     *
     * @var array<int, string>
     */
    public const GROUPS = [
        'attributes',
        'attribute_groups',
        'families',
        'category_fields',
        'channels',
        'locales',
        'currencies',
    ];

    public function enabled(): bool
    {
        return (bool) config('api.structure_cache.enabled', true);
    }

    public function ttl(): int
    {
        return (int) config('api.structure_cache.ttl', 3600);
    }

    public function version(string $group): int
    {
        return (int) Cache::get(self::VERSION_KEY_PREFIX.$group, 1);
    }

    public function bump(string ...$groups): void
    {
        foreach ($groups as $group) {
            // A missing key (never bumped, or its TTL ran out) is seeded atomically with a
            // value no earlier version can share, so a reseed never lands on a version whose
            // responses are still cached. The TTL is what routes add() to the store's atomic add.
            Cache::add(self::VERSION_KEY_PREFIX.$group, $this->freshVersionSeed(), max($this->ttl() * 2, 2592000));
            Cache::increment(self::VERSION_KEY_PREFIX.$group);
        }
    }

    /**
     * Milliseconds since the epoch with a random suffix: above every earlier seed plus its
     * bumps, and distinct from a seed raced in the same millisecond.
     */
    protected function freshVersionSeed(): int
    {
        return (int) now()->getPreciseTimestamp(3) * 1000 + random_int(0, 999);
    }

    public function bumpAll(): void
    {
        $this->bump(...self::GROUPS);
    }

    public function remember(string $group, string $keySuffix, \Closure $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }

        return Cache::remember(
            self::RESPONSE_KEY_PREFIX.$group.':v'.$this->version($group).':'.$keySuffix,
            $this->ttl(),
            $callback
        );
    }
}
