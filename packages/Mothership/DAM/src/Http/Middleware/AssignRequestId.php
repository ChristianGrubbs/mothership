<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Mothership\DAM\Support\RequestId;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request one id: a safe inbound X-Request-Id is kept, anything else is replaced with a uuid. The id
 * goes on Laravel's Context (log lines and queued jobs), back onto the request so a later reader of the header
 * sees the checked value, and onto the response.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = RequestId::accept(inbound: $request->headers->get(RequestId::HEADER));

        Context::add(RequestId::CONTEXT_KEY, $id);
        $request->headers->set(RequestId::HEADER, $id);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
