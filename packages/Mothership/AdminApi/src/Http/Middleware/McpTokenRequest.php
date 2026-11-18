<?php

namespace Mothership\AdminApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Services\McpOAuth;
use Mothership\AdminApi\Services\McpTokenExchanged;
use Symfony\Component\HttpFoundation\Response;

class McpTokenRequest
{
    public function __construct(private McpOAuth $policy, private ClientRepository $clients) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->input('client_id', $request->getUser());
        $scope = $request->input('scope', '');
        $isMcp = is_string($id) && $this->policy->configuredClient($id);

        if (! $isMcp) {
            if (is_string($scope) && in_array(McpOAuth::SCOPE, explode(' ', $scope), true)) {
                return response()->json(['error' => 'invalid_scope'], 400);
            }

            return $next($request);
        }

        if (! $this->policy->enabled() || ! $this->policy->acceptsClient($this->clients->findActive($id))) {
            return response()->json(['error' => 'invalid_client'], 401);
        }

        if ($request->input('resource') !== $this->policy->resource()) {
            return response()->json(['error' => 'invalid_target'], 400);
        }

        $grant = $request->input('grant_type');
        if (! in_array($grant, ['authorization_code', 'refresh_token'], true)) {
            return response()->json(['error' => 'unauthorized_client'], 400);
        }

        $request->attributes->remove(McpTokenExchanged::USER_ATTRIBUTE);
        $response = $next($request);
        $status = $response->getStatusCode();
        $error = $status === 200 ? null : $this->oauthError(response: $response);
        $this->policy->audit(event: 'oauth.exchange', status: $status, grant: $grant, error: $error);
        $user = $request->attributes->get(McpTokenExchanged::USER_ATTRIBUTE);
        event(new McpTokenExchanged(
            grantType: $grant, provider: $this->policy->provider(id: $id), status: $status,
            userId: $status === 200 && is_numeric($user) ? (int) $user : null, error: $error,
        ));

        return $response;
    }

    /** Only the bounded OAuth error code (invalid_grant, ...): never the description, hint or any input. */
    private function oauthError(Response $response): string
    {
        $error = json_decode((string) $response->getContent(), true)['error'] ?? null;

        return is_string($error) && preg_match('/\A[a-z_]{1,48}\z/', $error) === 1 ? $error : 'unknown';
    }
}
