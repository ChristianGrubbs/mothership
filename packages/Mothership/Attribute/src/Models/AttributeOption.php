<?php

namespace Mothership\Attribute\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Mothership\Attribute\Contracts\AttributeOption as AttributeOptionContract;
use Mothership\Attribute\Database\Factories\AttributeOptionFactory;
use Mothership\Core\Eloquent\TranslatableModel;
use Mothership\HistoryControl\Contracts\HistoryAuditable as HistoryContract;
use Mothership\HistoryControl\Traits\HistoryTrait;

#[Appends([
    'swatch_value_url',
])]
#[Fillable([
    'code',
    'swatch_value',
    'sort_order',
    'attribute_id',
])]
#[WithoutTimestamps]
class AttributeOption extends TranslatableModel implements AttributeOptionContract, HistoryContract
{
    use HasFactory;
    use HistoryTrait;

    public $translatedAttributes = ['label'];

    protected $historyTags = ['attribute'];

    /**
     * Get the attribute that owns the attribute option.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(AttributeProxy::modelClass());
    }

    /**
     * Get image url for the swatch value url.
     */
    public function swatch_value_url()
    {
        if (! $this->swatch_value) {
            return null;
        }

        if ($this->attribute && $this->attribute->swatch_type == 'image') {
            return Storage::url($this->swatch_value);
        }

        return null;
    }

    /**
     * Get image url for the product image.
     */
    protected function swatchValueUrl(): Attribute
    {
        return Attribute::make(get: fn () => $this->swatch_value_url());
    }

    /**
     * Create a new factory instance for the model
     */
    protected static function newFactory(): Factory
    {
        return AttributeOptionFactory::new();
    }

    /**
     * Id used for creating version for history
     *
     * {@inheritdoc}
     */
    public function getPrimaryModelIdForHistory(): int
    {
        return $this->attribute_id;
    }
}
