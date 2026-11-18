<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetPropertyOption;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\Automation\ValueDecisions;
use Mothership\DAM\Support\MetadataFieldLog;

/**
 * Field definitions (P4a, D9): the one place the admin screen and REST create, change, reorder, delete and restore
 * a field, so both answer the same 422s.
 *
 * - The code is the field's stable key (REST values and filters use it); it is set once and never changes.
 * - The type can change only while no asset has a value; an option can be dropped only while no asset uses it.
 * - card_priority 1-5 is unique among fields (the database enforces it too); an archived field gives its slot up.
 * - Delete: a field without values is deleted. A field with values is archived only with explicit confirmation;
 *   the values stay where they are, invisible to cards, filters and the editor, and restore() brings it all back.
 */
class FieldDefinitions
{
    public const MAX_OPTIONS = 200;

    /**
     * @throws ValidationException
     */
    public function create(array $input): MetadataField
    {
        $data = $this->validated(input: $input, field: null);

        $field = DB::transaction(function () use ($data) {
            $data['position'] ??= (int) MetadataField::query()->max('position') + 1;

            return MetadataField::query()->create($data);
        });

        MetadataFieldLog::write('field.created', ['field_id' => $field->id, 'code' => $field->code, 'type' => $field->type]);

        return $field;
    }

    /**
     * @throws ValidationException
     */
    public function update(MetadataField $field, array $input): MetadataField
    {
        $data = $this->validated(input: $input, field: $field);
        $renamed = isset($data['label']) && $data['label'] !== $field->label;

        DB::transaction(function () use ($field, $data, $renamed) {
            $field->fill($data)->save();

            if ($renamed) {
                // Linked values carry the label as their property name, so free-text readers stay in step.
                AssetProperty::query()->where('field_id', $field->id)->update(['name' => $field->label]);
            }
        });

        MetadataFieldLog::write('field.updated', ['field_id' => $field->id, 'code' => $field->code, 'renamed' => $renamed]);

        return $field->refresh();
    }

    /**
     * Sets the order of the active fields; $ids must name each of them exactly once.
     *
     * @param  list<int>  $ids
     *
     * @throws ValidationException
     */
    public function reorder(array $ids): void
    {
        $active = MetadataField::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $given = array_map('intval', $ids);
        $sorted = $given;
        sort($sorted);

        if ($sorted !== $active) {
            throw ValidationException::withMessages(['ids' => [trans('dam::app.admin.metadata-fields.errors.order')]]);
        }

        DB::transaction(function () use ($given) {
            foreach ($given as $position => $id) {
                MetadataField::query()->where('id', $id)->update(['position' => $position]);
            }
        });

        MetadataFieldLog::write('field.reordered', ['ids' => $given]);
    }

    /**
     * Deletes a field without values; archives a field with values when $confirmed, else refuses.
     *
     * @return array{deleted: bool, archived: bool, values: int}
     */
    public function delete(MetadataField $field, bool $confirmed): array
    {
        $values = $field->values()->count();

        if ($values === 0) {
            $field->delete();
            // P5b: a person's removals of this field's values mean nothing once the field is gone.
            app(ValueDecisions::class)->forgetTarget(kind: ValueDecisions::KIND_FIELD, targetIds: (int) $field->id);
            MetadataFieldLog::write('field.deleted', ['field_id' => $field->id, 'code' => $field->code]);

            return ['deleted' => true, 'archived' => false, 'values' => 0];
        }

        if (! $confirmed) {
            return ['deleted' => false, 'archived' => false, 'values' => $values];
        }

        $field->forceFill(['archived_at' => now(), 'card_priority' => null])->save();
        MetadataFieldLog::write('field.archived', ['field_id' => $field->id, 'code' => $field->code, 'values' => $values]);

        return ['deleted' => false, 'archived' => true, 'values' => $values];
    }

    public function restore(MetadataField $field): MetadataField
    {
        $field->forceFill(['archived_at' => null, 'position' => (int) MetadataField::query()->max('position') + 1])->save();
        MetadataFieldLog::write('field.restored', ['field_id' => $field->id, 'code' => $field->code]);

        return $field;
    }

