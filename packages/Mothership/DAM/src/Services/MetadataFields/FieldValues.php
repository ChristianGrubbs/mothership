<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mothership\Core\Models\Locale;
use Mothership\DAM\Events\AssetValuesChanged;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetPropertyOption;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Automation\ValueDecisions;
use Mothership\DAM\Services\Automation\ValueSource;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\MetadataFieldLog;

/**
 * Reads and writes typed field values (P4a, D9). Storage, per field type:
 * - text, url, select: the canonical string in the property row's existing `value` column;
 * - number, date, boolean: value_number, value_date, value_bool, with `value` holding the same value as text so
 *   free-text readers (Details layouts, the Campus lookup, `filter_prop_*`) keep working;
 * - multi_select: one dam_asset_property_options row per chosen option, `value` holding them comma-joined.
 *
 * One property row per asset and field. Callers validate first (FieldValueValidator); write() trusts its input.
 */
class FieldValues
{
    /**
     * How many assets the viewer may list carry each field, by field id: the admin screen's counts and the delete
     * confirmation's number. Hidden assets are never counted; whether a field has values at all (archive rather than
     * delete) is FieldDefinitions' call, over every asset.
     *
     * @return array<int, int>
     */
    public function visibleValueCounts(AccessContext $context): array
    {
        $rows = AssetProperty::query()->whereNotNull('field_id');
        app(AssetAccessDecision::class)->scopeListable($rows, $context, 'dam_asset_properties.dam_asset_id');

        return $rows->selectRaw('field_id, COUNT(DISTINCT dam_asset_id) AS assets')->groupBy('field_id')
            ->pluck('assets', 'field_id')->map(fn ($count) => (int) $count)->all();
    }

    /** @return Collection<int, MetadataField> */
    public function activeFields(): Collection
    {
        return MetadataField::query()->active()->ordered()->get();
    }

    /**
     * Each asset's typed values, keyed by field code; an asset with none maps to [].
     *
     * @param  list<int>  $assetIds
     * @param  Collection<int, MetadataField>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function forAssets(array $assetIds, Collection $fields): array
    {
        $values = array_fill_keys($assetIds, []);

        if ($assetIds === [] || $fields->isEmpty()) {
            return $values;
        }

        $byId = $fields->keyBy('id');
        $rows = AssetProperty::query()
            ->whereIn('dam_asset_id', $assetIds)
            ->whereIn('field_id', $byId->keys()->all())
            ->orderBy('id')
            ->get(['id', 'dam_asset_id', 'field_id', 'value', 'value_number', 'value_date', 'value_bool']);

        $options = AssetPropertyOption::query()
            ->whereIn('asset_property_id', $rows->filter(fn ($row) => $byId->get($row->field_id)?->type === 'multi_select')->pluck('id')->all() ?: [0])
            ->orderBy('sort')->orderBy('id')
            ->get(['asset_property_id', 'value'])
            ->groupBy('asset_property_id');

        foreach ($rows as $row) {
            $field = $byId->get($row->field_id);
            $assetId = (int) $row->dam_asset_id;

            // One value per asset and field: the oldest row wins, as the writer keeps it.
            if ($field === null || array_key_exists($field->code, $values[$assetId] ?? [])) {
                continue;
            }

            $value = match ($field->type) {
                'number'       => $row->value_number,
                'date'         => $row->value_date?->format('Y-m-d'),
                'boolean'      => $row->value_bool,
                'multi_select' => $options->get($row->id)?->pluck('value')->map(fn ($v) => (string) $v)->values()->all() ?? [],
                default        => $row->value,
            };

            if ($value !== null && $value !== []) {
                $values[$assetId][$field->code] = $value;
            }
        }

        return $values;
    }

    /**
     * One asset's fields for the modal editor and REST reads: every active field in order with its value, plus the
     * codes of required fields still empty.
     *
     * @return array{fields: list<array<string, mixed>>, values: array<string, mixed>, missing_required: list<string>}
     */
    public function forAsset(int $assetId): array
    {
        $fields = $this->activeFields();
        $values = $this->forAssets(assetIds: [$assetId], fields: $fields)[$assetId] ?? [];

        return [
            'fields'           => $fields->map(fn (MetadataField $field) => $field->toDefinition() + ['value' => $values[$field->code] ?? null])->values()->all(),
            'values'           => $values,
            'missing_required' => $this->missingRequired(fields: $fields, values: $values),
        ];
    }

