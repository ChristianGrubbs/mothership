<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use InvalidArgumentException;

/** Unit-length float32 vectors, little-endian, base64 in a text column (spec §2). */
final class VectorCodec
{
    /** @param list<float|int> $vector @return list<float> */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => (float) $v * (float) $v, $vector)));

        if ($vector === [] || $norm <= 0.0 || ! is_finite($norm)) {
            throw new InvalidArgumentException('Vector has no direction.');
        }

        return array_map(fn ($v) => (float) $v / $norm, array_values($vector));
    }

    /** @param list<float|int> $vector */
    public static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...self::normalize($vector)));
    }

    /** @return list<float> */
    public static function decode(string $encoded): array
    {
        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '' || strlen($bytes) % 4 !== 0) {
            throw new InvalidArgumentException('Malformed vector.');
        }

        return array_values(unpack('g*', $bytes));
    }

    /** Cosine of two unit vectors; a length mismatch scores 0 rather than comparing different models. */
    public static function dot(array $a, array $b): float
    {
        $n = count($a);

        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }

        $sum = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
