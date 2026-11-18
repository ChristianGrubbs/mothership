<?php

namespace Mothership\User\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Laravel's AuthenticateSession for the admin routes (UX eval 2026-10-10, PERM-12): a session keeps the password
 * hash it signed in with and ends once the account's password changes, so changing a password signs that user out
 * of every other session. The parent reads the default guard; these routes are the admin session guard's, so it is
 * made the default first, as Laravel's Authenticate middleware does for the guard it checks. Without that, a
 * request after one that switched the default to the API's token guard would ask that guard for remember-me state
 * it does not have.
 */
class AuthenticateAdminSession extends AuthenticateSession
{
    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next)
    {
        $this->auth->shouldUse('admin');

        return parent::handle($request, $next);
    }
}
