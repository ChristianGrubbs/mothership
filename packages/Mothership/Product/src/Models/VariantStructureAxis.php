<?php

namespace Mothership\Product\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mothership\Attribute\Models\AttributeProxy;

#[Fillable([
    'variant_structure_id',
    'attribute_id',
    'level',
    'position',
])]
#[WithoutTimestamps]
class VariantStructureAxis extends Model
{
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(AttributeProxy::modelClass());
    }
}
