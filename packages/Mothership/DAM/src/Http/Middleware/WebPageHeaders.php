<?php

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mothership\DAM\Services\WebPagePolicy;
use Symfony\Component\HttpFoundation\Response;

/** Applies the page policy to every response of a page route, including 404/409/429 responses. */
class WebPageHeaders
{
    public function handle(Request $request, Closure $next, string $scope = 'admin'): Response
    {
        $response = $next($request);

        foreach (WebPagePolicy::headers(public: $scope === 'public') as $name => $value) {
            $response->headers->set($name, $value);
        }

        if (! $response->headers->has('Cache-Control') || $response->getStatusCode() >= 400) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
