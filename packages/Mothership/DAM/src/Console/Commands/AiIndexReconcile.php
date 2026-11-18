<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\AiIndex\AiBridgeClient;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\AiIndex\AiIndexStore;
use Throwable;

/**
 * Nightly: re-index changed or failed content, re-render every lexical doc (catches raw-SQL folder moves).
 * Hourly with --transient: re-queue only the runs the AI service or the queue lost, and only while the service answers.
 */
class AiIndexReconcile extends Command
{
    /** Tries a failed or running row may spend across dispatches (4 per dispatch, so three nightly cycles). */
    public const MAX_ATTEMPTS = 12;

    /** Failures that say the machine could not look, not that the file cannot be described. */
    public const TRANSIENT_ERRORS = ['AiBridgeUnavailable', 'AiSamplerUnavailable'];

    protected $signature = 'dam:ai-index:reconcile
        {--transient : Only re-queue runs lost to a service outage or a stopped worker, while the AI service answers}';

    protected $description = 'Re-queue changed or failed AI index rows and refresh search words';

    /** Fixed sink for the start and terminal records (one JSON object per line). */
    public const LOG_FILE = 'logs/ai-index-reconcile.jsonl';

    /** State of the CURRENT invocation only; handle() resets it, so a second run in one process starts clean. */
    private string $runId = '';

    private int $startedAt = 0;

    private ?string $skipReason = null;

    private int $fullDispatched = 0;

    private ?int $lexicalDispatched = null;

    private int $transientDispatched = 0;

    /**
     * Writes a start record, runs the reconcile, then writes ONE terminal record (completed or failed) and returns the
     * unchanged result or rethrows the SAME exception. "completed" means this command finished and its dispatch
     * calls returned; it does not mean the queued AI jobs have run. A start with no terminal record (killed process)
     * proves nothing either way. A failing log sink never changes the outcome.
     */
    public function handle(): int
    {
        $this->runId = (string) Str::uuid();
        $this->startedAt = hrtime(true);
        $this->skipReason = null;
        $this->fullDispatched = 0;
        $this->lexicalDispatched = null;
        $this->transientDispatched = 0;
        $this->record('info', 'ai_index_reconcile.start', ['enabled' => (bool) config('ai-index.index_enabled')]);

        try {
            $code = $this->reconcile();
        } catch (Throwable $e) {
            $this->record('error', 'ai_index_reconcile.result', ['outcome' => 'failed', 'exception_class' => $e::class, 'exit_code' => null]);

            throw $e;
        }

        $this->record('info', 'ai_index_reconcile.result', ['outcome' => 'completed', 'exit_code' => $code]);

        return $code;
    }

    private function reconcile(): int
    {
        if (! config('ai-index.index_enabled')) {
            $this->skipReason = 'index_disabled';
            $this->info('AI indexing is off.');

            return self::SUCCESS;
        }

        if ($this->option('transient')) {
            return $this->retryTransient();
        }

        $full = 0;
        $lexical = [];
        $rows = DB::table('dam_assets as a')
            ->leftJoin('dam_ai_index as i', 'i.asset_id', '=', 'a.id')
            ->leftJoin('dam_asset_files as f', fn ($join) => $join->on('f.dam_asset_id', '=', 'a.id')->where('f.role', '=', 'original'))
            ->leftJoin('dam_file_objects as o', 'o.id', '=', 'f.file_object_id')
            ->select(['a.id', 'i.status', 'i.attempts', 'i.content_sha256', 'i.caption', 'i.error', 'i.run_token', 'i.claimed_at', 'i.updated_at', 'o.sha256'])
            ->orderBy('a.id');

        foreach ($rows->lazy() as $row) {
            // A run whose job is still queued or going is left alone: queuing it again would only send it to the back.
            if (in_array(AiIndexStore::liveness($row), [AiIndexStore::LIVE_QUEUED, AiIndexStore::LIVE_RUNNING], true)) {
                $lexical[] = (int) $row->id;

                continue;
            }

            // Attempts accumulate across dispatches (4 tries each), so 12 is three nightly cycles. A failed or
            // running row is never "changed" by its sha: content_sha256 is only written on success, and
            // genuinely new content has already reset the count (AiIndexDispatcher::contentReplaced).
            $changed = match ($row->status) {
                null, 'pending'     => true,
                'failed', 'running' => (int) $row->attempts < self::MAX_ATTEMPTS,
                default             => $row->sha256 !== null && $row->sha256 !== $row->content_sha256,
            };
            // A thumbnail is written after the asset row, so an index that ran first finds nothing to caption.
            // A run on these very bytes that found no picture is final: a picture appearing later re-queues it itself
            // (AiIndexDispatcher::derivativeReady), so retrying here would only repeat it every night.
            $noPicture = in_array($row->error, [AiEligibility::NO_IMAGE, AiEligibility::UNSUPPORTED], true) && $row->sha256 !== null && $row->sha256 === $row->content_sha256;
            $uncaptioned = $row->status === 'done' && $row->caption === null && ! $noPicture;

            if ($changed || $uncaptioned) {
                AiIndexDispatcher::full((int) $row->id);
                $full++;
                $this->fullDispatched = $full;
            } else {
                $lexical[] = (int) $row->id;
            }
        }

        AiIndexDispatcher::lexical($lexical);
        // The dispatcher drops zero ids and duplicates before it queues; count what it was actually given to queue.
        $this->lexicalDispatched = count(array_unique(array_filter(array_map('intval', $lexical))));
        $this->info("Re-indexing {$full}; refreshing words for ".count($lexical).'.');

        return self::SUCCESS;
    }

