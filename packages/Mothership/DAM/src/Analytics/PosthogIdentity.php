<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/** One identity contract for the first-party exporter and the explicitly enabled browser recorder. */
final class PosthogIdentity
{
    public static function distinctId(AnalyticsActor $actor): string
    {
        return hash_hmac('sha256', 'posthog:'.$actor->type.':'.$actor->key, (string) config('app.key'));
    }
}
