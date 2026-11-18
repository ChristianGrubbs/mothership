<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P4 D14: one JSONL line per related-assets ranking (signal candidate counts, timing), so a later reader
 * reconstructs what happened without re-running it. Off unless DAM_RELATED_LOGGING is set; only ints, bools and short
 * strings reach the sink, never tag names, file names or paths.
 */
final class RelatedLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam-related.logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || is_float($value) || (is_string($value) && strlen($value) <= 32)
            || (is_array($value) && array_filter($value, fn ($item) => ! is_int($item) && ! is_float($item)) === []));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-related', 'ctx' => $ctx];
        file_put_contents(storage_path('logs/dam-related.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
