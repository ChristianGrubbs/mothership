<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

/**
 * Reads the thumbnail and a few facts out of a Blender `.blend` file without Blender (ADR 0006 stands: no
 * headless renderer). Blender embeds a small preview of the file in a `TEST` block every time it saves.
 *
 * The file layout (Blender's BLO_core_blend_header.hh and BLO_core_bhead.hh):
 *
 * - The whole file may be gzip-compressed (before 3.0) or zstd-compressed (3.0 and later); either is read
 *   as a stream, never inflated to disk or memory as a whole.
 * - Legacy header, 12 bytes: `BLENDER`, pointer size (`_` = 4, `-` = 8), endianness (`v` little, `V` big),
 *   a 3-digit version (`279`, `402`). Block headers are then BHead4 (`code, len, old:u32, SDNAnr, nr`) or
 *   SmallBHead8 (`code, len, old:u64, SDNAnr, nr`).
 * - Header since Blender 5.0, 17 bytes: `BLENDER17-01v0500` — header size `17`, `-`, file-format version
 *   `01`, `v` (always little-endian, 8-byte pointers) and a 4-digit version. Block headers are LargeBHead8
 *   (`code, SDNAnr, old:u64, len:i64, nr:i64`).
 * - Blocks follow until `ENDB`. `TEST` (int width, int height, then width*height RGBA pixels, bottom row
 *   first) is written after the `REND` blocks and before `GLOB`, so a thumbnail-only read stops at `GLOB`.
 *
 * Every ID datablock is one block whose code is its two-letter ID code padded with NULs (`SC\0\0` a scene,
 * `OB\0\0` an object), so datablocks are counted from block codes alone. Their names sit at an offset that
 * changes between versions (DNA), so they are not read.
 *
 * Nothing about the input is trusted: a byte cap and a deadline bound every read, a corrupt or truncated
 * file yields what was read before the damage, and a file that is not a .blend yields null.
 */
final class BlendFileReader
{
    /** The most decompressed bytes one read walks before it gives up (512 MB). */
    public const DEFAULT_MAX_BYTES = 536870912;

    public const COMPRESSION_NONE = 'none';

    public const COMPRESSION_GZIP = 'gzip';

    public const COMPRESSION_ZSTD = 'zstd';

    /** The ID datablocks counted, by block code. */
    public const COUNTED = [
        'SC' => 'scenes',
        'OB' => 'objects',
        'ME' => 'meshes',
        'MA' => 'materials',
        'CA' => 'cameras',
        'LA' => 'lights',
        'IM' => 'images',
        'AC' => 'actions',
        'GR' => 'collections',
    ];

    /** Blender writes 128 or 256 px previews; anything beyond this is damage, not a thumbnail. */
    private const MAX_THUMBNAIL_SIDE = 2048;

    private const CHUNK = 1048576;

    /** @var resource|null */
    private $stream = null;

    /** @var resource|null */
    private $process = null;

    private int $consumed = 0;

    private float $deadline = 0.0;

