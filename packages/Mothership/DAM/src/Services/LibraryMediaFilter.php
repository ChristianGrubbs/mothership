<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Support\PreviewKinds;

/** One media definition for both Library query paths, applied before pagination. */
class LibraryMediaFilter
{
    /** The "3D" chip: every model format the Library stores (the viewer renders GLB, STL and 3MF). */
    public const MODEL_EXTENSIONS = PreviewKinds::MODEL_3D;

    public static function mediaLabels(): array
    {
        return trans('dam::app.admin.explorer.media-types');
    }

    /** Explain only a known exclusion; an unclassified extension is an ordinary no-match. */
    public static function conflict(?string $mediaType, ?string $extension): ?array
    {
        $extension = strtolower(trim((string) $extension));
        $labels = self::mediaLabels();
        if ($extension === '' || $mediaType === 'all' || ! isset($labels[$mediaType])) {
            return null;
        }

        $kind = in_array($extension, self::MODEL_EXTENSIONS, true)
            ? 'model' : AssetHelper::getFileTypeUsingExtension(extension: $extension);
        if ($kind === 'unspecified') {
            return null;
        }

        $matches = match ($mediaType) {
            'images' => $kind === 'image' && $extension !== 'svg',
            'svgs'   => $extension === 'svg',
            'videos' => $kind === 'video',
            'audio'  => $kind === 'audio',
            'models' => $kind === 'model',
        };

        return $matches ? null : [
            'filters' => ['media_type', 'extension'],
            'message' => trans('dam::app.admin.explorer.filter-recovery.conflict', ['extension' => strtoupper($extension), 'media' => $labels[$mediaType]]),
        ];
    }

    public static function apply(Builder|EloquentBuilder $query, ?string $type): void
    {
        if ($type === 'svgs') {
            $query->whereRaw('LOWER(extension) = ?', ['svg']);
        } elseif ($type === 'images') {
            $query->where('dam_assets.file_type', 'image')->where(function ($raster) {
                $raster->whereNull('dam_assets.extension')->orWhereRaw('LOWER(extension) <> ?', ['svg']);
            });
        } elseif ($type === 'models') {
            $placeholders = implode(',', array_fill(0, count(self::MODEL_EXTENSIONS), '?'));
            $query->whereRaw("LOWER(extension) IN ({$placeholders})", self::MODEL_EXTENSIONS);
        } elseif (in_array($type, ['videos', 'audio'], true)) {
            $query->where('dam_assets.file_type', $type === 'videos' ? 'video' : 'audio');
        }
    }
}
