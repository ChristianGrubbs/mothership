<?php

/*
 * The product event registry (P1b, §3.6.3). Every event the DAM records is declared here: its schema version, who
 * may emit it, what its subject is, and the only props it may carry, each typed. Ingest and the server emitter
 * drop anything else, and the collector's allow-list is rendered from this file, so the two cannot drift.
 *
 * Prop types: int (0 to 1e9), bool, short ([a-z0-9_.:-], 1-48), token ([A-Za-z0-9-], 8-64), term (search text,
 * at most 100 characters; moved to dam_search_terms and never stored on the event), or a list of allowed values.
 *
 * subject: asset, directory, target (the asset or folder a link points at), or null for none.
 * emitter: server or client. scopes: where a client event may come from (admin pages, share pages).
 * window: minutes within which a repeat by the same actor on the same subject is not counted again (§3.6.4).
 * terminal: the operation key shared by an operation's succeeded and failed events, so it has exactly one.
 *
 * client_context: coarse device context the collector attaches to every client event (envelope field `x`), stored
 * and exported as these two props. A value outside its list rejects the event like any other undeclared prop.
 */

$surfaces = ['library', 'modal', 'details', 'share', 'home', 'hub', 'upload', 'api', 'reports', 'transfers', 'pwa', 'other'];
// Which server path settled an upload; each path emits the same attempt and one terminal event per upload_id.
$uploadPaths = ['chunked', 'direct', 'folder', 'reupload', 'hub', 'portal', 'api'];
$filters = ['file_name', 'extension', 'file_type', 'tag', 'uploaded_by', 'stack', 'stack_key', 'unavailable', 'created_at', 'updated_at', 'property', 'media_type', 'subfolders', 'sort', 'clear'];

