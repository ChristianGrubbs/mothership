<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Media\PanoramaCancelled;
use Mothership\DAM\Media\PanoramaWorker;
use Mothership\DAM\Media\PanoramaWorkerResult;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\PanoramaPreviewService;
use Mothership\DAM\Services\RenditionPublisher;
use Mothership\DAM\Support\AttemptDirectory;
use Mothership\DAM\Support\PanoramaLogger;
use Throwable;

/**
 * Task 4's queue worker: runs a queued Rendition through a PanoramaWorker,
 * clamps and persists its progress, and hands a successful result to
 * RenditionPublisher for atomic, content-addressed publication.
 *
 * Lifecycle:
 *  - reload + lockForUpdate the rendition before every state transition;
 *  - no-op if it is already terminal in a way nothing here should revisit
 *    (done or cancelled — a fresh attempt is a new row, never a rerun of
 *    this one);
 *  - honor a cancellation requested before the worker ever starts, and poll
 *    for one requested mid-run via the $cancelled closure passed to the
 *    worker (which re-reads cancel_requested_at from the database on every
 *    call, so a cancel written by another request is observed live);
 *  - clamp reported progress to max(current, min(99, incoming)) so it can
 *    only move forward, and only publish() is allowed to set it to 100;
 *  - never let a worker exception escape as an automatic queue retry — this
 *    job has $tries = 1 and catches everything itself, marking the row
 *    failed with a short, path-free error code. A stuck run is recovered by
 *    the queue's own timeout mechanism, which invokes failed() below and
 *    frees the row for Task 3's retry() rather than leaving it wedged in
 *    STATUS_RUNNING forever.
 *
 * OPERATIONAL REQUIREMENT — this job's queue connection's `retry_after`
 * MUST exceed TIMEOUT. Laravel's database/redis/beanstalkd queue drivers
 * re-reserve (and, with $tries = 1, immediately fail) any job whose
 * reservation is older than the connection's `retry_after`, REGARDLESS of
 * whether the original worker process is still alive and actively running
 * it — that check is purely time-based at the queue-storage layer, not a
 * liveness probe. With the framework-default retry_after of 90 seconds,
 * a multi-hour conversion would get marked STATUS_FAILED at t+90s by a
 * second reservation while the original process kept converting for
 * hours, then flip the same row to DONE when it finished — a false
 * failure now, and a window where retry() can spawn a duplicate
 * conversion of the same source. $connection/$queue below route this job
 * onto a dedicated 'panorama' connection (config/queue.php) whose
 * retry_after is derived from self::TIMEOUT precisely so the two can never
 * drift apart again; see that config entry's comment for the required
 * `php artisan queue:work panorama --queue=panorama` deploy step.
 */
class GeneratePanoramaPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 6 hours — panorama conversions are long-running by nature. Exposed as
     * a class constant (rather than only the $timeout property) so
     * config/queue.php's dedicated 'panorama' connection can derive its
     * retry_after from the same number instead of duplicating it.
     */
    public const TIMEOUT = 21600;

    public int $timeout = self::TIMEOUT;

    /**
     * Never let Laravel's queue silently re-attempt a failed conversion on
     * this same row; a retry is always a deliberate new attempt created by
     * PanoramaPreviewService::retry().
     */
    public int $tries = 1;

    /**
     * The dedicated connection/queue name (config/queue.php) this job must
     * route onto instead of the app default — see the OPERATIONAL
     * REQUIREMENT above. Never dispatch this job onto a different
     * connection without also reconciling that connection's retry_after,
     * or it silently reintroduces the false-failure bug the dedicated
     * connection exists to prevent.
     */
    private const QUEUE_CONNECTION = 'panorama';

    private const NOOP_STATUSES = [Rendition::STATUS_DONE, Rendition::STATUS_CANCELLED];

    /**
     * Longest allowed sanitized `stage` label — see sanitizeStage().
     */
    private const MAX_STAGE_LENGTH = 64;

    public function __construct(protected int $renditionId)
    {
        // Queueable (used above) declares $connection/$queue as plain
        // untyped properties with no default — set via the constructor
        // rather than redeclared here, since redeclaring a trait-provided
        // property with an incompatible type/default is a PHP fatal error
        // at class-load time, not a silent override.
        $this->connection = self::QUEUE_CONNECTION;
        $this->queue = self::QUEUE_CONNECTION;
    }

    public function handle(PanoramaWorker $worker, RenditionPublisher $publisher): void
    {
        $rendition = Rendition::find($this->renditionId);

        if ($rendition === null || in_array($rendition->status, self::NOOP_STATUSES, true)) {
            return;
        }

        $logger = new PanoramaLogger;
        $runId = PanoramaLogger::runIdForRendition($rendition->id);
        $startedAt = microtime(true);

        if ($rendition->worker_version !== PanoramaPreviewService::WORKER_VERSION) {
            $this->markFailed($rendition, 'worker_version_mismatch');
            $logger->write($runId, 'error', 'panorama_job_failed', 'GeneratePanoramaPreview', [
                'rendition_id' => $rendition->id,
                'asset_id'     => $rendition->dam_asset_id,
                'recipe'       => $rendition->recipe,
                'error_code'   => 'worker_version_mismatch',
                'stage'        => 'before-start',
            ]);

            return;
        }

        $cancelled = fn (): bool => Rendition::query()->whereKey($rendition->id)->value('cancel_requested_at') !== null;

        if ($cancelled()) {
            $this->markCancelled($rendition);
            $logger->write($runId, 'info', 'panorama_job_cancelled', 'GeneratePanoramaPreview', [
                'rendition_id' => $rendition->id,
                'asset_id'     => $rendition->dam_asset_id,
                'recipe'       => $rendition->recipe,
                'stage'        => 'before-start',
            ]);

            return;
        }

        $this->markRunning($rendition);

        $logger->write($runId, 'info', 'panorama_job_started', 'GeneratePanoramaPreview', [
            'rendition_id'    => $rendition->id,
            'asset_id'        => $rendition->dam_asset_id,
            'recipe'          => $rendition->recipe,
            'checksum_prefix' => substr((string) $rendition->fingerprint, 0, 12),
        ]);

        $progress = function (int $percent, string $stage) use ($rendition, $logger, $runId): void {
            $stored = $this->recordProgress($rendition, $percent);

            if (config('app.debug')) {
                $logger->write($runId, 'info', 'panorama_progress', 'GeneratePanoramaPreview', [
                    'rendition_id' => $rendition->id,
                    'percent'      => $stored,
                    'stage'        => $this->sanitizeStage($stage),
                ]);
            }
        };

        $result = null;

        try {
            $result = $worker->run($rendition, $progress, $cancelled);

            $publisher->publish($rendition, $result);

            $logger->write($runId, 'info', 'panorama_publish_done', 'GeneratePanoramaPreview', [
                'rendition_id' => $rendition->id,
                'asset_id'     => $rendition->dam_asset_id,
                'recipe'       => $rendition->recipe,
                'duration_ms'  => $this->elapsedMs($startedAt),
            ]);
        } catch (PanoramaCancelled) {
            $this->markCancelled($rendition);
            $logger->write($runId, 'info', 'panorama_job_cancelled', 'GeneratePanoramaPreview', [
                'rendition_id' => $rendition->id,
                'asset_id'     => $rendition->dam_asset_id,
                'recipe'       => $rendition->recipe,
                'stage'        => 'during-run',
                'duration_ms'  => $this->elapsedMs($startedAt),
            ]);
        } catch (Throwable $e) {
            $errorCode = $this->redactedErrorCode($e);
            $this->markFailed($rendition, $errorCode);
            $logger->write($runId, 'error', 'panorama_job_failed', 'GeneratePanoramaPreview', [
                'rendition_id' => $rendition->id,
                'asset_id'     => $rendition->dam_asset_id,
                'recipe'       => $rendition->recipe,
                'error_code'   => $errorCode,
                'duration_ms'  => $this->elapsedMs($startedAt),
            ]);
        } finally {
            $this->cleanupAttempt($result);
        }
    }

    /**
     * Laravel invokes this when the queue itself gives up on the job — most
     * importantly, when it exceeds $timeout and the worker process is
     * killed out from under a running attempt. Without this, an interrupted
     * run leaves its row stuck in STATUS_RUNNING forever, which would also
     * permanently block PanoramaPreviewService::retry() (it only accepts
     * failed/cancelled rows). It never touches a row that already finished
     * through handle() itself.
     */
    public function failed(?Throwable $exception): void
    {
        $rendition = Rendition::find($this->renditionId);

        if ($rendition === null || in_array($rendition->status, [...self::NOOP_STATUSES, Rendition::STATUS_FAILED], true)) {
            return;
        }

        $errorCode = $exception !== null ? $this->redactedErrorCode($exception) : 'interrupted';
        $this->markFailed($rendition, $errorCode);

        (new PanoramaLogger)->write(PanoramaLogger::runIdForRendition($rendition->id), 'error', 'panorama_job_failed', 'GeneratePanoramaPreview', [
            'rendition_id' => $rendition->id,
            'asset_id'     => $rendition->dam_asset_id,
            'recipe'       => $rendition->recipe,
            'error_code'   => $errorCode,
            'stage'        => 'interrupted',
        ]);
    }

    private function markRunning(Rendition $rendition): void
    {
        DB::transaction(function () use ($rendition): void {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status'     => Rendition::STATUS_RUNNING,
                'started_at' => $locked->started_at ?? now(),
            ]);
            $rendition->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Persists max(current, min(99, incoming)) and returns the value that
     * ended up stored, so callers (the debug progress log line) report what
     * actually landed rather than the raw, possibly-decreasing input.
     */
    private function recordProgress(Rendition $rendition, int $incoming): int
    {
        return DB::transaction(function () use ($rendition, $incoming): int {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();
            $clamped = max($locked->progress, min(99, $incoming));

            if ($clamped !== $locked->progress) {
                $locked->update(['progress' => $clamped]);
            }

            $rendition->setRawAttributes($locked->getAttributes(), true);

            return $clamped;
        });
    }

    private function markCancelled(Rendition $rendition): void
    {
        DB::transaction(function () use ($rendition): void {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status'      => Rendition::STATUS_CANCELLED,
                'finished_at' => now(),
            ]);
            $rendition->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function markFailed(Rendition $rendition, string $errorCode): void
    {
        DB::transaction(function () use ($rendition, $errorCode): void {
            $locked = Rendition::query()->whereKey($rendition->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'status'      => Rendition::STATUS_FAILED,
                'error'       => $errorCode,
                'finished_at' => now(),
            ]);
            $rendition->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * A short, stable code safe to store on the row and to log — never the
     * raw exception message, which (for a subprocess failure) could embed a
     * local filesystem path or process output. Redacts every whitespace-
     * delimited token that contains a path separator, not just POSIX
     * absolute paths: a Windows path (`C:\Users\...`), a UNC path
     * (`\\host\share\...`), and a bare relative path (`attempt-42/source.osv`)
     * all match too. Paths are not guaranteed POSIX, so a POSIX-only pattern would
     * leak exactly the paths it exists to hide.
     */
    private function redactedErrorCode(Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return class_basename($e);
        }

        $redacted = preg_replace_callback(
            '/\S+/',
            static fn (array $m): string => (str_contains($m[0], '/') || str_contains($m[0], '\\')) ? '<path>' : $m[0],
            $message,
        );

        $redacted = trim($redacted ?? $message);

        return substr($redacted !== '' ? $redacted : class_basename($e), 0, 200);
    }

    /**
     * Constrains a worker-reported progress stage label before it ever
     * reaches the log: lowercased, restricted to [a-z0-9_-], capped at
     * MAX_STAGE_LENGTH. `stage` is free text that ultimately comes from the
     * CLI subprocess's stdout (via CliPanoramaWorker) — the CLI contract
     * requires it stay a short slug and never a path, but this is the
     * defense-in-depth backstop in case a future CLI version (or a bug in
     * this one) puts something else there.
     */
    private function sanitizeStage(string $stage): string
    {
        $stage = strtolower(trim($stage));
        $stage = preg_replace('/[^a-z0-9_-]/', '', $stage) ?? '';
        $stage = substr($stage, 0, self::MAX_STAGE_LENGTH);

        return $stage !== '' ? $stage : 'unknown';
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Removes the whole local, attempt-scoped working directory the worker
     * produced its result in — recursively, not just the one result file.
     * FFmpeg routinely leaves two-pass log files, temp segments, or a
     * `.tmp` output beside the final file; deleting only $result->path and
     * then a plain (non-recursive) rmdir() silently fails and leaks the
     * rest of the directory forever on every successful run that left
     * anything else behind.
     *
     * A successful result's file has already been copied into content-
     * addressed storage by RenditionPublisher::publish() by this point, so
     * removing its directory here is safe regardless of whether publish()
     * succeeded or threw a validation error (a failed or cancelled worker
     * never returns a result to clean up here in the first place — see
     * PanoramaWorker's docblock for the full cleanup-ownership contract:
     * the implementation that created the directory is responsible for
     * removing it, recursively, on every path that does NOT return a
     * result to this caller).
     */
    private function cleanupAttempt(?PanoramaWorkerResult $result): void
    {
        if ($result === null || ! is_file($result->path)) {
            return;
        }

        $tempRoot = rtrim(sys_get_temp_dir(), '/');
        $dir = dirname($result->path);

        if ($dir !== $tempRoot && str_starts_with($dir, $tempRoot.'/')) {
            AttemptDirectory::removeRecursively($dir);
        }
    }
}
