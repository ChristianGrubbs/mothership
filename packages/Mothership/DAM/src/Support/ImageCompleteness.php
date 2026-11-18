<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * Whether an uploaded image's bytes run all the way to their format's end (G1-17). `getimagesize()` reads only the
 * header and GD decodes a truncated JPEG into a half-grey picture without a warning, so each format is walked by
 * its own structure instead of searched for marker bytes, which can also occur inside compressed data:
 *
 * - PNG: every chunk is complete, with its CRC, up to and including an empty IEND chunk.
 * - GIF: every extension and image is complete, sub-block by sub-block, up to the trailer.
 * - JPEG: every marker segment is complete, every entropy-coded scan ends at a marker, and an EOI follows a scan.
 *
 * Bytes after the end (padding some writers add) are allowed; nothing is decoded or re-encoded.
 */
final class ImageCompleteness
{
    public static function check(string $bytes, ?string $mimeType): bool
    {
        return match ($mimeType) {
            'image/jpeg' => self::jpeg($bytes),
            'image/png'  => self::png($bytes),
            'image/gif'  => self::gif($bytes),
            default      => false,
        };
    }

    public static function png(string $bytes): bool
    {
        $length = strlen($bytes);

        if ($length < 8 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return false;
        }

        for ($offset = 8; $offset + 12 <= $length;) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $end = $offset + 12 + $size;

            if ($end > $length) {
                return false;
            }

            $type = substr($bytes, $offset + 4, 4);
            $crc = unpack('N', substr($bytes, $offset + 8 + $size, 4))[1];

            if (crc32($type.substr($bytes, $offset + 8, $size)) !== $crc) {
                return false;
            }

            if ($type === 'IEND') {
                return $size === 0;
            }

            $offset = $end;
        }

        return false;
    }

    public static function gif(string $bytes): bool
    {
        $length = strlen($bytes);

        if ($length < 13 || ! in_array(substr($bytes, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return false;
        }

        $flags = ord($bytes[10]);
        $offset = 13 + (($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0);

        while ($offset < $length) {
            $introducer = ord($bytes[$offset]);

            if ($introducer === 0x3B) {
                return true;
            }

            if ($introducer === 0x21) {
                // Extension: introducer, label, then data sub-blocks.
                $offset = self::gifSubBlocks($bytes, $offset + 2);
            } elseif ($introducer === 0x2C) {
                // Image: a 10-byte descriptor, its colour table, the LZW minimum code size, then data sub-blocks.
                if ($offset + 10 > $length) {
                    return false;
                }

                $local = ord($bytes[$offset + 9]);
                $offset += 10 + (($local & 0x80) ? 3 * (2 ** (($local & 0x07) + 1)) : 0);
                $offset = self::gifSubBlocks($bytes, $offset + 1);
            } else {
                return false;
            }

            if ($offset === null) {
                return false;
            }
        }

        return false;
    }

    public static function jpeg(string $bytes): bool
    {
        $length = strlen($bytes);

        if ($length < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            return false;
        }

        $offset = 2;
        $scanned = false;

        while ($offset + 2 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return false;
            }

            $marker = ord($bytes[$offset + 1]);

            // Fill bytes before a marker.
            if ($marker === 0xFF) {
                $offset++;

                continue;
            }

            if ($marker === 0xD9) {
                return $scanned;
            }

            // Markers without a length: TEM and the restart markers.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $offset += 2;

                continue;
            }

            if ($offset + 4 > $length) {
                return false;
            }

            $segment = unpack('n', substr($bytes, $offset + 2, 2))[1];
            $offset += 2 + $segment;

            if ($segment < 2 || $offset > $length) {
                return false;
            }

            if ($marker === 0xDA) {
                // Entropy-coded data runs to the next marker that is neither a stuffed 0xFF00 nor a restart marker.
                $offset = self::jpegScanEnd($bytes, $offset);

                if ($offset === null) {
                    return false;
                }

                $scanned = true;
            }
        }

        return false;
    }

    /** The offset after a GIF's run of data sub-blocks, which ends with an empty block; null when it is cut short. */
    private static function gifSubBlocks(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);

        while ($offset < $length) {
            $size = ord($bytes[$offset]);
            $offset += 1 + $size;

            if ($size === 0) {
                return $offset;
            }
        }

        return null;
    }

    /** The offset of the marker that ends a JPEG scan starting at `$offset`; null when the data is cut short. */
    private static function jpegScanEnd(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);

        while (($offset = strpos($bytes, "\xFF", $offset)) !== false && $offset + 1 < $length) {
            $next = ord($bytes[$offset + 1]);

            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7) || $next === 0xFF) {
                $offset += $next === 0xFF ? 1 : 2;

                continue;
            }

            return $offset;
        }

        return null;
    }
}
