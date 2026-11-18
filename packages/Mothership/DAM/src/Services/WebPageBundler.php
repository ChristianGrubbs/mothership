<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

/**
 * Unpacks a web page's original (.html or .zip) into the immutable bundle
 * bundles/{asset}/{version}/ it is served from (ADR 0007). Local disk only: a presigned S3
 * redirect would drop the page policy, so the bundler refuses there.
 */
class WebPageBundler
{
    public const ENTRY = 'index.html';

    public function __construct(protected WebPageInspector $inspector) {}

    public static function versionNumber(Asset $asset): int
    {
        return (int) ($asset->versions()->max('number') ?? 0);
    }

    public static function rootFor(Asset $asset): ?string
    {
        $manifest = $asset->meta_data['web_bundle'] ?? null;

        return is_array($manifest) && empty($manifest['error']) && ! empty($manifest['root']) ? (string) $manifest['root'] : null;
    }

    /** The bundle-relative document the thumbnail capture runs in; a bundle built before it was recorded captures its entry. */
    public static function captureEntryFor(Asset $asset): string
    {
        $captureEntry = $asset->meta_data['web_bundle']['capture_entry'] ?? null;

        return is_string($captureEntry) && $captureEntry !== '' ? $captureEntry : self::ENTRY;
    }

    /**
     * Build the bundle for one specific version. The version is bound at dispatch time, so a
     * second re-upload that lands while this build runs publishes nothing ('stale') instead of
     * overwriting the newer page.
     */
    public function build(Asset $asset, int $version): array
    {
        $disk = Directory::getAssetDisk();

        if ($disk === Directory::ASSETS_DISK_AWS) {
            return $this->fail($asset, $version, 'disk_unsupported');
        }

        $storage = Storage::disk($disk);
        $root = "bundles/{$asset->id}/{$version}";
        $absoluteRoot = $storage->path($root);
        $absoluteStaging = $storage->path("bundles/{$asset->id}/.staging-{$version}-".uniqid());

        try {
            $sourceFile = app(ContentStore::class)->localPath(app(ContentStore::class)->current($asset), $asset->extension);
        } catch (ContentUnavailable) {
            return $this->fail($asset, $version, 'original_missing');
        }

        $source = $sourceFile->path;

        mkdir($absoluteStaging, 0755, true);

        try {
            $files = $this->materialize(source: $source, extension: (string) $asset->extension, stagingDir: $absoluteStaging);
        } catch (\RuntimeException $e) {
            $this->removeTree($absoluteStaging);

            return $this->fail($asset, $version, $e->getMessage());
        } finally {
            $sourceFile->release();
        }

        $totalBytes = 0;
        foreach ($files as $relative) {
            $totalBytes += (int) filesize($absoluteStaging.'/'.$relative);
        }

        $captureEntry = $this->captureEntry($absoluteStaging, $files);

        $manifest = [
            'version'    => $version, 'root' => $root, 'entry' => self::ENTRY, 'capture_entry' => $captureEntry,
            'file_count' => count($files), 'total_bytes' => $totalBytes,
            'built_at'   => now()->toIso8601String(),
        ];

        $published = DB::transaction(function () use ($asset, $version, $manifest, $absoluteRoot, $absoluteStaging): bool {
            $fresh = Asset::query()->lockForUpdate()->find($asset->id);

            // Someone re-uploaded while this build ran: that newer version owns the page now.
            if (! $fresh || self::versionNumber($fresh) !== $version) {
                return false;
            }

            $this->removeTree($absoluteRoot);

            if (! is_dir(dirname($absoluteRoot))) {
                mkdir(dirname($absoluteRoot), 0755, true);
            }

            rename($absoluteStaging, $absoluteRoot);

            $meta = is_array($fresh->meta_data) ? $fresh->meta_data : [];
            $meta['web_bundle'] = $manifest;
            $fresh->update(['meta_data' => $meta]);
            $asset->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });

        if (! $published) {
            $this->removeTree($absoluteStaging);

            return ['version' => $version, 'error' => 'stale'];
        }

        WebPageTelemetry::record('web_pages.bundle_built', ['asset_id' => $asset->id, 'version' => $version, 'file_count' => count($files), 'total_bytes' => $totalBytes, 'entry' => self::ENTRY, 'capture_entry' => $captureEntry]);

        return $manifest;
    }

