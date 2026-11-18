<?php

namespace Mothership\AdminApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Mothership\AdminApi\Services\McpOAuth;

class McpDiscoveryController
{
    public function __construct(private McpOAuth $policy) {}

    public function resource(): JsonResponse
    {
        abort_unless($this->policy->enabled(), 404);

        return response()->json([
            'resource'                 => $this->policy->resource(),
            'authorization_servers'    => [$this->policy->issuer()],
            'scopes_supported'         => [McpOAuth::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        abort_unless($this->policy->enabled(), 404);

        return response()->json([
            'issuer'                                         => $this->policy->issuer(),
            'authorization_response_iss_parameter_supported' => true,
            'authorization_endpoint'                         => $this->policy->issuer().'/oauth/mcp/authorize',
            'token_endpoint'                                 => $this->policy->issuer().'/'.trim((string) config('passport.path', 'oauth'), '/').'/token',
            'response_types_supported'                       => ['code'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported'          => ['none'],
            'code_challenge_methods_supported'               => ['S256'],
            'scopes_supported'                               => [McpOAuth::SCOPE],
        ]);
    }
}
