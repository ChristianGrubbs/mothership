<?php

return [
    'enabled' => env('STYLEPRESS_ENABLED', true),

    // Use the configured Higgsfield account and API key pair.
    // Do not commit real keys. Set these in .env or the deployment secret store.
    'higgsfield' => [
        'driver'           => env('STYLEPRESS_HIGGSFIELD_DRIVER', 'api'),
        'cli_binary'       => env('STYLEPRESS_HIGGSFIELD_CLI_BINARY', '/usr/local/bin/higgsfield'),
        'cli_config_home'  => env('STYLEPRESS_HIGGSFIELD_CLI_HOME', '/var/lib/mothership'),
        'account_email'    => env('STYLEPRESS_HIGGSFIELD_ACCOUNT_EMAIL'),
        'workspace_id'     => env('STYLEPRESS_HIGGSFIELD_WORKSPACE_ID'),
        'api_base_url'     => env('STYLEPRESS_HIGGSFIELD_API_BASE_URL', 'https://api.higgsfield.ai'),
        'api_key_id'       => env('STYLEPRESS_HIGGSFIELD_KEY_ID'),
        'api_key_secret'   => env('STYLEPRESS_HIGGSFIELD_KEY_SECRET'),
        'allowed_hosts'    => ['api.higgsfield.ai'],
        'estimate_prefix'  => env('STYLEPRESS_HIGGSFIELD_ESTIMATE_PREFIX', '/estimate'),
        'balance_endpoint' => env('STYLEPRESS_HIGGSFIELD_BALANCE_ENDPOINT'),
        'default_model'    => env('STYLEPRESS_HIGGSFIELD_MODEL', '/nano-banana'),
    ],

    'access' => [
        // Empty = any admin with the stylepress ACL permission. Fill for shared-work-account restriction.
        'allowed_emails'   => array_filter(array_map('trim', explode(',', (string) env('STYLEPRESS_ALLOWED_EMAILS', '')))),
        'allowed_user_ids' => array_filter(array_map('trim', explode(',', (string) env('STYLEPRESS_ALLOWED_USER_IDS', '')))),
    ],

    // Text model for the saved asset title; empty = the Magic AI default model, then the platform's first text model.
    'title_model' => env('STYLEPRESS_TITLE_MODEL'),

    // Prompt enhancement: 'groq' uses the fast Groq models below and falls back to the shared Higgsfield
    // enhancer on failure; 'higgsfield' keeps the previous behaviour. Groq needs STYLEPRESS_GROQ_API_KEY.
    'enhance' => [
        'driver'   => env('STYLEPRESS_ENHANCE_DRIVER', 'higgsfield'),
        'fallback' => env('STYLEPRESS_ENHANCE_FALLBACK', true),
    ],
    'groq' => [
        'api_key'      => env('STYLEPRESS_GROQ_API_KEY'),
        'base_url'     => env('STYLEPRESS_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'text_model'   => env('STYLEPRESS_GROQ_TEXT_MODEL', 'openai/gpt-oss-120b'),
        'vision_model' => env('STYLEPRESS_GROQ_VISION_MODEL', 'qwen/qwen3.8-27b'),
        'timeout'      => (int) env('STYLEPRESS_GROQ_TIMEOUT', 40),
    ],

    'catalog_path' => __DIR__.'/../Resources/widgets/stylepress/assets/stylepress-catalog.json',
];