    /**
     * A file uploaded while the AI service was down fails after the queue's own retries (about 20 minutes) and would
     * otherwise wait for the nightly run. Checked only while /health answers, so an outage spends no attempts, and
     * bounded by the same MAX_ATTEMPTS as the nightly run. Words are left to the nightly run.
     */
    private function retryTransient(): int
    {
        if (app(AiBridgeClient::class)->health() !== true) {
            $this->skipReason = 'ai_service_not_answering';
            $this->info('The AI service is not answering; nothing re-queued.');

            return self::SUCCESS;
        }

        $queued = 0;
        $rows = DB::table('dam_ai_index as i')
            ->join('dam_assets as a', 'a.id', '=', 'i.asset_id')
            ->whereNull('a.deleted_at')
            ->whereIn('i.status', ['failed', 'pending', 'running'])
            ->where('i.attempts', '<', self::MAX_ATTEMPTS)
            ->select(['i.asset_id', 'i.status', 'i.error', 'i.run_token', 'i.claimed_at', 'i.updated_at'])
            ->orderBy('i.asset_id');

        foreach ($rows->lazy() as $row) {
            $lost = AiIndexStore::liveness($row) === AiIndexStore::LIVE_LOST;
            $outage = $row->status === 'failed' && in_array($row->error, self::TRANSIENT_ERRORS, true);

            if ($lost || $outage) {
                AiIndexDispatcher::full((int) $row->asset_id);
                $queued++;
                $this->transientDispatched = $queued;
            }
        }

        $this->info("Re-queued {$queued} lost to an outage.");

        return self::SUCCESS;
    }

    /**
     * One JSONL line: ts/run_id/level/event/loc/ctx. The context carries only fixed words, counts, the PID and an
     * exception CLASS - never an exception message, SQL, file name, caption, token, URL or user identifier.
     * Best effort: a sink that cannot be written is silent and cannot change what the command does or returns.
     *
     * @param  array<string, bool|int|string|null>  $ctx
     */
    private function record(string $level, string $event, array $ctx): void
    {
        try {
            $ctx += [
                'mode'                 => $this->option('transient') ? 'transient' : 'full',
                'pid'                  => getmypid(),
                'duration_ms'          => intdiv(hrtime(true) - $this->startedAt, 1_000_000),
                'skip_reason'          => $this->skipReason,
                'full_dispatched'      => $this->fullDispatched,
                'lexical_dispatched'   => $this->lexicalDispatched,
                'transient_dispatched' => $this->transientDispatched,
            ];

            file_put_contents(storage_path(self::LOG_FILE), json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => $this->runId,
                'level'  => $level,
                'event'  => $event,
                'loc'    => self::class,
                'ctx'    => $ctx,
            ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // No sink, no record: nothing is fabricated and the reconcile is untouched.
        }
    }
}
