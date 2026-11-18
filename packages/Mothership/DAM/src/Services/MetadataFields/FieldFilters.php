<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\MetadataField;

/**
 * Typed field filters for the Library listing and the REST asset listing (P4a, D10 part). Each filter is one
 * EXISTS over the asset's value row, ANDed onto a query that already carries the access scope, so a hidden asset can
 * never match or be counted.
 *
 * Library request shape, `filter_field[<code>]`:
 * - text, url: a substring (case-insensitive);
 * - select, multi_select: one option value or a list of them (any of);
 * - number: {min, max}; date: {from, to} (YYYY-MM-DD), either end optional;
 * - boolean: 1 or 0.
 * An unknown, archived or non-filterable code is ignored, so a saved filter never breaks the listing.
 */
class FieldFilters
{
    /** @return Collection<int, MetadataField> */
    public function filterableFields(): Collection
    {
        return MetadataField::query()->active()->where('is_filterable', true)->ordered()->get();
    }

    /**
     * The Library's `filter_field` input as [code => [field, normalized criterion]].
     *
     * @return array<string, array{field: MetadataField, criterion: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public function parse(mixed $input): array
    {
        if (! is_array($input) || $input === []) {
            return [];
        }

        $fields = $this->filterableFields()->keyBy('code');
        $parsed = [];
        $errors = [];

        foreach ($input as $code => $raw) {
            $field = $fields->get((string) $code);

            if (! $field instanceof MetadataField) {
                continue;
            }

            $criterion = $this->criterion(field: $field, raw: $raw);

            if ($criterion === false) {
                $errors["filter_field.{$code}"] = [trans('dam::app.admin.metadata-fields.validation.filter', ['field' => $field->label])];
            } elseif ($criterion !== null) {
                $parsed[$field->code] = ['field' => $field, 'criterion' => $criterion];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $parsed;
    }

    /**
     * @param  array<string, array{field: MetadataField, criterion: array<string, mixed>}>  $filters
     */
    public function apply($query, array $filters): void
    {
        foreach ($filters as ['field' => $field, 'criterion' => $criterion]) {
            $query->whereExists(fn ($sub) => $this->constrain(sub: $this->valueRows($sub, $field), field: $field, criterion: $criterion));
        }
    }

    /**
     * The REST listing's `field.<code>` filter keys and the operators each type accepts (ApiDataSource::addFilter).
     *
     * @return array<string, list<string>>
     */
    public function apiOperators(): array
    {
        return $this->filterableFields()->mapWithKeys(fn (MetadataField $field) => ['field.'.$field->code => match ($field->type) {
            'number', 'date'           => ['=', '>', '>=', '<', '<=', 'BETWEEN'],
            'select', 'multi_select'   => ['=', 'IN'],
            'boolean'                  => ['='],
            default                    => ['=', 'LIKE'],
        }])->all();
    }

    /**
     * One REST filter, `field.<code>` with {operator, value}, as the same EXISTS the Library uses.
     *
     * @param  array{operator: string, value: mixed}  $filter
     */
    public function applyApi($query, string $code, array $filter): void
    {
        $field = $this->filterableFields()->firstWhere('code', $code);

        if (! $field instanceof MetadataField) {
            return;
        }

        $value = $filter['value'];
        $raw = match ($field->type) {
            'number' => match ($filter['operator']) {
                '>=', '>' => ['min' => $value], '<=', '<' => ['max' => $value],
                'BETWEEN' => ['min' => $value[0] ?? null, 'max' => $value[1] ?? null],
                default   => ['min' => $value, 'max' => $value],
            },
            'date' => match ($filter['operator']) {
                '>=', '>' => ['from' => $value], '<=', '<' => ['to' => $value],
                'BETWEEN' => ['from' => $value[0] ?? null, 'to' => $value[1] ?? null],
                default   => ['from' => $value, 'to' => $value],
            },
            default => $value,
        };
        $criterion = $this->criterion(field: $field, raw: $raw);

        if ($criterion === false) {
            throw ValidationException::withMessages(['filters' => [trans('dam::app.admin.metadata-fields.validation.filter', ['field' => $field->label])]]);
        }

        if ($criterion === null) {
            return;
        }

        // A strict bound (> or <) excludes the bound itself.
        $criterion['strict'] = in_array($filter['operator'], ['>', '<'], true);
        // REST `=` on text is an exact match; LIKE is the Library's contains.
        $criterion['exact'] = in_array($field->type, ['text', 'url'], true) && $filter['operator'] === '=';

        $query->whereExists(fn ($sub) => $this->constrain(sub: $this->valueRows($sub, $field), field: $field, criterion: $criterion));
    }

