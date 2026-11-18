<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * storage/logs/security-events.jsonl: a write the recorder could not make, and each retention or prune run.
 * Ids, counts and exception classes only; never an identifier, an IP or a message that could carry one.
 */
final class SecurityEventLog
{
    /** @param array<string, int|bool|string|null> $ctx */
    public static function write(string $level, string $event, array $ctx, ?string $runId = null): void
    {
        $record = ['ts' => now()->toIso8601String(), 'run_id' => $runId ?? (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'security-events', 'ctx' => RequestId::ctx($ctx)];

        // A diagnostics write never fails the login, request or prune it describes.
        @file_put_contents(storage_path('logs/security-events.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
