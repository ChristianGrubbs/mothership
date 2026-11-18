<?php

$local = __DIR__.'/sphereseam.local.php';
$localConfig = file_exists($local) ? require $local : [];

return array_replace_recursive([
    'enabled' => env('SPHERESEAM_ENABLED', true),
    'models'  => ['gpt_image_2', 'gpt_image_2_5'],
    // GPT Image 2.5 verified 2026-09-10: keeps the locked strips 2-3x closer than GPT Image 2.
    'default_model' => env('SPHERESEAM_HIGGSFIELD_MODEL', 'gpt_image_2_5'),
    // GPT Image 2.5 variant. "flare" held the locked strips within ~7-12 levels on both fixtures;
    // "sunburst" was marginally closer on one scene and stays opt-in until proven on a second.
    'variant' => env('SPHERESEAM_HIGGSFIELD_VARIANT', 'flare'),
    // Generation rides the shared StylePress subscription (stylepress.higgsfield.*): same work
    // account, mounted CLI session, queue and allowlist. SphereSeam holds no credential of its own.
    'storage_disk'         => env('SPHERESEAM_STORAGE_DISK', 'private'),
    // Saved exports land in a dedicated DAM folder. Resolved by name (find-or-create under Root)
    // so it works across environments; set the id explicitly to pin an existing folder. Falls back
    // to 'dam_directory_id' if resolution fails.
    'exports_directory_name' => env('SPHERESEAM_EXPORTS_DIRECTORY_NAME', 'SphereSeam-Exports'),
    'exports_directory_id'   => (int) env('SPHERESEAM_EXPORTS_DIRECTORY_ID', 0),
    'dam_directory_id'       => (int) env('SPHERESEAM_DAM_DIRECTORY_ID', env('STYLEPRESS_DAM_DIRECTORY_ID', 1)),
    // Upload ceilings in kilobytes, matching Laravel's `max:` rule. These are the REAL limits the
    // image ships (dockerfiles/php.ini: upload_max_filesize 50M, post_max_size 55M; nginx already
    // allows 200M), not the 32 MB placeholder they replaced on 2026-09-16. PHP discards a body
    // over post_max_size before Laravel sees it, so a too-large panorama used to surface as "The
    // panorama field is required", which named neither the size nor the limit. generate() posts
    // the normalized panorama and the seam bridge together, so those two share post_max_size;
    // import() posts one file. The browser pre-checks each blob against the same numbers
    // (checkUploadSize in sphereseam.js) and reports the byte size when it does not fit.
    'max_upload_kilobytes' => [
        'import'     => 49152,
        'normalized' => 34816,
        'bridge'     => 17408,
    ],
    'max_result_bytes'       => 32 * 1024 * 1024,
    'result_allowed_hosts'   => ['higgsfield.ai', 'cloudfront.net', 'amazonaws.com'],
], $localConfig);
