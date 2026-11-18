<?php

namespace Mothership\DAM\Support;

use Generator;
use InflateContext;
use RuntimeException;

/**
 * A streaming reader of the entry headers of a gzip-compressed tar archive: each entry's name, size and type, with
 * nothing extracted (a caller may opt small entries into memory, see entries()). It exists for the archive
 * `dam:storage:backup` writes (DamUpdater::archiveAssetFiles(), `tar -czf … -C <disk root> assets`), and a Unity
 * package (UnityPackageReader) is the same gzip-compressed tar. Whichever tar wrote it:
 *
 * - POSIX ustar: a name longer than 100 bytes is split into the `prefix` and `name` fields.
 * - GNU tar (the Studio container is Debian): a long name comes in a preceding `L` record, and the prefix field
 *   holds other data, so it is read only under the POSIX magic. A size of 8 GiB or more is base-256.
 * - PAX (macOS bsdtar): a preceding `x` record carries `path` and `size`, which override the header's fields.
 *
 * Parsing `tar -tv` text instead would depend on which tar prints it. Names are returned as stored, and a caller
 * comparing them with a directory listing normalizes both sides, because a tar may store another Unicode
 * normalization than the disk lists.
 *
 * The whole archive is decompressed once, through zlib's gzip decoder (inflate_init(ZLIB_ENCODING_GZIP)), and an
 * archive is accepted only when all of this holds:
 * - it is one gzip stream that ends with its trailer, and zlib checks that trailer's CRC-32 and length against
 *   everything it decompressed, every entry's data included;
 * - nothing but zero bytes follows that stream;
 * - every tar header's checksum matches;
 * - the tar reaches its end-of-archive block before the decompressed data runs out.
 *
 * gzread() was not enough: it returns an empty string at the end of the file whether or not the gzip trailer was
 * there, so an archive cut anywhere in its last eight bytes read as complete. Anything else throws, so a damaged
 * backup is never read as a shorter one. Entry data is read through and discarded, never compared with a disk.
 */
final class TarListing
{
    private const BLOCK = 512;

    /** The largest `L`, `K`, `x` or `g` record body read into memory; a real one is a few hundred bytes. */
    private const MAX_META = 1048576;

    /**
     * Compressed bytes inflated per step. Deflate expands at most about 1032:1, so one step yields at most about
     * 17 MB, however well the archive compressed.
     */
    private const INFLATE_CHUNK = 16384;

    private const TAIL_CHUNK = 65536;

    /** The largest entry body a capture keeps in memory, whatever the callback asks for. */
    public const MAX_CAPTURE = 16777216;

    /** Type flags of entries that carry no data blocks, whatever their size field says. */
    private const NO_DATA = ['1', '2', '3', '4', '5', '6'];

    /** Decompressed bytes not yet read; `$offset` marks how far into them reading has got. */
    private string $pending = '';

    private int $offset = 0;

    /** Compressed bytes handed to zlib so far. */
    private int $fed = 0;

    /** Whether the gzip stream has ended, its trailer verified. */
    private bool $ended = false;

    /** Compressed bytes of the last chunk that followed the end of the gzip stream. */
    private string $afterStream = '';

    /** Decompressed bytes produced so far, against the caller's optional budget. */
    private int $inflated = 0;

    /**
     * @param  resource  $handle
     * @param  (callable(string, int): bool)|null  $capture
     */
    private function __construct(
        private $handle,
        private InflateContext $inflate,
        private $capture = null,
        private ?int $maxBytes = null,
        private ?float $deadline = null,
    ) {}

