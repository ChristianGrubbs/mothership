<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Processing\BatchRunner;
use Throwable;

/**
 * One item of a processing batch (P6a). One attempt per dispatch ($tries = 1): a failure is recorded on the item
 * with its reason, and a retry is a deliberate new dispatch from the batch page, never the queue's own.
 *
 * OPERATIONAL REQUIREMENT, as GenerateVideoPreviewProxy: it runs on the `panorama` connection, whose retry_after
 * exceeds TIMEOUT, so a long transcode is never re-reserved and run twice. Each tool run gets
 * dam.batch.tool_timeout (25 minutes by default), inside this job's 30.
 */
class ProcessBatchItem implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public const TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public int $itemId)
    {
        $this->onConnection(self::connectionName())->onQueue((string) config('dam.batch.queue', 'video-preview'));
    }

    /** The batch connection, or sync when the app runs every job inline (tests, a bare local checkout). */
    public static function connectionName(): string
    {
        return config('queue.default') === 'sync' ? 'sync' : (string) config('dam.batch.connection', 'panorama');
    }

    /** A cancelled item is no longer `queued`, so the runner's claim leaves it alone; nothing else to check here. */
    public function handle(BatchRunner $runner): void
    {
        $runner->run($this->itemId);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(BatchRunner::class)->abandon($this->itemId);
    }
}