    /** A code held by an archived field says so, because Restore (not a new field) is what frees it. */
    private function codeTakenMessage(mixed $code, ?MetadataField $field): string
    {
        $archived = is_string($code) && MetadataField::query()
            ->where('code', $code)->whereNotNull('archived_at')
            ->when($field, fn ($query) => $query->where('id', '!=', $field->id))
            ->exists();

        return trans($archived ? 'dam::app.admin.metadata-fields.errors.code-archived' : 'dam::app.admin.metadata-fields.errors.code-taken');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?MetadataField $field): array
    {
        $type = $input['type'] ?? $field?->type;
        $hasOptions = in_array($type, MetadataField::OPTION_TYPES, true);
        $messages = [
            'code.regex'               => trans('dam::app.admin.metadata-fields.errors.code-format'),
            'code.unique'              => $this->codeTakenMessage($input['code'] ?? null, $field),
            'card_priority.unique'     => trans('dam::app.admin.metadata-fields.errors.priority-taken'),
            'options.required'         => trans('dam::app.admin.metadata-fields.errors.options-required'),
            'options.min'              => trans('dam::app.admin.metadata-fields.errors.options-required'),
            'options.*.value.distinct' => trans('dam::app.admin.metadata-fields.errors.option-distinct'),
        ];
        $rules = [
            'code'             => $field ? ['sometimes', 'string', Rule::in([$field->code])] : ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/', Rule::unique('dam_metadata_fields', 'code')],
            'label'            => [$field ? 'sometimes' : 'required', 'string', 'max:191'],
            'type'             => [$field ? 'sometimes' : 'required', Rule::in(MetadataField::TYPES)],
            'options'          => $hasOptions
                ? [$field === null || ! $field->hasOptions() ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.self::MAX_OPTIONS]
                : ['nullable', 'array'],
            'options.*.value'  => ['required', 'string', 'max:191', 'distinct'],
            'options.*.label'  => ['nullable', 'string', 'max:191'],
            'required'         => ['sometimes', 'boolean'],
            'card_priority'    => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.MetadataField::CARD_SLOTS, Rule::unique('dam_metadata_fields', 'card_priority')->ignore($field?->id)],
            'position'         => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_filterable'    => ['sometimes', 'boolean'],
        ];
        $messages['code.in'] = trans('dam::app.admin.metadata-fields.errors.code-fixed');

        $data = Validator::make($input, $rules, $messages)->validate();
        unset($data['code']);

        if ($field === null) {
            $data['code'] = $input['code'];
        }

        if (array_key_exists('label', $data)) {
            $data['label'] = trim($data['label']);
        }

        if (in_array($type, MetadataField::OPTION_TYPES, true) && array_key_exists('options', $data)) {
            $data['options'] = array_values(array_map(fn ($option) => [
                'value' => trim((string) $option['value']),
                'label' => trim((string) (($option['label'] ?? '') !== '' ? $option['label'] : $option['value'])),
            ], $data['options'] ?? []));
        } elseif (! in_array($type, MetadataField::OPTION_TYPES, true)) {
            $data['options'] = null;
        }

        if ($field !== null) {
            $this->guardExistingValues(field: $field, type: $type, options: $data['options'] ?? $field->options);
        }

        return $data;
    }

    /**
     * @throws ValidationException
     */
    private function guardExistingValues(MetadataField $field, string $type, ?array $options): void
    {
        $values = $field->values();

        if ($type !== $field->type && $values->exists()) {
            throw ValidationException::withMessages(['type' => [trans('dam::app.admin.metadata-fields.errors.type-locked')]]);
        }

        if (! $field->hasOptions()) {
            return;
        }

        $kept = array_map(fn ($option) => (string) $option['value'], $options ?? []);
        $dropped = array_values(array_diff($field->optionValues(), $kept));

        if ($dropped === []) {
            return;
        }

        $inUse = $field->type === 'select'
            ? AssetProperty::query()->where('field_id', $field->id)->whereIn('value', $dropped)->pluck('value')
            : AssetPropertyOption::query()->whereIn('value', $dropped)
                ->whereIn('asset_property_id', AssetProperty::query()->select('id')->where('field_id', $field->id))->pluck('value');

        if ($inUse->isNotEmpty()) {
            throw ValidationException::withMessages(['options' => [trans('dam::app.admin.metadata-fields.errors.option-in-use', ['option' => $inUse->unique()->implode(', ')])]]);
        }
    }
}
