<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

/**
 * Creative Hub decisions as JSONL in storage/logs/creative-hub.jsonl, written only with DAM_HUB_LOG. Only the keys
 * below reach the sink, and only as ints, bools or short strings (identifiers and outcomes, never names, paths or
 * file contents). One run_id per process, so a later reader groups the lines of one run.
 */
final class CreativeHubLog
{
    public const PATH = 'logs/creative-hub.jsonl';

    private const CTX = [
        'asset_id', 'source_asset_id', 'app', 'app_version', 'mode', 'status', 'reason', 'size', 'ext',
        'version', 'count', 'dropped', 'source', 'duration_ms', 'error_class',
    ];

    private static ?string $runId = null;

    public static function write(string $event, array $ctx = [], string $level = 'debug'): void
    {
        if (! config('dam.hub.log')) {
            return;
        }

        try {
            $ctx = array_filter(
                array_intersect_key($ctx, array_flip(self::CTX)),
                fn (mixed $value): bool => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 64),
            );

            File::append(
                path: storage_path(self::PATH),
                data: json_encode([
                    'ts'     => now()->toIso8601String(),
                    'run_id' => self::$runId ??= (string) Str::uuid(),
                    'level'  => $level,
                    'event'  => $event,
                    'loc'    => 'creative-hub',
                    'ctx'    => RequestId::ctx($ctx),
                ], JSON_THROW_ON_ERROR).PHP_EOL,
                lock: true,
            );
        } catch (\Throwable) {
            // Diagnostics never change the outcome of a hub request.
        }
    }
}
