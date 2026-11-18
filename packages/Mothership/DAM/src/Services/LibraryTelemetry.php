<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

class LibraryTelemetry
{
    /** Diagnostics contain structural counts and scope only, never search text or names. */
    public static function record(string $event, array $ctx): void
    {
        if (! config('dam.library_log_enabled')) {
            return;
        }

        $request = request();
        $runId = $request->attributes->get('library_run_id') ?? (string) Str::uuid();
        $request->attributes->set('library_run_id', $runId);
        $record = [
            'ts'    => now()->toIso8601String(), 'run_id' => $runId, 'level' => 'debug',
            'event' => $event, 'loc' => 'dam.library',
            'ctx'   => RequestId::ctx(array_intersect_key($ctx, array_flip(['scope', 'media_type', 'directory_id', 'page', 'total_assets', 'has_search']))),
        ];
        @file_put_contents(storage_path('logs/library.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
