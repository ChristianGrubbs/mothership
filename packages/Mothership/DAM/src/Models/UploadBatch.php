<?php

namespace Mothership\DAM\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Traits\StampsHttpRequestId;

class UploadBatch extends Model
{
    use StampsHttpRequestId;

    const STATE_PENDING = 'pending';

    const STATE_PROCESSING = 'processing';

    const STATE_PROCESSED = 'processed';

    const STATE_FAILED = 'failed';

    const STATE_CANCELLED = 'cancelled';

    protected $table = 'dam_upload_batches';

    protected $fillable = [
        'upload_tracker_id',
        'finalization_key',
        'claim_attempts',
        'recovery_attempts',
        'last_recovered_at',
        'asset_id',
        'state',
        'error',
    ];

    protected $casts = [
        'claim_attempts'    => 'integer',
        'recovery_attempts' => 'integer',
        'last_recovered_at' => 'datetime',
    ];

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(UploadTracker::class, 'upload_tracker_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    /** CAS settlement keeps duplicate jobs, failed callbacks, and the recovery sweep from counting a file twice. */
    public function settle(bool $failed, ?string $error = null, ?CarbonInterface $staleProcessingBefore = null): bool
    {
        return DB::transaction(function () use ($failed, $error, $staleProcessingBefore): bool {
            $query = self::query()->whereKey($this->id);

            if ($failed && $staleProcessingBefore !== null) {
                $query->where(function ($open) use ($staleProcessingBefore): void {
                    $open->where('state', self::STATE_PENDING)
                        ->orWhere(function ($processing) use ($staleProcessingBefore): void {
                            $processing->where('state', self::STATE_PROCESSING)
                                ->where('updated_at', '<=', $staleProcessingBefore);
                        });
                });
            } else {
                $query->where('state', self::STATE_PROCESSING);
            }

            $settled = $query->update([
                'state' => $failed ? self::STATE_FAILED : self::STATE_PROCESSED,
                'error' => $failed ? $error : null,
            ]);

            if ($settled !== 1 || $this->upload_tracker_id === null) {
                return $settled === 1;
            }

            UploadTracker::whereKey($this->upload_tracker_id)
                ->increment($failed ? 'failed_files' : 'processed_files');

            $tracker = UploadTracker::find($this->upload_tracker_id);

            if (! $tracker || ! in_array($tracker->state, [UploadTracker::STATE_PENDING, UploadTracker::STATE_PROCESSING], true)) {
                return true;
            }

            if ($tracker->total_files <= 0 || $tracker->processed_files + $tracker->failed_files < $tracker->total_files) {
                return true;
            }

            if (! $tracker->batches()->whereIn('state', [self::STATE_PENDING, self::STATE_PROCESSING])->exists()) {
                $tracker->update(['state' => UploadTracker::STATE_COMPLETED, 'completed_at' => now()]);
            }

            return true;
        });
    }
}
