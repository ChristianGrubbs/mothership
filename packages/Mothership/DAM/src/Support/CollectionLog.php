<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P4 D2: one JSONL line per collection decision (create, update, delete, member add, remove and reorder, audience
 * change, slug change, link, and each visit through a collection), so a later reader reconstructs who could reach
 * which members and when without rerunning anything. Off unless DAM_COLLECTION_LOGGING is set; only ints, bools and
 * short strings (event outcomes, never names, slugs, tokens or paths) reach the sink.
 */
final class CollectionLog
{
    public const PATH = 'logs/dam-collections.jsonl';

    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.collection_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 32)
            || (is_array($value) && count($value) <= 500 && array_filter($value, 'is_int') === $value));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-collections', 'ctx' => $ctx];
        file_put_contents(storage_path(self::PATH), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
