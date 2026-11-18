<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PIM Surface Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the inherited PIM surface (catalog, products, categories,
    | attributes, completeness, data transfer, measurement, AI agents).
    | When disabled, PIM menu items are hidden and PIM admin routes return 403.
    |
    */

    'pim_enabled' => env('MOTHERSHIP_PIM_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | PIM Menu Keys
    |--------------------------------------------------------------------------
    |
    | Menu keys (and their descendants) that belong to the PIM surface and
    | are filtered from the admin menu tree when pim_enabled is false.
    |
    */

    'pim_menu_keys' => [
        'dashboard',
        'catalog',
        'data_transfer',
        'ai-agent',
    ],

    /*
    |--------------------------------------------------------------------------
    | Accelerated Media Delivery
    |--------------------------------------------------------------------------
    |
    | When set (e.g. "/__dam_media/"), authorized DAM file responses are handed to
    | the web server with X-Accel-Redirect instead of being streamed through PHP.
    | The value must match an `internal` nginx location whose alias is the root of
    | the local asset disk. Leave null on setups without that location.
    |
    */

    'media_accel_prefix' => env('MOTHERSHIP_MEDIA_ACCEL_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | Video Preview Proxy
    |--------------------------------------------------------------------------
    |
    | Playback prefers a 1080p H.264 proxy over an oversized master (ADR 0003).
    | `encoder` is the ffmpeg video encoder used to produce it — set this to
    | h264_nvenc on a host with an NVIDIA GPU to make the transcode far cheaper.
    | `crf` sets quality for libx264 (the default; lower is better); h264_nvenc
    | does not support -crf, so the job maps this same value onto NVENC's own
    | quality control, `-rc vbr -cq <crf>`, instead.
    | `enabled` is the master switch for generating new proxies; turning it off
    | never hides proxies that already exist.
    |
    */

    'video_preview' => [
        'enabled'    => (bool) env('MOTHERSHIP_VIDEO_PREVIEW_ENABLED', true),
        'encoder'    => env('MOTHERSHIP_VIDEO_PREVIEW_ENCODER', 'libx264'),
        'max_width'  => 1920,
        'crf'        => 23,
        'max_rate'   => '6M',
        'buf_size'   => '12M',
        'audio_rate' => '128k',
    ],

    /*
    |--------------------------------------------------------------------------
    | On-Demand 360 Preview Processing
    |--------------------------------------------------------------------------
    |
    | Master switch for on-demand 360/OSV preview conversion job creation.
    | When disabled, PanoramaPreviewService::request() aborts with 409 —
    | direct viewing, existing proxies, and state inspection are unaffected.
    |
    */

    'panorama_processing_enabled' => env('MOTHERSHIP_360_PROCESSING_ENABLED', false),
];
