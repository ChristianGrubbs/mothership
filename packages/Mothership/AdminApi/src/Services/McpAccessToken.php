<?php

namespace Mothership\AdminApi\Services;

use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;

class McpAccessToken extends AccessToken
{
    use AccessTokenTrait { toString as private passportToString; }

    public function toString(): string
    {
        $scopes = array_map(fn ($scope): string => $scope->getIdentifier(), $this->getScopes());

        if (! in_array(McpOAuth::SCOPE, $scopes, true)) {
            return $this->passportToString();
        }

        // Keep Passport's client audience first: its ResourceServer maps aud[0] to oauth_client_id.
        // The second, signed audience is checked at the MCP boundary. It is the allowlisted origin this token
        // request arrived on, which McpTokenRequest already required as the exact `resource`; never a raw Host.
        $this->initJwtConfiguration();

        return $this->jwtConfiguration->builder()
            ->permittedFor($this->getClient()->getIdentifier())
            ->permittedFor(app(McpOAuth::class)->resource())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable)
            ->canOnlyBeUsedAfter(new DateTimeImmutable)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }
}
