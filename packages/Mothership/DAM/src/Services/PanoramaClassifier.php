<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;

final class PanoramaClassifier
{
    private const MIN_RATIO = 1.95;

    private const MAX_RATIO = 2.05;

    public function classify(Asset $asset): array
    {
        $mode = $asset->panorama_mode ?: Asset::PANORAMA_AUTO;
        $extension = strtolower((string) ($asset->extension ?: pathinfo((string) $asset->path, PATHINFO_EXTENSION)));
        $mediaType = $this->mediaType($asset, $extension);

        if ($extension === 'svg' || strtolower(trim(explode(';', (string) $asset->mime_type)[0])) === 'image/svg+xml') {
            return $this->decision($mode, false, 'vector-image', 'image', false);
        }

        if ($mode === Asset::PANORAMA_FORCE_FLAT) {
            return $this->decision($mode, false, 'forced-flat', $mediaType, false);
        }

        $isOsv = $extension === 'osv';
        $forced = $mode === Asset::PANORAMA_FORCE_360;
        $legacy = $asset->asset_kind === 'pano360';
        [$width, $height] = $this->dimensions($asset);
        $ratio = $height > 0 ? $width / $height : 0.0;
        $ratioMatch = in_array($mediaType, ['image', 'video'], true)
            && $ratio >= self::MIN_RATIO
            && $ratio <= self::MAX_RATIO;
        $isPanorama = $forced || $isOsv || $legacy || $ratioMatch;
        $reason = $forced ? 'forced-360' : ($isOsv ? 'osv' : ($legacy ? 'legacy-kind' : ($ratioMatch ? 'aspect-ratio' : 'none')));
        $direct = $isPanorama && ! $isOsv && $this->serverDirectCandidate($asset, $extension, $mediaType);

        return $this->decision($mode, $isPanorama, $reason, $mediaType, $direct);
    }

    private function decision(string $mode, bool $panorama, string $reason, ?string $mediaType, bool $direct): array
    {
        return [
            'mode'             => $mode,
            'is_panorama'      => $panorama,
            'reason'           => $reason,
            'media_type'       => $mediaType,
            'direct_candidate' => $direct,
            'requires_proxy'   => $panorama && ! $direct,
        ];
    }

    private function mediaType(Asset $asset, string $extension): ?string
    {
        if ($extension === 'osv') {
            return 'video';
        }

        $mime = strtolower((string) $asset->mime_type);

        if ($asset->file_type === 'image' || str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if ($asset->file_type === 'video' || str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return null;
    }

    private function dimensions(Asset $asset): array
    {
        $original = $asset->files()->with('fileObject')->where('role', AssetFile::ROLE_ORIGINAL)->first();
        $metadata = array_merge(
            is_array($asset->meta_data) ? $asset->meta_data : [],
            is_array($original?->fileObject?->technical) ? $original->fileObject->technical : [],
        );
        $width = 0;
        $height = 0;
        array_walk_recursive($metadata, function ($value, $key) use (&$width, &$height): void {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($normalized, ['width', 'imagewidth', 'sourceimagewidth', 'videoframewidth'], true)) {
                $width = max($width, (int) $value);
            }
            if (in_array($normalized, ['height', 'imageheight', 'sourceimageheight', 'videoframeheight'], true)) {
                $height = max($height, (int) $value);
            }
        });

        // Historical imports keep dimensions as one field. Current image
        // dimensions take precedence when an imported original has been edited.
        $resolution = data_get($metadata, 'legacy.fields.tech_resolution');
        if ($width <= 0 && $height <= 0
            && is_string($resolution)
            && preg_match('/\A\s*(\d+)\s*[x×]\s*(\d+)\s*\z/iu', $resolution, $matches)
        ) {
            $width = (int) $matches[1];
            $height = (int) $matches[2];
        }

        return [$width, $height];
    }

    private function serverDirectCandidate(Asset $asset, string $extension, ?string $mediaType): bool
    {
        if ($mediaType === 'image') {
            return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
        }

        if ($mediaType !== 'video' || ! in_array($extension, ['mp4', 'm4v', 'webm', 'mov'], true)) {
            return false;
        }

        $original = $asset->files()->with('fileObject')->where('role', AssetFile::ROLE_ORIGINAL)->first();
        $metadata = array_merge(
            is_array($asset->meta_data) ? $asset->meta_data : [],
            is_array($original?->fileObject?->technical) ? $original->fileObject->technical : [],
        );
        $codecs = [];
        array_walk_recursive($metadata, function ($value, $key) use (&$codecs): void {
            if (is_scalar($value) && str_contains(strtolower((string) $key), 'codec')) {
                $codecs[] = strtolower((string) $value);
            }
        });

        if ($codecs === []) {
            return true;
        }

        foreach ($codecs as $codec) {
            foreach (['h264', 'h.264', 'avc', 'vp8', 'vp08', 'vp9', 'vp09', 'av1', 'av01'] as $supported) {
                if (str_contains($codec, $supported)) {
                    return true;
                }
            }
        }

        return false;
    }
}
