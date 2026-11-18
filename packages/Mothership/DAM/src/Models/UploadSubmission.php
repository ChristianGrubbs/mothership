<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P5a (D1): one guest visit that sent files to an upload request. `guest_key` is a random pseudonym; the name and
 * email are optional, self-reported, and cleared by dam:upload-requests:prune after the retention period (§3.6.7).
 */
class UploadSubmission extends Model
{
    protected $table = 'dam_upload_submissions';

    protected $hidden = ['visit_hash'];

    protected $fillable = [
        'upload_request_id',
        'guest_key',
        'visit_hash',
        'guest_name',
        'guest_email',
        'identity_pruned_at',
        'file_count',
    ];

    protected $casts = [
        'file_count'         => 'integer',
        'identity_pruned_at' => 'datetime',
    ];

    public function uploadRequest(): BelongsTo
    {
        return $this->belongsTo(UploadRequest::class, 'upload_request_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'upload_submission_id');
    }
}
