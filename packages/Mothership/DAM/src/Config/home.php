<?php

return [
    // 'paid' => true marks a widget that spends shared paid credits (StylePress, SphereSeam) or a metered per-user
    // allowance (Image Vectorization). A custom role gets it only by an explicit tick, never by default: see
    // HomeWidgetRegistry::defaultWidgetIds().
    'widgets' => [
        'recent'           => ['title' => 'Recent files', 'description' => 'The latest additions to your library.', 'icon' => 'files', 'kind' => 'native', 'sizes' => ['wide', 'large'], 'size' => 'wide', 'permission' => 'dam.assets', 'settings' => ['limit' => 12]],
        'favorites'        => ['title' => 'Favorites', 'description' => 'Assets you starred for quick access.', 'icon' => 'favorite', 'kind' => 'native', 'sizes' => ['small', 'wide', 'large'], 'size' => 'wide', 'permission' => 'dam.assets', 'settings' => ['count' => 6]],
        'storage'          => ['title' => 'Storage', 'description' => 'Available space on your asset volume.', 'icon' => 'storage', 'kind' => 'native', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'dam.assets', 'settings' => []],
        'totals'           => ['title' => 'Your library', 'description' => 'Files and folders you can access.', 'icon' => 'library', 'kind' => 'native', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'dam.assets', 'settings' => []],
        'links'            => ['title' => 'Quick links', 'description' => 'Your shortcuts to the tools you use.', 'icon' => 'links', 'kind' => 'native', 'sizes' => ['small', 'wide'], 'size' => 'small', 'settings' => ['links' => []]],
        'rss'              => ['title' => 'Signal', 'description' => 'The newest stories from your feeds.', 'icon' => 'rss', 'kind' => 'tool', 'sizes' => ['small', 'wide', 'large'], 'size' => 'wide', 'settings' => ['feed_id' => null, 'count' => 5]],
        'trending'         => ['title' => 'Trending on GitHub', 'description' => 'Repositories trending today, with a daily archive.', 'icon' => 'trending', 'kind' => 'native', 'sizes' => ['small', 'wide', 'large'], 'size' => 'wide', 'settings' => []],
        'stylepress'       => ['title' => 'StylePress', 'paid' => true, 'description' => 'Explore house styles and create something new.', 'icon' => 'stylepress', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'dam.assets', 'entry' => 'index.html', 'settings' => []],
        'looper'           => ['title' => 'Video Loop Refiner', 'description' => 'Find the smoothest cut for a seamless loop.', 'icon' => 'looper', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'dam.assets', 'entry' => 'index.html', 'settings' => []],
        // 'requires_config' names a config value that must be set for the tool to exist at all: Video Downloader
        // needs the Studio's native video service (docs/ops/studio-video.md) and is left off Home without it.
        'video-downloader' => ['title' => 'Video Downloader', 'description' => 'Save a video from a link to your device.', 'icon' => 'video-download', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'requires_config' => 'dam-home.video_api', 'entry' => 'index.html', 'settings' => []],
        'loop-maker'       => ['title' => 'Loop Maker', 'description' => 'Shape audio into a clean, seamless loop.', 'icon' => 'audio-loop', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'always_available' => true, 'entry' => 'index.html', 'settings' => []],
        'palette-studio'   => ['title' => 'Palette Studio', 'description' => 'Build, check and export colour palettes.', 'icon' => 'palette', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'always_available' => true, 'entry' => 'index.html', 'settings' => []],
        'sound-effects'    => ['title' => 'Sound Effect Maker', 'description' => 'Make short sound effects and save them as WAV files.', 'icon' => 'sound-effects', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'always_available' => true, 'entry' => 'index.html', 'settings' => []],
        'vectorization'    => ['title' => 'Image Vectorization', 'paid' => true, 'description' => 'Create vectors for Adobe and AI tools.', 'icon' => 'vectorization', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'dam.assets', 'entry' => 'index.html', 'settings' => []],
        'plaque'           => ['title' => 'Plaque Maker', 'description' => 'Animated overlay plaques with sound, ready to export.', 'icon' => 'plaque', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'always_available' => true, 'entry' => 'index.html', 'settings' => []],
        'sphereseam'       => ['title' => 'SphereSeam', 'paid' => true, 'description' => 'Repair the wrap seam of 360° panoramas.', 'icon' => 'sphereseam', 'kind' => 'tool', 'sizes' => ['small', 'wide'], 'size' => 'small', 'permission' => 'sphereseam', 'entry' => 'index.html', 'settings' => []],
        'calendar'         => ['title' => 'Calendar', 'description' => 'Events, games and campaigns, with their tasks and assets.', 'icon' => 'calendar', 'kind' => 'native', 'sizes' => ['wide', 'large'], 'size' => 'large', 'rows' => 4, 'permission' => 'dam.assets', 'settings' => []],
        'system-quickview' => ['title' => 'Studio & people', 'description' => 'Live load on the host Mac and who is online now.', 'icon' => 'system', 'kind' => 'native', 'sizes' => ['wide', 'large'], 'size' => 'wide', 'always_available' => true, 'settings' => []],
    ],
    // Accounts whose SIGNAL announcement strip is on until they choose otherwise (SignalAlerts). Every other account
    // is off until it opts in. An entry matches only when the account's id AND its email (trimmed, lower-cased) both
    // equal the pair, so a reused id or a changed email never inherits the default. MOTHERSHIP_SIGNAL_DEFAULT_ON is a
    // comma-separated list of id:email pairs; the example account the demos and sample data sign in as is always on.
    'signal_default_on' => [
        ['id' => 1, 'email' => 'admin@mothership.local'],
        ...array_values(array_filter(array_map(
            static function (string $pair): ?array {
                [$id, $email] = array_pad(explode(':', trim($pair), 2), 2, '');

                return ctype_digit($id) && $email !== '' ? ['id' => (int) $id, 'email' => $email] : null;
            },
            explode(',', (string) env('MOTHERSHIP_SIGNAL_DEFAULT_ON', ''))
        ))),
    ],
    'rss_origin'   => env('MOTHERSHIP_HOME_RSS_ORIGIN'),
    // Signal feed adapter base URL. No built-in address: unset answers the Signal and Trending widgets with their
    // existing "service unavailable" state.
    'rss_api'      => env('MOTHERSHIP_HOME_RSS_API'),
    'trending_api' => env('MOTHERSHIP_HOME_TRENDING_API', env('MOTHERSHIP_HOME_RSS_API')),
    // Icon library (Iconvault) base URL. No built-in address: unset hides the Icon Library card, link and dialog.
    'icon_library_url' => env('MOTHERSHIP_ICON_LIBRARY_URL'),
    'looper_api'       => env('MOTHERSHIP_HOME_LOOPER_API', 'http://host.docker.internal:3457'),
    // The Studio's native video download service. Unset (the default) leaves Video Downloader off Home.
    'video_api'    => env('MOTHERSHIP_HOME_VIDEO_API'),
    'log_enabled'  => env('MOTHERSHIP_HOME_LOG_ENABLED', false),

    // Named Redis connection (config/database.php `redis.*`) that ephemeral presence tracking writes to.
    'presence_connection' => env('MOTHERSHIP_HOME_PRESENCE_CONNECTION', 'default'),

    // A library store on another machine. A host process writes its free and total bytes to a capacity file
    // (storage/app/private/umbrel-capacity.json); a file older than this reads as the store being unreachable.
    'store_capacity' => [
        'label'           => 'Umbrel',
        'max_age_minutes' => 15,
    ],

    // The centre of Home (docs/features/customizable-home.md).
    'summary' => [
        // Opens of Home closer together than this are one visit, so a refresh does not clear what is new.
        'visit_gap_minutes'   => 30,
        // Files from one producer and person this close together are one landing.
        'landing_gap_minutes' => 10,
        // How many of the newest files the board groups. Past this, `truncated` tells Home the board is partial.
        'landing_scan_limit'  => 1000,
        'board_rows'          => 5,
        // Cargo: how many landings Home lists, and how many pictures each one shows.
        'cargo_rows'          => 8,
        'cargo_pictures'      => 6,
        // A share link counts as needing attention this many days before it expires.
        'share_warning_days'  => 7,
        // Failures and expired requests stay on Needs you for this long.
        'attention_days'      => 7,
        // An upload silent for this long is abandoned, and leaves the board.
        'stowing_stale_hours' => 24,
    ],
];