    /**
     * Writes one source file's page into the existing empty $stagingDir: a single .html/.htm as the entry, or a .zip
     * through the inspector and the hardened extractor (traversal, symlink, forbidden-entry and size/entry limits).
     * The extension is only a candidate: a zip with no entry is refused ('entry_missing'), not served. Shared by the
     * asset's own bundle (build()) and a stack member's (WebPageMembers), so both are held to one set of rules. It
     * writes nothing outside $stagingDir and its `.unpack` sibling; the caller removes both on a refusal.
     *
     * @return list<string> relative paths written
     *
     * @throws \RuntimeException a machine reason ('too_large', 'entry_missing', 'forbidden_entry:...', 'zip-...')
     */
    public function materialize(string $source, string $extension, string $stagingDir): array
    {
        if (in_array(strtolower($extension), ['html', 'htm'], true)) {
            $maxBytes = (int) config('dam.web_pages.max_bundle_size', 536870912);

            if ($maxBytes > 0 && (int) filesize($source) > $maxBytes) {
                throw new \RuntimeException('too_large');
            }

            copy($source, $stagingDir.'/'.self::ENTRY);
            $files = [self::ENTRY];
        } else {
            $files = $this->extract($source, $stagingDir);
        }

        if (! is_file($stagingDir.'/'.self::ENTRY)) {
            throw new \RuntimeException('entry_missing');
        }

        return $files;
    }

    /** Remove every bundle directory of the asset except $keepVersion. */
    public function purge(int $assetId, ?int $keepVersion = null): void
    {
        $storage = Storage::disk(Directory::getAssetDisk());

        foreach ($storage->directories("bundles/{$assetId}") as $dir) {
            if ($keepVersion === null || basename($dir) !== (string) $keepVersion) {
                $storage->deleteDirectory($dir);
            }
        }

        if ($keepVersion === null) {
            $storage->deleteDirectory("bundles/{$assetId}");
        }
    }

    /** @return list<string> relative paths extracted, prefix stripped */
    protected function extract(string $zipPath, string $absoluteRoot): array
    {
        $report = $this->inspector->inspect($zipPath);

        if (! $report['is_web']) {
            throw new \RuntimeException('entry_missing');
        }
        if ($report['forbidden_entry'] !== null) {
            WebPageTelemetry::record('web_pages.forbidden_entry', ['entry' => $report['forbidden_entry']]);
            throw new \RuntimeException('forbidden_entry:'.$report['forbidden_entry']);
        }
        if ($report['reason'] !== null) {
            WebPageTelemetry::record('web_pages.forbidden_entry', ['reason' => $report['reason']]);
            throw new \RuntimeException($report['reason']);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('invalid_zip');
        }

        try {
            // The inspector already ran this preflight at ingress; reuse its limits so the two
            // cannot disagree about what an acceptable archive is.
            $extractor = WebPageInspector::extractorFor();

            $prefix = $report['prefix'];
            $staging = $absoluteRoot.'.unpack';
            $extracted = $extractor->extract($zip, $staging, function (string $relative, string $extension): bool {
                return ! str_starts_with($relative, '__MACOSX/') && ! WebPageInspector::isForbiddenEntry($extension);
            });
        } finally {
            $zip->close();
        }

        // A short extraction means an entry was silently dropped (oversized, unreadable, or a
        // path the extractor refused). Serving a partial page is worse than failing the build.
        if (count($extracted) < $report['entries']) {
            $this->removeTree($staging);

            throw new \RuntimeException('extraction_incomplete');
        }

        $files = [];
        foreach ($extracted as $relative) {
            if ($prefix !== '' && ! str_starts_with($relative, $prefix)) {
                continue;
            }
            $stripped = $prefix === '' ? $relative : substr($relative, strlen($prefix));
            $target = $absoluteRoot.'/'.$stripped;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            rename($staging.'/'.$relative, $target);
            $files[] = $stripped;
        }
        $this->removeTree($staging);

        // A lone non-index entry ("OPEN ME.html" beside site/) is served as index.html. Same
        // directory, so the page's relative links resolve exactly as they did beside the original.
        $entry = $report['entry'];
        if ($entry !== self::ENTRY && is_file($absoluteRoot.'/'.$entry) && ! is_file($absoluteRoot.'/'.self::ENTRY)) {
            copy($absoluteRoot.'/'.$entry, $absoluteRoot.'/'.self::ENTRY);
            $files[] = self::ENTRY;
        }

        return $files;
    }