    /**
     * The active required fields an asset has no value for: the modal's "missing required" badge.
     *
     * @param  Collection<int, MetadataField>  $fields
     * @param  array<string, mixed>  $values  the asset's values by code
     * @return list<string>
     */
    public function missingRequired(Collection $fields, array $values): array
    {
        return $fields->filter(fn (MetadataField $field) => $field->required && ! array_key_exists($field->code, $values))
            ->pluck('code')->values()->all();
    }

    /**
     * The card payload: up to five fields with a card priority, in priority order, each as label and display value.
     *
     * @param  list<int>  $assetIds
     * @return array<int, list<array{code: string, label: string, value: string}>>
     */
    public function cardFields(array $assetIds): array
    {
        $fields = MetadataField::query()->active()->whereNotNull('card_priority')
            ->orderBy('card_priority')->limit(MetadataField::CARD_SLOTS)->get();
        $cards = array_fill_keys($assetIds, []);

        foreach ($this->forAssets(assetIds: $assetIds, fields: $fields) as $assetId => $values) {
            foreach ($fields as $field) {
                if (array_key_exists($field->code, $values)) {
                    $cards[$assetId][] = ['code' => $field->code, 'label' => $field->label, 'value' => $this->display(field: $field, value: $values[$field->code])];
                }
            }
        }

        return $cards;
    }

    /** A value as one line of text, for cards and the stored `value` column. */
    public function display(MetadataField $field, mixed $value): string
    {
        return match ($field->type) {
            'number'       => self::numberText((float) $value),
            'boolean'      => $value ? trans('dam::app.admin.metadata-fields.boolean.yes') : trans('dam::app.admin.metadata-fields.boolean.no'),
            'select'       => $field->optionLabel((string) $value),
            'multi_select' => implode(', ', array_map(fn ($option) => $field->optionLabel((string) $option), (array) $value)),
            default        => (string) $value,
        };
    }