return [
    // Off: nothing is emitted, ingested or rendered; reports still read what was kept.
    'enabled' => (bool) env('DAM_ANALYTICS_ENABLED', true),

    // Routine lines in storage/logs/analytics.jsonl (ingest batches); failures are always written.
    'log_enabled' => (bool) env('DAM_ANALYTICS_LOG', false),

    // Explicit opt-in. The capture token is injected from Keychain by the deployment environment, never sent to browsers.
    'posthog' => [
        'enabled' => (bool) env('DAM_POSTHOG_ENABLED', false),
        'host'    => env('DAM_POSTHOG_HOST', 'https://us.i.posthog.com'),
        'key'     => env('DAM_POSTHOG_PROJECT_TOKEN'),
        // The commit the image was built from, when it names one (config/app.php build_commit), so each deploy
        // gets its own release with no setting to change. DAM_ANALYTICS_RELEASE names the release anywhere else.
        'release' => preg_match('/\A[0-9a-f]{40}\z/', (string) env('MOTHERSHIP_BUILD_COMMIT')) === 1
            ? env('MOTHERSHIP_BUILD_COMMIT')
            : env('DAM_ANALYTICS_RELEASE'),
    ],

    // Explicit operator-authorized browser replay. This public ingestion identifier is separate configuration;
    // never fall back to the server exporter token or expose a personal API key.
    'replay' => [
        'enabled'    => (bool) env('DAM_POSTHOG_REPLAY_ENABLED', false),
        'public_key' => env('DAM_POSTHOG_REPLAY_PUBLIC_KEY'),
    ],

    // Admin ids whose exported events carry internal_actor=true (operator, agents, QA accounts), so PostHog's
    // test-account filter can exclude them: persons are disabled, so a cohort cannot match. Deployment config only.
    'internal_admin_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('DAM_ANALYTICS_INTERNAL_ADMIN_IDS', ''))))),

    // Admin ids whose share links and upload requests are QA probes: their visitors are exported as internal too.
    // Links an API account creates always are. Not the operator's id: the operator's links reach real people.
    'internal_link_owner_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('DAM_ANALYTICS_INTERNAL_LINK_OWNER_IDS', ''))))),

    'retention' => [
        'raw_days'          => 90,
        'rollup_months'     => 25,
        'search_terms_days' => 30,
    ],

    // §3.6.7: a term, or an actor count in a role slice, is shown only at this many distinct actors.
    'k' => 3,

    'ingest' => [
        'token_ttl_minutes'    => 120,
        // An admin page's token is renewed by the ingest response once less than this is left.
        'token_renew_minutes'  => 30,
        'max_events'           => 25,
        'max_bytes'            => 16384,
        'clock_past_hours'     => 24,
        'clock_future_minutes' => 5,
        'per_minute'           => 20,
    ],

    'collector' => [
        'flush_seconds' => 10,
        'flush_at'      => 25,
        'queue_cap'     => 200,
        'per_minute'    => 60,
        'vitals_sample' => 0.1,
    ],

    'surfaces' => $surfaces,

    // Phone/tablet from a coarse primary pointer and the shorter screen side; viewport is CSS-pixel width bands.
    'client_context' => [
        'device_class'   => ['phone', 'tablet', 'desktop'],
        'viewport_width' => ['lt640', '640-1023', '1024-1439', '1440-1919', 'ge1920'],
    ],

    'events' => [
        ...array_fill_keys(['transfer.file.attempted', 'transfer.file.acknowledged', 'transfer.file.received', 'transfer.file.failed', 'transfer.file.cancelled', 'transfer.file.declined'], [
            'v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
                'transfer_id' => 'short', 'direction' => ['send', 'receive'], 'files' => 'int',
                'error_code'  => ['disconnected', 'connection', 'cancelled', 'declined'],
            ],
        ]),
        ...array_fill_keys(['pwa.installable', 'pwa.install.prompted', 'pwa.install.choice', 'pwa.installed', 'pwa.standalone.opened', 'pwa.update.ready'], [
            'v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
                'choice' => ['accepted', 'dismissed'],
            ],
        ]),
        ...array_fill_keys(['hub.open.confirmed', 'hub.open.failed', 'hub.save.attempted', 'hub.save.succeeded', 'hub.save.failed', 'hub.save.unchanged'], [
            'v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
                'app_id' => 'short', 'hub_operation_id' => 'short', 'mode' => ['new', 'version', 'stack'], 'error_code' => 'short',
            ],
        ]),
        'home.feature.exposed' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'widget_id' => 'short', 'cols' => 'int', 'rows' => 'int', 'visible_ms' => 'int',
        ]],
        'home.feature.activated' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'widget_id' => 'short', 'action' => ['open', 'resume', 'navigate', 'interact'], 'input' => ['pointer', 'keyboard'], 'launch_id' => 'short',
        ]],
        ...array_fill_keys(['home.launch.attempted', 'home.launch.loaded', 'home.launch.slow', 'home.launch.abandoned', 'home.launch.failed'], [
            'v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
                'widget_id' => 'short', 'launch_id' => 'short', 'phase' => ['opening', 'loaded', 'ready', 'timeout', 'abandoned', 'error'], 'error_code' => 'short',
            ],
        ]),
        // Server-side outcomes.
        'asset.preview.served' => ['v' => 1, 'emitter' => 'server', 'subject' => 'asset', 'window' => 30, 'props' => []],
        // A target: one file, or the folder a link's zip was made from; a zip of a selection has no subject.
        'asset.download.served' => ['v' => 1, 'emitter' => 'server', 'subject' => 'target', 'window' => 10, 'props' => [
            'via'   => ['direct', 'zip', 'compressed', 'custom', 'alternate'],
            'files' => 'int',
        ]],
        'upload.file.attempted' => ['v' => 1, 'emitter' => 'server', 'subject' => 'directory', 'props' => [
            'upload_id' => 'short', 'attempt' => 'int', 'path' => $uploadPaths,
        ]],
        'upload.file.succeeded' => ['v' => 1, 'emitter' => 'server', 'subject' => 'directory', 'terminal' => 'upload.file', 'props' => [
            'upload_id' => 'short', 'path' => $uploadPaths,
        ]],
        'upload.file.failed' => ['v' => 1, 'emitter' => 'server', 'subject' => 'directory', 'terminal' => 'upload.file', 'props' => [
            'upload_id' => 'short', 'path' => $uploadPaths, 'error_code' => 'short',
        ]],
        'share.link.created' => ['v' => 1, 'emitter' => 'server', 'subject' => 'target', 'props' => [
            'share_type' => ['asset', 'directory', 'collection'], 'expires' => 'bool',
        ]],
        'share.link.revoked' => ['v' => 1, 'emitter' => 'server', 'subject' => 'target', 'props' => [
            'share_type' => ['asset', 'directory', 'collection'],
        ]],
        'share.link.visited' => ['v' => 1, 'emitter' => 'server', 'subject' => 'target', 'window' => 30, 'props' => [
            'share_type' => ['asset', 'directory', 'collection'], 'view_token' => 'token',
        ]],
        'search.served' => ['v' => 1, 'emitter' => 'server', 'subject' => null, 'props' => [
            'result_count' => 'int', 'smart' => 'bool', 'similar' => 'bool', 'scope' => ['all', 'directory'], 'filtered' => 'bool',
        ]],
        'processing.optimization.succeeded' => ['v' => 1, 'emitter' => 'server', 'subject' => 'asset', 'terminal' => 'processing.optimization', 'props' => [
            'job_id' => 'short', 'kind' => 'short',
        ]],
        'processing.optimization.failed' => ['v' => 1, 'emitter' => 'server', 'subject' => 'asset', 'terminal' => 'processing.optimization', 'props' => [
            'job_id' => 'short', 'kind' => 'short', 'error_code' => 'short',
        ]],
        'processing.vectorization.succeeded' => ['v' => 1, 'emitter' => 'server', 'subject' => 'asset', 'terminal' => 'processing.vectorization', 'props' => [
            'job_id' => 'short',
        ]],
        'processing.vectorization.failed' => ['v' => 1, 'emitter' => 'server', 'subject' => 'asset', 'terminal' => 'processing.vectorization', 'props' => [
            'job_id' => 'short', 'error_code' => 'short',
        ]],

        // Client interactions (the collector). Nothing here carries text, a URL or a DOM node.
        // One per document load, admin SPA visit or back-forward-cache restore: the denominator for every route.
        // A link page's carries its visit's view_token: that is what moves the link's view count (ShareRepository).
        'route.viewed' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin', 'share'], 'subject' => null, 'props' => [
            'route' => 'short', 'navigation' => ['document', 'spa', 'restore'], 'view_token' => 'token',
        ]],
        'asset.preview.opened' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin', 'share'], 'subject' => 'asset', 'window' => 30, 'props' => [
            'view_token' => 'token', 'visible_ms' => 'int',
        ]],
        'search.submitted' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'q_norm' => 'term', 'words' => 'int', 'result_count' => 'int', 'smart' => 'bool',
        ]],
        'search.result.opened' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => 'asset', 'props' => [
            'position' => 'int', 'smart' => 'bool',
        ]],
        'filter.applied' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'filter' => $filters,
        ]],
        // The Craft connect wizard: one connect_id per page visit joins its steps, status checks and completion.
        // Leaving is not an event (a tab switch to the client is the normal path): opened steps without completion are.
        ...array_fill_keys(['hub.connect.step.opened', 'hub.connect.status.checked', 'hub.connect.status.failed', 'hub.connect.completed'], [
            'v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
                'connect_id'   => 'token', 'provider' => ['claude', 'work'], 'step' => 'int', 'action' => 'int', 'setup' => ['shared', 'new'],
                'active_grant' => 'bool', 'service_enabled' => 'bool', 'worker' => ['responded', 'unavailable', 'disabled'], 'apps' => 'int',
                'error_code'   => 'short',
            ],
        ]),
        // Craft connector token exchanges: refresh_token grants are a lasting connection, authorization_code a new consent.
        ...array_fill_keys(['craft.oauth.token.issued', 'craft.oauth.token.failed'], [
            'v' => 1, 'emitter' => 'server', 'subject' => null, 'props' => [
                'grant_type' => ['authorization_code', 'refresh_token'], 'provider' => ['claude', 'chatgpt', 'other'],
                'status'     => 'int', 'error_code' => 'short',
            ],
        ]),
        'modal.tab.opened' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => 'asset', 'props' => [
            'tab' => 'short',
        ]],
        'upload.dialog.opened'    => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => []],
        'upload.dialog.abandoned' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'files' => 'int',
        ]],
        'share.dialog.opened' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'share_type' => ['asset', 'directory'],
        ]],
        'share.dialog.abandoned' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin'], 'subject' => null, 'props' => [
            'share_type' => ['asset', 'directory'],
        ]],
        'client.error.raised' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin', 'share'], 'subject' => null, 'props' => [
            'kind'        => ['error', 'rejection'], 'route' => 'short',
            'bundle'      => 'bundle', 'line' => 'int', 'column' => 'int',
            'source_kind' => ['bundle', 'document'],
            'error_type'  => ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'URIError', 'EvalError', 'AggregateError',
                'DOMException', 'AbortError', 'NetworkError', 'InvalidStateError', 'NotAllowedError', 'SecurityError',
                'DataError', 'QuotaExceededError', 'NotSupportedError', 'TimeoutError'],
        ]],
        'client.web_vitals.sampled' => ['v' => 1, 'emitter' => 'client', 'scopes' => ['admin', 'share'], 'subject' => null, 'props' => [
            'metric'          => ['LCP', 'CLS', 'INP', 'FCP', 'TTFB'], 'value' => 'int', 'route' => 'short',
            'metric_id'       => 'short', 'metric_revision' => 'int', 'measurement' => ['web-vitals-6.2.3'],
            'navigation_type' => ['navigate', 'reload', 'back-forward', 'back-forward-cache', 'prerender', 'restore'],
        ]],
    ],
];
