<?php

namespace Mothership\AdminApi\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Mothership\AdminApi\Services\McpOAuth;
use Symfony\Component\HttpFoundation\Response;

class McpAuthenticate
{
    public function __construct(private Factory $auth, private ClientRepository $clients, private McpOAuth $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->policy->enabled(), 404);
        $bearer = $request->bearerToken();
        // Require Bearer explicitly: Passport's browser cookie is deliberately not an MCP credential.
        $user = $bearer ? $this->auth->guard('api')->user() : null;

        if (! $this->policy->acceptsUser($user)) {
            return $this->unauthorized();
        }

        // Parsing follows Passport signature/expiry/revocation verification of the same bearer bytes.
        try {
            $parsed = (new Parser(new JoseEncoder))->parse($bearer);
            if (! $parsed instanceof UnencryptedToken) {
                return $this->unauthorized();
            }
            $claims = $parsed->claims();
        } catch (\Throwable) {
            return $this->unauthorized();
        }
        $audience = $claims->get('aud', []);
        $clientId = $audience[0] ?? '';

        if (! in_array($this->policy->resource(), $audience, true)
            || $claims->get('scopes', []) !== [McpOAuth::SCOPE]
            || ! $this->policy->acceptsClient($this->clients->findActive($clientId))) {
            return $this->unauthorized();
        }

        // Existing DAM policies consult the admin guard. setUser changes only this request's guard;
        // no login(), session middleware, or session cookie is used on the remote MCP endpoint.
        $guard = $this->auth->guard('admin');
        $previous = $guard->user();
        $guard->setUser($user);
        $request->setUserResolver(fn () => $user);

        try {
            $response = $next($request);
            $this->policy->audit(event: 'mcp.request', status: $response->getStatusCode());

            return $response;
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }

    private function unauthorized(): Response
    {
        $this->policy->audit(event: 'mcp.authentication_rejected', status: 401);

        return response()->json(['error' => 'invalid_token'], 401, [
            'WWW-Authenticate' => 'Bearer resource_metadata="'.$this->policy->issuer().'/.well-known/oauth-protected-resource/mcp", scope="'.McpOAuth::SCOPE.'"',
        ]);
    }
}
