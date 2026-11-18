<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * P6 D26: one JSONL line per embed and preview-proxy decision (made, revoked, refused, retry queued or skipped), so a
 * later reader reconstructs who embedded what and why a retry did or did not run. Off unless DAM_VIDEO_LOGGING is
 * set; only ints, bools and short codes reach the sink, never a token, an origin, a name or a path.
 */
final class VideoLog
{
    private static ?string $runId = null;

    public static function write(string $event, array $ctx, string $level = 'info'): void
    {
        if (! config('dam.video_logging', false)) {
            return;
        }

        $ctx = array_filter($ctx, fn ($value) => is_int($value) || is_bool($value) || (is_string($value) && strlen($value) <= 32));
        $record = ['ts' => now()->toIso8601String(), 'run_id' => self::$runId ??= (string) Str::uuid(), 'level' => $level,
            'event'     => $event, 'loc' => 'dam-video', 'ctx' => $ctx];
        file_put_contents(storage_path('logs/dam-video.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
