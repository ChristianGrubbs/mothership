<?php

declare(strict_types=1);

return [
    /*
     * Where asset bytes live (ADR 0017): `private` (the local disk, the default) or `s3` (any S3-compatible
     * store configured on the `s3` disk: AWS, R2, MinIO, Garage). Kept apart from FILESYSTEM_DISK, which other
     * code (logos, avatars, Storage::url() in views) reads as its own default. Unset falls back to the older
     * rule: `s3` only when FILESYSTEM_DISK is `s3`.
     */
    'asset_disk' => env('MOTHERSHIP_ASSET_DISK'),

    /*
     * With a remote asset disk, chunked uploads stage on the local `mothership-staging` disk, and each
     * assembled upload stays there as a warm copy for the first preview jobs for this many hours (ADR 0017).
     */
    'staging' => [
        'keep_hours' => (int) env('MOTHERSHIP_STAGING_KEEP_HOURS', 6),
    ],

    'library_log_enabled' => env('MOTHERSHIP_LIBRARY_LOG_ENABLED', false),

    'access_logging' => env('DAM_ACCESS_LOGGING', false),

    // P2 S4: trash, restore and purge decisions to storage/logs/dam-trash.jsonl (ids and counts only).
    'trash_logging' => env('DAM_TRASH_LOGGING', false),

    // P3b-1: merge, split, finalize and link-revoke decisions to storage/logs/dam-merge.jsonl (ids and counts only).
    'merge_logging' => env('DAM_MERGE_LOGGING', false),

    // P3b-2: JSONL decision log for exact-duplicate notices and stack suggestions (storage/logs/dam-duplicates.jsonl).
    'duplicate_logging' => env('DAM_DUPLICATE_LOGGING', false),

    /**
     * P5c (D16): near-duplicate images by perceptual hash (Services/Similarity). `threshold` is the most bits two
     * 64-bit dHashes may differ by and still be a pair (0-11, see DHash). 4 because, on the 148 StylePress images,
     * every re-encode and resize stayed within 3 bits and only 5 of 10,871 distinct pairs fell within 4. `receipt_inline_max` is how many files of one
     * upload are hashed on the spot so the upload receipt can name a near-duplicate; the rest are hashed by the
     * queued job and appear on the Similar images page. `thumbnail_max_bytes` is the largest original PHP reads to
     * make the hashed thumbnail; a larger one is skipped as `too_large`. 64 MiB because the read, Intervention's
     * copy of it and GD's decoded pixels (below 32 MP, at about 5 bytes a pixel plus a copy) share the worker's 512 MB.
     */
    'similar' => [
        'enabled'             => (bool) env('DAM_SIMILAR_ENABLED', true),
        'threshold'           => (int) env('DAM_SIMILAR_THRESHOLD', 4),
        'receipt_inline_max'  => (int) env('DAM_SIMILAR_RECEIPT_INLINE_MAX', 20),
        'thumbnail_max_bytes' => (int) env('DAM_SIMILAR_THUMBNAIL_MAX_BYTES', 67_108_864),
    ],

    // P4a: field definition changes, value writes and adopt runs to storage/logs/dam-metadata-fields.jsonl (ids,
    // codes and counts only, never values).
    'metadata_field_logging' => env('DAM_METADATA_FIELD_LOGGING', false),

    // P4: label, board, saved-search, section, sort and orientation-backfill decisions to
    // storage/logs/dam-organization.jsonl (ids, counts and codes only).
    'organization_logging' => env('DAM_ORGANIZATION_LOGGING', false),

    // P4 (D11): offers the Library's "Popularity" sort. Every score reads 0 until the P1b usage analytics exist
    // (roadmap §3.6.10), so the order is the newest-first tie-break; nothing invents a number meanwhile.
    'popularity_sort_enabled' => (bool) env('DAM_POPULARITY_SORT_ENABLED', false),

    // P6a batch processing: one JSONL line per batch decision, item state change and tool exit, in
    // storage/logs/dam-batch.jsonl (Support/BatchLog). Off by default.
    'batch_logging' => env('DAM_BATCH_LOGGING', false),

    /**
     * P6a batch processing (Processing/*). Items run one job each on the panorama connection, whose
     * retry_after (6 h) exceeds the item timeout, on the video-preview queue its worker already consumes;
     * a long recipe on a 90-second connection would be re-reserved and run twice. When the app's default
     * connection is sync (tests, a bare local checkout), items run inline instead.
     */
    'batch' => [
        'max_items'    => (int) env('DAM_BATCH_MAX_ITEMS', 200),
        'connection'   => env('DAM_BATCH_QUEUE_CONNECTION', 'panorama'),
        'queue'        => env('DAM_BATCH_QUEUE', 'video-preview'),
        // Per tool run; the item job's own timeout (ProcessBatchItem::TIMEOUT) leaves it a margin.
        'tool_timeout' => (int) env('DAM_BATCH_TOOL_TIMEOUT', 1500),
        'binaries'     => [
            'ffmpeg'  => env('DAM_BATCH_FFMPEG', 'ffmpeg'),
            'ffprobe' => env('DAM_BATCH_FFPROBE', 'ffprobe'),
            'vips'    => env('DAM_BATCH_VIPS', 'vips'),
        ],
    ],

    // P5b (D18, D19): mapping and rule evaluations, action writes, refusals and loop-guard skips to
    // storage/logs/dam-automation.jsonl (ids, counts and short codes only, never values or names). Off by default.
    'automation_logging' => env('DAM_AUTOMATION_LOGGING', false),

    // P5b: how many assets one queued mapping or rule chunk evaluates, and the most a synchronous dry run reads.
    'automation' => [
        'chunk'         => (int) env('DAM_AUTOMATION_CHUNK', 200),
        'dry_run_limit' => (int) env('DAM_AUTOMATION_DRY_RUN_LIMIT', 500),
    ],

    // P6 D26: embed and preview-proxy retry decisions to storage/logs/dam-video.jsonl (ids and short codes only).
    'video_logging' => env('DAM_VIDEO_LOGGING', false),
    // P4 D2: collection member, audience, slug and visit decisions to storage/logs/dam-collections.jsonl (ids only).
    'collection_logging' => env('DAM_COLLECTION_LOGGING', false),

    // P8′ templates: template published, asset created from a template and slot refusals (reason codes), by id, to
    // storage/logs/dam-template.jsonl (Support/TemplateLog). Off by default.
    'template_logging' => env('DAM_TEMPLATE_LOGGING', false),

    'templates' => [
        // The font text slots render with on the server (Liberation Sans, SIL OFL, shipped with the pdf.js build).
        'font' => env('DAM_TEMPLATE_FONT', dirname(__DIR__, 2).'/publishable/dam/build/pdfjs/standard_fonts/LiberationSans-Regular.ttf'),
    ],

    // Whether a list write that reaches AccessListWriter without the DAM_ACCESS_MODEL row held throws
    // instead of logging `access_fence_missing` and taking the hold late (ADR 0010's fence). Null leaves
    // the default: strict in tests, where a missed hold is a writer to fix, and lenient in production,
    // where a lost list write is worse than a late lock.
    'access_fence_strict' => env('DAM_ACCESS_FENCE_STRICT'),

    // ContentStore always records unavailable content to storage/logs/content-store.jsonl; this adds one
    // debug record per staged or delivered read.
    'content_store' => [
        'verbose_log' => env('DAM_CONTENT_STORE_VERBOSE_LOG', false),

        /*
         * Same-origin delivery of a blob on a remote asset disk (ADR 0017). When set (`/__dam_remote` in the
         * shipped nginx configs), PHP authorizes the request and hands nginx the blob's short-lived signed URL
         * with X-Accel-Redirect, and nginx streams it from the store. The browser is never redirected to the
         * store, which may sit on a private network, and the bytes never pass through PHP. Unset keeps the
         * redirect.
         */
        'remote_accel_prefix' => env('MOTHERSHIP_REMOTE_ACCEL_PREFIX'),
    ],

    // P7 review notifications: one record per notification decision (who was asked, who could see the asset, who
    // was notified) in storage/logs/dam-review.jsonl. Ids and counts only, never comment text.
    'review' => [
        'log' => env('DAM_REVIEW_LOG', false),
    ],

    'tree' => [
        'show_assets' => env('DAM_TREE_SHOW_ASSETS', false),
    ],

    'explorer' => [
        'enabled'           => env('DAM_EXPLORER_ENABLED', false),
        'bookmarks_enabled' => env('DAM_EXPLORER_BOOKMARKS_ENABLED', false),
        'show_tree'         => env('DAM_EXPLORER_SHOW_TREE', true),

        'upload' => [
            'concurrency'   => (int) env('DAM_UPLOAD_CONCURRENCY', 3),
            'max_file_bytes'=> (int) env('DAM_UPLOAD_MAX_FILE_BYTES', 21_474_836_480),
            'chunk_bytes'   => (int) env('DAM_UPLOAD_CHUNK_BYTES', 33_554_432),
            'stale_hours'   => (int) env('DAM_UPLOAD_STALE_HOURS', 12),
            'retry_attempts'=> (int) env('DAM_UPLOAD_RETRY_ATTEMPTS', 3),
            'verbose_log'   => env('DAM_UPLOAD_VERBOSE_LOG', false),

            // Finalize hashing (2026-09-26 perf fix): a part at most this size is read whole and verified
            // with openssl_digest() (hardware SHA-256); a larger one streams through hash_update() as before,
            // so memory stays bounded when chunk_bytes is configured large.
            'part_digest_in_memory_max_bytes' => (int) env('DAM_UPLOAD_PART_DIGEST_IN_MEMORY_MAX_BYTES', 67_108_864),
            // The assembled file is hashed once, natively, only once it is at least this size; a smaller
            // file falls back to hash_file() straight away, since spawning sha256_binary would not pay for itself.
            'native_sha256_min_bytes'         => (int) env('DAM_UPLOAD_NATIVE_SHA256_MIN_BYTES', 67_108_864),
            // The coreutils binary Sha256File shells out to for a file at or above the threshold above; an
            // empty string disables the native path (hash_file() always runs instead).
            'sha256_binary'                   => (string) env('DAM_UPLOAD_SHA256_BINARY', 'sha256sum'),
            // FU: a dropped or picked folder may be at most this many folders deep, and make at most this many
            // folders in one upload (FolderUploadPaths); over either, nothing is created.
            'folder_max_depth'                => (int) env('DAM_UPLOAD_FOLDER_MAX_DEPTH', 32),
            'folder_max_count'                => (int) env('DAM_UPLOAD_FOLDER_MAX_COUNT', 2000),
        ],
    ],

    /*
     * P5a (D1): guest upload requests (`/u/{token}`).
     * - max_files_cap: the highest `max_files` a request may set.
     * - visit_hours: how long one portal visit (its cookie) lasts.
     * - identity_retention_days: dam:upload-requests:prune clears a submission's self-reported name and email after
     *   this many days (§3.6.7; the raw-event window Q1 set).
     * - rate: requests a minute per IP and per request link, for the portal's page and control calls and, separately,
     *   for its chunk uploads.
     */
    'upload_requests' => [
        'max_files_cap'           => (int) env('DAM_UPLOAD_REQUEST_MAX_FILES_CAP', 1000),
        'visit_hours'             => (int) env('DAM_UPLOAD_REQUEST_VISIT_HOURS', 12),
        'identity_retention_days' => (int) env('DAM_UPLOAD_REQUEST_IDENTITY_DAYS', 90),
        'rate'                    => [
            'portal_per_ip'      => (int) env('DAM_UPLOAD_PORTAL_PER_IP', 60),
            'portal_per_request' => (int) env('DAM_UPLOAD_PORTAL_PER_REQUEST', 300),
            'chunk_per_ip'       => (int) env('DAM_UPLOAD_PORTAL_CHUNKS_PER_IP', 600),
            'chunk_per_request'  => (int) env('DAM_UPLOAD_PORTAL_CHUNKS_PER_REQUEST', 3000),
        ],
    ],

    /**
     * Limits applied to an export archive uploaded to an import job. These are far wider
     * than the product-images equivalent because a DAM bundle legitimately carries video
     * and other large binaries; they exist to bound zip-bomb damage, not to size assets.
     */
    'import_bundle' => [
        'max_entry_size'        => (int) env('DAM_IMPORT_BUNDLE_MAX_ENTRY_SIZE', 524288000),
        'max_total_size'        => (int) env('DAM_IMPORT_BUNDLE_MAX_TOTAL_SIZE', 5368709120),
        'max_entries'           => (int) env('DAM_IMPORT_BUNDLE_MAX_ENTRIES', 50000),
        'max_compression_ratio' => (float) env('DAM_IMPORT_BUNDLE_MAX_COMPRESSION_RATIO', 200),
    ],

    /**
     * Web pages (ADR 0007): HTML bundles unpacked once per version and served only through the
     * sandboxed page routes. max_bundle_size caps the unpacked bytes of one bundle.
     */
    'web_pages' => [
        'enabled'         => (bool) env('DAM_WEB_PAGES_ENABLED', true),
        'max_bundle_size' => (int) env('DAM_WEB_PAGES_MAX_BUNDLE_SIZE', 536870912),
        'max_entries'     => (int) env('DAM_WEB_PAGES_MAX_ENTRIES', 5000),
        // ADR 0008: collections nest at most this many levels (a root collection is level 1).
        'max_collection_depth' => (int) env('DAM_WEB_PAGES_MAX_COLLECTION_DEPTH', 10),
        // Upload here files every page into this one root-level Library folder (created on first
        // use) instead of offering the content folders.
        'upload_directory' => (string) env('DAM_WEB_PAGES_UPLOAD_DIRECTORY', 'Web Pages'),
    ],

    /*
     * Feedback & bug reports: every signed-in person can report a bug or an
     * idea from any page; admins with dam.feedback.manage triage them. Off until the production deploy turns it on.
     */
    'feedback' => [
        'enabled'              => (bool) env('DAM_FEEDBACK_ENABLED', false),
        'screenshot_max_bytes' => 8 * 1024 * 1024,
        'screenshot_max_px'    => 8000,
        'context_max_bytes'    => 32 * 1024,
        'request_max_bytes'    => 9 * 1024 * 1024,
        'per_10_minutes'       => 10,
        'per_day'              => 50,
        'log'                  => (bool) env('DAM_FEEDBACK_LOG', false),
    ],

    // Transfers has its own origin and signaling process; see docs/features/transfers.md.
    'transfers' => [
        'enabled'    => (bool) env('DAM_TRANSFERS_ENABLED', false),
        'origin'     => rtrim((string) env('DAM_TRANSFERS_ORIGIN', ''), '/'),
        'shared_key' => (string) env('DAM_TRANSFERS_SHARED_KEY', ''),
        'logging'    => (bool) env('DAM_TRANSFERS_LOGGING', false),
    ],

    /*
     * What is left of the Creative Hub (ADR 0012) now its in-app workspace is gone (2026-11-17): `enabled` gates the
     * Fonts & brushes manager (ADR 0015), `log` the Craft save log (CreativeHubLog), `resources` the font and brush caps.
     */
    'hub' => [
        'enabled'                => (bool) env('DAM_HUB_ENABLED', false),
        'log'                    => (bool) env('DAM_HUB_LOG', false),
        // Fonts and brushes people upload for the hub apps (ADR 0015). Sizes in bytes. Operator decision 2026-10-19: one
        // byte quota per person and no file-count limit. Each of a person's resources counts at its full size, even when
        // another row shares its blob.
        'resources'              => [
            'font_max_bytes'          => (int) env('DAM_HUB_RESOURCE_FONT_MAX_BYTES', 20 * 1024 * 1024),
            'brush_max_bytes'         => (int) env('DAM_HUB_RESOURCE_BRUSH_MAX_BYTES', 50 * 1024 * 1024),
            'per_user_max_bytes'      => (int) env('DAM_HUB_RESOURCE_MAX_BYTES', 5 * 1024 * 1024 * 1024),
            // The child process that parses a font: its wall-clock budget and its PHP memory limit.
            'inspect_timeout_seconds' => 10,
            'inspect_memory_limit'    => '128M',
        ],
    ],

];
