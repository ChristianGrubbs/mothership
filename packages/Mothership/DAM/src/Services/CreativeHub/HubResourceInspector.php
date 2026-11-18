<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

use LogicException;
use Mothership\DAM\Helpers\AssetHelper;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Decides what an uploaded Hub resource is from its bytes alone (ADR 0015): the client's MIME type is never read, and
 * the name only decides the brush format (an ABR has no magic of its own) and the forbidden-file rule.
 *
 * Fonts are TrueType, OpenType (CFF), WOFF or WOFF2. A variable TrueType or OpenType font is one face and is accepted.
 * Font collections (.ttc / .otc, `ttcf`) are refused in v1 (operator decision 2026-10-19), by magic and by name.
 * The vendored dompdf/php-font-lib reads the name, OS/2 and head tables in a child PHP
 * process with its own memory and time limits, so a hostile file (a WOFF table that inflates without bound, a table
 * directory that never ends) exhausts the child, not the request. php-font-lib cannot read WOFF2 (its tables are Brotli
 * compressed and this PHP has no Brotli), so a WOFF2 is accepted on a checked header only: its metadata says
 * `parsed: false` and its family is its file name.
 *
 * Brushes are Photoshop ABR files, passed through: the version word must be 1, 2, 6, 7 or 10, and from version 6 on
 * the file must carry the `8BIM` block signature after its version words.
 */
final class HubResourceInspector
{
    public const KIND_FONT = 'font';

    public const KIND_BRUSH = 'brush';

    /** The MIME type each accepted format is stored and later delivered with. */
    public const MIME_TYPES = [
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'abr'   => 'application/octet-stream',
    ];

    /** The first four bytes of each accepted font container. */
    private const FONT_MAGIC = [
        "\x00\x01\x00\x00" => 'ttf',
        'true'             => 'ttf',
        'OTTO'             => 'otf',
        'wOFF'             => 'woff',
        'wOF2'             => 'woff2',
    ];

    private const ABR_VERSIONS = [1, 2, 6, 7, 10];

    /** Where, after its two version words, an ABR from version 6 on must show its first `8BIM` block. */
    private const ABR_SIGNATURE_WINDOW = 64;

    private const HEAD_BYTES = 128;

    /** A font collection's magic, and the names a collection goes by; both are refused. */
    private const COLLECTION_MAGIC = 'ttcf';

    private const COLLECTION_EXTENSIONS = ['ttc', 'otc'];

    /** Test seam: the script the parser child runs instead of PARSER. Only honoured under unit tests. */
    private ?string $parserOverride = null;

    /** Each parser child's own temp directory under sys_get_temp_dir(), removed when the child is done. */
    public const SCRATCH_PREFIX = 'hub-font-parse-';

    /**
     * Runs in the child process with `php -- <autoload> <font>`, reads only the tables it needs, and always answers one
     * JSON object on stdout. The parent decides; this only reports.
     */
    private const PARSER = <<<'PHP'
<?php
error_reporting(0);
$answer = static function (array $result): never {
    fwrite(STDOUT, (string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    exit(0);
};
require $argv[1];
try {
    $font = \FontLib\Font::load($argv[2]);
    if (! $font instanceof \FontLib\TrueType\File) {
        $answer(['ok' => false, 'reason' => 'unparsed']);
    }
    $tags = array_map('strval', array_keys((array) $font->getTable()));
    if (! in_array('name', $tags, true)) {
        $answer(['ok' => false, 'reason' => 'unparsed']);
    }
    $name = static function (int $id) use ($font): ?string {
        $value = $font->getNameTableString($id);

        return $value === null ? null : (string) $value;
    };
    $os2 = in_array('OS/2', $tags, true) ? $font->getData('OS/2') : null;
    $head = in_array('head', $tags, true) ? $font->getData('head') : null;
    $answer([
        'ok'                    => true,
        'family'                => $name(1),
        'subfamily'             => $name(2),
        'full_name'             => $name(4),
        'version'               => $name(5),
        'postscript_name'       => $name(6),
        'typographic_family'    => $name(16),
        'typographic_subfamily' => $name(17),
        'weight'                => is_array($os2) ? ($os2['usWeightClass'] ?? null) : null,
        'fs_selection'          => is_array($os2) ? ($os2['fsSelection'] ?? null) : null,
        'mac_style'             => is_array($head) ? ($head['macStyle'] ?? null) : null,
        'variable'              => in_array('fvar', $tags, true),
        'tables'                => count($tags),
    ]);
} catch (\Throwable $e) {
    $answer(['ok' => false, 'reason' => 'unparsed', 'error_class' => get_class($e)]);
}
PHP;

    /**
     * Test seam: run $script in the parser child instead of the php-font-lib parser (null restores it), so a test can
     * make the child hang or write scratch files. Setting it outside unit tests throws, and it never runs outside them.
     */
    public function overrideParser(?string $script): void
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('HubResourceInspector::overrideParser() is a test seam and cannot be set outside unit tests.');
        }

        $this->parserOverride = $script;
    }