    public static function numberText(float $number): string
    {
        $text = rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * A person's write of validated values to one asset; a null value clears its field. Free-text properties with the
     * field's name are never touched.
     *
     * P5b (§3.4): the row's `source` becomes `manual`, whoever wrote it before, so no mapping or rule replaces it.
     * Clearing a value records the removal (dam_value_decisions), so no machine writer puts it back; setting one
     * again lifts that removal. Each call that changes something raises one AssetValuesChanged.
     *
     * `$expected` is the editor's precondition: for each code in it, the value the person started from. When the stored
     * value differs (someone else saved since the editor opened), nothing is written and FieldValueConflict is thrown.
     * The check runs under the asset lock, so no write lands between it and the save. Codes not in it are not checked.
     *
     * @param  array<string, array{field: MetadataField, value: mixed}>  $values  FieldValueValidator::validate() output
     * @param  string  $source  the channel (web, bulk, api, web-property, api-property), for the log
     * @param  array<string, mixed>|null  $expected  field code => the value the editor loaded
     *
     * @throws FieldValueConflict
     */
    public function write(Asset $asset, array $values, string $source, ?array $expected = null): void
    {
        if ($values === []) {
            return;
        }

        // A person's write (set, change or clear) is for the files they added when their role is limited to its own items;
        // mappings, rules and imports write through writeMachine() and are never asked.
        app(RoleBuilder::class)->abortUnlessOwn(model: $asset);

        $cleared = 0;
        $changed = [];
        $actorId = auth()->guard('admin')->id() ?? auth()->guard('api')->id();

        DB::transaction(function () use ($asset, $values, $expected, $source, $actorId, &$cleared, &$changed) {
            // The same per-asset lock a mapping's or a rule's write takes, so a person's write and a machine's decision never interleave.
            $this->lockAsset((int) $asset->id);

            if ($expected !== null) {
                $this->assertUnchanged(asset: $asset, values: $values, expected: $expected, source: $source);
            }

            $decisions = app(ValueDecisions::class);

            foreach ($values as ['field' => $field, 'value' => $value]) {
                $result = $this->store(asset: $asset, field: $field, value: $value, provenance: ValueSource::MANUAL);

                if ($value === null) {
                    if ($result === 'cleared') {
                        $cleared++;
                        $decisions->remove(assetIds: (int) $asset->id, kind: ValueDecisions::KIND_FIELD, targetId: (int) $field->id, actorId: $actorId === null ? null : (int) $actorId);
                    }
                } else {
                    $decisions->clear(assetIds: (int) $asset->id, kind: ValueDecisions::KIND_FIELD, targetId: (int) $field->id);
                }

                if ($result !== 'unchanged') {
                    $changed[(int) $field->id] = $field->code;
                }
            }
        });

        MetadataFieldLog::write('field_values.written', [
            'asset_id' => (int) $asset->id, 'source' => $source, 'codes' => array_keys($values), 'cleared' => $cleared,
        ]);

        $this->changed(asset: $asset, changed: $changed, provenance: ValueSource::MANUAL);
    }

    /**
     * A mapping's or a rule's write of one validated value (ValueWriter decides whether it may). The row keeps
     * `$provenance` as its source; no removal is recorded or lifted. Returns false when the value was already there.
     */
    public function writeMachine(Asset $asset, MetadataField $field, mixed $value, string $provenance): bool
    {
        $result = DB::transaction(fn () => $this->store(asset: $asset, field: $field, value: $value, provenance: $provenance));

        MetadataFieldLog::write('field_values.written', [
            'asset_id' => (int) $asset->id, 'source' => $provenance, 'codes' => [$field->code], 'cleared' => 0,
        ]);

        if ($result === 'unchanged') {
            return false;
        }

        $this->changed(asset: $asset, changed: [(int) $field->id => $field->code], provenance: $provenance);

        return true;
    }

    /**
     * A person deletes a property row through the free-text property endpoints (web or REST, one or many). `$delete`
     * removes the row. When the row was a field's value, that is a person clearing the field: the removal is recorded,
     * so no mapping or rule writes it back, and the change is raised. A free-text row is no field value and changes
     * nothing here.
     *
     * For a field's row the delete and the recorded removal are one transaction under the asset lock a mapping's or a
     * rule's write waits on, so no machine write lands between the two and puts the value back.
     */
    public function deleteByPerson(AssetProperty $row, ?int $actorId, \Closure $delete): void
    {
        if (! $row->field_id) {
            $delete();

            return;
        }

        DB::transaction(function () use ($row, $actorId, $delete) {
            $this->lockAsset((int) $row->dam_asset_id);
            $delete();
            app(ValueDecisions::class)->remove(assetIds: (int) $row->dam_asset_id, kind: ValueDecisions::KIND_FIELD, targetId: (int) $row->field_id, actorId: $actorId);
        });

        $field = MetadataField::query()->find($row->field_id);

        if ($field !== null && ($asset = Asset::query()->find($row->dam_asset_id)) !== null) {
            $this->changed(asset: $asset, changed: [(int) $field->id => $field->code], provenance: ValueSource::MANUAL);
        }
    }

    /**
     * writeMachine, with the decision made inside the write. The asset row is locked first (a person's `write()` takes
     * the same lock, even for a field that has no value row yet), then `$guard` looks at the stored value and the
     * recorded removal again; a person's change that landed after the caller's own check is therefore seen, and the
     * machine value is not written over or back over it.
     *
     * @param  \Closure(): bool  $guard  true when the machine may still write
     * @return 'written'|'unchanged'|'refused'
     */
    public function writeMachineGuarded(Asset $asset, MetadataField $field, mixed $value, string $provenance, \Closure $guard): string
    {
        $result = DB::transaction(function () use ($asset, $field, $value, $provenance, $guard) {
            $this->lockAsset((int) $asset->id);

            return $guard() ? $this->store(asset: $asset, field: $field, value: $value, provenance: $provenance) : 'refused';
        });

        if ($result === 'refused') {
            return 'refused';
        }

        MetadataFieldLog::write('field_values.written', [
            'asset_id' => (int) $asset->id, 'source' => $provenance, 'codes' => [$field->code], 'cleared' => 0,
        ]);

        if ($result === 'unchanged') {
            return 'unchanged';
        }

        $this->changed(asset: $asset, changed: [(int) $field->id => $field->code], provenance: $provenance);

        return 'written';
    }

    /**
     * Refuses the write when a field the person changes no longer holds the value their editor loaded.
     *
     * @param  array<string, array{field: MetadataField, value: mixed}>  $values
     * @param  array<string, mixed>  $expected
     *
     * @throws FieldValueConflict
     */
    private function assertUnchanged(Asset $asset, array $values, array $expected, string $source): void
    {
        $checked = array_filter($values, fn (string $code) => array_key_exists($code, $expected), ARRAY_FILTER_USE_KEY);

        if ($checked === []) {
            return;
        }

        $fields = collect($checked)->map(fn (array $entry) => $entry['field'])->values();
        $stored = $this->forAssets(assetIds: [(int) $asset->id], fields: $fields)[(int) $asset->id] ?? [];
        $stale = [];

        foreach ($checked as $code => ['field' => $field]) {
            if (! $this->sameValue(field: $field, stored: $stored[$code] ?? null, expected: $expected[$code])) {
                $stale[] = (string) $code;
            }
        }

        if ($stale !== []) {
            MetadataFieldLog::write('field_values.conflict', ['asset_id' => (int) $asset->id, 'source' => $source, 'codes' => $stale]);

            throw new FieldValueConflict($stale);
        }
    }

    /** Whether a stored value (as forAssets() reads it) is the value an editor was shown. */
    private function sameValue(MetadataField $field, mixed $stored, mixed $expected): bool
    {
        if ($expected === '' || $expected === []) {
            $expected = null;
        }

        if ($stored === null || $expected === null) {
            return $stored === null && $expected === null;
        }

        return match ($field->type) {
            'number'       => is_numeric($expected) && self::numberText((float) $stored) === self::numberText((float) $expected),
            'boolean'      => $stored === (is_bool($expected) ? $expected : in_array($expected, [1, '1', 'true'], true)),
            'multi_select' => is_array($expected) && collect($stored)->map(fn ($v) => (string) $v)->sort()->values()->all()
                === collect($expected)->map(fn ($v) => is_scalar($v) ? (string) $v : '')->sort()->values()->all(),
            default        => is_scalar($expected) && (string) $stored === (string) $expected,
        };
    }

    /** Serializes writers of one asset's field values: held until the surrounding transaction ends. */
    private function lockAsset(int $assetId): void
    {
        Asset::query()->whereKey($assetId)->lockForUpdate()->value('id');
    }

    /**
     * The field's one value row on this asset, with its source, or null when it has none.
     *
     * @return array{text: string, options: list<string>, source: string}|null
     */
    public function current(int $assetId, MetadataField $field): ?array
    {
        $row = AssetProperty::query()->where('dam_asset_id', $assetId)->where('field_id', $field->id)->orderBy('id')->first();

        if ($row === null) {
            return null;
        }

        return [
            'text'    => (string) $row->value,
            'options' => $field->type === 'multi_select'
                ? AssetPropertyOption::query()->where('asset_property_id', $row->id)->orderBy('sort')->orderBy('id')->pluck('value')->map(fn ($v) => (string) $v)->all()
                : [],
            'source'  => (string) ($row->source ?? ValueSource::MANUAL),
        ];
    }

    /**
     * Stores (or clears, for null) one field's value. Only the linked row is the field's value: a free-text row with
     * the same name (an adoption leftover) is left exactly as it is; `dam:fields:adopt` is the one thing that links an
     * existing row.
     *
     * @return 'written'|'cleared'|'unchanged'
     */
    private function store(Asset $asset, MetadataField $field, mixed $value, string $provenance): string
    {
        $row = AssetProperty::query()->where('dam_asset_id', $asset->id)->where('field_id', $field->id)->orderBy('id')->first();

        if ($value === null) {
            if ($row === null) {
                return 'unchanged';
            }

            AssetPropertyOption::query()->where('asset_property_id', $row->id)->delete();
            $row->delete();

            return 'cleared';
        }

        $text = $this->storedText(field: $field, value: $value);
        $options = $field->type === 'multi_select' ? array_map('strval', array_values((array) $value)) : [];
        $before = $row === null ? null : $this->current(assetId: (int) $asset->id, field: $field);
        $same = $before !== null && $before['text'] === $text && $before['options'] === $options;

        $row ??= new AssetProperty(['dam_asset_id' => $asset->id, 'language' => $this->language()]);
        $row->fill([
            'field_id'     => $field->id,
            'name'         => $field->label,
            'type'         => $field->type,
            'value'        => $text,
            'value_number' => $field->type === 'number' ? $value : null,
            'value_date'   => $field->type === 'date' ? $value : null,
            'value_bool'   => $field->type === 'boolean' ? $value : null,
            'source'       => $provenance,
        ])->save();

        if (! $same) {
            AssetPropertyOption::query()->where('asset_property_id', $row->id)->delete();

            foreach ($options as $sort => $option) {
                AssetPropertyOption::query()->create(['asset_property_id' => $row->id, 'value' => $option, 'sort' => $sort]);
            }
        }

        return $same ? 'unchanged' : 'written';
    }

    /** @param  array<int, string>  $changed  field id => code */
    private function changed(Asset $asset, array $changed, string $provenance): void
    {
        if ($changed === []) {
            return;
        }

        Event::dispatch(new AssetValuesChanged(
            assetId: (int) $asset->id,
            kind: AssetValuesChanged::KIND_FIELD,
            keys: array_values(array_map(fn (string $code) => 'fields.'.$code, $changed)),
            source: $provenance,
            fieldIds: array_keys($changed),
        ));
    }

    /**
     * A write through the free-text property endpoints (web and REST) that names a field, by the row's link or by a
     * name equal to a field's label or code, is checked and stored as that field's value, so those endpoints cannot
     * write around the field's type. Returns the field's value row (null once cleared), or false when the property is
     * plain free text and the caller should carry on.
     *
     * @throws ValidationException
     */
    public function writeThroughProperty(Asset $asset, ?AssetProperty $row, string $name, mixed $raw, string $source): AssetProperty|false|null
    {
        // An existing unlinked row (an adoption leftover, or one written before its field existed) stays free text:
        // editing it never changes what kind of value it is. Only `dam:fields:adopt` links an existing row.
        if ($row !== null && ! $row->field_id) {
            return false;
        }

        $validator = app(FieldValueValidator::class);
        $field = $row?->field_id
            ? MetadataField::query()->active()->find($row->field_id)
            : $validator->fieldForPropertyName($name);

        if (! $field instanceof MetadataField) {
            return false;
        }

        if ($field->type === 'multi_select' && is_string($raw)) {
            // The property form has one text box: a comma-separated list of option values.
            $raw = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($item) => $item !== ''));
        }

        $value = $validator->validateOne(field: $field, raw: $raw, errorKey: 'value');

        $this->write(asset: $asset, values: [$field->code => ['field' => $field, 'value' => $value]], source: $source);

        return AssetProperty::query()->where('dam_asset_id', $asset->id)->where('field_id', $field->id)->orderBy('id')->first();
    }

    /** The text kept in `value`: what free-text readers show and match. */
    public function storedText(MetadataField $field, mixed $value): string
    {
        return match ($field->type) {
            'number'       => self::numberText((float) $value),
            'boolean'      => $value ? '1' : '0',
            'multi_select' => implode(', ', (array) $value),
            default        => (string) $value,
        };
    }

    /** The default channel's locale id, as the properties form stores it; field values are not per-language. */
    private function language(): string
    {
        try {
            $code = core()->getDefaultLocaleCodeFromDefaultChannel();

            return (string) (Locale::query()->where('code', $code)->value('id') ?? $code);
        } catch (\Throwable) {
            return 'en_US';
        }
    }
}