    /**
     * A filter value as a criterion; null when it filters nothing (blank), false when it is malformed.
     *
     * @return array<string, mixed>|false|null
     */
    private function criterion(MetadataField $field, mixed $raw): array|false|null
    {
        switch ($field->type) {
            case 'number':
            case 'date':
                [$low, $high] = $field->type === 'number' ? ['min', 'max'] : ['from', 'to'];
                if (! is_array($raw)) {
                    return false;
                }
                $bounds = [];
                foreach ([$low, $high] as $end) {
                    $value = is_string($raw[$end] ?? null) ? trim($raw[$end]) : ($raw[$end] ?? null);
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $ok = $field->type === 'number'
                        ? is_numeric($value) && ! is_bool($value)
                        : is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
                    if (! $ok) {
                        return false;
                    }
                    $bounds[$end === $low ? 'low' : 'high'] = $field->type === 'number' ? (float) $value : $value;
                }

                return $bounds === [] ? null : $bounds;

            case 'boolean':
                return match (true) {
                    $raw === null, $raw === ''                                 => null,
                    $raw === true, $raw === 1, $raw === '1', $raw === 'true'   => ['bool' => true],
                    $raw === false, $raw === 0, $raw === '0', $raw === 'false' => ['bool' => false],
                    default                                                    => false,
                };

            case 'select':
            case 'multi_select':
                $list = is_array($raw) ? $raw : [$raw];
                $list = array_values(array_filter($list, fn ($item) => $item !== null && $item !== ''));
                foreach ($list as $item) {
                    if (! is_string($item) && ! is_int($item)) {
                        return false;
                    }
                }

                return $list === [] ? null : ['any' => array_map('strval', $list)];

            default:
                if (! is_scalar($raw) || is_bool($raw)) {
                    return false;
                }
                $text = trim((string) $raw);

                return $text === '' ? null : ['contains' => mb_substr($text, 0, 255)];
        }
    }

    private function valueRows($sub, MetadataField $field)
    {
        return $sub->select(DB::raw(1))
            ->from('dam_asset_properties')
            ->whereColumn('dam_asset_properties.dam_asset_id', 'dam_assets.id')
            ->where('dam_asset_properties.field_id', $field->id);
    }

    /** @param  array<string, mixed>  $criterion */
    private function constrain($sub, MetadataField $field, array $criterion)
    {
        $grammar = DB::connection()->getQueryGrammar();

        switch ($field->type) {
            case 'number':
            case 'date':
                $column = $field->type === 'number' ? 'dam_asset_properties.value_number' : 'dam_asset_properties.value_date';
                $strict = $criterion['strict'] ?? false;
                if (array_key_exists('low', $criterion)) {
                    $sub->where($column, $strict ? '>' : '>=', $criterion['low']);
                }
                if (array_key_exists('high', $criterion)) {
                    $sub->where($column, $strict ? '<' : '<=', $criterion['high']);
                }
                break;

            case 'boolean':
                $sub->where('dam_asset_properties.value_bool', $criterion['bool']);
                break;

            case 'select':
                $sub->whereIn('dam_asset_properties.value', $criterion['any']);
                break;

            case 'multi_select':
                $sub->whereExists(fn ($options) => $options->select(DB::raw(1))
                    ->from('dam_asset_property_options')
                    ->whereColumn('dam_asset_property_options.asset_property_id', 'dam_asset_properties.id')
                    ->whereIn('dam_asset_property_options.value', $criterion['any']));
                break;

            default:
                $value = 'LOWER('.$grammar->wrap('dam_asset_properties.value').')';
                ($criterion['exact'] ?? false)
                    ? $sub->whereRaw($value.' = ?', [mb_strtolower($criterion['contains'])])
                    : $sub->whereRaw($value.' LIKE ?', ['%'.mb_strtolower($criterion['contains']).'%']);
        }

        return $sub;
    }
}
