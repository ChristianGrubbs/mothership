<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/**
 * The stable label a journey shows for an actor to a viewer without dam.reports.identify (§3.6.8): "User 7f3a" or
 * "Visitor 1a2b". Keyed with APP_KEY so it is not the actor key itself and cannot be reversed from a screenshot.
 */
final class Pseudonym
{
    public static function for(string $actorType, string $actorKey): string
    {
        $tag = substr(hash_hmac('sha256', $actorType.':'.$actorKey, (string) config('app.key')), 0, 4);

        return match ($actorType) {
            AnalyticsActor::ADMIN        => trans('dam::app.admin.analytics.pseudonym.user', ['tag' => $tag]),
            AnalyticsActor::LINK_VISITOR => trans('dam::app.admin.analytics.pseudonym.visitor', ['tag' => $tag]),
            default                      => trans('dam::app.admin.analytics.pseudonym.system'),
        };
    }
}
