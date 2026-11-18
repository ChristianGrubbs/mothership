<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Mothership\DAM\Support\SafeZipExtractor;

/**
 * Decides whether a zip is a web page bundle and, if so, where its entry lives.
 * A bundle has index.html at the root or inside exactly one top-level folder ($prefix). Failing
 * that, a lone .html/.htm file at that same level is the entry ($entry) -- the "OPEN ME.html
 * next to site/" shape exporters produce; the bundler serves it as index.html.
 * Nested zips are ordinary entries and are never expanded (ADR 0007).
 *
 * A bundle is also rejected whole ($reason) when it carries an unsafe entry name or breaks the
 * archive limits, so the refusal happens at ingress rather than half-way through extraction.
 */
class WebPageInspector
{
    public const FORBIDDEN_ENTRY_EXTENSIONS = [
        'php', 'phtml', 'phar', 'py', 'sh', 'bat', 'pl', 'cgi', 'asp', 'aspx', 'jsp', 'exe', 'rb', 'jar', 'hta', 'shtml',
    ];

    public static function isForbiddenEntry(string $extension): bool
    {
        return in_array(strtolower($extension), self::FORBIDDEN_ENTRY_EXTENSIONS, true);
    }

    /** @return array{is_web: bool, prefix: string, entry: string, forbidden_entry: ?string, entries: int, reason: ?string} */
    public function inspect(string $zipPath): array
    {
        $none = ['is_web' => false, 'prefix' => '', 'entry' => '', 'forbidden_entry' => null, 'entries' => 0, 'reason' => null];
        $zip = new \ZipArchive;

        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            return $none;
        }

        try {
            $names = [];
            $unsafe = false;
            $symlink = false;
            $entryTooLarge = false;
            $totalBytes = 0;
            $maxEntryBytes = (int) config('dam.import_bundle.max_entry_size', 524288000);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $raw = (string) $zip->getNameIndex($i);

                if ($raw === '') {
                    continue;
                }

                if (self::isUnsafeEntryName($raw)) {
                    $unsafe = true;
                }

                if ($this->isSymlinkIndex($zip, $i)) {
                    $symlink = true;
                }

                $name = ltrim(str_replace('\\', '/', $raw), '/');
                if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) {
                    continue;
                }

                $names[] = $name;
                $stat = $zip->statIndex($i);
                $entryBytes = $stat === false ? 0 : (int) ($stat['size'] ?? 0);
                $totalBytes += $entryBytes;

                if ($maxEntryBytes > 0 && $entryBytes > $maxEntryBytes) {
                    $entryTooLarge = true;
                }
            }

            $located = $this->locateEntry($names);
            if ($located === null) {
                return ['is_web' => false, 'prefix' => '', 'entry' => '', 'forbidden_entry' => null, 'entries' => count($names), 'reason' => null];
            }
            [$prefix, $entry] = $located;

            // Raw numFiles, not the filtered list: directory records cost the extractor work too.
            $reason = $this->limitReason($unsafe, $symlink, $entryTooLarge, $zip->numFiles, $totalBytes);

            if ($reason === null) {
                // The same preflight the bundler would run, so a zip bomb is refused at ingress.
                $rejection = self::extractorFor()->rejectionReason($zip);
                $reason = $rejection === null ? null : $rejection['key'];
            }

            foreach ($names as $name) {
                if (self::isForbiddenEntry(pathinfo($name, PATHINFO_EXTENSION))) {
                    return ['is_web' => true, 'prefix' => $prefix, 'entry' => $entry, 'forbidden_entry' => $name, 'entries' => count($names), 'reason' => $reason];
                }
            }

            return ['is_web' => true, 'prefix' => $prefix, 'entry' => $entry, 'forbidden_entry' => null, 'entries' => count($names), 'reason' => $reason];
        } finally {
            $zip->close();
        }
    }

    /** An absolute name, a `..` segment or a backslash never belongs to a bundle we will serve. */
    public static function isUnsafeEntryName(string $name): bool
    {
        if (str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:[\\\\/]#', $name) === 1) {
            return true;
        }

        return in_array('..', explode('/', $name), true);
    }

    /**
     * A ZIP entry can be a symlink, which points wherever it likes once unpacked. ZipArchive
     * exposes the Unix mode in the high word of the external attributes.
     */
    private function isSymlinkIndex(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0xF000) === 0xA000);
    }

    /**
     * The archive limits a bundle is held to. Shared with the bundler so ingress and unpacking
     * cannot disagree about what is acceptable.
     */
    public static function extractorFor(): SafeZipExtractor
    {
        $maxBytes = (int) config('dam.web_pages.max_bundle_size', 536870912);

        return new SafeZipExtractor(
            // The per-entry cap is the import bundle's, not the whole-bundle size (spec: only
            // max_total_size and max_entries are replaced for web pages).
            maxEntrySize: (int) config('dam.import_bundle.max_entry_size', 524288000),
            maxTotalSize: $maxBytes,
            maxEntries: (int) config('dam.web_pages.max_entries', 5000),
            maxCompressionRatio: (float) config('dam.import_bundle.max_compression_ratio', 200),
        );
    }

    /** The whole-archive refusal, checked before a single entry is written. */
    private function limitReason(bool $unsafe, bool $symlink, bool $entryTooLarge, int $entries, int $totalBytes): ?string
    {
        if ($unsafe) {
            return 'unsafe_path';
        }

        if ($symlink) {
            return 'symlink_entry';
        }

        if ($entryTooLarge) {
            return 'entry_too_large';
        }

        $maxEntries = (int) config('dam.web_pages.max_entries', 5000);
        if ($maxEntries > 0 && $entries > $maxEntries) {
            return 'too_many_entries';
        }

        $maxBytes = (int) config('dam.web_pages.max_bundle_size', 536870912);
        if ($maxBytes > 0 && $totalBytes > $maxBytes) {
            return 'too_large';
        }

        return null;
    }

    /**
     * [prefix, entry]: prefix is '' for the root or 'folder/' for a single top-level folder; entry
     * is index.html when present there, else the one .html/.htm file at that level. null when
     * neither level has exactly one entry.
     *
     * @return array{0: string, 1: string}|null
     */
    private function locateEntry(array $names): ?array
    {
        $rootFiles = array_values(array_filter($names, fn (string $n) => ! str_contains($n, '/')));
        $tops = array_values(array_unique(array_filter(array_map(fn (string $n) => str_contains($n, '/') ? strstr($n, '/', true) : null, $names), fn ($t) => $t !== null)));

        if (in_array('index.html', $rootFiles, true)) {
            return ['', 'index.html'];
        }

        if (count($tops) === 1 && $rootFiles === []) {
            $prefix = $tops[0].'/';
            $level = array_values(array_filter(array_map(fn (string $n) => substr($n, strlen($prefix)), $names), fn (string $n) => ! str_contains($n, '/')));
            $entry = self::entryAt($level);

            return $entry === null ? null : [$prefix, $entry];
        }

        $entry = self::entryAt($rootFiles);

        return $entry === null ? null : ['', $entry];
    }

    /** index.html among one level's file names, else the level's only .html/.htm file. */
    private static function entryAt(array $files): ?string
    {
        if (in_array('index.html', $files, true)) {
            return 'index.html';
        }

        $html = array_values(array_filter($files, fn (string $n) => in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), ['html', 'htm'], true)));

        return count($html) === 1 ? $html[0] : null;
    }
}
