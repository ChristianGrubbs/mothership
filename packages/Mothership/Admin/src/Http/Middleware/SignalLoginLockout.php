<?php

namespace Mothership\Admin\Http\Middleware;

use Closure;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The login route is throttled by the `throttle:admin-login` route middleware, which answers 429 but, unlike
 * Laravel's ThrottlesLogins, never fires Illuminate\Auth\Events\Lockout. Placed outside the throttle, this
 * fires it for each refused attempt, so lockouts reach the same listeners as every other auth event.
 */
class SignalLoginLockout
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
            event(new Lockout($request));
        }

        return $response;
    }
}
