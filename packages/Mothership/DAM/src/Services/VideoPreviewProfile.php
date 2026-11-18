<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Asset;

/**
 * The rule for whether a video is worth transcoding a preview proxy for.
 *
 * Deliberately conservative in both directions: an asset whose height we
 * cannot determine DOES get a proxy (an unknown master is exactly the case
 * where a bounded 1080p copy is worth having), while anything already at or
 * below 1080p never does UNLESS the browser cannot play the original file
 * directly (e.g. a ProRes .mov, or any QuickTime or HEVC file) — a 1080p master that isn't browser-playable
 * still needs a proxy, since without one there is nothing to play at all.
 */
final class VideoPreviewProfile
{
    public const MAX_UNPROXIED_HEIGHT = 1080;

    public function needsProxy(Asset $asset): bool
    {
        if ($asset->file_type !== 'video') {
            return false;
        }

        if ($asset->panoramaClassification()['is_panorama']) {
            return false;
        }

        $height = $this->sourceHeight($asset);

        if ($height === null || $height > self::MAX_UNPROXIED_HEIGHT) {
            return true;
        }

        // A QuickTime or HEVC original plays in some browsers and not others, so it gets a proxy too.
        return $asset->originalBrowserPlaybackType() === null || $asset->originalNeedsPreviewProxy();
    }

    /**
     * `dam_assets` has no `width`/`height` columns — real video dimensions
     * live only in `meta_data` as flat exiftool keys, so this reads
     * exclusively from extracted metadata (never an asset attribute). When
     * exiftool recorded no height, GenerateVideoPreviewProxy writes its
     * source probe under the same keys (`DimensionsSource: ffprobe`).
     */
    public function sourceHeight(Asset $asset): ?int
    {
        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];

        foreach (['ImageHeight', 'SourceImageHeight', 'height'] as $key) {
            $candidate = (int) ($meta[$key] ?? 0);

            if ($candidate > 0) {
                return $candidate;
            }
        }

        return null;
    }
}
