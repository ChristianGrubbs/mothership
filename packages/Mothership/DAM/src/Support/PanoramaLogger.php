<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Str;

/**
 * The single JSONL sink for on-demand 360 preview logging (Tasks 3 and 4).
 * Every line is bare JSON — no Monolog/LineFormatter prefix — so any
 * consumer (Task 6's CLI, Task 8's runtime gate, a human with `jq`) can
 * `json.loads()`/`json_decode()` each line directly.
 *
 * run_id correlation: a caller that is emitting several events about the
 * same logical operation (a job run, a request lifecycle tied to one
 * rendition) must pass the *same* $runId to every write() call for that
 * operation. runIdForRendition() is the shared derivation both
 * PanoramaPreviewService and GeneratePanoramaPreview use so that, when a
 * rendition exists, every log line about it — from the API request that
 * queued it through to the worker's publish/fail/cancel outcome — carries
 * one run_id.
 */
final class PanoramaLogger
{
    private const LOG_PATH = 'logs/panorama-preview.jsonl';

    /**
     * A run id stable for the lifetime of a given rendition, shared by every
     * caller that logs about that rendition. When no rendition exists yet
     * (e.g. a read that found nothing to correlate with), a fresh id is
     * minted per call since there is nothing to correlate.
     */
    public static function runIdForRendition(?int $renditionId): string
    {
        return $renditionId === null
            ? Str::uuid()->toString()
            : 'rendition-'.$renditionId;
    }

    public function write(string $runId, string $level, string $event, string $loc, array $ctx): void
    {
        $record = [
            'ts'     => now()->toIso8601String(),
            'run_id' => $runId,
            'level'  => $level,
            'event'  => $event,
            'loc'    => $loc,
            'ctx'    => RequestId::ctx($this->redact($ctx)),
        ];

        file_put_contents(
            storage_path(self::LOG_PATH),
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * Private filesystem paths and raw file/process contents must never
     * reach the log — only ids, statuses, and other non-sensitive metadata.
     */
    private function redact(array $ctx): array
    {
        unset(
            $ctx['source_path'],
            $ctx['output_path'],
            $ctx['path'],
            $ctx['local_path'],
            $ctx['stderr'],
            $ctx['stdout'],
            $ctx['contents'],
        );

        return $ctx;
    }
}
