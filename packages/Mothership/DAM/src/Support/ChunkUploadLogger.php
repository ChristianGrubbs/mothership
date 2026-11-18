<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

final class ChunkUploadLogger
{
    private const FORBIDDEN_KEYS = [
        'api_key',
        'contents',
        'cookie',
        'filename',
        'headers',
        'passwd',
        'password',
        'path',
        'secret',
        'token',
    ];

    public function write(
        string $runId,
        string $level,
        string $event,
        string $loc,
        array $ctx,
        bool $verbose = false,
    ): void {
        if ($verbose && ! config('dam.explorer.upload.verbose_log')) {
            return;
        }

        $logPath = storage_path('logs/chunk-uploads.jsonl');
        $directory = dirname($logPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $record = [
            'ts'     => now()->toISOString(),
            'run_id' => $runId,
            'level'  => $level,
            'event'  => $event,
            'loc'    => $loc,
            'ctx'    => RequestId::ctx($this->redact($ctx)),
        ];

        file_put_contents(
            $logPath,
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    private function redact(array $context): array
    {
        $redacted = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }
}
