<?php

return [
    // Base URL of the Studio AI bridge (docs/ops/studio-ai.md). Null disables captioning and the semantic leg.
    'api'                 => env('MOTHERSHIP_AI_API'),
    'index_enabled'       => (bool) env('MOTHERSHIP_AI_INDEX_ENABLED', false),
    'search_enabled'      => (bool) env('MOTHERSHIP_SMART_SEARCH_ENABLED', false),
    // `available` means the bridge answered /health within the last health_ttl seconds, not only that it is configured.
    'health_check'        => (bool) env('MOTHERSHIP_AI_HEALTH_CHECK', true),
    'health_ttl'          => 30,
    'connect_timeout'     => 3,
    'caption_timeout'     => 150,
    'embed_timeout'       => 40,
    // Rename with AI: one text-only call per file, answered inside a single web request (Cloudflare cuts at 100 s).
    'name_timeout'        => 80,
    // JSONL at storage/logs/ai-rename.jsonl: ids, counts and flags per suggestion.
    'rename_log'          => (bool) env('MOTHERSHIP_AI_RENAME_LOG', false),
    'query_embed_timeout' => 2,
    // is_numeric: a blank value keeps the default, an explicit 0 is honoured. At 0.42 a query with no real match still
    // filled a page ('graduation', 'basketball' topped out at 0.48-0.49 on the Studio corpus, 30 results each), while
    // real matches top 0.53-0.79; 0.50 drops the former to none and keeps the latter (2026-10-13).
    'min_cosine'          => is_numeric(env('MOTHERSHIP_SMART_SEARCH_MIN_COSINE')) ? (float) env('MOTHERSHIP_SMART_SEARCH_MIN_COSINE') : 0.50,
    // Meaning matches must also score within this of the best one, and at most semantic_limit are kept.
    'semantic_window'     => is_numeric(env('MOTHERSHIP_SMART_SEARCH_WINDOW')) ? (float) env('MOTHERSHIP_SMART_SEARCH_WINDOW') : 0.12,
    'semantic_limit'      => 30,
    // Typo tolerance (pg_trgm word_similarity). At 0.5 a three-letter word matched any word sharing its first two
    // letters ('map' -> 377 files via 'mascot' and the like, 2026-10-01); 0.6 keeps one-letter typos.
    'trigram_threshold'   => 0.6,
    'rrf_k'               => 60,
    // auto = Postgres FTS + trigram on pgsql, LIKE elsewhere; like = LIKE everywhere (tests, fallback).
    'lexical'             => env('MOTHERSHIP_SMART_SEARCH_LEXICAL', 'auto'),
    'max_pdf_pages'       => 50,
    'image_edge'          => 1024,
    // Larger rasters are scaled by ffmpeg, out of process: GD needs ~5 bytes a pixel plus a copy, and the worker has 512 MB.
    'gd_max_pixels'       => 16_000_000,
    'similar_limit'       => 30,
];
