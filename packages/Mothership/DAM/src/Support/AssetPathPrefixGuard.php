<?php

namespace Mothership\DAM\Support;

/**
 * Bounds a REPLACE()-based path-prefix rewrite (a folder rename or move rewriting every
 * `dam_assets.path` under it) to rows actually under that prefix. REPLACE() is a raw substring
 * swap with no concept of a path boundary, so renaming/moving `.../Photos` must not also rewrite
 * a sibling `.../Photos2/x` just because it starts with the same characters.
 */
final class AssetPathPrefixGuard
{
    /** Escape a literal string for safe use inside a SQL LIKE pattern (paired with ESCAPE '\'). */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [boundedOldPrefix, boundedNewPrefix, likePattern]
     *                                                boundedOldPrefix/boundedNewPrefix always end in
     *                                                '/'; likePattern matches only paths under it
     *                                                (pair with `LIKE ? ESCAPE '\\'`).
     */
    public static function boundedPrefixes(string $oldPrefix, string $newPrefix): array
    {
        $old = rtrim($oldPrefix, '/').'/';
        $new = rtrim($newPrefix, '/').'/';

        return [$old, $new, self::escapeLike($old).'%'];
    }
}
