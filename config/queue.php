<?php

use Mothership\DAM\Jobs\GeneratePanoramaPreview;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'       => 'database',
            'connection'   => env('DB_QUEUE_CONNECTION'),
            'table'        => env('DB_QUEUE_TABLE', 'jobs'),
            'queue'        => env('DB_QUEUE', 'default'),
            'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'vectorization' => [
            'driver'       => 'database',
            'connection'   => null,
            'table'        => 'jobs',
            'queue'        => 'vectorization',
            // Longer than the longest job's own $timeout that runs on this connection/queue, with
            // margin: VectorizeImage is 480s, OptimizeAsset (Mothership\DAM\Jobs\OptimizeAsset) is 1020s.
            // Below the job's own timeout, the database queue driver's own "abandoned reservation"
            // window lets a second consumer of the same queue re-reserve and run a job that is still
            // legitimately in progress (round-1 review finding, 2026-09-26) — the single
            // vectorization_queue worker container (tools/studio/runtime.py) is the intended sole
            // consumer, but this still has to hold if a restart or scale-out briefly overlaps two.
            'retry_after'  => (int) (env('VECTORIZATION_QUEUE_RETRY_AFTER') ?: 1200), // blank in .env keeps the default, never 0
            'after_commit' => false,
        ],

        'ai-index' => [
            'driver'       => 'database',
            'connection'   => null,
            'table'        => 'jobs',
            'queue'        => 'ai-index',
            // Longer than AiIndexAsset::$timeout (300s) with margin, so a restart that briefly overlaps two
            // consumers never re-reserves a caption still in progress (the vectorization reasoning above).
            'retry_after'  => (int) (env('AI_INDEX_QUEUE_RETRY_AFTER') ?: 600),
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver'       => 'beanstalkd',
            'host'         => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue'        => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after'  => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for'    => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver'       => 'sqs',
            'key'          => env('AWS_ACCESS_KEY_ID'),
            'secret'       => env('AWS_SECRET_ACCESS_KEY'),
            'prefix'       => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'        => env('SQS_QUEUE', 'default'),
            'suffix'       => env('SQS_SUFFIX'),
            'region'       => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver'       => 'redis',
            'connection'   => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'        => env('REDIS_QUEUE', 'default'),
            'retry_after'  => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for'    => null,
            'after_commit' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Dedicated connection for GeneratePanoramaPreview (Task 4)
        |----------------------------------------------------------------------
        |
        | On-demand 360 preview conversions can legitimately run for hours
        | (see GeneratePanoramaPreview::TIMEOUT). Every other connection
        | above defaults retry_after to 90 seconds, which assumes a job
        | either finishes or the worker process holding it dies within
        | that window. A database/redis/beanstalkd driver re-reserves (and,
        | with the job's $tries = 1, immediately fails) any job whose
        | reservation is older than retry_after — a purely time-based check
        | at the queue-storage layer, independent of whether the original
        | worker process is still alive and actively converting. Left at
        | the framework default, a multi-hour conversion gets falsely
        | marked STATUS_FAILED at t+90s while it is still running, then
        | flips to DONE hours later when it actually finishes.
        |
        | retry_after here is derived from the job's own TIMEOUT constant
        | (plus a buffer) specifically so the two cannot drift apart again
        | by an unrelated future change to either number. Uses the dedicated
        | Redis driver to match the deployed Compose runtime. This connection
        | intentionally remains separate from the default Redis connection so
        | its long retry_after cannot be replaced by the default 90-second
        | value. If the deployed queue backend changes, update this dedicated
        | connection and its worker together.
        |
        | DEPLOY REQUIREMENT (Task 8): a worker must consume this
        | connection/queue specifically, in addition to (not instead of)
        | whatever worker(s) consume the default connection, e.g.:
        |
        |     php artisan queue:work panorama --queue=panorama --tries=1
        |
        | The job's own $timeout (21600s) governs the actual per-job kill
        | timer via Laravel's worker (it takes precedence over --timeout
        | when the job declares one), so --timeout need not be passed
        | explicitly, but the worker process must still be a *separate*
        | long-lived one — see the job class docblock.
        |
        */
        'panorama' => [
            'driver'       => 'redis',
            'connection'   => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'        => 'panorama',
            'retry_after'  => (int) env('PANORAMA_QUEUE_RETRY_AFTER', GeneratePanoramaPreview::TIMEOUT + 300),
            'block_for'    => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database'   => env('DB_CONNECTION', 'mysql'),
        'table'      => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'failed_jobs',
    ],

];
