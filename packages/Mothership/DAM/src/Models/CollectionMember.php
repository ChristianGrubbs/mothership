<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member of a collection (P4 D2): the same row shape as a label membership (`dam_label_assets`), so position
 * sorting has one implementation. The row grants nothing by itself: members are always read through the seam with
 * `AccessContext::collection()`.
 */
class CollectionMember extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dam_collection_assets';

    protected $fillable = ['collection_id', 'asset_id', 'position', 'added_by'];

    protected $casts = [
        'collection_id' => 'integer',
        'asset_id'      => 'integer',
        'position'      => 'integer',
        'added_by'      => 'integer',
    ];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(AssetCollection::class, 'collection_id');
    }

    /** The asset, trashed or not: a trashed member stays a row (so Restore brings it back) but is never served. */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id')->withTrashed();
    }
}
