<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'The Mothership'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Build Commit
    |--------------------------------------------------------------------------
    |
    | The full git commit the running code was built from. The Studio images bake
    | it in (dockerfiles/fpm.Dockerfile ARG MOTHERSHIP_BUILD_COMMIT) and CI sets it
    | per run, so deploy checks, acceptance runs and screenshots can name the exact
    | commit they observed. It is published read-only in the
    | `mothership-build-commit` meta tag of the admin and sign-in layouts.
    |
    */

    'build_commit' => env('MOTHERSHIP_BUILD_COMMIT', 'unknown'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    */

    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Admin URL
    |--------------------------------------------------------------------------
    */

    'admin_url' => env('APP_ADMIN_URL', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Debug Allowed IPs
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of IPs that can see the debug bar. When set,
    | debug bar is hidden from all other IPs even if APP_DEBUG is true.
    |
    */

    'debug_allowed_ips' => env('APP_DEBUG_ALLOWED_IPS'),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed by the "api" named limiter, keyed by the
    | authenticated user when there is one and by client IP otherwise.
    |
    */

    'api_rate_limit' => (int) env('API_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of hostnames accepted in the Host header in addition
    | to APP_URL and its subdomains. Any other host is rejected with a 400,
    | which is what keeps Host / X-Forwarded-Host poisoning out.
    |
    */

    'trusted_hosts' => env('TRUSTED_HOSTS'),

    /*
    |--------------------------------------------------------------------------
    | Public Origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated bare HTTPS origins The Mothership also serves on, besides
    | APP_URL (e.g. https://mothership.example.org,https://mothership.example.net). A web
    | request on one of these hosts builds its URLs, redirects and
    | config('app.url') from that origin; any other host, and all console,
    | queue and scheduler work, stays on APP_URL. Unset: APP_URL only. Each
    | host must also be admitted by TRUSTED_HOSTS. MCP OAuth uses the
    | same list unless MOTHERSHIP_MCP_OAUTH_PUBLIC_ORIGINS is set.
    |
    */

    'public_origins' => env('APP_PUBLIC_ORIGINS'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of proxy IPs (or CIDRs) allowed to set X-Forwarded-*
    | headers. Use '*' only when the edge already strips those headers from
    | public traffic.
    |
    | Read from a booting callback in bootstrap/app.php: TrustProxies takes a
    | value rather than a closure, and the middleware configuration callback
    | runs before the environment and the config files are loaded, so anything
    | read there would always fall back to the default below.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    */

    'locale' => env('APP_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    */

    'fallback_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Base Currency Code
    |--------------------------------------------------------------------------
    */

    'currency' => env('APP_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Default channel Code
    |--------------------------------------------------------------------------
    */

    'channel' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Previous Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of retired APP_KEY values. Payloads encrypted with
    | them (sessions, cookies, signed URLs) still decrypt, so APP_KEY can be
    | rotated without logging every admin out or bricking stored ciphertext.
    |
    */

    'previous_keys' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
    ), strlen(...))),

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
