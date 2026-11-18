<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\DamConfiguration;

class DetailLayoutService
{
    public const LABELS = [
        'file_name'       => 'Filename', 'title' => 'Title', 'file_type' => 'File type',
        'asset_kind'      => 'Asset kind', 'extension' => 'Extension', 'mime_type' => 'MIME type',
        'file_size_human' => 'Size', 'dimensions' => 'Dimensions', 'duration' => 'Duration',
        'aspect_ratio'    => 'Aspect ratio',
        'codec'           => 'Codec', 'created_at' => 'Created', 'updated_at' => 'Updated',
        'created_by'      => 'Created by', 'legacy_creation_date' => 'Original creation date',
        'folder_path'     => 'Folder', 'tags' => 'Tags', 'sha256' => 'SHA-256',
    ];

    public function layouts(): array
    {
        return json_decode(DamConfiguration::find('DAM_DETAIL_LAYOUTS')?->value ?? '[]', true) ?: [];
    }

    public function isGlobal(): bool
    {
        return DamConfiguration::find('DAM_DETAIL_LAYOUT_GLOBAL')?->value === '1';
    }

    public function catalog(): array
    {
        $fields = self::LABELS;
        foreach (AssetProperty::query()->distinct()->orderBy('name')->pluck('name') as $name) {
            $fields['property:'.$name] = $name;
        }

        return $fields;
    }

    public function fields(Asset $asset, array $info): ?array
    {
        $layouts = collect($this->layouts())->keyBy('match');
        $layout = $this->isGlobal() ? $layouts->get('*') : ($layouts->get(strtolower((string) $asset->extension))
            ?? $layouts->get((string) $asset->asset_kind) ?? $layouts->get((string) $asset->file_type) ?? $layouts->get('*'));
        if ($layout === null) {
            return null;
        }
        $properties = collect($info['properties'])->groupBy('name')->map(fn ($values) => $values->pluck('value')
            ->filter(fn ($value) => $value !== null && $value !== '')->unique()->implode("\n"));
        $rows = [];
        foreach ($layout['fields'] as $key) {
            $property = str_starts_with($key, 'property:');
            $name = $property ? substr($key, 9) : $key;
            $value = $property ? $properties->get($name) : match ($key) {
                'dimensions', 'duration', 'aspect_ratio' => $info[$key]['display'] ?? null,
                'sha256'                                 => $info['original']['sha256'] ?? null,
                'tags'                                   => collect($info['tags'])->pluck('name')->implode(', '),
                default                                  => $info[$key] ?? null,
            };
            if ($property && self::isEmpty($value) && in_array(strtolower($name), self::RESOLUTION_PROPERTIES, true)) {
                // Legacy resolution fields fall back to the measured original.
                $value = $info['dimensions']['display'] ?? null;
            }
            // A row the asset has no value for is never shown; placeholders count as empty.
            if (self::isEmpty($value)) {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $property ? $name : (self::LABELS[$key] ?? $key), 'value' => $value];
        }

        return $rows;
    }

    private const RESOLUTION_PROPERTIES = ['tech_resolution', 'resolution'];

    private const PLACEHOLDERS = ['-', '—', '–', 'n/a', 'na', 'none', 'null', 'unknown'];

    public static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            return $trimmed === '' || in_array($trimmed, self::PLACEHOLDERS, true);
        }

        return false;
    }
}
