<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * P3b-2 (D5 suggestions): the stem two file names share once their version, delivery and size suffixes are gone,
 * so `hero-v2.jpg`, `Hero_final.png`, `hero@2x.png`, `hero-1080p.mp4` and `hero(1).jpg` all read as `hero`.
 *
 * The rules, applied in order:
 * 1. Drop the extension (the last `.ext` of 1-8 letters or digits) and trim.
 * 2. Strip trailing suffixes, repeatedly, so `hero_final-v2@2x` is `hero`:
 *    - a pixel density: `@2x`, `@1.5x`;
 *    - a version: `-v2`, `_v03`, ` v2`, `.v2`;
 *    - a finality marker: `-final`, `_final2`, ` FINAL`;
 *    - a video size: `-1080p`, `_720p`, `-2160p`, `-4k`, `_8k`;
 *    - pixel dimensions: `-1920x1080`, `_300x250`;
 *    - the Library's free-name counter: `(1)`, ` (2)`.
 * 3. Fold separators (`-`, `_`, `.`, spaces) to one space, and lowercase for matching.
 *
 * A name that is nothing but suffixes keeps its last non-empty form rather than becoming blank, and a stem shorter
 * than 2 characters is no stem (null): `1.jpg` and `a.png` group nothing.
 */
final class FilenameStem
{
    /** The column's width; a longer stem is cut, as stack_key is. */
    public const MAX_LENGTH = 191;

    private const SUFFIXES = [
        '/@\d+(?:\.\d+)?x$/i',
        '/[-_ .]v\d+$/i',
        '/[-_ .]final\d*$/i',
        '/[-_ .](?:\d{3,4}p|[48]k)$/i',
        '/[-_ .]\d{2,5}x\d{2,5}$/i',
        '/\s*\(\d+\)$/',
    ];

    /** The matching form (lowercased), or null when the name has no usable stem. */
    public static function of(?string $fileName): ?string
    {
        $readable = self::readable($fileName);

        return $readable === null ? null : mb_strtolower($readable);
    }

    /** The same stem with its case kept, for showing and for prefilling a stack key. */
    public static function readable(?string $fileName): ?string
    {
        $name = trim((string) $fileName);
        $name = (string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name);

        do {
            $before = $name;

            foreach (self::SUFFIXES as $pattern) {
                $stripped = trim((string) preg_replace($pattern, '', $name));

                if ($stripped !== '') {
                    $name = $stripped;
                }
            }
        } while ($name !== $before);

        $name = trim((string) preg_replace('/[-_.\s]+/u', ' ', $name));

        if (mb_strlen($name) < 2) {
            return null;
        }

        return mb_substr($name, 0, self::MAX_LENGTH);
    }
}
