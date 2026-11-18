<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Where an asset's cover art (audio cover, embedded picture) lives. A cover is published once, to a name that carries a
 * hash of its bytes (`covers/<asset id>-<hash>.<ext>`), and the asset row points at it: a name is never rewritten, so a
 * failed or rolled-back publish leaves the previous cover exactly as it was, and an old cover is deleted only after the
 * pointer that named it has been replaced. Covers written before that sit at `covers/<asset id>.<ext>`.
 */
final class CoverArt
{
    public const EXTENSIONS = ['jpg', 'png', 'gif', 'webp'];

    /** `covers/<id>.<ext>` (legacy) or `covers/<id>-<16 hex>.<ext>`. */
    public const PATTERN = '#^covers/\d+(-[a-f0-9]{16})?\.(jpg|png|gif|webp)$#D';

    public static function path(int $assetId, string $bytes, string $extension): string
    {
        return 'covers/'.$assetId.'-'.substr(hash('sha256', $bytes), 0, 16).'.'.$extension;
    }

    /** Whether `$path` is one of this asset's own covers (the legacy or the hashed name), not a copy's inherited pointer to another's. */
    public static function ownedBy(int $assetId, string $path): bool
    {
        return preg_match(self::PATTERN, $path) === 1 && (str_starts_with($path, 'covers/'.$assetId.'.') || str_starts_with($path, 'covers/'.$assetId.'-'));
    }

    /**
     * Deletes the legacy cover names (`covers/<asset id>.<ext>`), which only the first-upload paths ever wrote, when the
     * entry's content is replaced. A hashed cover is never deleted here or by any publish, reject or promote path: a
     * name is its bytes, another job may be about to point a row at it, and an unreferenced one is small and harmless.
     * They are reclaimed only when the asset is purged (AssetDeletionService).
     */
    public static function purgeLegacy(int $assetId, string $disk): void
    {
        $storage = Storage::disk($disk);

        foreach (self::EXTENSIONS as $extension) {
            try {
                $storage->delete('covers/'.$assetId.'.'.$extension);
            } catch (Throwable) {
            }
        }
    }
}
