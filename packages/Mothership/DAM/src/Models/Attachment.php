<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One file slot inside an asset entry (P3a S2). Its dam_asset_files rows are its revisions, exactly one
 * current. The representative slot's role is `original`; its current revision is the asset's original.
 */
class Attachment extends Model
{
    protected $table = 'dam_attachments';

    protected $fillable = [
        'dam_asset_id',
        'role',
        'label',
        'position',
        'is_representative',
        'created_by',
        'merge_id',
        'origin_attachment_id',
    ];

    protected $casts = [
        'position'             => 'integer',
        'is_representative'    => 'boolean',
        // P3b-1: the merge this slot came from while that merge is active, and the source slot it links.
        'merge_id'             => 'integer',
        'origin_attachment_id' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AssetFile::class, 'attachment_id')->orderBy('revision_number');
    }

    public function currentRevision(): HasOne
    {
        return $this->hasOne(AssetFile::class, 'attachment_id')->where('is_current', true);
    }
}
