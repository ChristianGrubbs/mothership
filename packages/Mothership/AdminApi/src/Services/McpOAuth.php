<?php

namespace Mothership\AdminApi\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Mothership\Core\PublicOrigins;
use Mothership\User\Models\Admin;

class McpOAuth
{
    public const SCOPE = 'mcp:use';

    public function enabled(): bool
    {
        return (bool) config('mcp-oauth.enabled', false)
            && $this->httpsUrl($this->canonicalIssuer());
    }

    /** The configured APP_URL issuer: the fallback for every request whose Host is not allowlisted. */
    public function canonicalIssuer(): string
    {
        return rtrim((string) config('mcp-oauth.issuer', ''), '/');
    }

    /**
     * The issuer for the current request: the allowlisted public origin whose host[:port] equals the
     * request's, else the canonical issuer. The returned string always comes from configuration, never
     * from the Host header, so an arbitrary Host can neither choose nor shape the advertised URLs.
     */
    public function issuer(): string
    {
        $request = app()->bound('request') ? app('request') : null;

        return ($request instanceof Request ? PublicOrigins::match(request: $request, origins: $this->publicOrigins()) : null)
            ?? $this->canonicalIssuer();
    }

    /**
     * Origins that may act as issuer and resource: the canonical issuer first, then each valid
     * MOTHERSHIP_MCP_OAUTH_PUBLIC_ORIGINS (default APP_PUBLIC_ORIGINS) entry that is a bare HTTPS origin.
     *
     * @return list<string>
     */
    public function publicOrigins(): array
    {
        return PublicOrigins::list(canonical: $this->canonicalIssuer(), extra: (array) config('mcp-oauth.public_origins', []));
    }

    public function resource(): string
    {
        return $this->issuer().'/mcp';
    }

    public function configuredClient(string $id): bool
    {
        return in_array($id, config('mcp-oauth.clients', []), true);
    }

    /** Which wizard provider a client id is mapped to; analytics never carries the id itself. */
    public function provider(string $id): string
    {
        $provider = array_search($id, (array) config('mcp-oauth.onboard_client_ids', []), true);

        return is_string($provider) ? $provider : 'other';
    }

    public function acceptsClient(?Client $client): bool
    {
        return $client !== null && ! $client->revoked && ! $client->confidential()
            && $this->configuredClient((string) $client->getKey())
            && $client->hasGrantType('authorization_code')
            && ! $client->hasGrantType('password')
            && ! $client->hasGrantType('client_credentials')
            && ! $client->hasGrantType('personal_access')
            && count($client->redirect_uris) > 0
            && collect($client->redirect_uris)->every(fn (string $uri): bool => $this->validRedirect($uri));
    }

    public function acceptsUser(mixed $user): bool
    {
        return $user instanceof Admin && (bool) $user->status && ! $user->isApiUser();
    }

    /** A client callback: HTTPS, or a native app's loopback listener (RFC 8252 §7.3) on a fixed, registered port. */
    public function validRedirect(string $uri): bool
    {
        if ($this->httpsUrl($uri)) {
            return true;
        }
        $parts = $this->urlParts($uri);

        // Loopback literals only. Unlike RFC 8252, the port is exact-matched too, so it must be a real listener port.
        return $parts !== null && ($parts['scheme'] ?? '') === 'http' && (int) ($parts['port'] ?? 0) > 0
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    private function httpsUrl(string $uri): bool
    {
        $parts = $this->urlParts($uri);

        return $parts !== null && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']);
    }

    /** @return ?array<string, int|string> parsed URL without a fragment or embedded credentials */
    private function urlParts(string $uri): ?array
    {
        $parts = parse_url($uri);

        return filter_var($uri, FILTER_VALIDATE_URL) !== false && is_array($parts)
            && ! isset($parts['fragment']) && ! isset($parts['user']) && ! isset($parts['pass']) ? $parts : null;
    }

    /** @param  ?string  $grant  the validated grant type of a token exchange; @param ?string $error its OAuth error code */
    public function audit(string $event, int $status, ?McpAuthorizationFailure $reason = null, ?string $grant = null, ?string $error = null): void
    {
        if (! config('mcp-oauth.logging', false)) {
            return;
        }

        // Deliberately no request parameters, identity, authorization code, or bearer bytes.
        file_put_contents(storage_path('logs/mcp-oauth.jsonl'), json_encode([
            'ts'     => now()->toIso8601String(),
            'run_id' => (string) Str::uuid(),
            'level'  => $status < 400 ? 'info' : 'warning',
            'event'  => $event,
            'loc'    => self::class,
            'ctx'    => array_filter(['status' => $status, 'reason' => $reason?->value, 'grant' => $grant, 'error' => $error], fn (mixed $value): bool => $value !== null),
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
