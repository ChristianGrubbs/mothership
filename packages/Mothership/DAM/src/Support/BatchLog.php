<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P6a: one JSONL line per batch decision, item state change and tool run, so a later reader walks from a failed
 * item to its cause (roadmap §3.6.11, P6 version: batch.item.failed → the dam_batch_items row → its rendition →
 * these lines) without re-running anything. Off unless DAM_BATCH_LOGGING is set. Only ints, bools and short codes
 * reach the sink: never names, paths, commands, tool output or file contents.
 */
final class BatchLog
{
    public const PATH = 'logs/dam-batch.jsonl';

    /** The batch's correlation id while one is being worked on; every line in between carries it. */
    private static ?string $runId = null;

    public static function within(?string $runId): void
    {
        self::$runId = $runId;
    }

    public static function write(string $event, array $ctx, string $level = 'info', string $loc = 'dam-batch'): void
    {
        if (! config('dam.batch_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || is_float($value) || (is_string($value) && strlen($value) <= 64));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ?? (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => $loc, 'ctx' => $ctx];
        // Diagnostics never make a batch fail on a full disk.
        @file_put_contents(storage_path(self::PATH), json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
