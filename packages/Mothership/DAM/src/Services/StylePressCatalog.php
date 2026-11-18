<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class StylePressCatalog
{
    // How long a parsed catalog may be served from cache before a fresh read is forced even
    // without a filemtime/size change. The fingerprint key below already busts on re-import;
    // this ceiling only guards against an on-disk edit that keeps the same size (rare).
    private const CACHE_TTL_SECONDS = 3600;

    private ?array $dataCache = null;

    private ?string $dataCacheKey = null;

    public function __construct(private ?string $path = null) {}

    public function data(): array
    {
        $path = $this->catalogPath();
        if ($path === null || ! File::exists($path)) {
            return $this->unavailable('missing');
        }

        // A 1.38 MB catalog is decoded here on every StylePress thumbnail request (up to 148
        // per cold grid view) unless memoized. The instance memo covers repeat calls within one
        // request (index() + thumbnail() both call data()); Cache::remember covers the far more
        // common case of many separate requests, each with a fresh StylePressCatalog instance.
        // The key fingerprints the file's mtime and size, so a re-import (which changes at least
        // one of those) busts the cache without needing an explicit invalidation call.
        $fingerprint = $this->fingerprint($path);
        if ($this->dataCache !== null && $this->dataCacheKey === $fingerprint) {
            return $this->dataCache;
        }

        $decoded = Cache::remember(
            'stylepress-catalog-data:'.$fingerprint,
            self::CACHE_TTL_SECONDS,
            static fn () => json_decode(File::get($path), true)
        );

        $result = is_array($decoded) ? $decoded : $this->unavailable('invalid');
        $this->dataCache = $result;
        $this->dataCacheKey = $fingerprint;

        return $result;
    }

    private function fingerprint(string $path): string
    {
        return hash('sha256', $path.'|'.(@filemtime($path) ?: 0).'|'.(@filesize($path) ?: 0));
    }

    public function find(string $slug): ?array
    {
        return $this->resolve(slug: $slug)['style'] ?? null;
    }

    public function resolve(string $slug): ?array
    {
        $catalog = $this->data();
        foreach (($catalog['presets'] ?? []) as $preset) {
            if (is_array($preset) && ($preset['slug'] ?? null) === $slug) {
                return ['style'=>$preset, 'catalog_version'=>$catalog['version'] ?? 'unknown'];
            }
        }

        return null;
    }

    public function compile(array $style, string $prompt, string $mode): string
    {
        $request = trim($prompt);
        $modeInstruction = $mode === 'restyle'
            ? 'RESTYLE MODE: preserve the source image subject, identity, product geometry, composition, readable text, logos, and spatial relationships. Change only the selected StylePress visual language and explicit user-requested details.'
            : 'CREATE MODE: the user request supplies the content; the selected StylePress template supplies the art direction.';

        return implode("\n\n", array_filter([
            'STYLEPRESS HOUSE STYLE — LOCKED PRODUCTION INSTRUCTION',
            'STYLE: '.($style['title'] ?? 'Untitled style'),
            'STYLE LOOK: '.($style['look'] ?? ''),
            'STYLE TEMPLATE (VERBATIM):',
            $style['template'] ?? '',
            ! empty($style['slots']) ? 'INPUT SLOT CONTRACT (VERBATIM):' : null,
            $style['slots'] ?? null,
            $modeInstruction,
            'USER REQUEST:',
            $request,
            'Produce exactly one finished image. No watermark, no prompt text, no implementation notes.',
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    public function thumbnailPath(string $slug): ?string
    {
        $style = $this->find(slug: $slug);
        $catalogPath = $this->catalogPath();
        $relative = $style['image'] ?? null;
        if (! is_string($relative) || $relative === '' || $catalogPath === null || str_contains($relative, "\0")) {
            return null;
        }

        $root = realpath(dirname($catalogPath));
        $thumbnail = $root ? realpath($root.'/'.ltrim($relative, '/\\')) : false;
        if (! $root || ! $thumbnail || ! str_starts_with($thumbnail, $root.DIRECTORY_SEPARATOR) || ! is_file($thumbnail)) {
            return null;
        }

        return $thumbnail;
    }

    public function attachmentPath(string $slug, int $index): ?string
    {
        $style = $this->find(slug: $slug);
        $relative = $style['references'][$index] ?? null;
        $catalogPath = $this->catalogPath();
        if (! is_string($relative) || ! $catalogPath) {
            return null;
        }
        try {
            return app(StylePressPackageFiles::class)->resolve(root: dirname($catalogPath), relative: $relative);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function catalogPath(): ?string
    {
        if ($this->path === null) {
            $root = config('stylepress.import_root', storage_path('app/private/stylepress/catalogs'));
            if (is_file($root.'/current.json')) {
                $pointer = json_decode(File::get($root.'/current.json'), true);
                $relative = $pointer['path'] ?? '';
                if (is_string($relative) && preg_match('~\Aversions/[a-f0-9]{64}/catalog\.json\z~', $relative)) {
                    return $root.'/'.$relative;
                }

                return null;
            }
        }
        $path = $this->path ?? config('stylepress.catalog_path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function unavailable(string $version): array
    {
        return ['version' => $version, 'count' => 0, 'categories' => [], 'presets' => []];
    }
}
