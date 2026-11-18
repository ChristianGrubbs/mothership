<?php

namespace Mothership\DAM\Traits;

/**
 * The REST form of bouncer()->hasPermission() for a check inside an action (P7): whether the calling key holds an
 * api-acl permission, as the api.scope middleware asks per route. bouncer() reads only the admin guard, so it is
 * always false on the REST API.
 */
trait ApiKeyPermission
{
    protected function apiKeyAllows(string $permission): bool
    {
        $user = auth()->guard('api')->user();
        $key = $user?->apiKey;

        if ($key === null || $key->revoked || ! $user->status) {
            return false;
        }

        return $key->permission_type === 'all' || (bool) $key->hasPermission($permission);
    }
}