    /**
     * The document the thumbnail capture runs in. An exporter's launcher ("OPEN ME.html", served
     * as index.html) only meta-refreshes to the real page and is gone before a capture could fire,
     * so when the entry refreshes to an HTML file inside this bundle, that file is captured
     * instead. No refresh, an off-site or origin-rooted URL, a path that climbs out of the bundle,
     * or one that names no extracted HTML file keeps the entry.
     *
     * @param  list<string>  $files  the extracted relative paths: membership is byte-exact, as the
     *                               resolver's case check is when the page is served.
     */
    protected function captureEntry(string $absoluteRoot, array $files): string
    {
        // A refresh belongs in <head>; 64 KiB is plenty and bounds the scan of a large entry.
        $url = self::refreshUrl((string) file_get_contents($absoluteRoot.'/'.self::ENTRY, false, null, 0, 65536));

        // A browser reads `\` as `/` in an http(s) URL. Same-bundle relative URLs only: a scheme,
        // `//host` or `/path` leaves the bundle's URL space.
        $url = $url === null ? '' : (string) preg_replace('/[?#].*$/s', '', str_replace('\\', '/', $url));

        if ($url === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|/)#i', $url) === 1) {
            return self::ENTRY;
        }

        $segments = [];
        foreach (explode('/', rawurldecode($url)) as $segment) {
            if ($segment === '..') {
                if ($segments === []) {
                    return self::ENTRY;
                }
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        // A directory target is served as its index.html, exactly as the resolver does.
        $path = implode('/', $segments);
        foreach ([$path, ltrim($path.'/'.self::ENTRY, '/')] as $candidate) {
            if (in_array($candidate, $files, true) && in_array(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
                return $candidate;
            }
        }

        return self::ENTRY;
    }

    /**
     * The URL the entry's declarative refresh navigates to, or null when it has none or refreshes
     * itself. The HTML5 parser tokenises comments, raw-text/RCDATA elements and <template> as a
     * browser does, so a meta tag the browser would read as text never counts. The content is read
     * with the declarative-refresh syntax: a time, a `;`/`,` or whitespace separator, an optional
     * `url=`, then the URL, optionally quoted.
     */
    private static function refreshUrl(string $html): ?string
    {
        // The page runs with scripting on (the sandbox allows scripts), where <noscript> is raw text
        // up to its end tag; the parser runs with scripting off and would build markup out of it.
        // Renaming it to a raw-text element the document never names reproduces the browser's
        // tokenisation exactly: a one-to-one rename cannot let an end tag close the wrong element.
        if (preg_match('#</?noscript(?=[\s/>])#i', $html) === 1) {
            $standIn = null;
            foreach (['xmp', 'noembed', 'noframes'] as $candidate) {
                if (preg_match('#</?'.$candidate.'(?=[\s/>])#i', $html) !== 1) {
                    $standIn = $candidate;
                    break;
                }
            }

            if ($standIn === null) {
                return null;
            }

            $html = (string) preg_replace('#<(/?)noscript(?=[\s/>])#i', '<$1'.$standIn, $html);
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (strtolower(trim((string) $meta->getAttribute('http-equiv'))) !== 'refresh' || ! $meta->hasAttribute('content')) {
                continue;
            }

            // The first content that parses wins, as in a browser; a bare time refreshes the page itself.
            if (preg_match('/^\s*[\d.]+(?:(?:\s*[;,]\s*|\s+)(?:url\s*=\s*)?(.*))?$/is', (string) $meta->getAttribute('content'), $match) !== 1) {
                continue;
            }

            $url = $match[1] ?? '';
            if ($url !== '' && ($url[0] === '"' || $url[0] === "'")) {
                $close = strpos($url, $url[0], 1);
                $url = substr($url, 1, $close === false ? null : $close - 1);
            }

            $url = trim($url);

            return $url === '' ? null : $url;
        }

        return null;
    }

    /**
     * A failure is published under the same version guard as a success: a late failure for an
     * old version must not erase the manifest of the page that is actually being served.
     */
    protected function fail(Asset $asset, int $version, string $reason): array
    {
        $manifest = ['version' => $version, 'error' => $reason, 'built_at' => now()->toIso8601String()];

        $stored = DB::transaction(function () use ($asset, $version, $manifest): bool {
            $fresh = Asset::query()->lockForUpdate()->find($asset->id);

            if (! $fresh || self::versionNumber($fresh) !== $version) {
                return false;
            }

            $meta = is_array($fresh->meta_data) ? $fresh->meta_data : [];
            $meta['web_bundle'] = $manifest;
            $fresh->update(['meta_data' => $meta]);
            $asset->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });

        if (! $stored) {
            return ['version' => $version, 'error' => 'stale'];
        }

        WebPageTelemetry::record('web_pages.bundle_failed', ['asset_id' => $asset->id, 'version' => $version, 'reason' => $reason]);

        return $manifest;
    }

    /** Removes a directory tree; a link inside it is unlinked, never followed. */
    public function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            ! $item->isLink() && $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
