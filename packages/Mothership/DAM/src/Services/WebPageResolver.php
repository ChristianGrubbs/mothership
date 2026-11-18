<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;

class WebPageResolver
{
    public const MIME = [
        'html' => 'text/html', 'htm' => 'text/html', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'css'  => 'text/css', 'json' => 'application/json', 'map' => 'application/json', 'svg' => 'image/svg+xml',
        'png'  => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'otf' => 'font/otf',
        'ttf'  => 'font/ttf', 'glb' => 'model/gltf-binary', 'wasm' => 'application/wasm', 'txt' => 'text/plain',
        'md'   => 'text/markdown', 'csv' => 'text/csv', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
    ];

    /** @return array{status:int, absolute?:string, relative?:string, mime?:string, attachment?:bool, is_entry?:bool, is_capture_entry?:bool, location?:string} */
    public function resolve(Asset $asset, string $path): array
    {
        if ($asset->file_type !== 'web') {
            return ['status' => 404];
        }

        $root = WebPageBundler::rootFor($asset);
        if ($root === null) {
            return ['status' => 409];
        }

        return $this->resolveWithin(asset: $asset, root: $root, path: $path, exactRoot: null);
    }

    /**
     * Resolves $path inside one bundle root (storage-relative). `$exactRoot` is set only for a stack member's isolated
     * cache (WebPageMembers): the canonical root that cache must be, so a link or a re-pointed directory that lands
     * anywhere else, another asset's bundle included, answers 404. A member's entry is never a capture entry, so the
     * admin route never injects the thumbnail script into it.
     *
     * @return array{status:int, absolute?:string, relative?:string, mime?:string, attachment?:bool, is_entry?:bool, is_capture_entry?:bool, location?:string}
     */
    public function resolveWithin(Asset $asset, string $root, string $path, ?string $exactRoot): array
    {
        $path = ltrim(rawurldecode($path), '/');

        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            WebPageTelemetry::record('web_pages.path_rejected', ['asset_id' => $asset->id, 'path' => $path, 'reason' => 'traversal']);

            return ['status' => 404];
        }

        $storage = Storage::disk(Directory::getAssetDisk());
        $unresolvedRoot = $storage->path($root);

        // realpath() would follow a symlinked bundle root before containment is established, so a
        // root swapped for a link is refused outright, and the canonical root must stay under the
        // canonical bundles directory (review round, audit A3).
        if (is_link($unresolvedRoot)) {
            WebPageTelemetry::record('web_pages.path_rejected', ['asset_id' => $asset->id, 'path' => $path, 'reason' => 'root_symlink']);

            return ['status' => 404];
        }

        $realRoot = realpath($unresolvedRoot);
        if ($realRoot === false) {
            return ['status' => 409];
        }

        $realBundles = realpath($storage->path('bundles'));
        if ($realBundles === false || ! str_starts_with($realRoot, $realBundles.DIRECTORY_SEPARATOR)) {
            WebPageTelemetry::record('web_pages.path_rejected', ['asset_id' => $asset->id, 'path' => $path, 'reason' => 'root_outside_bundles']);

            return ['status' => 404];
        }

        if ($exactRoot !== null && $realRoot !== $exactRoot) {
            WebPageTelemetry::record('web_pages.path_rejected', ['asset_id' => $asset->id, 'path' => $path, 'reason' => 'root_alias']);

            return ['status' => 404];
        }

        // A directory asked for without a trailing slash redirects to one. Serving index.html at
        // `docs` would make the page's own relative `app.js` resolve as a sibling of `docs`, one
        // level above where it actually lives, so every relative resource under it would 404.
        if ($path !== '' && ! str_ends_with($path, '/') && is_dir($realRoot.'/'.$path)) {
            return ['status' => 302, 'location' => $path.'/'];
        }

        // Directory default (`` and `docs/` -> index.html), then one realpath of the final file.
        if ($path === '' || str_ends_with($path, '/')) {
            $path = ($path === '' ? '' : rtrim($path, '/').'/').WebPageBundler::ENTRY;
        }

        $file = realpath($realRoot.'/'.$path);

        if ($file === false || ! str_starts_with($file, $realRoot.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            if ($file !== false) {
                WebPageTelemetry::record('web_pages.path_rejected', ['asset_id' => $asset->id, 'path' => $path, 'reason' => 'outside_root']);
            }

            return ['status' => 404];
        }

        // Walk the requested segments once. Symlinks inside a bundle are never followed:
        // SafeZipExtractor refuses them at build time, so any symlink here was placed on disk
        // afterwards and is treated as outside the root. Case-insensitive filesystems (APFS)
        // open DOCS/app.js as docs/app.js and realpath() keeps the requested spelling, so each
        // segment must also appear byte-exact in its parent's listing (audit A3).
        $walk = $realRoot;
        foreach (explode('/', $path) as $segment) {
            $listing = @scandir($walk);
            $walk .= '/'.$segment;
            $reason = is_link($walk) ? 'symlink' : (($listing === false || ! in_array($segment, $listing, true)) ? 'case_mismatch' : null);

            if ($reason !== null) {
                WebPageTelemetry::record('web_pages.path_rejected', ['asset_id' => $asset->id, 'path' => $path, 'reason' => $reason]);

                return ['status' => 404];
            }
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = self::MIME[$extension] ?? null;

        return [
            'status'     => 200,
            'absolute'   => $file,
            'relative'   => $root.'/'.$path,
            'mime'       => $mime ?? 'application/octet-stream',
            'attachment' => $mime === null,
            'is_entry'   => $path === WebPageBundler::ENTRY,
            // The entry, or the page a launcher entry refreshes to (WebPageBundler::captureEntry()).
            'is_capture_entry' => $exactRoot === null && $path === WebPageBundler::captureEntryFor($asset),
        ];
    }
}
