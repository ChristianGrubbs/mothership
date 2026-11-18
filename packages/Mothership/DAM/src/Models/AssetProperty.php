<?php

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mothership\DAM\Contracts\AssetProperty as AssetPropertyContract;
use Mothership\DAM\Database\Factories\PropertyFactory;
use Mothership\DAM\Presenters\AssetProperty as AssetPropertyPresenter;
use Mothership\HistoryControl\Contracts\HistoryAuditable;
use Mothership\HistoryControl\Interfaces\PresentableHistoryInterface;
use Mothership\HistoryControl\Traits\HistoryTrait;

class AssetProperty extends Model implements AssetPropertyContract, HistoryAuditable, PresentableHistoryInterface
{
    use HasFactory;
    use HistoryTrait;

    protected $historyTags = ['asset'];

    protected $table = 'dam_asset_properties';

    protected $fillable = ['name', 'type', 'language', 'value', 'dam_asset_id', 'is_filterable', 'sort_order',
        'field_id', 'value_number', 'value_date', 'value_bool', 'source'];

    protected $casts = [
        'is_filterable' => 'boolean',
        'sort_order'    => 'integer',
        'field_id'      => 'integer',
        'value_number'  => 'float',
        'value_date'    => 'date:Y-m-d',
        'value_bool'    => 'boolean',
    ];

    protected $auditExclude = [
        'id',
        'dam_asset_id',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'dam_asset_id');
    }

    /** The typed field this value belongs to (P4a); null for a free-text property. */
    public function field(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class, 'field_id');
    }

    /** A multi-select value's chosen options, in the field's option order. */
    public function options(): HasMany
    {
        return $this->hasMany(AssetPropertyOption::class, 'asset_property_id')->orderBy('sort')->orderBy('id');
    }

    public function getPrimaryModelIdForHistory(): int
    {
        return $this->dam_asset_id;
    }

    public static function getPresenters(): array
    {
        return [
            'name'     => AssetPropertyPresenter::class,
            'type'     => AssetPropertyPresenter::class,
            'value'    => AssetPropertyPresenter::class,
            'language' => AssetPropertyPresenter::class,
        ];
    }

    protected static function newFactory(): Factory
    {
        return PropertyFactory::new();
    }
}
