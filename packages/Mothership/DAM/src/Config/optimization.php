<?php

return [
    // A null api hides the Optimize action entirely (Optimizer::ready()).
    'api'             => env('MOTHERSHIP_OPTIMIZER_API'),
    'connect_timeout' => 5,
    'timeout'         => 960,
    // Seconds a job may sit in `queued` before the UI says nothing has picked it up (usually: no worker or
    // bridge running). Display only; the expiry sweep still settles the row after 30 minutes.
    'queued_notice_after' => 120,
    // Matches the host service's CLOP_MAX_BYTES default (docs/ops/studio-clop.md).
    // Parent of each request's private staging directory; null uses the system temp directory.
    'staging_dir'     => null,
    'max_bytes'       => (int) (env('MOTHERSHIP_OPTIMIZER_MAX_BYTES') ?: 2 * 1024 * 1024 * 1024), // blank keeps the cap on
];
