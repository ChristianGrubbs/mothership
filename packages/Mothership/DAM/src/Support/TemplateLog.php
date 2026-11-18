<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P8′ (D25): one JSONL line per template decision (published, asset created, slot refused with its reason code),
 * so a later reader goes from an asset back to the template version and the fill that made it without re-running
 * anything. Off unless DAM_TEMPLATE_LOGGING is set. Only ids, counts and short codes reach the sink: never a slot's
 * value, a file name or bytes.
 */
final class TemplateLog
{
    public const PATH = 'logs/dam-template.jsonl';

    public static function write(string $event, array $ctx, string $level = 'info', string $loc = 'dam-template'): void
    {
        if (! config('dam.template_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || is_float($value) || (is_string($value) && strlen($value) <= 64));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => RequestId::current() ?? (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => $loc, 'ctx' => $ctx];
        // Diagnostics never fail a fill on a full disk.
        @file_put_contents(storage_path(self::PATH), json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
