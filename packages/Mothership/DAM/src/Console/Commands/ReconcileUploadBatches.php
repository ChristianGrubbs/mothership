<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\UploadBatch;
use Mothership\DAM\Models\UploadTracker;
use Throwable;

class ReconcileUploadBatches extends Command
{
    protected $signature = 'dam:uploads:reconcile';

    protected $description = 'Recover aged open upload batches after a queue or worker failure.';

    private const RECOVERY_SPACING_SECONDS = 1800;

    public function handle(): int
    {
        $staleAt = now()->subSeconds(ProcessAssetUpload::RECLAIM_AFTER_SECONDS);
        $lastRecoveryAt = now()->subSeconds(self::RECOVERY_SPACING_SECONDS);
        $queued = 0;
        $pushErrors = 0;

        $batches = UploadBatch::query()->with('tracker')
            ->whereIn('state', [UploadBatch::STATE_PENDING, UploadBatch::STATE_PROCESSING])
            ->whereDoesntHave('tracker', fn ($tracker) => $tracker->whereIn('state', [
                UploadTracker::STATE_PAUSED, UploadTracker::STATE_CANCELLED, UploadTracker::STATE_FAILED,
            ]))
            ->where('updated_at', '<=', $staleAt)
            ->where(function ($query) use ($lastRecoveryAt): void {
                $query->whereNull('last_recovered_at')->orWhere('last_recovered_at', '<=', $lastRecoveryAt);
            })
            ->orderBy('id')->limit(200)->get();

        foreach ($batches as $batch) {
            $tracker = $batch->tracker;

            if ($tracker instanceof UploadTracker && $tracker->shouldStop()) {
                continue;
            }

            // Query-builder update deliberately leaves updated_at alone: it is the processing claim's lease clock.
            $reserved = DB::table('dam_upload_batches')->where('id', $batch->id)
                ->where('state', $batch->state)
                ->where('updated_at', '<=', $staleAt)
                ->where(function ($query) use ($lastRecoveryAt): void {
                    $query->whereNull('last_recovered_at')->orWhere('last_recovered_at', '<=', $lastRecoveryAt);
                })
                ->update([
                    'recovery_attempts' => DB::raw('recovery_attempts + 1'),
                    'last_recovered_at' => now(),
                ]);

            if ($reserved !== 1) {
                continue;
            }

            try {
                ProcessAssetUpload::dispatch($batch->asset_id, $batch->id);
                $queued++;
            } catch (Throwable $error) {
                $pushErrors++;
                Log::warning('DAM upload batch recovery could not enqueue finalization.', [
                    'batch' => $batch->id, 'error_type' => get_class($error),
                ]);
            }
        }

        $this->line(json_encode(compact('queued', 'pushErrors'), JSON_THROW_ON_ERROR));

        return $pushErrors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
