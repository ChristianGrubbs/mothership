<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P3b-2: one JSONL line per exact-duplicate check, Stack instead, stack suggestion build, dismissal and Stack them,
 * so a later reader can reconstruct what was offered and what was done. Off unless DAM_DUPLICATE_LOGGING is set;
 * only ints, bools and short reason strings reach the sink, never names, paths, keys or checksums.
 */
final class DuplicateLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.duplicate_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 32));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-duplicates', 'ctx' => $ctx];
        file_put_contents(storage_path('logs/dam-duplicates.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
