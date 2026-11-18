<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\Category\Models\CategoryProxy;
use Mothership\DAM\Contracts\AssetResourceMapping as AssetResourceMappingContract;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Traits\HistoryTrait;
use Mothership\Product\Models\ProductProxy;

class AssetResourceMapping extends Model implements AssetResourceMappingContract, HistoryAuditable
{
    use HistoryTrait;

    protected $historyTags = ['asset'];

    protected $auditExclude = [
        'id',
    ];

    protected $table = 'dam_asset_resource_mappings';

    protected $fillable = [
        'type',
        'related_field',
        'dam_asset_id',
        'product_id',
        'category_id',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryProxy::class, 'category_id');
    }

    public function getPrimaryModelIdForHistory(): int
    {
        return $this->dam_asset_id;
    }
}
