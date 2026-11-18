<?php

namespace Mothership\DAM\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resizes a StylePress preview image (the checked-in catalog originals run 800-1000px on a
 * side, 110 KB average) down to a display-sized JPEG the first time it is requested, then
 * serves that derivative from storage/app/stylepress-thumbnails/{catalogVersion}/{slug}.jpg on
 * every later request. Keyed by catalog version so a re-import naturally invalidates it. Guarded
 * by Cache::lock (the DngPreviewService::ensure() pattern) so concurrent first requests for the
 * same slug do not all render at once. An existing file at that path is trusted only after a
 * magic-byte check confirms it is really a JPEG (see isValidJpeg()); anything else is
 * regenerated. Never throws: a source that can't be read or decoded falls back to the original
 * file untouched, so a broken source image never turns into a 500.
 */
class StylePressThumbnailDerivatives
{
    public const DEFAULT_WIDTH = 480;

    private const QUALITY = 82;

    public function path(string $originalPath, string $slug, string $catalogVersion, int $width = self::DEFAULT_WIDTH): string
    {
        $disk = Storage::disk('local');
        $relative = $this->relativePath(slug: $slug, catalogVersion: $catalogVersion);

        if ($this->isValidJpeg($disk, $relative)) {
            return $disk->path($relative);
        }

        $lock = Cache::lock('stylepress-thumbnail-derivative:'.$relative, 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            // Another request is already rendering this slug; serve the original this once
            // rather than block the response on a lock we could not acquire in time.
            return $this->isValidJpeg($disk, $relative) ? $disk->path($relative) : $originalPath;
        }

        try {
            // Re-check under the lock: a file already sitting at this exact path is trusted only
            // if it is really a JPEG. A pre-fix deploy could have written PNG/WebP bytes here
            // under the .jpg name (the bug both routes trusted for Content-Type); never re-serve
            // that verbatim, always regenerate it.
            if (! $this->isValidJpeg($disk, $relative)) {
                $this->render(originalPath: $originalPath, disk: $disk, relative: $relative, width: $width);
            }
        } finally {
            $lock->release();
        }

        return $this->isValidJpeg($disk, $relative) ? $disk->path($relative) : $originalPath;
    }

    /**
     * A cheap on-disk check (magic-byte header only, never the full file) that a path claiming
     * to be a rendered derivative is actually a JPEG, not e.g. PNG/WebP bytes copied verbatim
     * under a .jpg name by a since-fixed bug, or a partial/corrupt write.
     */
    private function isValidJpeg(Filesystem $disk, string $relative): bool
    {
        if (! $disk->exists($relative)) {
            return false;
        }

        $absolute = $disk->path($relative);
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $header = fread($handle, 3);
        } finally {
            fclose($handle);
        }

        // JPEG's SOI marker: 0xFFD8FF. GD/libjpeg always emits it as the first three bytes.
        return $header === "\xFF\xD8\xFF";
    }

    private function render(string $originalPath, Filesystem $disk, string $relative, int $width): void
    {
        try {
            // The derivative is always named {slug}.jpg and both routes trust that suffix for
            // Content-Type, so a PNG/WebP source (the catalog carries all three) must always be
            // transcoded to a real JPEG here -- never written verbatim under a .jpg name, even
            // when it is already <= the target width and would otherwise just be copied.
            $manager = new ImageManager(new Driver);
            $image = $manager->decode(file_get_contents($originalPath));

            $size = @getimagesize($originalPath);
            $alreadyNarrowEnough = $size !== false && $size[0] > 0 && $size[0] <= $width;
            if (! $alreadyNarrowEnough) {
                $image = $image->scale(width: $width);
            }

            $encoded = (string) $image->encode(new JpegEncoder(quality: self::QUALITY, progressive: true));

            $disk->put($relative, $encoded);
        } catch (Throwable $error) {
            Log::info('StylePress thumbnail derivative could not be generated; serving the original file.', [
                'source' => $originalPath,
                'error'  => $error->getMessage(),
            ]);
        }
    }

    private function relativePath(string $slug, string $catalogVersion): string
    {
        return 'stylepress-thumbnails/'.$this->safeSegment($catalogVersion).'/'.$this->safeSegment($slug).'.jpg';
    }

    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '-', $value) ?? '';

        return $safe !== '' ? $safe : 'unknown';
    }
}
