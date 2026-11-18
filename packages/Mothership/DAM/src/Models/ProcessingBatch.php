<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\User\Models\Admin;

/**
 * One request to run a recipe over a set of assets (P6a, D17/D27/D28). Its items carry each file's progress; the
 * counts here are recomputed from them after every change, so the two never disagree.
 */
class ProcessingBatch extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    /** Every item reached a final state; some may have failed or been skipped. */
    public const STATUS_FINISHED = 'finished';

    public const STATUS_CANCELLED = 'cancelled';

    public const SAVE_ATTACH = 'attach';

    public const SAVE_NEW_ASSET = 'new_asset';

    protected $table = 'dam_batches';

    protected $fillable = [
        'recipe', 'params', 'save_as', 'requested_by', 'via', 'status', 'total', 'done', 'failed', 'skipped', 'cancelled',
        'bus_batch_id', 'correlation_id', 'cancelled_at', 'finished_at',
    ];

    protected $casts = [
        'params'       => 'array',
        'total'        => 'integer',
        'done'         => 'integer',
        'failed'       => 'integer',
        'skipped'      => 'integer',
        'cancelled'    => 'integer',
        'cancelled_at' => 'datetime',
        'finished_at'  => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProcessingBatchItem::class, 'batch_id')->orderBy('position');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    /** Recounts the items and settles the batch once none is left to run. */
    public function refreshCounts(): void
    {
        $counts = ProcessingBatchItem::query()->where('batch_id', $this->id)
            ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $queued = $counts[ProcessingBatchItem::STATUS_QUEUED] ?? 0;
        $open = $queued + ($counts[ProcessingBatchItem::STATUS_RUNNING] ?? 0);
        // Queued until the first item starts or settles; running while any is left; then cancelled or finished.
        $status = match (true) {
            $open > 0 && $queued === $counts->sum() => self::STATUS_QUEUED,
            $open > 0                               => self::STATUS_RUNNING,
            $this->cancelled_at !== null            => self::STATUS_CANCELLED,
            default                                 => self::STATUS_FINISHED,
        };

        $this->forceFill([
            'total'       => $counts->sum(),
            'done'        => $counts[ProcessingBatchItem::STATUS_DONE] ?? 0,
            'failed'      => $counts[ProcessingBatchItem::STATUS_FAILED] ?? 0,
            'skipped'     => $counts[ProcessingBatchItem::STATUS_SKIPPED] ?? 0,
            'cancelled'   => $counts[ProcessingBatchItem::STATUS_CANCELLED] ?? 0,
            'status'      => $status,
            'finished_at' => $open === 0 ? ($this->finished_at ?? now()) : null,
        ])->save();
    }
}
