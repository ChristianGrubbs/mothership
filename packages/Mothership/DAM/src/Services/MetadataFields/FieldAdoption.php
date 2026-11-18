<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\MetadataFields;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Support\MetadataFieldLog;

/**
 * Adopts free-text property names as typed fields (P4a, D9): `dam:fields:adopt`. Never run by a migration.
 *
 * Names are grouped case-insensitively. Each group proposes one field, or reuses an active field whose code or label
 * matches. Type inference over the group's non-empty values, first rule that holds:
 *   1. every value numeric                      -> number
 *   2. every value an ISO date (YYYY-MM-DD, or an ISO 8601 date-time, kept as its date) -> date
 *   3. at most 12 distinct values               -> select, the distinct values as options
 *   4. otherwise                                -> text
 * Applying links, per asset, the oldest row with a value the field accepts. Everything else stays a free-text
 * property exactly as it was: a second row for the same asset (another language), an empty value, or a value the
 * field refuses. rows = linked + leftovers for every name, and after --apply the linked rows are recounted from the
 * database; a mismatch rolls the whole run back.
 */
class FieldAdoption
{
    public const SELECT_MAX_OPTIONS = 12;

    private const ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    public function __construct(private readonly FieldValueValidator $validator) {}

    /**
     * @param  list<string>|null  $names  limit the run to these property names (case-insensitive)
     * @return array<string, mixed> the report
     */
    public function run(bool $apply, ?array $names = null): array
    {
        $groups = $this->groups($names);
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'fields' => [], 'totals' => ['names' => 0, 'rows' => 0, 'linked' => 0, 'leftovers' => 0, 'fields_created' => 0, 'fields_reused' => 0]];

        $work = function () use ($groups, $apply, &$report) {
            $codes = MetadataField::query()->pluck('code')->all();

            foreach ($groups as $key => $rows) {
                $entry = $this->adoptGroup(key: $key, rows: $rows, apply: $apply, codes: $codes);
                $report['fields'][] = $entry;
                $report['totals']['names']++;
                $report['totals']['rows'] += $entry['rows'];
                $report['totals']['linked'] += $entry['linked'];
                $report['totals']['leftovers'] += $entry['leftovers']['total'];
                // A field is created (or reused) only when it gets at least one value.
                if ($entry['linked'] > 0) {
                    $report['totals'][$entry['existing'] ? 'fields_reused' : 'fields_created']++;
                }
            }

            $report['reconciled'] = $report['totals']['rows'] === $report['totals']['linked'] + $report['totals']['leftovers']
                && collect($report['fields'])->every(fn ($entry) => $entry['reconciled']);

            if ($apply && ! $report['reconciled']) {
                throw new FieldAdoptionException($report);
            }
        };

        $apply ? DB::transaction($work) : $work();

        MetadataFieldLog::write('adopt.run', [
            'mode'           => $report['mode'], 'names' => $report['totals']['names'], 'rows' => $report['totals']['rows'],
            'linked'         => $report['totals']['linked'], 'leftovers' => $report['totals']['leftovers'],
            'fields_created' => $report['totals']['fields_created'], 'reconciled' => $report['reconciled'] ?? false,
        ]);

