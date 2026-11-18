<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P3b-1: one JSONL line per merge, split, finalize, link revoke and refusal, so a later reader reconstructs which
 * sources went into which destination, what came back and what was refused. Off unless DAM_MERGE_LOGGING is set;
 * only ints, bools, short reason strings and lists of ints reach the sink, never names, paths or tokens.
 */
final class MergeLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.merge_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value)
            || (is_string($value) && strlen($value) <= 32)
            || (is_array($value) && array_is_list($value) && array_filter($value, fn ($item) => ! is_int($item)) === []));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-merge', 'ctx' => $ctx];
        file_put_contents(storage_path('logs/dam-merge.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
