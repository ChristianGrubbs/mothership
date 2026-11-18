<?php

return [
    'sections' => [],

    'cta' => null,

    'version_check' => [
        // Packagist metadata URL to compare releases against. Unset (the default) skips the network check.
        'packagist'       => env('MOTHERSHIP_VERSION_CHECK_URL'),
        'cache_hours'     => 12,
        'fallback_latest' => null,
    ],

    'is_cloud' => (bool) env('MOTHERSHIP_CLOUD', false),

    'banners' => [],
];
