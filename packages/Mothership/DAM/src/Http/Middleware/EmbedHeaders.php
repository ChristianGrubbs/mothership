<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mothership\DAM\Http\Controllers\Embed\EmbedController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The embed routes' fixed headers (P6 D26), on every response they give, a 404 or a 429 included. The routes sit
 * outside the `web` group, so no session or CSRF cookie is made; any Set-Cookie is removed all the same. The token in
 * the path is the whole capability, so nothing is sent on as a Referer. A response the controller did not frame
 * (a refusal, a throttle) gets the refusal policy, which every refusal shares.
 */
class EmbedHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy') || $response->getStatusCode() >= 400) {
            $response->headers->set('Content-Security-Policy', EmbedController::REFUSAL_CSP);
        }

        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->remove('X-Frame-Options');
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
