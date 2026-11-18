<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/** Optional correlation from an initialized browser SDK, never a manufactured server-side recording id. */
final class ReplaySession
{
    public const HEADER = 'X-Dam-Replay-Session';

    public static function enabled(): bool
    {
        return (bool) config('dam_events.enabled') && (bool) config('dam_events.posthog.enabled')
            && (bool) config('dam_events.replay.enabled');
    }

    public static function validate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value)
            ? strtolower($value) : null;
    }

    public static function forActor(AnalyticsActor $actor, mixed $value): ?string
    {
        return self::enabled() && $actor->isAdmin() && ! $actor->aggregateOnly && ! $actor->suspectedBot
            ? self::validate(value: $value) : null;
    }
}
