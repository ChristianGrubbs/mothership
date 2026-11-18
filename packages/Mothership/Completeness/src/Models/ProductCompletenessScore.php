<?php

namespace Mothership\Completeness\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\Core\Models\Channel;
use Mothership\Core\Models\Locale;
use Mothership\Product\Models\ProductProxy;

#[Fillable([
    'product_id',
    'channel_id',
    'locale_id',
    'score',
    'missing_count',
])]
#[Table(name: 'product_completeness')]
class ProductCompletenessScore extends Model
{
    /**
     * Get the product related to this score.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductProxy::modelClass(), 'product_id');
    }

    /**
     * Get the channel related to this score.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class, 'locale_id');
    }
}
