<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;

/**
 * Laravel's StartSession, except on a route marked ReadOnlySession: the session is started as usual, but nothing is
 * stored, saved or sent back. A beacon has nothing of its own to keep, so it no longer ages another request's flash
 * data, records a previous URL or extends the idle lifetime of a session nobody is using.
 */
class ReadOnlyAwareStartSession extends StartSession
{
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        if (! ReadOnlySession::marks($request)) {
            return parent::handleStatefulRequest($request, $session, $next);
        }

        $request->setLaravelSession($this->startSession($request, $session));
        [$startedAs, $signedIn] = [$session->getId(), $this->signIn($session)];

        $response = $next($request);

        // A sign-in again from a remember-me cookie, or a sign-out, still has to stick, like on any other request.
        // A sign-in key only added (a password hash recorded for the first time) is recorded again by the next request.
        if ($session->getId() !== $startedAs || array_diff_assoc($signedIn, $this->signIn($session)) !== []) {
            $this->addCookieToResponse($response, $session);
            $this->saveSession($request);
        } else {
            // A token generated for a session that is not kept would replace the browser's working one.
            $config = $this->manager->getSessionConfig();
            $response->headers->removeCookie('XSRF-TOKEN', $config['path'], $config['domain']);
        }

        return $response;
    }

    /** Every guard's sign-in keys, which a sign-out removes. */
    private function signIn(Session $session): array
    {
        return array_filter($session->all(), fn ($key) => Str::startsWith($key, ['login_', 'password_hash_']), ARRAY_FILTER_USE_KEY);
    }
}
