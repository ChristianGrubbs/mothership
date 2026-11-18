<?php

namespace Mothership\DAM\Support;

use Mothership\DAM\Models\Asset;

/**
 * The Library path is a name, not a file (ADR 0009 slice 3): `dam_assets.path` is UNIQUE and is the only
 * thing a new name can collide with. Uploads, copies, moves and renames all pick names here, so every
 * writer agrees on the `name(1).ext` scheme. The UNIQUE constraint still decides a race between two
 * writers; callers that insert catch it and ask again.
 */
final class LibraryPaths
{
    /**
     * A file name that is free in `$directoryPath` (e.g. `assets/Root/Photos`), starting from `$fileName`.
     * `$ignoreAssetId` lets an asset keep its own current name (a rename or move onto itself).
     */
    public static function uniqueFileName(string $directoryPath, string $fileName, ?int $ignoreAssetId = null): string
    {
        $directoryPath = rtrim($directoryPath, '/');
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $candidate = $fileName;

        for ($counter = 1; self::taken($directoryPath.'/'.$candidate, $ignoreAssetId); $counter++) {
            $candidate = $extension === '' ? "{$stem}({$counter})" : "{$stem}({$counter}).{$extension}";
        }

        return $candidate;
    }

    /** Trashed rows count too: they hold their `assets/.trash/{id}/` path under the same UNIQUE constraint (P2 S4). */
    public static function taken(string $path, ?int $ignoreAssetId = null): bool
    {
        return Asset::withTrashed()
            ->where('path', $path)
            ->when($ignoreAssetId !== null, fn ($query) => $query->whereKeyNot($ignoreAssetId))
            ->exists();
    }
}
