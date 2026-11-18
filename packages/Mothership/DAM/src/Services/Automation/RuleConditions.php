<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\MetadataFields\FieldValues;

/**
 * A rule's conditions (P5b, D19): `{match: all|any, conditions: [...]}`, every condition one of
 *
 * - folder {directory_id}: the asset is filed in that folder or under it;
 * - extension {values: [..]}; mime {value} (a glob such as `image/*`); filename {value} (a glob, case-insensitive);
 * - field {field_id, op: eq|neq|contains|gt|lt|empty|not_empty, value};
 * - has_tag {value} / has_label {label_id}, each with an optional `negate`;
 * - exif {key, op: eq|neq|contains|matches|exists|not_exists, value} over the raw ExifTool data.
 *
 * No conditions match every asset. Facts are loaded once per chunk, so a chunk of 200 assets is a handful of queries.
 */
final class RuleConditions
{
    public const TYPES = ['folder', 'extension', 'mime', 'filename', 'field', 'has_tag', 'has_label', 'exif'];

    public const FIELD_OPS = ['eq', 'neq', 'contains', 'gt', 'lt', 'empty', 'not_empty'];

    public const EXIF_OPS = ['eq', 'neq', 'contains', 'matches', 'exists', 'not_exists'];

    /** @var array<int, array{folders: list<int>, tags: list<string>, labels: list<int>, fields: array<string, mixed>}> */
    private array $facts = [];

    /** @var array<int, string> field id => code */
    private array $fieldCodes = [];

    public function __construct(private readonly FolderScope $folders, private readonly FieldValues $values) {}