    /**
     * Every entry, in archive order. `type` is `file` (a regular file), `directory`, `link` (hard or symbolic)
     * or `other`. The archive is verified to its last byte after the final entry has been yielded, so a caller
     * must consume the whole generator before it trusts any entry.
     *
     * `$capture(name, size)` opts a regular file of at most MAX_CAPTURE bytes into carrying its bytes as `data`
     * (a Unity package's `pathname` and `preview.png`); every other entry's data is still read through and
     * discarded. Without a callback nothing is kept.
     *
     * `$maxBytes` and `$timeoutSeconds` bound an untrusted archive (a Unity package): once `$maxBytes` of
     * decompressed data have been read or skipped, or the time is up, the next inflate step throws archive_budget
     * or archive_deadline instead. Memory stays bounded by one step (about 17 MB) either way. Neither is set for a
     * backup, which is read to its end whatever its size.
     *
     * @param  (callable(string, int): bool)|null  $capture
     * @return Generator<int, array{name: string, size: int, type: string, data?: string}>
     *
     * @throws RuntimeException archive_unreadable, archive_truncated, archive_corrupt, archive_budget or archive_deadline
     */
    public static function entries(string $path, ?callable $capture = null, ?int $maxBytes = null, ?float $timeoutSeconds = null): Generator
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new RuntimeException('archive_unreadable');
        }

        try {
            $inflate = @inflate_init(ZLIB_ENCODING_GZIP);

            if ($inflate === false) {
                throw new RuntimeException('archive_unreadable');
            }

            $deadline = $timeoutSeconds === null ? null : microtime(true) + $timeoutSeconds;

            yield from (new self($handle, $inflate, $capture, $maxBytes, $deadline))->headers();
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int, array{name: string, size: int, type: string, data?: string}> */
    private function headers(): Generator
    {
        $longName = null;
        $pax = [];

        while (true) {
            $header = $this->read(self::BLOCK);

            if (trim($header, "\0") === '') {
                $this->finish();

                return;
            }

            self::verifyChecksum($header);
            $type = $header[156] === "\0" ? '0' : $header[156];
            $size = self::number(substr($header, 124, 12));

            if (in_array($type, ['L', 'K', 'x', 'g'], true)) {
                $body = $this->meta($size);

                if ($type === 'L') {
                    $longName = self::cString($body);
                } elseif ($type === 'x') {
                    $pax = self::paxRecords($body);
                }

                continue;
            }

            $name = $pax['path'] ?? $longName ?? self::ustarName($header);
            $size = isset($pax['size']) ? self::paxSize($pax['size']) : $size;
            $longName = null;
            $pax = [];

            $entry = ['name' => $name, 'size' => $size, 'type' => self::kind($type)];

            if ($entry['type'] === 'file' && $this->capture !== null && $size <= self::MAX_CAPTURE
                && ($this->capture)($name, $size)) {
                $entry['data'] = $this->read($size);
                $this->skip(self::padded($size) - $size);

                yield $entry;

                continue;
            }

            yield $entry;

            if (! in_array($type, self::NO_DATA, true)) {
                $this->skip(self::padded($size));
            }
        }
    }

    private function read(int $length): string
    {
        while (strlen($this->pending) - $this->offset < $length) {
            $this->inflateMore();
        }

        $data = substr($this->pending, $this->offset, $length);
        $this->offset += $length;

        return $data;
    }

    private function skip(int $length): void
    {
        while ($length > 0) {
            if (strlen($this->pending) === $this->offset) {
                $this->inflateMore();
            }

            $take = min($length, strlen($this->pending) - $this->offset);
            $this->offset += $take;
            $length -= $take;
        }
    }

    /**
     * Inflate the next compressed chunk onto the pending bytes. At the end of the file zlib is asked to finish: a
     * stream that has not reached its end by then is missing data or its trailer. zlib verifies the trailer's
     * CRC-32 and length as the stream ends and reports a mismatch as a data error.
     */
    private function inflateMore(): void
    {
        if ($this->ended) {
            // The tar asks for more than the verified gzip stream holds.
            throw new RuntimeException('archive_truncated');
        }

        // Bytes already read or skipped: what was inflated, less what is still pending.
        $this->checkBounds($this->inflated - (strlen($this->pending) - $this->offset));

        if ($this->offset > 0) {
            $this->pending = substr($this->pending, $this->offset);
            $this->offset = 0;
        }

        $chunk = fread($this->handle, self::INFLATE_CHUNK);

        if ($chunk === false) {
            throw new RuntimeException('archive_unreadable');
        }

        $last = $chunk === '';
        $this->fed += strlen($chunk);
        $output = @inflate_add($this->inflate, $chunk, $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);

        if ($output === false) {
            throw new RuntimeException('archive_corrupt');
        }

        $this->pending .= $output;
        $this->inflated += strlen($output);

        if (inflate_get_status($this->inflate) === ZLIB_STREAM_END) {
            $this->ended = true;
            $unread = $this->fed - inflate_get_read_len($this->inflate);
            $this->afterStream = $unread > 0 ? substr($chunk, -$unread) : '';
        } elseif ($last) {
            throw new RuntimeException('archive_truncated');
        }
    }

    /**
     * After the end-of-archive block: inflate the rest (the record padding tar adds), so that zlib reaches and
     * verifies the trailer, then require that only zero bytes follow the gzip stream in the file.
     */
    private function finish(): void
    {
        while (! $this->ended) {
            $this->pending = '';
            $this->offset = 0;
            $this->inflateMore();
        }

        // What followed the stream in its last chunk, then the rest of the file, to its end. That padding counts
        // against the caller's bounds too: a small archive can carry a tail of zeros of any length.
        $rest = $this->afterStream;
        $trailing = 0;

        do {
            if (trim($rest, "\0") !== '') {
                throw new RuntimeException('archive_corrupt');
            }

            $trailing += strlen($rest);
            $this->checkBounds($this->inflated + $trailing);
            $rest = fread($this->handle, self::TAIL_CHUNK);

            if ($rest === false) {
                throw new RuntimeException('archive_unreadable');
            }
        } while ($rest !== '');
    }

    /** The caller's optional bounds, given the bytes consumed so far; without them nothing is checked. */
    private function checkBounds(int $consumed): void
    {
        if ($this->maxBytes !== null && $consumed >= $this->maxBytes) {
            throw new RuntimeException('archive_budget');
        }

        if ($this->deadline !== null && microtime(true) > $this->deadline) {
            throw new RuntimeException('archive_deadline');
        }
    }

    private function meta(int $size): string
    {
        if ($size > self::MAX_META) {
            throw new RuntimeException('archive_corrupt');
        }

        $body = $this->read($size);
        $this->skip(self::padded($size) - $size);

        return $body;
    }

    private static function padded(int $size): int
    {
        return intdiv($size + self::BLOCK - 1, self::BLOCK) * self::BLOCK;
    }

    /** The checksum covers the header with its own field read as spaces; old tars summed signed bytes. */
    private static function verifyChecksum(string $header): void
    {
        $stored = self::number(substr($header, 148, 8));
        $blanked = substr_replace($header, str_repeat(' ', 8), 148, 8);

        if ($stored !== array_sum(unpack('C*', $blanked)) && $stored !== array_sum(unpack('c*', $blanked))) {
            throw new RuntimeException('archive_corrupt');
        }
    }

    /** A numeric field: octal digits padded with NULs or spaces, or GNU base-256 when the high bit is set. */
    private static function number(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            if (ord($field[0]) === 0xFF) {
                throw new RuntimeException('archive_corrupt');
            }

            $value = ord($field[0]) & 0x7F;

            for ($i = 1; $i < strlen($field); $i++) {
                if ($value > (PHP_INT_MAX >> 8)) {
                    throw new RuntimeException('archive_corrupt');
                }

                $value = ($value << 8) | ord($field[$i]);
            }

            return $value;
        }

        $digits = trim($field, "\0 ");

        if ($digits === '') {
            return 0;
        }

        if (preg_match('/^[0-7]{1,12}$/', $digits) !== 1) {
            throw new RuntimeException('archive_corrupt');
        }

        return (int) octdec($digits);
    }

    private static function paxSize(string $value): int
    {
        if (preg_match('/^\d{1,18}$/', $value) !== 1) {
            throw new RuntimeException('archive_corrupt');
        }

        return (int) $value;
    }

    /**
     * PAX records, each `<length> <key>=<value>\n` where the length counts the whole record.
     *
     * @return array<string, string>
     */
    private static function paxRecords(string $body): array
    {
        $records = [];
        $offset = 0;

        while ($offset < strlen($body)) {
            $space = strpos($body, ' ', $offset);
            $length = $space === false ? '' : substr($body, $offset, $space - $offset);

            // The shortest record body is `k=\n`, so a length that ends before it never advances the offset.
            if ($length === '' || ! ctype_digit($length) || $offset + (int) $length > strlen($body) || $offset + (int) $length < $space + 4) {
                throw new RuntimeException('archive_corrupt');
            }

            $record = substr($body, $space + 1, $offset + (int) $length - $space - 1);
            $equals = strpos($record, '=');

            if ($equals === false || ! str_ends_with($record, "\n")) {
                throw new RuntimeException('archive_corrupt');
            }

            $records[substr($record, 0, $equals)] = substr($record, $equals + 1, -1);
            $offset += (int) $length;
        }

        return $records;
    }

    /** The name a header carries itself: `prefix/name` under the POSIX magic, where GNU keeps other data. */
    private static function ustarName(string $header): string
    {
        $name = self::cString(substr($header, 0, 100));
        $prefix = substr($header, 257, 6) === "ustar\0" ? self::cString(substr($header, 345, 155)) : '';

        return $prefix === '' ? $name : $prefix.'/'.$name;
    }

    private static function cString(string $field): string
    {
        $end = strpos($field, "\0");

        return $end === false ? $field : substr($field, 0, $end);
    }

    private static function kind(string $type): string
    {
        return match ($type) {
            '0', '7' => 'file',
            '5'      => 'directory',
            '1', '2' => 'link',
            default  => 'other',
        };
    }
}
