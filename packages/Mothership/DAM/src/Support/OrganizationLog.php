<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P4: one JSONL line per label, board, saved-search, section, sort-preference and orientation-backfill decision, so a
 * later reader reconstructs who organized what from storage/logs/dam-organization.jsonl rather than rerunning it.
 * Off unless DAM_ORGANIZATION_LOGGING is set; only ints, bools and short codes reach the sink, never names, search
 * text or definitions.
 */
final class OrganizationLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.organization_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 32)
            || (is_array($value) && count($value) <= 50 && array_filter($value, fn ($item) => ! is_int($item)) === []));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-organization', 'ctx' => $ctx];
        @file_put_contents(storage_path('logs/dam-organization.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