    private bool $seekable = false;

    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $timeoutSeconds = 60,
        private readonly string $zstdBinary = 'zstd',
    ) {}

    /**
     * @return array{
     *     compression: string,
     *     version: string,
     *     version_number: int,
     *     file_format_version: int,
     *     pointer_size: int,
     *     little_endian: bool,
     *     thumbnail: array{width: int, height: int, rgba: string}|null,
     *     counts: array<string, int>|null,
     *     complete: bool,
     * }|null  null when the file is not a .blend this reader understands (or cannot be opened)
     */
    public function read(string $path, bool $countBlocks = true): ?array
    {
        try {
            $compression = $this->open($path);

            if ($compression === null) {
                return null;
            }

            $header = $this->readHeader();

            if ($header === null) {
                return null;
            }

            [$thumbnail, $counts, $complete] = $this->walkBlocks($header, $countBlocks);

            return [
                'compression'         => $compression,
                'version'             => self::versionString($header['version']),
                'version_number'      => $header['version'],
                'file_format_version' => $header['file_format_version'],
                'pointer_size'        => $header['pointer_size'],
                'little_endian'       => $header['little_endian'],
                'thumbnail'           => $thumbnail,
                'counts'              => $countBlocks ? $counts : null,
                'complete'            => $complete,
            ];
        } finally {
            $this->close();
        }
    }

    /**
     * The thumbnail as a JPEG, flipped upright and flattened onto white (a poster has no alpha), or null
     * when GD cannot build it.
     *
     * @param  array{width: int, height: int, rgba: string}  $thumbnail
     */
    public static function thumbnailJpeg(array $thumbnail, int $quality = 85): ?string
    {
        $width = $thumbnail['width'];
        $height = $thumbnail['height'];
        $rgba = $thumbnail['rgba'];

        if ($width < 1 || $height < 1 || strlen($rgba) < $width * $height * 4) {
            return null;
        }

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            return null;
        }

        try {
            for ($row = 0; $row < $height; $row++) {
                // Row 0 in the file is the bottom of the picture.
                $y = $height - 1 - $row;
                $offset = $row * $width * 4;

                for ($x = 0; $x < $width; $x++, $offset += 4) {
                    $alpha = ord($rgba[$offset + 3]) / 255;
                    $red = (int) round(ord($rgba[$offset]) * $alpha + 255 * (1 - $alpha));
                    $green = (int) round(ord($rgba[$offset + 1]) * $alpha + 255 * (1 - $alpha));
                    $blue = (int) round(ord($rgba[$offset + 2]) * $alpha + 255 * (1 - $alpha));

                    imagesetpixel($image, $x, $y, ($red << 16) | ($green << 8) | $blue);
                }
            }

            ob_start();
            $written = imagejpeg($image, null, $quality);
            $jpeg = (string) ob_get_clean();

            return $written && $jpeg !== '' ? $jpeg : null;
        } finally {
            imagedestroy($image);
        }
    }

    /** Blender's own spelling: `2.79` and `2.80` before 3.0, `3.6`, `4.2`, `5.0` after. */
    public static function versionString(int $version): string
    {
        $major = intdiv($version, 100);
        $minor = $version % 100;

        return $version >= 300 ? "{$major}.{$minor}" : sprintf('%d.%02d', $major, $minor);
    }

    /** Opens `$path` as a stream of the uncompressed file; returns its compression, or null. */
    private function open(string $path): ?string
    {
        $this->consumed = 0;
        $this->deadline = microtime(true) + $this->timeoutSeconds;

        $file = @fopen($path, 'rb');

        if ($file === false) {
            return null;
        }

        $magic = (string) fread($file, 4);

        if (str_starts_with($magic, "\x1f\x8b")) {
            fclose($file);
            $this->stream = @fopen('compress.zlib://'.$path, 'rb') ?: null;

            return $this->stream ? self::COMPRESSION_GZIP : null;
        }

        if ($magic === "\x28\xb5\x2f\xfd") {
            fclose($file);

            return $this->openZstd($path) ? self::COMPRESSION_ZSTD : null;
        }

        rewind($file);
        $this->stream = $file;
        $this->seekable = true;

        return self::COMPRESSION_NONE;
    }

    /** Blender's zstd files are standard (seekable-format) frames; the `zstd` CLI streams them. */
    private function openZstd(string $path): bool
    {
        $pipes = [];
        $process = @proc_open(
            [$this->zstdBinary, '-dcq', '--', $path],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return false;
        }

        $this->process = $process;
        $this->stream = $pipes[1];
        stream_set_blocking($this->stream, false);

        return true;
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        if (is_resource($this->process)) {
            // An early stop (thumbnail found, cap reached) leaves zstd mid-stream.
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->stream = null;
        $this->process = null;
        $this->seekable = false;
    }

    /**
     * @return array{version: int, file_format_version: int, pointer_size: int, little_endian: bool, bhead_size: int}|null
     */
    private function readHeader(): ?array
    {
        $legacy = $this->readUpTo(12);

        if (strlen($legacy) < 12 || ! str_starts_with($legacy, 'BLENDER')) {
            return null;
        }

        $pointer = $legacy[7];

        if ($pointer === '_' || $pointer === '-') {
            $endian = $legacy[8];
            $digits = substr($legacy, 9, 3);

            if (($endian !== 'v' && $endian !== 'V') || ! ctype_digit($digits)) {
                return null;
            }

            $pointerSize = $pointer === '_' ? 4 : 8;

            return [
                'version'             => (int) $digits,
                'file_format_version' => 0,
                'pointer_size'        => $pointerSize,
                'little_endian'       => $endian === 'v',
                // code, len, old pointer, SDNAnr, nr.
                'bhead_size'          => 16 + $pointerSize,
            ];
        }

        // `BLENDER17-01v0500`: the 12 bytes read so far are `BLENDER17-01`.
        $header = $legacy.$this->readUpTo(5);

        if (strlen($header) < 17 || substr($header, 7, 2) !== '17' || $header[9] !== '-'
            || substr($header, 10, 2) !== '01' || $header[12] !== 'v' || ! ctype_digit(substr($header, 13, 4))) {
            return null;
        }

        return [
            'version'             => (int) substr($header, 13, 4),
            'file_format_version' => 1,
            'pointer_size'        => 8,
            'little_endian'       => true,
            // LargeBHead8: code, SDNAnr, old (u64), len (i64), nr (i64).
            'bhead_size'          => 32,
        ];
    }

    /**
     * @param  array{version: int, file_format_version: int, pointer_size: int, little_endian: bool, bhead_size: int}  $header
     * @return array{0: array{width: int, height: int, rgba: string}|null, 1: array<string, int>, 2: bool}
     */
    private function walkBlocks(array $header, bool $countBlocks): array
    {
        $thumbnail = null;
        $counts = array_fill_keys(array_values(self::COUNTED), 0);
        $little = $header['little_endian'];

        while (true) {
            $bhead = $this->readUpTo($header['bhead_size']);
            $code = substr($bhead, 0, 4);

            if ($code === 'ENDB') {
                return [$thumbnail, $counts, true];
            }

            if (strlen($bhead) < $header['bhead_size']) {
                // Truncated: keep what was found before the end.
                return [$thumbnail, $counts, false];
            }

            $length = $header['file_format_version'] === 1
                ? self::int64(substr($bhead, 16, 8))
                : self::int32(substr($bhead, 4, 4), $little);

            if ($length < 0 || $length > $this->maxBytes) {
                return [$thumbnail, $counts, false];
            }

            if ($code === 'TEST' && $thumbnail === null) {
                $thumbnail = $this->thumbnail($length, $little);

                if ($thumbnail === false) {
                    return [null, $counts, false];
                }

                continue;
            }

            if (! $countBlocks && $code !== 'REND') {
                // TEST comes right after the REND blocks; past them there is no thumbnail to find.
                return [$thumbnail, $counts, true];
            }

            if ($code[2] === "\0" && $code[3] === "\0" && isset(self::COUNTED[substr($code, 0, 2)])) {
                $counts[self::COUNTED[substr($code, 0, 2)]]++;
            }

            if (! $this->skip($length)) {
                return [$thumbnail, $counts, false];
            }
        }
    }

    /**
     * The TEST block's picture, null when the block is not a sane thumbnail (its bytes skipped), or false
     * when the stream ended inside it.
     *
     * @return array{width: int, height: int, rgba: string}|null|false
     */
    private function thumbnail(int $length, bool $little): array|null|false
    {
        $maxLength = 8 + self::MAX_THUMBNAIL_SIDE * self::MAX_THUMBNAIL_SIDE * 4;

        if ($length < 8 || $length > $maxLength) {
            return $this->skip($length) ? null : false;
        }

        $data = $this->readUpTo($length);

        if (strlen($data) < $length) {
            return false;
        }

        $width = self::int32(substr($data, 0, 4), $little);
        $height = self::int32(substr($data, 4, 4), $little);

        if ($width < 1 || $height < 1 || $width > self::MAX_THUMBNAIL_SIDE || $height > self::MAX_THUMBNAIL_SIDE
            || $length < 8 + $width * $height * 4) {
            return null;
        }

        return ['width' => $width, 'height' => $height, 'rgba' => substr($data, 8, $width * $height * 4)];
    }

    /** Up to `$length` bytes; fewer only at the end of the stream, the byte cap or the deadline. */
    private function readUpTo(int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length && $this->withinBudget()) {
            $chunk = $this->readChunk(min(self::CHUNK, $length - strlen($buffer)));

            if ($chunk === null) {
                break;
            }

            $buffer .= $chunk;
            $this->consumed += strlen($chunk);
        }

        return $buffer;
    }

    /** Skips `$length` bytes; false when the stream, the byte cap or the deadline ended first. */
    private function skip(int $length): bool
    {
        if ($this->seekable) {
            if ($this->consumed + $length > $this->maxBytes || fseek($this->stream, $length, SEEK_CUR) !== 0) {
                return false;
            }

            $this->consumed += $length;

            // fseek past the end succeeds on a plain file; the next header read then sees the truncation.
            return true;
        }

        $remaining = $length;

        while ($remaining > 0 && $this->withinBudget()) {
            $chunk = $this->readChunk(min(self::CHUNK, $remaining));

            if ($chunk === null) {
                return false;
            }

            $remaining -= strlen($chunk);
            $this->consumed += strlen($chunk);
        }

        return $remaining === 0;
    }

    /** One non-empty read, or null at the end of the stream (or a stalled zstd past the deadline). */
    private function readChunk(int $length): ?string
    {
        if ($this->process === null) {
            $chunk = fread($this->stream, $length);

            return $chunk === false || $chunk === '' ? null : $chunk;
        }

        while (microtime(true) < $this->deadline) {
            $read = [$this->stream];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000);

            if ($ready === false) {
                return null;
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($this->stream, $length);

            if ($chunk !== false && $chunk !== '') {
                return $chunk;
            }

            if (feof($this->stream)) {
                return null;
            }
        }

        return null;
    }

    private function withinBudget(): bool
    {
        return $this->consumed < $this->maxBytes && microtime(true) < $this->deadline;
    }

    private static function int32(string $bytes, bool $little): int
    {
        $value = unpack($little ? 'V' : 'N', $bytes)[1];

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    /** LargeBHead8 is always little-endian. */
    private static function int64(string $bytes): int
    {
        return unpack('P', $bytes)[1];
    }
}
