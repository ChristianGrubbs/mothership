<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Templates;

/**
 * Whether the template font can draw a piece of text. GD draws a character the font lacks as an empty box, so a fill
 * with emoji, Hebrew or CJK text used to render as tofu with no warning (G3-04). The only bundled font is
 * LiberationSans, which covers Latin, Greek and Cyrillic; no font that covers more is in the repository (DejaVu
 * ships only with dompdf under vendor/, and it lacks CJK and emoji as well), so unsupported text is refused with
 * a message instead of rendering as boxes. The check reads the font's own cmap table, so a different DAM_TEMPLATE_FONT
 * is judged by what it actually holds.
 */
final class FontCoverage
{
    /** @var array<string, array{bytes: string, format: int, offset: int}|null> */
    private static array $tables = [];

    /** The first character of $text the font has no glyph for, or null when it can draw all of it. */
    public static function firstMissing(string $fontPath, string $text): ?string
    {
        $table = self::table($fontPath);

        if ($table === null) {
            return null;
        }

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            // Spacing, line breaks and invisible format characters (zero-width joiners, direction marks) draw nothing.
            if (preg_match('/^[\s\p{Cc}\p{Cf}]$/u', $character) === 1) {
                continue;
            }

            if (! self::has(table: $table, codePoint: mb_ord($character, 'UTF-8'))) {
                return $character;
            }
        }

        return null;
    }

    /** @return array{bytes: string, format: int, offset: int}|null null when the font cannot be read: the caller then draws as before */
    private static function table(string $fontPath): ?array
    {
        if (array_key_exists($fontPath, self::$tables)) {
            return self::$tables[$fontPath];
        }

        return self::$tables[$fontPath] = self::load($fontPath);
    }

    /** @return array{bytes: string, format: int, offset: int}|null */
    private static function load(string $fontPath): ?array
    {
        $bytes = is_file($fontPath) ? @file_get_contents($fontPath) : false;

        if (! is_string($bytes) || strlen($bytes) < 12) {
            return null;
        }

        $tables = self::u16($bytes, 4);
        $cmap = null;

        for ($i = 0; $i < $tables; $i++) {
            $record = 12 + $i * 16;

            if (substr($bytes, $record, 4) === 'cmap') {
                $cmap = self::u32($bytes, $record + 8);
                break;
            }
        }

        if ($cmap === null) {
            return null;
        }

        $best = null;

        for ($i = 0, $count = self::u16($bytes, $cmap + 2); $i < $count; $i++) {
            $record = $cmap + 4 + $i * 8;
            $platform = self::u16($bytes, $record);
            $encoding = self::u16($bytes, $record + 2);
            $offset = $cmap + self::u32($bytes, $record + 4);
            $format = self::u16($bytes, $offset);
            $rank = match (true) {
                $platform === 3 && $encoding === 10 && $format === 12   => 3,
                $platform === 0 && $format === 12                       => 3,
                $platform === 3 && $encoding === 1 && $format === 4     => 2,
                $platform === 0 && $format === 4                        => 1,
                default                                                 => 0,
            };

            if ($rank > 0 && ($best === null || $rank > $best['rank'])) {
                $best = ['rank' => $rank, 'format' => $format, 'offset' => $offset];
            }
        }

        return $best === null ? null : ['bytes' => $bytes, 'format' => $best['format'], 'offset' => $best['offset']];
    }

    /** @param  array{bytes: string, format: int, offset: int}  $table */
    private static function has(array $table, int $codePoint): bool
    {
        ['bytes' => $bytes, 'format' => $format, 'offset' => $offset] = $table;

        if ($format === 12) {
            for ($i = 0, $groups = self::u32($bytes, $offset + 12); $i < $groups; $i++) {
                $group = $offset + 16 + $i * 12;

                if ($codePoint >= self::u32($bytes, $group) && $codePoint <= self::u32($bytes, $group + 4)) {
                    return true;
                }
            }

            return false;
        }

        if ($codePoint > 0xFFFF) {
            return false;
        }

        $segments = intdiv(self::u16($bytes, $offset + 6), 2);
        $ends = $offset + 14;
        $starts = $ends + $segments * 2 + 2;
        $deltas = $starts + $segments * 2;
        $rangeOffsets = $deltas + $segments * 2;

        for ($i = 0; $i < $segments; $i++) {
            if ($codePoint > self::u16($bytes, $ends + $i * 2)) {
                continue;
            }

            if ($codePoint < self::u16($bytes, $starts + $i * 2)) {
                return false;
            }

            $delta = self::u16($bytes, $deltas + $i * 2);
            $rangeOffset = self::u16($bytes, $rangeOffsets + $i * 2);

            if ($rangeOffset === 0) {
                return (($codePoint + $delta) & 0xFFFF) !== 0;
            }

            $glyph = self::u16($bytes, $rangeOffsets + $i * 2 + $rangeOffset + ($codePoint - self::u16($bytes, $starts + $i * 2)) * 2);

            return $glyph !== 0 && (($glyph + $delta) & 0xFFFF) !== 0;
        }

        return false;
    }

    private static function u16(string $bytes, int $at): int
    {
        return strlen($bytes) >= $at + 2 ? (int) unpack('n', $bytes, $at)[1] : 0;
    }

    private static function u32(string $bytes, int $at): int
    {
        return strlen($bytes) >= $at + 4 ? (int) unpack('N', $bytes, $at)[1] : 0;
    }
}
