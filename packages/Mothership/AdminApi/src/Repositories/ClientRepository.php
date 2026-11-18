<?php

namespace Mothership\AdminApi\Repositories;

use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as BaseClientRepository;
use Laravel\Passport\Passport;

class ClientRepository extends BaseClientRepository
{
    /**
     * Get a client by the given ID.
     */
    public function find($id): ?Client
    {
        // Client ids are UUIDs on every driver. Anything else names no client, and on PostgreSQL's uuid column
        // the lookup itself would throw, turning a bad client_id into a 500 instead of invalid_client.
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        $client = Passport::client();

        // Whether this client may act for the username is checked by UserRepository: refused here, a wrong
        // username read as "Client authentication failed" and sent integrators to check the secret (FAULT-13).
        return $client->where($client->getKeyName(), $id)->first();
    }

    /**
     * Get an active client by the given ID.
     */
    public function findActive($id): ?Client
    {
        $client = $this->find($id);

        return $client && ! $client->revoked ? $client : null;
    }
}
