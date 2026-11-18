<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\AssetFile;

/**
 * The "available versions" chips beside a Library card's type badge: what else is attached to each asset,
 * so a user sees at a glance that an optimized copy, an SVG, or another format exists. One query per page.
 *
 * - `optimized` — an Optimize output (role `output`, label `<name>-optimized.<ext>`, see Optimization\AssetOutputs).
 * - `svg`       — any attachment that resolves to SVG, e.g. a Vectorize output.
 * - `type`      — every other attachment type unlike the asset's own (PSD, ZIP, SRT…).
 *
 * The original, and the system-made proxy, thumbnail and previous-version files, never count.
 */
class AvailableVersions
{
    private const IGNORED_ROLES = [
        AssetFile::ROLE_ORIGINAL,
        AssetFile::ROLE_PROXY,
        AssetFile::ROLE_THUMBNAIL,
        AssetFile::ROLE_VERSION,
    ];

    /** Spellings of one format, so a JPEG never advertises its own `.jpg` twin as another type. */
    private const ALIASES = ['jpeg' => 'jpg', 'jpe' => 'jpg', 'tiff' => 'tif', 'htm' => 'html', 'mpeg' => 'mpg', 'yml' => 'yaml'];

    /**
     * @param  array<int, string|null>  $extensionsById  asset id => the asset's own extension
     * @return array<int, list<array{kind: string, label: string}>> asset id => chips, OPT then SVG then types A–Z
     */
    public function forAssets(array $extensionsById): array
    {
        if ($extensionsById === []) {
            return [];
        }

        $files = AssetFile::query()
            ->with('fileObject:id,mime_type')
            ->whereIn('dam_asset_id', array_keys($extensionsById))
            ->whereNotIn('role', self::IGNORED_ROLES)
            ->get(['id', 'dam_asset_id', 'file_object_id', 'role', 'label']);

        $chips = [];

        foreach ($files->groupBy('dam_asset_id') as $assetId => $attachments) {
            $own = $this->normalize((string) ($extensionsById[(int) $assetId] ?? ''));
            $optimized = false;
            $svg = false;
            $types = [];

            foreach ($attachments as $file) {
                if ($file->role === AssetFile::ROLE_OUTPUT && str_contains(strtolower((string) $file->label), '-optimized.')) {
                    $optimized = true;

                    continue;
                }

                $extension = $this->normalize($file->extension());

                if ($extension === 'svg') {
                    $svg = true;
                } elseif ($extension !== '' && $extension !== $own) {
                    $types[$extension] = true;
                }
            }

            ksort($types);
            $list = [];

            if ($optimized) {
                $list[] = ['kind' => 'optimized', 'label' => 'OPT'];
            }

            if ($svg && $own !== 'svg') {
                $list[] = ['kind' => 'svg', 'label' => 'SVG'];
            }

            foreach (array_keys($types) as $type) {
                $list[] = ['kind' => 'type', 'label' => strtoupper((string) $type)];
            }

            if ($list !== []) {
                $chips[(int) $assetId] = $list;
            }
        }

        return $chips;
    }

    private function normalize(string $extension): string
    {
        $extension = strtolower(trim($extension));

        return self::ALIASES[$extension] ?? $extension;
    }
}
