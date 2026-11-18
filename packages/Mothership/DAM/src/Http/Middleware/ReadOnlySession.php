<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a background beacon route whose session is read but never saved. The marker does nothing itself:
 * ReadOnlyAwareStartSession, which takes Laravel's StartSession place, honours it, so a response from any middleware
 * (CSRF, sign-in, throttle) is covered too.
 *
 * Laravel saves the whole session at the end of every request, so a beacon that read it before a concurrent request
 * stored something and finished after it wrote the stale copy back. The replay relay posts every few seconds and
 * waits on PostHog, which was long enough to erase the pending Craft OAuth consent (`pending_missing`, "Connect
 * again") while another tab was open.
 */
class ReadOnlySession
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public static function marks(Request $request): bool
    {
        return in_array(self::class, $request->route()?->gatherMiddleware() ?? [], true);
    }
}
