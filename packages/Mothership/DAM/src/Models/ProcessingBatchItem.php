<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One asset of a processing batch (P6a): its source revision, its state, a user-safe reason, and its output. */
class ProcessingBatchItem extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Not run: the asset is missing to the requester (hidden, trashed, merged or gone), or the recipe does not fit it. */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const FINAL = [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_SKIPPED, self::STATUS_CANCELLED];

    protected $table = 'dam_batch_items';

    protected $fillable = [
        'batch_id', 'position', 'asset_id', 'source_asset_file_id', 'status', 'error', 'attempts',
        'output_asset_file_id', 'output_asset_id', 'rendition_id', 'duration_ms', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'position'             => 'integer',
        'asset_id'             => 'integer',
        'source_asset_file_id' => 'integer',
        'attempts'             => 'integer',
        'output_asset_file_id' => 'integer',
        'output_asset_id'      => 'integer',
        'rendition_id'         => 'integer',
        'duration_ms'          => 'integer',
        'started_at'           => 'datetime',
        'finished_at'          => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'batch_id');
    }
}
