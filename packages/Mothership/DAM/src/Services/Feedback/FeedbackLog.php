<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Feedback;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

/**
 * Feedback decisions as JSONL in storage/logs/feedback.jsonl, written only with DAM_FEEDBACK_LOG. Only the keys below
 * reach the sink, as ints, bools or short strings: identifiers and outcomes, never titles, descriptions or context.
 */
final class FeedbackLog
{
    public const PATH = 'logs/feedback.jsonl';

    private const CTX = ['report_id', 'type', 'screenshot', 'size', 'from', 'to', 'visible', 'reason', 'count', 'error_class'];

    private static ?string $runId = null;

    public static function write(string $event, array $ctx = [], string $level = 'debug'): void
    {
        if (! config('dam.feedback.log')) {
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
                    'loc'    => 'feedback',
                    'ctx'    => RequestId::ctx($ctx),
                ], JSON_THROW_ON_ERROR).PHP_EOL,
                lock: true,
            );
        } catch (\Throwable) {
            // Diagnostics never change the outcome of a report.
        }
    }

    /** The deployed build a report names: the image's MOTHERSHIP_BUILD_COMMIT, else the package version. */
    public static function build(): string
    {
        $sha = trim((string) config('app.build_commit'));

        return $sha !== '' && $sha !== 'unknown' ? mb_substr($sha, 0, 40) : (string) core()->version();
    }
}
