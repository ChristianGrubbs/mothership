<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\DAM\Traits\StampsHttpRequestId;

class ChunkUpload extends Model
{
    use StampsHttpRequestId;

    public const STATE_RECEIVING = 'receiving';

    public const STATE_QUEUED = 'queued';

    public const STATE_ASSEMBLING = 'assembling';

    public const STATE_COMPLETED = 'completed';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_EXPIRED = 'expired';

    public const STATE_FAILED = 'failed';

    protected $table = 'dam_chunk_uploads';

    protected $fillable = [
        'uuid',
        'upload_tracker_id',
        'user_id',
        'directory_id',
        'original_name',
        'mime_type',
        'extension',
        'relative_path',
        'preserve_root',
        'create_only',
        'expected_bytes',
        'source_modified_ms',
        'source_fingerprint',
        'chunk_bytes',
        'chunk_count',
        'received_bytes',
        'finalization_bytes',
        'state',
        'staged_root',
        'asset_id',
        'failure_code',
        'failure_reason',
        'expires_at',
        'finalized_at',
        'attach_asset_id',
        'attach_role',
        'attach_label',
        'asset_file_id',
        'upload_submission_id',
    ];

    protected $casts = [
        'preserve_root'       => 'boolean',
        'create_only'         => 'boolean',
        'expected_bytes'      => 'integer',
        'source_modified_ms'  => 'integer',
        'chunk_bytes'         => 'integer',
        'chunk_count'         => 'integer',
        'received_bytes'      => 'integer',
        'finalization_bytes'  => 'integer',
        'expires_at'          => 'datetime',
        'finalized_at'        => 'datetime',
        'failure_reason'      => 'array',
    ];

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(UploadTracker::class, 'upload_tracker_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ChunkUploadPart::class, 'chunk_upload_id');
    }

    /** True when this upload finishes as an attachment on an existing asset rather than a new asset. */
    public function isAttachment(): bool
    {
        return $this->attach_asset_id !== null;
    }

    /** P5a: a guest's upload through an upload request, bound to its visit; the staff chunk routes never serve it. */
    public function isGuest(): bool
    {
        return $this->upload_submission_id !== null;
    }

    public function isReceiving(): bool
    {
        return $this->state === self::STATE_RECEIVING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function touchExpiry(): void
    {
        $this->forceFill([
            'expires_at' => now()->addHours((int) config('dam.explorer.upload.stale_hours')),
        ])->save();
    }
}
