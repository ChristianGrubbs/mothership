<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Jobs\RunMetadataMappingChunk;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\MetadataMapping;
use Mothership\DAM\Support\AutomationLog;

/**
 * Embedded-metadata mappings (P5b, D18): definitions, evaluation, dry run, and runs at ingest and on demand.
 *
 * A mapping reads one ExifTool key (MappingTransforms), and writes each value through ValueWriter with source
 * `mapping:<id>` and the automation origin flag:
 * - tag: one tag per value; a name TagNameRule refuses is logged and not written;
 * - field: the first value (every value for a multi-select), validated by the field's type;
 * - label: each value names an existing shared label (case-insensitive); an unknown name is refused, never created.
 *
 * Its write mode is fill-empty (the default: only an empty field) or replace-machine (also a lower- or
 * equal-precedence machine value). A person's value and a person's removal always win.
 */
class MetadataMappingService
{
    public function __construct(
        private readonly ValueWriter $writer,
        private readonly FolderScope $folders,
    ) {}

    /**
     * Validated attributes for a create or an update, or a 422.
     *
     * @return array<string, mixed>
     */
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name'               => ['required', 'string', 'max:191'],
            'enabled'            => ['sometimes', 'boolean'],
            'source_key'         => ['required', 'string', 'max:191'],
            'transforms'         => ['nullable', 'array'],
            'target_type'        => ['required', Rule::in(MetadataMapping::TARGETS)],
            'target_id'          => ['nullable', 'integer'],
            'write_mode'         => ['sometimes', Rule::in(ValueWriter::MODES)],
            'scope_directory_id' => ['nullable', 'integer', Rule::exists('dam_directories', 'id')],
        ])->validate();

        $data['transforms'] = MappingTransforms::validate($input['transforms'] ?? []);
        $data['write_mode'] ??= ValueWriter::MODE_FILL_EMPTY;
        $data['source_key'] = trim($data['source_key']);

        if ($data['target_type'] === MetadataMapping::TARGET_FIELD) {
            $field = isset($data['target_id']) ? MetadataField::query()->active()->find($data['target_id']) : null;

            if ($field === null) {
                throw ValidationException::withMessages(['target_id' => [trans('dam::app.admin.automation.validation.field')]]);
            }
        } else {
            $data['target_id'] = null;
        }

        return $data;
    }

    public function create(array $input, ?int $actorId): MetadataMapping
    {
        $mapping = MetadataMapping::query()->create($this->validate($input) + ['created_by' => $actorId, 'updated_by' => $actorId, 'version' => 1]);
        AutomationLog::write(event: 'mapping.created', ctx: ['mapping_id' => (int) $mapping->id, 'target' => $mapping->target_type, 'actor_id' => (int) $actorId]);

        return $mapping;
    }

    /** Every saved edit is a new version, whatever changed. */
    public function update(MetadataMapping $mapping, array $input, ?int $actorId): MetadataMapping
    {
        $mapping->fill($this->validate($input + $mapping->only(['name', 'source_key', 'target_type', 'target_id', 'transforms', 'write_mode', 'scope_directory_id'])));

        if ($mapping->isDirty()) {
            // Turning it on or off is not a new version: what it does, and so its run key, is unchanged.
            if (array_diff(array_keys($mapping->getDirty()), ['enabled']) !== []) {
                $mapping->version = $mapping->version + 1;
            }

            $mapping->updated_by = $actorId;
            $mapping->save();
            AutomationLog::write(event: 'mapping.updated', ctx: ['mapping_id' => (int) $mapping->id, 'version' => $mapping->version, 'actor_id' => (int) $actorId]);
        }

        return $mapping;
    }

    /**
     * Every enabled mapping whose scope holds the asset, on its freshly extracted metadata. Called at ingest, after
     * metadata extraction, on ProcessAssetUpload's chain; a mapping that throws is logged and the next one runs.
     */
    public function applyAtIngest(Asset $asset): void
    {
        $folders = $this->folders->foldersOf([(int) $asset->id])[(int) $asset->id] ?? [];

        foreach (MetadataMapping::query()->where('enabled', true)->orderBy('id')->get() as $mapping) {
            if ($mapping->scope_directory_id !== null && ! $this->folders->within($folders, $mapping->scope_directory_id)) {
                continue;
            }

            try {
                $writes = $this->evaluate(mapping: $mapping, asset: $asset, dryRun: false);
                AutomationLog::write(event: 'mapping.ingest', ctx: ['mapping_id' => (int) $mapping->id, 'asset_id' => (int) $asset->id] + $this->counts($writes));
            } catch (\Throwable $e) {
                AutomationLog::write(event: 'mapping.failed', ctx: ['mapping_id' => (int) $mapping->id, 'asset_id' => (int) $asset->id, 'error_type' => class_basename($e)], level: 'warning');
            }
        }
    }

    /**
     * What the mapping does (or, dry, would do) to one asset: one row per value, with the target, before, after and
     * outcome. An asset outside the mapping's scope gets no rows.
     *
     * @return list<array{value: string, target: string, outcome: string, reason: ?string, before: ?string, after: ?string}>
     */
    public function evaluate(MetadataMapping $mapping, Asset $asset, bool $dryRun): array
    {
        $values = MappingTransforms::apply(MappingTransforms::read((array) ($asset->meta_data ?? []), $mapping->source_key), (array) ($mapping->transforms ?? []));
        $source = ValueSource::mapping((int) $mapping->id);
        $rows = [];

        $write = fn () => match ($mapping->target_type) {
            MetadataMapping::TARGET_TAG   => array_map(fn (string $value) => ['value' => $value, 'target' => 'tag']
                + $this->writer->addTag(asset: $asset, name: $value, source: $source, dryRun: $dryRun)->toArray(), $values),
            MetadataMapping::TARGET_LABEL => array_map(fn (string $value) => ['value' => $value, 'target' => 'label']
                + $this->writer->addLabel(asset: $asset, label: $this->sharedLabel($value), source: $source, dryRun: $dryRun)->toArray(), $values),
            default                       => $this->fieldWrite(mapping: $mapping, asset: $asset, values: $values, source: $source, dryRun: $dryRun),
        };

        if ($values !== []) {
            $rows = AutomationOrigin::run($write);
        }

        return $rows;
    }

    /**
     * The dry run over a selection: per asset, the values the key yields and what would be written. Nothing is
     * written. The caller has already filtered the ids to those the requester may see.
     *
     * @param  list<int>  $assetIds
     * @return list<array{asset_id: int, name: string, writes: list<array<string, mixed>>}>
     */
    public function dryRun(MetadataMapping $mapping, array $assetIds): array
    {
        $assetIds = array_slice($this->inScope(mapping: $mapping, assetIds: $assetIds), 0, max(1, (int) config('dam.automation.dry_run_limit', 500)));
        $result = [];

        foreach (Asset::query()->whereIn('id', $assetIds ?: [0])->orderBy('id')->get() as $asset) {
            $result[] = ['asset_id' => (int) $asset->id, 'name' => (string) $asset->file_name, 'writes' => $this->evaluate(mapping: $mapping, asset: $asset, dryRun: true)];
        }

        AutomationLog::write(event: 'mapping.dry_run', ctx: ['mapping_id' => (int) $mapping->id, 'assets' => count($result)]);

        return $result;
    }

    /**
     * Queues the mapping over a selection, in chunks. Each chunk evaluates and writes; a redelivered chunk writes
     * nothing new (add-if-absent, set-if-different). Returns how many assets were queued.
     *
     * @param  list<int>  $assetIds
     */
    public function run(MetadataMapping $mapping, array $assetIds, ?int $actorId): int
    {
        $assetIds = $this->inScope(mapping: $mapping, assetIds: $assetIds);
        $mapping->forceFill(['last_run_at' => now(), 'last_run_counts' => ['assets' => count($assetIds), 'written' => 0, 'skipped' => 0, 'refused' => 0]])->save();
        $jobs = array_map(fn (array $chunk) => new RunMetadataMappingChunk(mappingId: (int) $mapping->id, version: (int) $mapping->version, assetIds: $chunk),
            array_chunk($assetIds, max(1, (int) config('dam.automation.chunk', 200))));

        AutomationLog::write(event: 'mapping.run_queued', ctx: ['mapping_id' => (int) $mapping->id, 'assets' => count($assetIds), 'chunks' => count($jobs), 'actor_id' => (int) $actorId]);

        if ($jobs !== []) {
            Bus::batch($jobs)->allowFailures()->name('dam-mapping:'.$mapping->id)->dispatch();
        }

        return count($assetIds);
    }

    /**
     * One queued chunk. A mapping edited or disabled since it was queued does nothing: the run belonged to the
     * version that was asked for.
     *
     * @param  list<int>  $assetIds
     */
    public function runChunk(int $mappingId, int $version, array $assetIds): void
    {
        $mapping = MetadataMapping::query()->find($mappingId);

        if ($mapping === null || $mapping->version !== $version) {
            AutomationLog::write(event: 'mapping.chunk_stale', ctx: ['mapping_id' => $mappingId, 'version' => $version]);

            return;
        }

        $counts = ['written' => 0, 'skipped' => 0, 'refused' => 0];

        foreach (Asset::query()->whereIn('id', $assetIds ?: [0])->orderBy('id')->get() as $asset) {
            foreach ($this->counts($this->evaluate(mapping: $mapping, asset: $asset, dryRun: false)) as $outcome => $count) {
                $counts[$outcome] += $count;
            }
        }

        $this->addChunkCounts(mappingId: $mappingId, counts: $counts);
        AutomationLog::write(event: 'mapping.chunk_done', ctx: ['mapping_id' => $mappingId, 'assets' => count($assetIds)] + $counts);
    }

    /**
     * Adds one chunk's outcome counts to the mapping's run totals. Chunks finish on parallel workers: the totals are read and
     * written under the mapping's row lock, so two chunks never start from the same totals and each overwrite the other's share.
     *
     * @param  array{written: int, skipped: int, refused: int}  $counts
     */
    public function addChunkCounts(int $mappingId, array $counts): void
    {
        DB::transaction(function () use ($mappingId, $counts) {
            $totals = (array) (MetadataMapping::query()->whereKey($mappingId)->lockForUpdate()->first()?->last_run_counts ?? []);

            foreach ($counts as $outcome => $count) {
                $totals[$outcome] = (int) ($totals[$outcome] ?? 0) + $count;
            }

            MetadataMapping::query()->where('id', $mappingId)->update(['last_run_counts' => json_encode($totals)]);
        });
    }

    /**
     * The asset's raw ExifTool keys with a sample value each, for the editor's key picker.
     *
     * @return list<array{key: string, sample: string}>
     */
    public function keys(Asset $asset): array
    {
        $exif = (array) (($asset->meta_data ?? [])['exif'] ?? []);

        if (array_is_list($exif) && isset($exif[0]) && is_array($exif[0])) {
            $exif = $exif[0];
        }

        $keys = [];

        foreach ($exif as $key => $value) {
            if ($key === 'SourceFile' || (! is_scalar($value) && ! is_array($value))) {
                continue;
            }

            $sample = is_array($value) ? implode(', ', array_filter($value, 'is_scalar')) : (string) $value;
            $keys[] = ['key' => (string) $key, 'sample' => mb_substr($sample, 0, 120)];
        }

        usort($keys, fn ($a, $b) => strcasecmp($a['key'], $b['key']));

        return $keys;
    }

    /** @return array<string, mixed> */
    public function present(MetadataMapping $mapping): array
    {
        $target = match ($mapping->target_type) {
            MetadataMapping::TARGET_FIELD => MetadataField::query()->find($mapping->target_id)?->label,
            default                       => null,
        };

        return [
            'id'                 => (int) $mapping->id,
            'name'               => $mapping->name,
            'enabled'            => (bool) $mapping->enabled,
            'source_key'         => $mapping->source_key,
            'transforms'         => $mapping->transforms ?? [],
            'target_type'        => $mapping->target_type,
            'target_id'          => $mapping->target_id,
            'target_label'       => $target,
            'write_mode'         => $mapping->write_mode,
            'scope_directory_id' => $mapping->scope_directory_id,
            'version'            => (int) $mapping->version,
            'last_run_at'        => $mapping->last_run_at?->toIso8601String(),
            'last_run_counts'    => $mapping->last_run_counts,
            'updated_at'         => $mapping->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $assetIds
     * @return list<int>
     */
    private function inScope(MetadataMapping $mapping, array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        if ($mapping->scope_directory_id === null) {
            return $assetIds;
        }

        $folders = $this->folders->foldersOf($assetIds);

        return array_values(array_filter($assetIds, fn (int $id) => $this->folders->within($folders[$id] ?? [], (int) $mapping->scope_directory_id)));
    }

    /**
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    private function fieldWrite(MetadataMapping $mapping, Asset $asset, array $values, string $source, bool $dryRun): array
    {
        $field = MetadataField::query()->find($mapping->target_id);

        if ($field === null) {
            return [['value' => $values[0], 'target' => 'field'] + WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING)->toArray()];
        }

        $raw = $field->type === 'multi_select' ? $values : $values[0];
        $display = is_array($raw) ? implode(', ', $raw) : $raw;

        return [['value' => $display, 'target' => 'field:'.$field->code]
            + $this->writer->setField(asset: $asset, field: $field, raw: $raw, source: $source, mode: $mapping->write_mode, dryRun: $dryRun)->toArray()];
    }

    private function sharedLabel(string $name): ?Label
    {
        return Label::query()->where('kind', Label::KIND_SHARED)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->orderBy('id')->first();
    }

    /**
     * @param  list<array{outcome: string}>  $writes
     * @return array{written: int, skipped: int, refused: int}
     */
    private function counts(array $writes): array
    {
        $counts = ['written' => 0, 'skipped' => 0, 'refused' => 0];

        foreach ($writes as $write) {
            $counts[$write['outcome']]++;
        }

        return $counts;
    }
}
