<?php

namespace Mothership\AdminApi\Repositories;

use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mothership\AdminApi\Services\McpOAuth;
use Mothership\User\Models\Admin;

class McpScopeRepository extends ScopeRepository
{
    public function __construct(ClientRepository $clients, private McpOAuth $policy)
    {
        parent::__construct(clients: $clients);
    }

    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        $ids = array_map(fn ($scope): string => $scope->getIdentifier(), $scopes);
        $isMcp = $this->policy->configuredClient($clientEntity->getIdentifier());

        if ($isMcp || in_array(McpOAuth::SCOPE, $ids, true)) {
            if (! $isMcp || ! $this->policy->enabled()
                || ! in_array($grantType, ['authorization_code', 'refresh_token'], true)
                || $ids !== [McpOAuth::SCOPE]
                || ! $this->policy->acceptsClient($this->clients->findActive($clientEntity->getIdentifier()))
                || ! $this->policy->acceptsUser(Admin::query()->find($userIdentifier))) {
                throw OAuthServerException::invalidScope(McpOAuth::SCOPE);
            }
        }

        return parent::finalizeScopes(scopes: $scopes, grantType: $grantType, clientEntity: $clientEntity, userIdentifier: $userIdentifier, authCodeId: $authCodeId);
    }
}
