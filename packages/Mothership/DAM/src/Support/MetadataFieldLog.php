<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P4a: one JSONL line per field definition change, value write and adopt run, so a later reader reconstructs which
 * fields changed, which assets were written and what an adopt run linked. Off unless DAM_METADATA_FIELD_LOGGING is
 * set; only ints, bools, short strings (field codes, types, reasons) and int lists reach the sink, never values.
 */
final class MetadataFieldLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.metadata_field_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value)
            || (is_string($value) && strlen($value) <= 64)
            || (is_array($value) && array_is_list($value) && count($value) <= 100
                && array_filter($value, fn ($item) => ! is_int($item) && ! (is_string($item) && strlen($item) <= 64)) === []));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-metadata-fields', 'ctx' => $ctx];
        file_put_contents(storage_path('logs/dam-metadata-fields.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
