<?php

namespace Mothership\AdminApi\Http\Middleware;

use Closure;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Mothership\AdminApi\Services\McpOAuth;

class McpTokenThrottle
{
    public function __construct(private Pipeline $pipeline, private McpOAuth $policy) {}

    public function handle($request, Closure $next)
    {
        $id = $request->input('client_id', $request->getUser());
        if (is_string($id) && $this->policy->configuredClient($id)
            && in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return $this->pipeline->send(passable: $request)
                ->through(pipes: [ThrottleRequests::class.':mcp-oauth-token'])
                ->then(destination: $next);
        }

        // Preserve both original REST limits, including the generic 60/min route throttle.
        // Merely supplying an allowlisted client_id on a password request cannot switch lanes.
        // Resolve the framework middleware through the container, including its replacements.
        return $this->pipeline->send(passable: $request)
            ->through(pipes: [ThrottleRequests::class, ThrottleRequests::class.':oauth-token'])
            ->then(destination: $next);
    }
}
