<?php

declare(strict_types=1);

namespace Mothership\DAM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An admin-defined, typed metadata field (P4a, D9). Values live on dam_asset_properties rows linked by field_id;
 * a multi-select value's options live in dam_asset_property_options.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string $type
 * @property array<int, array{value: string, label: string}>|null $options
 * @property bool $required
 * @property int|null $card_priority
 * @property int $position
 * @property bool $is_filterable
 * @property Carbon|null $archived_at
 */
class MetadataField extends Model
{
    public const TYPES = ['text', 'number', 'date', 'select', 'multi_select', 'url', 'boolean'];

    public const OPTION_TYPES = ['select', 'multi_select'];

    /** Library cards show at most this many fields, one per priority. */
    public const CARD_SLOTS = 5;

    protected $table = 'dam_metadata_fields';

    protected $fillable = ['code', 'label', 'type', 'options', 'required', 'card_priority', 'position', 'is_filterable', 'archived_at'];

    protected $casts = [
        'options'       => 'array',
        'required'      => 'boolean',
        'card_priority' => 'integer',
        'position'      => 'integer',
        'is_filterable' => 'boolean',
        'archived_at'   => 'datetime',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(AssetProperty::class, 'field_id');
    }

    /** Fields in use: not archived. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, self::OPTION_TYPES, true);
    }

    /** @return list<string> */
    public function optionValues(): array
    {
        return array_values(array_map(fn ($option) => (string) $option['value'], $this->options ?? []));
    }

    public function optionLabel(string $value): string
    {
        foreach ($this->options ?? [] as $option) {
            if ((string) $option['value'] === $value) {
                return (string) ($option['label'] ?? $value);
            }
        }

        return $value;
    }

    /** The shape the admin screen, the modal, the filter panel and REST read. */
    public function toDefinition(): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'label'         => $this->label,
            'type'          => $this->type,
            'options'       => $this->hasOptions() ? array_values($this->options ?? []) : [],
            'required'      => $this->required,
            'card_priority' => $this->card_priority,
            'position'      => $this->position,
            'is_filterable' => $this->is_filterable,
            'archived'      => $this->archived_at !== null,
        ];
    }
}
