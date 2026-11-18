<?php

return [
    // Off until the remote mode (Mcp::web('/mcp')) ships; the local `mothership` stdio server needs no OAuth.
    'enabled'               => false,
    // Client ids are public identifiers. Provision with mcp:client and explicitly allow here.
    'clients'               => array_values(array_filter(explode(',', (string) env('MOTHERSHIP_MCP_OAUTH_CLIENT_IDS', '')))),
    'onboard_client_ids'    => [
        'claude'  => env('MOTHERSHIP_MCP_OAUTH_CLAUDE_CLIENT_ID'),
        'chatgpt' => env('MOTHERSHIP_MCP_OAUTH_CHATGPT_CLIENT_ID'),
    ],
    'issuer'               => rtrim((string) env('APP_URL', ''), '/'),
    // Extra public HTTPS origins (comma-separated) that act as their own issuer/resource when the request
    // arrives on that host. Defaults to APP_PUBLIC_ORIGINS; unset both for APP_URL only.
    'public_origins'       => array_values(array_filter(array_map('trim', explode(',', (string) env('MOTHERSHIP_MCP_OAUTH_PUBLIC_ORIGINS', env('APP_PUBLIC_ORIGINS', '')))))),
    'logging'              => (bool) env('MOTHERSHIP_MCP_OAUTH_LOGGING', false),
    'client_ip_rate_limit' => 600,
    'grant_rate_limit'     => 10,
];
