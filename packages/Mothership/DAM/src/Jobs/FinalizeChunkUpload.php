<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mothership\DAM\Analytics\ActorResolver;
use Mothership\DAM\Analytics\AnalyticsActor;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Analytics\ReplaySession;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Support\ChunkUploadError;
use Mothership\DAM\Support\ChunkUploadLogger;
use Throwable;

class FinalizeChunkUpload implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 21_600;

    protected ?string $replaySessionId = null;

    /**
     * The uploader as read from the request that completed the upload (its privacy signal, user agent and, for a
     * guest, visitor key): the worker has no request of its own. Declared with a default, not promoted, so a job
     * queued before it existed still unserializes and falls back to the owner.
     */
    protected ?AnalyticsActor $actor = null;

    public function __construct(
        protected int $uploadId,
        ?string $replaySessionId = null,
        ?AnalyticsActor $actor = null,
    ) {
        $this->replaySessionId = ReplaySession::validate(value: $replaySessionId);
        $this->actor = $actor;
        $this->onConnection(self::connectionName());
        $this->onQueue((string) config('dam.explorer.upload.finalize_queue', 'video-preview'));
    }

    /**
     * A connection whose retry_after exceeds $timeout (Config/dam.php explorer.upload.finalize_connection), or sync
     * when the app runs every job inline (tests, a bare local checkout).
     */
    public static function connectionName(): string
    {
        return config('queue.default') === 'sync' ? 'sync' : (string) config('dam.explorer.upload.finalize_connection', 'panorama');
    }

    public function uniqueId(): string
    {
        return (string) $this->uploadId;
    }

    public function handle(ChunkUploadService $service): void
    {
        $upload = ChunkUpload::find($this->uploadId);

        // A redelivered job for a finished upload has nothing to do, and recording it again would count the upload
        // twice on the count-only path, which keeps no event ids to deduplicate by.
        if (! $upload || $upload->state === ChunkUpload::STATE_COMPLETED) {
            return;
        }

        // P1b: this try is one attempt (a queue retry is a new one, a replay of the same try is not); the upload
        // ends in one terminal event, success or failure.
        $this->uploadEvent(upload: $upload, event: DamEvent::UploadAttempted, props: ['attempt' => $this->attempts()], attempt: ($this->job?->uuid() ?? 'sync').':'.$this->attempts());

        try {
            $service->finalize($upload);
            $this->uploadEvent(upload: $upload, event: DamEvent::UploadSucceeded);
        } catch (ChunkUploadError $error) {
            if ($error->retryable) {
                throw $error;
            }

            $this->uploadEvent(upload: $upload, event: DamEvent::UploadFailed, props: ['error_code' => substr($error->errorCode, 0, 48)]);
        }
    }

    /** @param array<string, int|string> $props */
    private function uploadEvent(ChunkUpload $upload, DamEvent $event, array $props = [], ?string $attempt = null): void
    {
        $actor = $this->actor ?? ActorResolver::forChunkUpload(upload: $upload, request: null);

        DamEvents::emit(
            event: $event,
            subjectId: (int) $upload->directory_id,
            props: ['upload_id' => substr(str_replace('-', '', (string) $upload->uuid), 0, 32), 'path' => $upload->isGuest() ? 'portal' : 'chunked'] + $props,
            surface: 'upload',
            actor: $actor,
            businessId: (string) $upload->uuid,
            attempt: $attempt,
            replaySessionId: $this->replaySessionId,
        );
    }

    public function failed(Throwable $error): void
    {
        $upload = ChunkUpload::find($this->uploadId);

        if (! $upload || $upload->state === ChunkUpload::STATE_COMPLETED) {
            return;
        }

        $upload->forceFill([
            'state'              => ChunkUpload::STATE_FAILED,
            'failure_code'       => 'finalization_retries_exhausted',
            'finalization_bytes' => 0,
        ])->save();

        app(ChunkUploadLogger::class)->write(
            runId: $upload->uuid,
            level: 'error',
            event: 'upload.finalization_exhausted',
            loc: self::class.'::failed',
            ctx: ['upload_id' => (int) $upload->id, 'error_type' => $error::class],
        );

        $this->uploadEvent(upload: $upload, event: DamEvent::UploadFailed, props: ['error_code' => 'finalization_retries_exhausted']);
    }
}