    /**
     * What the file at $path is. $originalName is the uploader's file name; it is cleaned the way Hub saves clean
     * names and becomes the resource's display name.
     *
     * @return array{kind: string, format: string, mime_type: string, name: string, family: ?string, subfamily: ?string, weight: ?int, style: ?string, postscript_name: ?string, size: int, meta: array<string, mixed>}
     *
     * @throws HubResourceRefused
     */
    public function inspect(string $path, string $originalName): array
    {
        $started = hrtime(true);
        $name = CreativeHubSaver::cleanName($originalName);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = @filesize($path);

        if ($size === false) {
            throw new RuntimeException('The uploaded resource is unreadable.');
        }

        try {
            if ($size === 0) {
                throw new HubResourceRefused(reason: HubResourceRefused::EMPTY, message: 'The file is empty.');
            }

            $sniffed = (string) MimeTypes::getDefault()->guessMimeType($path);
            if (AssetHelper::isForbiddenFile(extension: $extension, mimeType: $sniffed, fileName: $name, realPath: $path, inspectWebBundles: false)) {
                throw new HubResourceRefused(reason: HubResourceRefused::FORBIDDEN, message: 'This type of file is not allowed.');
            }

            $head = (string) file_get_contents($path, false, null, 0, self::HEAD_BYTES);
            if (substr($head, 0, 4) === self::COLLECTION_MAGIC || in_array($extension, self::COLLECTION_EXTENSIONS, true)) {
                throw new HubResourceRefused(reason: HubResourceRefused::COLLECTION, message: 'Font collections (.ttc, .otc) are not accepted. Upload each face as its own .ttf, .otf, .woff or .woff2 file.');
            }

            $format = self::FONT_MAGIC[substr($head, 0, 4)] ?? null;

            $result = match (true) {
                $format !== null     => $this->font(path: $path, head: $head, format: $format, name: $name, size: $size),
                $extension === 'abr' => $this->brush(head: $head, name: $name, size: $size),
                default              => throw new HubResourceRefused(reason: HubResourceRefused::UNKNOWN_FORMAT, message: 'This is not a font (TTF, OTF, WOFF, WOFF2) or a Photoshop brush (ABR).'),
            };
        } catch (HubResourceRefused $refused) {
            CreativeHubLog::write(event: 'resource_refused', ctx: ['reason' => $refused->reason, 'size' => $size, 'ext' => substr($extension, 0, 16)], level: 'info');

            throw $refused;
        }

        $result['meta']['sniffed_type'] = $sniffed;
        CreativeHubLog::write(event: 'resource_inspected', ctx: [
            'mode'        => $result['kind'],
            'ext'         => $result['format'],
            'size'        => $size,
            'status'      => ($result['meta']['parsed'] ?? false) ? 'parsed' : 'unparsed',
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ]);

        return $result;
    }

