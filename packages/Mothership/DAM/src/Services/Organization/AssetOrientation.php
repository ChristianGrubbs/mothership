<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Support\Facades\Schema;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\DisplayedAspectRatio;

/**
 * P4 (D10): an asset's orientation for the Library filter, from the width and height it was stored with. It reads the
 * same facts the Details pane's aspect ratio does (DisplayedAspectRatio), so an EXIF quarter turn or a rotated video
 * counts as displayed, not as encoded. Null means unknown: no dimensions, or not an image or video.
 */
final class AssetOrientation
{
    public const LANDSCAPE = 'landscape';

    public const PORTRAIT = 'portrait';

    public const SQUARE = 'square';

    public const VALUES = [self::LANDSCAPE, self::PORTRAIT, self::SQUARE];

    /** Within this of 1:1 counts as square, so a 1000×1004 crop is not a "portrait". */
    private const SQUARE_TOLERANCE = 0.01;

    /** Set once the column is known to exist; only a positive answer is remembered (a migration adds it mid-run). */
    private static bool $columnReady = false;

    public static function classify(?float $ratio): ?string
    {
        if ($ratio === null || ! is_finite($ratio) || $ratio <= 0) {
            return null;
        }

        if (abs($ratio - 1.0) <= self::SQUARE_TOLERANCE) {
            return self::SQUARE;
        }

        return $ratio > 1.0 ? self::LANDSCAPE : self::PORTRAIT;
    }

    /**
     * The orientation of what the asset stores: its embedded metadata and, when the caller has it, the original's
     * measured technical facts (StoredImageDimensions writes those on the file object).
     *
     * @param  array<string, mixed>  $technical
     */
    public static function of(Asset $asset, array $technical = []): ?string
    {
        $metadata = is_array($asset->meta_data) ? $asset->meta_data : [];
        $described = app(DisplayedAspectRatio::class)->describe(
            fileType: (string) $asset->file_type,
            extension: (string) $asset->extension,
            technical: $technical,
            metadata: $metadata,
        );

        return self::classify(isset($described['decimal']) ? (float) $described['decimal'] : null);
    }

    /**
     * The original's stored technical facts (what StoredImageDimensions measured), read only: nothing is measured
     * here. Empty before the asset has files.
     *
     * @return array<string, mixed>
     */
    public static function storedTechnical(Asset $asset): array
    {
        if (! $asset->exists) {
            return [];
        }

        $original = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')
            ->orderBy('sort')->orderBy('id')->first();
        $technical = $original?->fileObject?->technical;

        return is_array($technical) ? $technical : [];
    }

    /** At ingest: an asset whose stored facts change gets the orientation they describe (Asset::saving). */
    public static function refresh(Asset $asset): void
    {
        if ($asset->exists && ! $asset->isDirty(['meta_data', 'file_type', 'extension'])) {
            return;
        }

        // An older migration that saves assets runs before 2026_10_07_000902 adds the column.
        if (! self::$columnReady && ! (self::$columnReady = Schema::hasColumn($asset->getTable(), 'orientation'))) {
            return;
        }

        $orientation = self::of(asset: $asset, technical: self::storedTechnical($asset));

        if ($asset->getAttribute('orientation') !== $orientation) {
            $asset->setAttribute('orientation', $orientation);
        }
    }
}