    /**
     * The conditions as stored, or a 422 on `conditions.conditions.N.<key>`.
     *
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public static function validate(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $match = ($input['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $list = $input['conditions'] ?? [];

        if (! is_array($list) || ! array_is_list($list) || count($list) > 30) {
            throw ValidationException::withMessages(['conditions' => [trans('dam::app.admin.automation.validation.conditions')]]);
        }

        $clean = [];

        foreach ($list as $index => $condition) {
            $type = is_array($condition) ? ($condition['type'] ?? null) : null;
            $fail = fn () => ValidationException::withMessages(["conditions.conditions.$index" => [trans('dam::app.admin.automation.validation.condition')]]);
            $string = fn (string $key) => is_scalar($condition[$key] ?? null) && trim((string) $condition[$key]) !== '' && mb_strlen((string) $condition[$key]) <= 191
                ? trim((string) $condition[$key]) : throw $fail();

            $clean[] = match ($type) {
                'folder'    => DB::table('dam_directories')->where('id', (int) ($condition['directory_id'] ?? 0))->exists()
                    ? ['type' => 'folder', 'directory_id' => (int) $condition['directory_id']] : throw $fail(),
                'extension' => is_array($condition['values'] ?? null) && $condition['values'] !== [] && count($condition['values']) <= 50
                    ? ['type' => 'extension', 'values' => array_values(array_unique(array_map(fn ($v) => ltrim(mb_strtolower(trim((string) $v)), '.'), $condition['values'])))] : throw $fail(),
                'mime', 'filename' => ['type' => $type, 'value' => $string('value')],
                'field'            => MetadataField::query()->where('id', (int) ($condition['field_id'] ?? 0))->exists() && in_array($condition['op'] ?? null, self::FIELD_OPS, true)
                    ? ['type' => 'field', 'field_id' => (int) $condition['field_id'], 'op' => $condition['op'], 'value' => in_array($condition['op'], ['empty', 'not_empty'], true) ? null : $string('value')] : throw $fail(),
                'has_tag'   => ['type' => 'has_tag', 'value' => $string('value'), 'negate' => (bool) ($condition['negate'] ?? false)],
                'has_label' => DB::table('dam_labels')->where('id', (int) ($condition['label_id'] ?? 0))->where('kind', 'shared')->exists()
                    ? ['type' => 'has_label', 'label_id' => (int) $condition['label_id'], 'negate' => (bool) ($condition['negate'] ?? false)] : throw $fail(),
                'exif'      => in_array($condition['op'] ?? null, self::EXIF_OPS, true)
                    ? ['type' => 'exif', 'key' => $string('key'), 'op' => $condition['op'], 'value' => in_array($condition['op'], ['exists', 'not_exists'], true) ? null : $string('value')] : throw $fail(),
                default     => throw $fail(),
            };
        }

        return ['match' => $match, 'conditions' => $clean];
    }

    /**
     * Loads every fact the conditions read for these assets.
     *
     * @param  list<int>  $assetIds
     */
    public function load(array $assetIds): void
    {
        $this->facts = [];
        $folders = $this->folders->foldersOf($assetIds);
        $fields = MetadataField::query()->get();
        $this->fieldCodes = $fields->mapWithKeys(fn (MetadataField $field) => [(int) $field->id => $field->code])->all();
        $values = $this->values->forAssets(assetIds: $assetIds, fields: $fields);
        $tags = DB::table('dam_asset_tag')->join('dam_tags', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')->whereIn('dam_asset_tag.asset_id', $assetIds ?: [0])
            ->get(['dam_asset_tag.asset_id', 'dam_tags.name'])->groupBy('asset_id');
        $labels = DB::table('dam_label_assets')->whereIn('asset_id', $assetIds ?: [0])->get(['asset_id', 'label_id'])->groupBy('asset_id');

        foreach ($assetIds as $assetId) {
            $this->facts[$assetId] = [
                'folders' => $folders[$assetId] ?? [],
                'tags'    => ($tags->get($assetId) ?? collect())->map(fn ($row) => mb_strtolower((string) $row->name))->all(),
                'labels'  => ($labels->get($assetId) ?? collect())->map(fn ($row) => (int) $row->label_id)->all(),
                'fields'  => $values[$assetId] ?? [],
            ];
        }
    }

    /** @param  array{match?: string, conditions?: list<array<string, mixed>>}|null  $conditions */
    public function matches(Asset $asset, ?array $conditions): bool
    {
        $list = $conditions['conditions'] ?? [];

        if ($list === []) {
            return true;
        }

        $facts = $this->facts[(int) $asset->id] ?? ['folders' => [], 'tags' => [], 'labels' => [], 'fields' => []];
        $results = array_map(fn (array $condition) => $this->one(asset: $asset, facts: $facts, condition: $condition), $list);

        return ($conditions['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    private function one(Asset $asset, array $facts, array $condition): bool
    {
        $negate = (bool) ($condition['negate'] ?? false);

        $result = match ($condition['type']) {
            'folder'    => $this->folders->within($facts['folders'], (int) $condition['directory_id']),
            'extension' => in_array(mb_strtolower((string) $asset->extension), $condition['values'], true),
            'mime'      => fnmatch(mb_strtolower($condition['value']), mb_strtolower((string) $asset->mime_type)),
            'filename'  => fnmatch(mb_strtolower($condition['value']), mb_strtolower((string) $asset->file_name)),
            'field'     => $this->field(value: $facts['fields'][$this->fieldCodes[(int) $condition['field_id']] ?? ''] ?? null, op: $condition['op'], expected: $condition['value']),
            'has_tag'   => in_array(mb_strtolower($condition['value']), $facts['tags'], true),
            'has_label' => in_array((int) $condition['label_id'], $facts['labels'], true),
            'exif'      => $this->exif(values: MappingTransforms::read((array) ($asset->meta_data ?? []), $condition['key']), op: $condition['op'], expected: $condition['value']),
            default     => false,
        };

        return $negate ? ! $result : $result;
    }

    private function field(mixed $value, string $op, ?string $expected): bool
    {
        $values = array_map(fn ($item) => is_bool($item) ? ($item ? '1' : '0') : mb_strtolower((string) $item), is_array($value) ? $value : ($value === null ? [] : [$value]));
        $expected = mb_strtolower((string) $expected);

        return match ($op) {
            'empty'     => $values === [],
            'not_empty' => $values !== [],
            'eq'        => in_array($expected, $values, true),
            'neq'       => ! in_array($expected, $values, true),
            'contains'  => array_filter($values, fn (string $item) => str_contains($item, $expected)) !== [],
            'gt'        => $values !== [] && is_numeric($values[0]) && is_numeric($expected) ? (float) $values[0] > (float) $expected : ($values !== [] && $values[0] > $expected),
            'lt'        => $values !== [] && is_numeric($values[0]) && is_numeric($expected) ? (float) $values[0] < (float) $expected : ($values !== [] && $values[0] < $expected),
            default     => false,
        };
    }

    /** @param  list<string>  $values */
    private function exif(array $values, string $op, ?string $expected): bool
    {
        $values = array_map('mb_strtolower', $values);
        $expected = mb_strtolower((string) $expected);

        return match ($op) {
            'exists'     => $values !== [],
            'not_exists' => $values === [],
            'eq'         => in_array($expected, $values, true),
            'neq'        => ! in_array($expected, $values, true),
            'contains'   => array_filter($values, fn (string $item) => str_contains($item, $expected)) !== [],
            'matches'    => array_filter($values, fn (string $item) => fnmatch($expected, $item)) !== [],
            default      => false,
        };
    }
}