    /** @return array{kind: string, format: string, mime_type: string, name: string, family: ?string, subfamily: ?string, weight: ?int, style: ?string, postscript_name: ?string, size: int, meta: array<string, mixed>} */
    private function font(string $path, string $head, string $format, string $name, int $size): array
    {
        $this->assertSize(size: $size, limit: (int) config('dam.hub.resources.font_max_bytes'));

        $fallbackFamily = $this->clean(pathinfo($name, PATHINFO_FILENAME));

        if ($format === 'woff2') {
            return $this->resource(kind: self::KIND_FONT, format: $format, name: $name, size: $size, fields: ['family' => $fallbackFamily], meta: $this->woff2Header(head: $head, size: $size));
        }

        $parsed = $this->parse(path: $path);
        if (! ($parsed['ok'] ?? false)) {
            throw new HubResourceRefused(reason: HubResourceRefused::UNPARSED, message: 'The font could not be read. It may be damaged or not a font.');
        }

        $weight = is_int($parsed['weight'] ?? null) && $parsed['weight'] >= 1 && $parsed['weight'] <= 1000 ? $parsed['weight'] : null;
        $selection = is_int($parsed['fs_selection'] ?? null) ? $parsed['fs_selection'] : 0;
        $macStyle = is_int($parsed['mac_style'] ?? null) ? $parsed['mac_style'] : 0;
        $style = match (true) {
            ($selection & 0x200) !== 0                          => 'oblique',
            ($selection & 0x1) !== 0 || ($macStyle & 0x2) !== 0 => 'italic',
            default                                             => 'normal',
        };

        return $this->resource(kind: self::KIND_FONT, format: $format, name: $name, size: $size, fields: [
            'family'          => $this->clean($parsed['typographic_family'] ?? null) ?? $this->clean($parsed['family'] ?? null) ?? $fallbackFamily,
            'subfamily'       => $this->clean($parsed['typographic_subfamily'] ?? null) ?? $this->clean($parsed['subfamily'] ?? null),
            'weight'          => $weight,
            'style'           => $style,
            'postscript_name' => $this->clean($parsed['postscript_name'] ?? null),
        ], meta: [
            'parsed'    => true,
            'faces'     => 1,
            'variable'  => (bool) ($parsed['variable'] ?? false),
            'full_name' => $this->clean($parsed['full_name'] ?? null),
            'version'   => $this->clean($parsed['version'] ?? null),
        ]);
    }

