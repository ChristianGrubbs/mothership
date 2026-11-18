<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Similarity;

use GdImage;

/**
 * The 64-bit difference hash (dHash) and the banded lookup that finds near-duplicates without a scan (P5c, D16).
 *
 * Hash: the image is drawn onto white (so transparency hashes like the page it sits on), shrunk to 9x8 with GD's
 * area-averaging resample, turned to luma (Rec. 601), and each of the 8 rows gives 8 bits, one per neighbouring pair:
 * 1 when the left pixel is brighter by more than DEAD_ZONE luma levels. Bits run row by row, most significant first,
 * into a signed 64-bit PHP int.
 *
 * Dead zone: plain dHash (1 when left > right) turns every flat stretch (a studio backdrop, a page margin) into
 * coin flips, because JPEG noise moves two equal neighbours a level either way. Measured on the 148 real images in
 * the StylePress corpus, re-encoding at q40 moved up to 10 bits that way; with a 2-level dead zone every re-encode and
 * resize stayed within 3 bits, while a flat stretch reads 0 in every copy.
 *
 * Flat: an image whose 72 luma samples span at most FLAT_SPREAD levels (a blank page, a solid swatch) has no
 * gradient to describe, so its bits are noise. It is stored as `flat` and never paired.
 *
 * Banding (pigeonhole): split the 64 bits into BANDS = 4 slices of 16. If two hashes differ in at most t bits in
 * total, the four per-band distances sum to at most t, so at least one band differs in at most floor(t / 4) bits;
 * were every band at floor(t / 4) + 1 or more, the total would be at least 4 * floor(t / 4) + 4 > t. So looking up,
 * in each band, the stored values within floor(t / 4) bits of ours finds every hash within t: no false negatives.
 * With the default t = 4 (up to 7) that radius is 1, 17 values per band (the band itself and its 16 one-bit flips), 68
 * indexed values in all. Candidates are then checked on the full 64 bits. MAX_THRESHOLD caps t at 11 (radius 2, 137 values per band,
 * 548 bound parameters, under SQL Server's 2,100).
 */
final class DHash
{
    public const ALGORITHM = 'dhash-v1';

    public const BANDS = 4;

    public const MAX_THRESHOLD = 11;

    /** Luma levels (0-255) the left neighbour must exceed the right by for a 1 bit. */
    public const DEAD_ZONE = 2.0;

    /** Luma levels (0-255) the 72 samples may span and still count as flat. */
    public const FLAT_SPREAD = 6;

    /** @var array<int, int>|null popcount of each byte */
    private static ?array $byteBits = null;

    /**
     * The hash of an encoded image (JPEG, PNG, WebP, GIF, AVIF...), or null when GD cannot read it.
     *
     * @return array{hash: int, flat: bool}|null
     */
    public static function fromBytes(string $bytes): ?array
    {
        if ($bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            return null;
        }

        return self::fromImage($image);
    }

    /** @return array{hash: int, flat: bool} */
    public static function fromImage(GdImage $image): array
    {
        $small = imagecreatetruecolor(9, 8);
        imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
        imagealphablending($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 9, 8, imagesx($image), imagesy($image));

        $luma = [];

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $luma[$y][$x] = ((($rgb >> 16) & 0xFF) * 299 + (($rgb >> 8) & 0xFF) * 587 + ($rgb & 0xFF) * 114) / 1000;
            }
        }

        $hash = 0;
        $values = array_merge(...$luma);

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $hash = ($hash << 1) | ($luma[$y][$x] > $luma[$y][$x + 1] + self::DEAD_ZONE ? 1 : 0);
            }
        }

        return ['hash' => $hash, 'flat' => max($values) - min($values) <= self::FLAT_SPREAD];
    }

    /** Bits that differ between two hashes, 0-64. */
    public static function distance(int $a, int $b): int
    {
        self::$byteBits ??= array_map(fn (int $byte) => substr_count(decbin($byte), '1'), range(0, 255));
        $x = $a ^ $b;
        $bits = 0;

        for ($shift = 0; $shift < 64; $shift += 8) {
            $bits += self::$byteBits[($x >> $shift) & 0xFF];
        }

        return $bits;
    }

    /**
     * The four 16-bit bands, most significant first, each 0-65535.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function bands(int $hash): array
    {
        return [($hash >> 48) & 0xFFFF, ($hash >> 32) & 0xFFFF, ($hash >> 16) & 0xFFFF, $hash & 0xFFFF];
    }

    /** The per-band radius a lookup for `$threshold` needs: floor(t / BANDS). */
    public static function radius(int $threshold): int
    {
        return intdiv(self::clampThreshold($threshold), self::BANDS);
    }

    public static function clampThreshold(int $threshold): int
    {
        return max(0, min(self::MAX_THRESHOLD, $threshold));
    }

    /**
     * Every 16-bit value within `$radius` bits of `$band`, the band itself first.
     *
     * @return list<int>
     */
    public static function neighbours(int $band, int $radius): array
    {
        $values = [$band];
        $frontier = [[$band, -1]];

        for ($step = 0; $step < $radius; $step++) {
            $next = [];

            foreach ($frontier as [$value, $lastBit]) {
                // Flip bits in increasing order only, so each set of flipped bits is produced once.
                for ($bit = $lastBit + 1; $bit < 16; $bit++) {
                    $flipped = $value ^ (1 << $bit);
                    $values[] = $flipped;
                    $next[] = [$flipped, $bit];
                }
            }

            $frontier = $next;
        }

        return $values;
    }

    /** The hash as 16 hex digits, for logs and REST (two's complement for a negative int). */
    public static function hex(int $hash): string
    {
        return sprintf('%016x', $hash);
    }
}
