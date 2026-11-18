<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P5b (D18, D19): one JSONL line per mapping or rule evaluation, action write, refusal and loop-guard skip, so a
 * later reader walks from a run log row to why it was written or refused without re-running anything. Off unless
 * DAM_AUTOMATION_LOGGING is set. Only ints, bools, short codes and int lists reach the sink: never tag names, field
 * values, file names, exif values or paths.
 */
final class AutomationLog
{
    public const PATH = 'logs/dam-automation.jsonl';

    /** The run's correlation id while one evaluation is being worked on; every line in between carries it. */
    private static ?string $runId = null;

    public static function within(?string $runId): void
    {
        self::$runId = $runId;
    }

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.automation_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || is_float($value)
            || (is_string($value) && strlen($value) <= 64)
            || (is_array($value) && array_is_list($value) && count($value) <= 200 && array_filter($value, fn ($item) => ! is_int($item)) === []));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ?? (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-automation', 'ctx' => RequestId::ctx($ctx)];
        // Diagnostics never make a run fail on a full disk.
        @file_put_contents(storage_path(self::PATH), json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
