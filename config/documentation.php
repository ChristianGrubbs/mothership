<?php

return [
    'name'        => env('DOCUMENTATION_PRODUCT_NAME', 'The Mothership'),
    'root'        => base_path('docs/manual'),
    'log_enabled' => (bool) env('DOCUMENTATION_LOG_ENABLED', false),
];
