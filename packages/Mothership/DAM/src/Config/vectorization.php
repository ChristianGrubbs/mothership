<?php

return [
    'enabled'             => env('MOTHERSHIP_VECTORIZATION_ENABLED', false),
    // The human owner: unlimited, and the account `dam:vectorizations:check` requires to be active.
    'owner_admin_id'      => env('MOTHERSHIP_VECTORIZATION_OWNER_ADMIN_ID'),
    // Further unlimited accounts, comma-separated admin ids (for example the agent API account). The owner is
    // unlimited whether or not it is listed here.
    'unlimited_admin_ids' => array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string) env('MOTHERSHIP_VECTORIZATION_UNLIMITED_ADMIN_IDS', ''))),
        fn (int $id): bool => $id > 0,
    ))),
    'api_id'              => env('MOTHERSHIP_VECTORIZER_API_ID'),
    'api_secret'          => env('MOTHERSHIP_VECTORIZER_API_SECRET'),
    'base_url'            => 'https://api.vectorizer.ai/api/v1',
];
