<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\FileObject;

/**
 * Measures a stored raster image once and records width/height on its file object.
 *
 * Uploads that skip EXIF extraction (PNG, WebP, generated output) otherwise carry no
 * dimensions, which leaves the Details pane without a resolution or aspect ratio.
 */
class StoredImageDimensions
{
    /** Extensions measured when the stored MIME type is missing or generic. */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif'];

    private const GENERIC_MIMES = ['', 'application/octet-stream', 'binary/octet-stream'];

    /**
     * $fallback is accepted for call-site compatibility (a caller may still supply the asset's own
     * Library locator) but never read: slice 1's migration repointed every legacy FileObject
     * locator, so a blob that ContentStore cannot verify is unavailable content, not a reason to
     * measure different bytes from the Library path and persist them onto the shared FileObject
     * (ADR 0009). Unavailable content is recorded by ContentStore itself; nothing is persisted here.
     *
     * @param  array{disk:string,path:string}|null  $fallback  unused; kept for signature compatibility
     */
    public function ensure(?FileObject $object, string $fileType, string $extension = '', ?array $fallback = null): array
    {
        $technical = is_array($object?->technical) ? $object->technical : [];
        if ($object === null || $fileType !== 'image' || $this->hasDimensions($technical) || ! $this->isRaster($object, $extension)) {
            return $technical;
        }

        $info = $this->measureObject($object);

        if ($info === null) {
            return $technical;
        }
        // Re-read before merging so a concurrent metadata write is never overwritten.
        $fresh = $object->fresh() ?? $object;
        $merged = array_merge(is_array($fresh->technical) ? $fresh->technical : [], $info);
        $fresh->forceFill(['technical' => $merged])->save();
        $object->setRawAttributes($fresh->getAttributes(), true);

        return $merged;
    }

    /** @return array{width:int,height:int}|null */
    private function measureObject(FileObject $object): ?array
    {
        $store = app(ContentStore::class);

        try {
            $local = $store->localPath($object);
        } catch (ContentUnavailable) {
            return null;
        }

        try {
            // getimagesize seeks through the file itself, so late JPEG SOF markers are found.
            $info = @getimagesize($local->path);
        } finally {
            $local->release();
        }

        if (! is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return null;
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    private function isRaster(FileObject $object, string $extension): bool
    {
        $mime = strtolower(trim((string) $object->mime_type));
        if ($mime === 'image/svg+xml') {
            return false;
        }
        if (str_starts_with($mime, 'image/')) {
            return true;
        }
        $sniffed = strtolower(trim((string) $object->sniffed_type));
        if (str_starts_with($sniffed, 'image/')) {
            return $sniffed !== 'image/svg+xml';
        }

        return in_array($mime, self::GENERIC_MIMES, true) && in_array(strtolower(ltrim($extension, '.')), self::RASTER_EXTENSIONS, true);
    }

    private function hasDimensions(array $technical): bool
    {
        foreach ([['width', 'height'], ['dimensions.width', 'dimensions.height'], ['image.width', 'image.height']] as [$w, $h]) {
            $width = data_get($technical, $w);
            $height = data_get($technical, $h);
            if (is_numeric($width) && is_numeric($height) && (float) $width > 0 && (float) $height > 0) {
                return true;
            }
        }

        return false;
    }
}