        return $report;
    }

    /**
     * Unlinked property rows grouped by lower-cased, trimmed name, oldest first.
     *
     * @return Collection<string, Collection<int, AssetProperty>>
     */
    private function groups(?array $names): Collection
    {
        $wanted = $names ? array_map(fn ($name) => mb_strtolower(trim((string) $name)), $names) : null;

        return AssetProperty::query()->whereNull('field_id')
            ->orderBy('dam_asset_id')->orderBy('id')
            ->get(['id', 'dam_asset_id', 'name', 'value', 'is_filterable'])
            ->groupBy(fn (AssetProperty $row) => mb_strtolower(trim((string) $row->name)))
            ->filter(fn ($rows, $key) => $key !== '' && ($wanted === null || in_array($key, $wanted, true)))
            ->sortKeys();
    }

    /**
     * @param  Collection<int, AssetProperty>  $rows
     * @param  list<string>  $codes  every code in use, archived included; grows as fields are proposed
     */
    private function adoptGroup(string $key, Collection $rows, bool $apply, array &$codes): array
    {
        // The label is the most common spelling of the name.
        $label = mb_substr((string) $rows->countBy(fn ($row) => trim((string) $row->name))->sortDesc()->keys()->first(), 0, 191);
        $existing = MetadataField::query()->active()->get()
            ->first(fn (MetadataField $field) => $field->code === $key || mb_strtolower($field->label) === $key);
        $field = $existing ?? $this->propose(label: $label, rows: $rows, codes: $codes);

        if (! $existing) {
            $codes[] = $field->code;
        }

        $linked = [];
        $leftovers = ['duplicate' => 0, 'empty' => 0, 'invalid' => 0];
        $seen = [];

        foreach ($rows as $row) {
            $raw = self::candidate(field: $field, raw: (string) $row->value);

            if ($raw === null) {
                $leftovers['empty']++;

                continue;
            }

            if (isset($seen[$row->dam_asset_id]) || ($existing && $this->hasLinkedValue(field: $existing, assetId: (int) $row->dam_asset_id))) {
                $leftovers['duplicate']++;

                continue;
            }

            if ($this->validator->check(field: $field, raw: $field->type === 'multi_select' ? array_map('trim', explode(',', $raw)) : $raw, value: $value) !== null || $value === null) {
                $leftovers['invalid']++;

                continue;
            }

            $seen[$row->dam_asset_id] = true;
            $linked[] = ['row' => $row, 'value' => $value];
        }

        $entry = [
            'name'      => $label,
            'code'      => $field->code,
            'type'      => $field->type,
            'options'   => $field->hasOptions() ? count($field->options ?? []) : 0,
            'existing'  => $existing !== null,
            'rows'      => $rows->count(),
            'linked'    => count($linked),
            'leftovers' => $leftovers + ['total' => array_sum($leftovers)],
        ];
        $entry['reconciled'] = $entry['rows'] === $entry['linked'] + $entry['leftovers']['total'];

        if ($apply && $linked !== []) {
            if (! $existing) {
                $field->position = (int) MetadataField::query()->max('position') + 1;
                $field->is_filterable = $rows->contains(fn ($row) => (bool) $row->is_filterable);
                $field->save();
            }

            foreach ($linked as ['row' => $row, 'value' => $value]) {
                $this->link(row: $row, field: $field, value: $value);
            }

            // The database, not the loop, has the last word.
            $stored = AssetProperty::query()->where('field_id', $field->id)->whereIn('id', $rows->pluck('id')->all())->count();
            $entry['reconciled'] = $entry['reconciled'] && $stored === count($linked);
        }

        return $entry;
    }

    /**
     * @param  Collection<int, AssetProperty>  $rows
     * @param  list<string>  $codes
     */
    private function propose(string $label, Collection $rows, array $codes): MetadataField
    {
        $values = $rows->map(fn ($row) => trim((string) $row->value))->filter(fn ($value) => $value !== '')->values();
        $distinct = $values->unique()->values();

        $type = match (true) {
            $values->isEmpty()                                                    => 'text',
            $values->every(fn ($value) => is_numeric($value))                     => 'number',
            $values->every(fn ($value) => self::isoDate($value) !== null)         => 'date',
            $distinct->count() <= self::SELECT_MAX_OPTIONS                        => 'select',
            default                                                               => 'text',
        };

        $options = $type === 'select'
            ? $distinct->filter(fn ($value) => mb_strlen($value) <= 191)
                ->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values()
                ->map(fn ($value) => ['value' => $value, 'label' => $value])->all()
            : null;

        return new MetadataField([
            'code'     => $this->code(label: $label, codes: $codes), 'label' => $label, 'type' => $type, 'options' => $options,
            'required' => false, 'card_priority' => null, 'is_filterable' => false,
        ]);
    }

    /** A unique code from the label: lower-case letters, digits and underscores, starting with a letter. */
    private function code(string $label, array $codes): string
    {
        $base = Str::of(Str::slug($label, '_'))->replace('-', '_')->substr(0, 58)->toString();
        $base = $base === '' ? 'field' : (preg_match('/^[a-z]/', $base) ? $base : 'f_'.$base);
        $code = $base;

        for ($n = 2; in_array($code, $codes, true); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }

    /** The raw text as the field will read it: trimmed, a date-time cut to its date; null when empty. */
    private static function candidate(MetadataField $field, string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return $field->type === 'date' ? (self::isoDate($raw) ?? $raw) : $raw;
    }

    private static function isoDate(string $value): ?string
    {
        if (! preg_match(self::ISO_DATE, $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    private function hasLinkedValue(MetadataField $field, int $assetId): bool
    {
        return AssetProperty::query()->where('field_id', $field->id)->where('dam_asset_id', $assetId)->exists();
    }

    /** Links one row in place: its name, language and text stay; the typed column is filled. */
    private function link(AssetProperty $row, MetadataField $field, mixed $value): void
    {
        $row->forceFill([
            'field_id'     => $field->id,
            'type'         => $field->type,
            'value_number' => $field->type === 'number' ? $value : null,
            'value_date'   => $field->type === 'date' ? $value : null,
            'value_bool'   => $field->type === 'boolean' ? $value : null,
        ])->save();

        if ($field->type === 'multi_select') {
            foreach (array_values((array) $value) as $sort => $option) {
                DB::table('dam_asset_property_options')->insert(['asset_property_id' => $row->id, 'value' => (string) $option, 'sort' => $sort]);
            }
        }
    }
}
