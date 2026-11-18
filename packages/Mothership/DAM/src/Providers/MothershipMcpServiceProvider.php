<?php

namespace Mothership\DAM\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Mothership\DAM\Mcp\MothershipServer;

/**
 * Registers the `mothership` library server for stdio only (`php artisan mcp:start mothership`). It has no web
 * route: the server is reachable only by a process started on this machine, acting as MOTHERSHIP_MCP_USER_ID.
 */
class MothershipMcpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mcp::local('mothership', MothershipServer::class);
    }
}
