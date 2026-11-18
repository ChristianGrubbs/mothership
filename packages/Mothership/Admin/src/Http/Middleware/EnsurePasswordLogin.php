<?php

namespace Mothership\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mothership\Admin\Auth\PasswordLogin;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides a password route (404, like a missing one) while password sign-in is off.
 */
class EnsurePasswordLogin
{
    public function __construct(protected readonly PasswordLogin $passwordLogin) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->passwordLogin->enabled(), 404);

        return $next($request);
    }
}
