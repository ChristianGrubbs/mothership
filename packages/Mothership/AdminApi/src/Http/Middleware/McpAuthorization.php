<?php

namespace Mothership\AdminApi\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Exceptions\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Mothership\AdminApi\Services\McpAuthorizationFailure;
use Mothership\AdminApi\Services\McpOAuth;
use Symfony\Component\HttpFoundation\Response;

class McpAuthorization
{
    public function __construct(private McpOAuth $policy, private ClientRepository $clients, private Factory $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->policy->enabled(), 404);
        $user = $this->auth->guard('admin')->user();
        if ($user !== null && ! $this->policy->acceptsUser($user)) {
            $this->policy->audit(event: 'oauth.authorization_rejected', status: 403, reason: McpAuthorizationFailure::UserUnavailable);
            abort(403);
        }

        if ($request->isMethod('GET')) {
            $id = $request->query('client_id');
            $client = is_string($id) ? $this->clients->findActive($id) : null;

            if (! $this->policy->acceptsClient($client)) {
                return response()->json(['error' => 'invalid_client'], 400);
            }

            // Never redirect an error until the exact registered redirect has been validated.
            if (! in_array($request->query('redirect_uri'), $client->redirect_uris, true)) {
                return response()->json(['error' => 'invalid_request'], 400);
            }
            $redirectUri = $request->query('redirect_uri');

            if ($request->query('resource') !== $this->policy->resource()) {
                return response()->json(['error' => 'invalid_target'], 400);
            }

            $challenge = $request->query('code_challenge');
            if ($request->query('response_type') !== 'code'
                || $request->query('scope') !== McpOAuth::SCOPE
                || $request->query('code_challenge_method') !== 'S256'
                || ! is_string($challenge) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
                return response()->json(['error' => 'invalid_request'], 400);
            }

            // Every connection requires visible approval, including previously authorized clients.
            $request->query->set('prompt', 'consent');
            $request->session()->put('mcpResource', $this->policy->resource());
            $request->session()->put('mcpClient', $id);
            // Retain only the validated request so a changed account can start fresh consent.
            $request->session()->put('mcpAuthorization', $request->only([
                'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'resource',
                'code_challenge_method', 'code_challenge',
            ]));
        } else {
            $serialized = $request->session()->get('authRequest');
            $pending = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => [
                AuthorizationRequest::class,
                Client::class,
                Scope::class,
                User::class,
            ]]) : null;
            $client = $pending instanceof AuthorizationRequest
                ? $this->clients->findActive($pending->getClient()->getIdentifier()) : null;
            $redirectUri = $pending instanceof AuthorizationRequest ? $pending->getRedirectUri() : null;

            $reason = match (true) {
                ! $pending instanceof AuthorizationRequest                                             => McpAuthorizationFailure::PendingMissing,
                ! $this->policy->acceptsUser($user)                                                    => McpAuthorizationFailure::UserUnavailable,
                (string) $pending->getUser()?->getIdentifier() !== (string) $user->getAuthIdentifier() => McpAuthorizationFailure::PrincipalChanged,
                $request->session()->get('mcpResource') !== $this->policy->resource()                  => McpAuthorizationFailure::ResourceChanged,
                ! $this->policy->acceptsClient($client)                                                => McpAuthorizationFailure::ClientUnavailable,
                ! is_string($redirectUri) || ! in_array($redirectUri, $client->redirect_uris, true)    => McpAuthorizationFailure::RedirectChanged,
                default                                                                                => null,
            };
            if ($reason !== null) {
                return $this->reject(request: $request, reason: $reason);
            }
        }

        try {
            $response = $next($request);
        } catch (OAuthServerException $exception) {
            // Passport renders denied consent and other OAuth errors through an exception.
            // Keep this response handling local to the MCP authorization routes.
            $response = $exception->getResponse();
        }
        $response = $this->withIssuer(response: $response, redirectUri: $redirectUri);
        if (! $request->isMethod('GET') && $response->isRedirect()) {
            $request->session()->forget('mcpAuthorization');
        }
        $this->policy->audit(event: 'oauth.authorization', status: $response->getStatusCode());

        return $response;
    }

    private function reject(Request $request, McpAuthorizationFailure $reason): Response
    {
        $parameters = $request->session()->pull('mcpAuthorization');
        $request->session()->forget(['authRequest', 'authToken', 'mcpResource', 'mcpClient']);
        $this->policy->audit(event: 'oauth.authorization_rejected', status: 400, reason: $reason);
        if ($request->expectsJson()) {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        // This link only starts a GET: every client/resource/PKCE check and visible consent runs again.
        $restart = is_array($parameters) && in_array($reason, [
            McpAuthorizationFailure::PendingMissing,
            McpAuthorizationFailure::UserUnavailable,
            McpAuthorizationFailure::PrincipalChanged,
        ], true)
            ? route('mcp.oauth.authorize', parameters: $parameters, absolute: false)
            : null;

        return response()->view('admin_api::oauth.mcp-restart', ['restart' => $restart], 400);
    }

    private function withIssuer(Response $response, string $redirectUri): Response
    {
        $location = $response->headers->get('Location');
        $prefix = $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?');
        if (! $response->isRedirect() || ! is_string($location) || ! str_starts_with($location, $prefix)) {
            return $response;
        }
        parse_str(substr($location, strlen($prefix)), $parameters);
        if (! isset($parameters['code']) && ! isset($parameters['error'])) {
            return $response;
        }

        // Preserve callback query bytes and state; emit exactly one canonical RFC 9207 issuer.
        // Registered callbacks have no fragments. This never decorates the local login redirect.
        [$base, $query] = explode('?', $location, 2);
        $parts = array_filter(explode('&', $query), fn (string $part): bool => urldecode(explode('=', $part, 2)[0]) !== 'iss');
        $parts[] = 'iss='.rawurlencode($this->policy->issuer());
        $response->headers->set('Location', $base.'?'.implode('&', $parts));

        return $response;
    }
}
