<?php

return [
    'enabled'        => env('MOTHERSHIP_VECTORIZATION_ENABLED', false),
    'owner_admin_id' => env('MOTHERSHIP_VECTORIZATION_OWNER_ADMIN_ID'),
    'api_id'         => env('MOTHERSHIP_VECTORIZER_API_ID'),
    'api_secret'     => env('MOTHERSHIP_VECTORIZER_API_SECRET'),
    'base_url'       => 'https://api.vectorizer.ai/api/v1',
];
