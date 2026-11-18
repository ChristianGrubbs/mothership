<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * storage/logs/analytics.jsonl (P1b): a write that failed, a batch ingest dropped events from, and each rollup,
 * prune or forget run. Names, ids, counts and reason codes only: never a search term, a prop value, an IP or a
 * token. Failures always; routine lines (ingest batches) only with DAM_ANALYTICS_LOG on.
 */
final class AnalyticsLog
{
    /** @param array<string, int|bool|string|null> $ctx */
    public static function write(string $level, string $event, array $ctx, ?string $runId = null): void
    {
        if ($level === 'debug' && ! config('dam_events.log_enabled')) {
            return;
        }

        $record = ['ts' => now()->toIso8601String(), 'run_id' => $runId ?? (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam.analytics', 'ctx' => RequestId::ctx($ctx)];

        // A diagnostics write never fails the request, job or command it describes.
        @file_put_contents(storage_path('logs/analytics.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
