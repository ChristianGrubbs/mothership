<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mothership\User\Models\Admin;

/**
 * A request for feedback on one revision of an asset (P7). It is opened and closed, never decided: there are no
 * approvals (operator decision 2026-10-06). Recipients are admin ids, with groups expanded when it was made.
 */
class ReviewRequest extends Model
{
    protected $table = 'dam_review_requests';

    protected $fillable = [
        'dam_asset_id',
        'asset_file_id',
        'requested_by',
        'message',
        'due_at',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'dam_asset_id'  => 'integer',
        'asset_file_id' => 'integer',
        'requested_by'  => 'integer',
        'closed_by'     => 'integer',
        'due_at'        => 'datetime',
        'closed_at'     => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'dam_review_request_recipients', 'review_request_id', 'admin_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
