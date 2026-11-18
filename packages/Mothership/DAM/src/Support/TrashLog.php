<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P2 S4: one JSONL line per trash, restore and purge decision, so a later reader reconstructs what went to the trash,
 * what came back and what was deleted for good. Off unless DAM_TRASH_LOGGING is set; only ints, bools and short
 * reason strings reach the sink, never names or paths.
 */
final class TrashLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.trash_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 32));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-trash', 'ctx' => $ctx];
        file_put_contents(storage_path('logs/dam-trash.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