    /**
     * The WOFF2 header (48 bytes, big-endian) checked against the file: its own length is the file's, it names at
     * least one table, its reserved word is zero, and its flavor is a single-face font. A WOFF2 collection is refused, as
     * every collection is in v1.
     *
     * @return array<string, mixed>
     */
    private function woff2Header(string $head, int $size): array
    {
        $header = strlen($head) >= 48 ? unpack('a4signature/a4flavor/Nlength/nnumTables/nreserved', $head) : false;
        $valid = $header !== false
            && (int) $header['length'] === $size
            && (int) $header['numTables'] >= 1
            && (int) $header['reserved'] === 0;

        if ($valid && $header['flavor'] === 'ttcf') {
            throw new HubResourceRefused(reason: HubResourceRefused::COLLECTION, message: 'WOFF2 font collections are not accepted. Upload each face as its own font file.');
        }

        if (! $valid || ! in_array($header['flavor'], ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
            throw new HubResourceRefused(reason: HubResourceRefused::UNPARSED, message: 'The WOFF2 font header is damaged.');
        }

        return [
            'parsed'          => false,
            'unparsed_reason' => 'woff2_needs_brotli',
            'faces'           => 1,
            'flavor'          => $header['flavor'] === 'OTTO' ? 'otf' : 'ttf',
            'tables'          => (int) $header['numTables'],
        ];
    }

    /** @return array{kind: string, format: string, mime_type: string, name: string, family: ?string, subfamily: ?string, weight: ?int, style: ?string, postscript_name: ?string, size: int, meta: array<string, mixed>} */
    private function brush(string $head, string $name, int $size): array
    {
        $this->assertSize(size: $size, limit: (int) config('dam.hub.resources.brush_max_bytes'));

        $version = strlen($head) >= 4 ? (int) unpack('n', substr($head, 0, 2))[1] : -1;
        if (! in_array($version, self::ABR_VERSIONS, true)) {
            throw new HubResourceRefused(reason: HubResourceRefused::BAD_BRUSH, message: 'This is not a Photoshop brush file (ABR versions 1, 2, 6, 7 and 10 are accepted).');
        }

        if ($version >= 6 && ! str_contains(substr($head, 4, self::ABR_SIGNATURE_WINDOW), '8BIM')) {
            throw new HubResourceRefused(reason: HubResourceRefused::BAD_BRUSH, message: 'This Photoshop brush file is damaged: its brush blocks are missing.');
        }

        return $this->resource(kind: self::KIND_BRUSH, format: 'abr', name: $name, size: $size, fields: [], meta: [
            'parsed'         => false,
            'abr_version'    => $version,
            'abr_subversion' => $version >= 6 ? (int) unpack('n', substr($head, 2, 2))[1] : null,
        ]);
    }

    /**
     * php-font-lib in a child process: `memory_limit` and `max_execution_time` bound the parse inside it, the
     * process timeout bounds it from outside. Anything but one JSON object from the child is a font that cannot be
     * read. A server that has no PHP CLI is a server fault, not the file's.
     *
     * @return array<string, mixed>
     */
    private function parse(string $path): array
    {
        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false) {
            throw new RuntimeException('No PHP CLI binary is available to inspect fonts.');
        }

        $seconds = max(1, (int) config('dam.hub.resources.inspect_timeout_seconds'));
        // php-font-lib inflates a WOFF into tempnam(sys_get_temp_dir()) files it never removes. Each child gets its own
        // directory for those, removed here whatever the child did: answered, crashed or timed out.
        $scratch = $this->scratchDirectory();

        try {
            $process = new Process(
                command: [
                    $php,
                    '-d', 'memory_limit='.(string) config('dam.hub.resources.inspect_memory_limit'),
                    '-d', 'max_execution_time='.$seconds,
                    '-d', 'display_errors=stderr',
                    '-d', 'sys_temp_dir='.$scratch,
                    '--', base_path('vendor/autoload.php'), $path,
                ],
                env: ['TMPDIR' => $scratch, 'TMP' => $scratch, 'TEMP' => $scratch],
                input: $this->parserOverride !== null && app()->runningUnitTests() ? $this->parserOverride : self::PARSER,
                timeout: $seconds + 2,
            );

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                return ['ok' => false, 'reason' => 'timeout'];
            }

            if (! $process->isSuccessful()) {
                return ['ok' => false, 'reason' => 'child_failed'];
            }

            $answer = json_decode($process->getOutput(), true);

            return is_array($answer) ? $answer : ['ok' => false, 'reason' => 'no_answer'];
        } finally {
            $this->removeDirectory(directory: $scratch);
        }
    }

    /** A fresh private directory under the system temp dir, named with SCRATCH_PREFIX. */
    private function scratchDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::SCRATCH_PREFIX.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700)) {
            throw new RuntimeException('The font inspector could not create its scratch directory.');
        }

        return $directory;
    }

    /** Removes $directory and everything in it; the parser writes only flat files there. */
    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $entry->isDir() && ! $entry->isLink() ? $this->removeDirectory(directory: $entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }

    private function assertSize(int $size, int $limit): void
    {
        if ($size > $limit) {
            throw new HubResourceRefused(
                reason: HubResourceRefused::TOO_LARGE,
                message: sprintf('The file is %s MiB; the limit is %s MiB.', round($size / 1048576, 1), round($limit / 1048576, 1)),
                context: ['size' => $size, 'limit' => $limit],
            );
        }
    }

    /** A name-table string made safe to store and show: valid UTF-8, no control characters, at most 255 characters. */
    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', mb_scrub($value, 'UTF-8'));
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $meta
     * @return array{kind: string, format: string, mime_type: string, name: string, family: ?string, subfamily: ?string, weight: ?int, style: ?string, postscript_name: ?string, size: int, meta: array<string, mixed>}
     */
    private function resource(string $kind, string $format, string $name, int $size, array $fields, array $meta): array
    {
        return [
            'kind'            => $kind,
            'format'          => $format,
            'mime_type'       => self::MIME_TYPES[$format],
            'name'            => $name,
            'family'          => $fields['family'] ?? null,
            'subfamily'       => $fields['subfamily'] ?? null,
            'weight'          => $fields['weight'] ?? null,
            'style'           => $fields['style'] ?? null,
            'postscript_name' => $fields['postscript_name'] ?? null,
            'size'            => $size,
            'meta'            => $meta,
        ];
    }
}
