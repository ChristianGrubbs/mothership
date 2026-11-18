<?php

namespace Mothership\AdminApi\Console;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;
use Mothership\AdminApi\Services\McpOAuth;

class McpClientCommand extends Command
{
    protected $signature = 'mcp:client {name} {--redirect=* : Exact callback URI: HTTPS from a web client, or http://localhost:PORT/... for a native client}';

    protected $description = 'Create a public MCP PKCE client (requires explicit config allowlisting)';

    public function handle(ClientRepository $clients, McpOAuth $policy): int
    {
        $redirects = $this->option('redirect');
        if (! is_array($redirects) || $redirects === []
            || ! collect($redirects)->every(fn (string $uri): bool => $policy->validRedirect($uri))) {
            $this->error('Supply exact HTTPS or fixed-port loopback callback URIs without fragments or credentials.');

            return self::FAILURE;
        }

        $client = $clients->createAuthorizationCodeGrantClient(
            name: (string) $this->argument('name'), redirectUris: $redirects, confidential: false,
        );
        $this->line('Public client ID: '.$client->getKey());
        $this->line('Add this ID to MOTHERSHIP_MCP_OAUTH_CLIENT_IDS before connecting. No client secret is issued.');

        return self::SUCCESS;
    }
}
