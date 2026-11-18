<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P2 S5a: one JSONL line per decision of the operator-run share commands (`dam:shares:hash-tokens`,
 * `dam:shares:review-expiry`), so a later reader can reconstruct what a deploy-time run converted, scrubbed or
 * changed. P2 S5b adds each password attempt on a public link (`share.password.success|failure|lockout`, by
 * share id only). Always on: the commands run by hand and change production data, and the attempts are the
 * evidence of guessing. Only ints, bools and short strings reach the sink, never a token, a password or a name.
 */
final class ShareLog
{
    public const PATH = 'logs/dam-shares.jsonl';

    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 32));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-shares', 'ctx' => $ctx];
        file_put_contents(storage_path(self::PATH), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
