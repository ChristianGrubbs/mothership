<?php

namespace App\Documentation;

use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

final class DocumentationTelemetry
{
    private static ?string $runId = null;

    public static function record(string $event, array $ctx = []): void
    {
        if (! config('documentation.log_enabled', false)) {
            return;
        }

        self::$runId ??= (string) Str::uuid();
        $allowed = array_intersect_key($ctx, array_flip(['slug', 'count', 'status']));
        $line = [
            'ts'     => now()->toIso8601String(),
            'run_id' => self::$runId,
            'level'  => 'info',
            'event'  => $event,
            'loc'    => 'documentation',
            'ctx'    => RequestId::ctx($allowed),
        ];

        file_put_contents(storage_path('logs/documentation.jsonl'), json_encode($line, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
