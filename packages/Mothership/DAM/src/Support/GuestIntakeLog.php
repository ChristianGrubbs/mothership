<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P5a: storage/logs/dam-guest-intake.jsonl, one line per decision of the guest upload path (a portal opened, a visit
 * refused, a limit hit, a submission created, a file published, accepted or rejected), so a later reader can rebuild
 * a visit from the log without rerunning it. Ids, counts, codes and short strings only: never a token, a password, a
 * file name, a guest's name or email, or an IP. Always on: guest traffic is the one unauthenticated write path.
 */
final class GuestIntakeLog
{
    public const PATH = 'logs/dam-guest-intake.jsonl';

    private static ?string $runId = null;

    /** @param array<string, int|bool|string|null> $ctx */
    public static function write(string $event, array $ctx, string $level = 'info', string $loc = 'dam-guest-intake'): void
    {
        $ctx = array_filter($ctx, fn ($value) => $value === null || is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 48));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => $loc, 'ctx' => RequestId::ctx($ctx)];

        // A diagnostics write never fails the guest's upload or the staff action it describes.
        @file_put_contents(storage_path(self::PATH), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
