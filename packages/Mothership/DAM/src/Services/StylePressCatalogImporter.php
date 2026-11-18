<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mothership\DAM\Services\Access\AccessModel;
use RuntimeException;

class StylePressCatalogImporter
{
    public function __construct(private StylePressPackageFiles $files, private GenerationDirectory $directories) {}

    public function importManifest(string $manifestPath, bool $dryRun = false): array
    {
        $catalog = $this->files->read(manifestPath: $manifestPath);
        $presets = $catalog['presets'] ?? null;
        if (! is_array($presets) || ! array_is_list($presets) || ! count($presets) || ($catalog['count'] ?? null) !== count($presets)
            || ! is_string($catalog['version'] ?? null) || $catalog['version'] === '' || ! is_array($catalog['categories'] ?? null)) {
            throw new RuntimeException('Invalid or empty style catalog.');
        }
        $attachments = [];
        $slugs = [];
        $generatable = 0;
        foreach ($presets as $preset) {
            foreach (['slug', 'title', 'category', 'categoryLabel', 'sourceNote', 'kind'] as $key) {
                if (! is_string($preset[$key] ?? null) || trim($preset[$key]) === '' || mb_strlen($preset[$key]) > 1000) {
                    throw new RuntimeException('A catalog entry is missing '.$key.'.');
                }
            }
            if (! preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $preset['slug']) || isset($slugs[$preset['slug']])) {
                throw new RuntimeException('Catalog identities must be unique slugs.');
            }
            $slugs[$preset['slug']] = true;
            if (! in_array($preset['kind'], ['style', 'workflow', 'tool'], true) || ! is_bool($preset['generatable'] ?? null)
                || ! is_string($preset['template'] ?? null) || ! is_string($preset['slots'] ?? null)
                || ! is_array($preset['references'] ?? null) || ! array_is_list($preset['references'])) {
                throw new RuntimeException('Invalid catalog entry capabilities.');
            }
            if ($preset['generatable']) {
                if ($preset['kind'] !== 'style' || trim($preset['template']) === '') {
                    throw new RuntimeException('Only reusable styles with a complete template can generate.');
                }
                $generatable++;
            }
            foreach (array_filter([$preset['image'] ?? null, ...$preset['references']], static fn ($path) => $path !== null) as $relative) {
                if (! is_string($relative)) {
                    throw new RuntimeException('Invalid catalog attachment.');
                }
                $attachments[$relative] = $this->files->resolve(root: dirname($manifestPath), relative: $relative);
            }
        }
        $result = ['ok'=>true, 'count'=>count($presets), 'generatable'=>$generatable, 'version'=>$catalog['version']];
        if ($dryRun) {
            return $result;
        }

        return Cache::lock('stylepress.catalog-import', 1800)->block(30, function () use ($catalog, $presets, $attachments, $result) {
            $root = config('stylepress.import_root', storage_path('app/private/stylepress/catalogs'));
            File::ensureDirectoryExists($root, 0700, true);
            // The directory identity includes content hashes, so a changed attachment cannot reuse an older package.
            $hash = hash_init('sha256');
            hash_update($hash, json_encode($catalog, JSON_THROW_ON_ERROR));
            foreach ($attachments as $relative => $source) {
                hash_update($hash, $relative.hash_file('sha256', $source));
            }
            $identity = hash_final($hash);
            $destination = $root.'/versions/'.$identity;
            if (! is_file($destination.'/catalog.json')) {
                $stage = $root.'/.staging-'.Str::uuid();
                File::ensureDirectoryExists($stage, 0700, true);
                try {
                    foreach ($attachments as $relative => $source) {
                        File::ensureDirectoryExists(dirname($stage.'/'.$relative), 0700, true);
                        if (! copy($source, $stage.'/'.$relative)) {
                            throw new RuntimeException('Could not install a style attachment.');
                        }
                    }
                    File::put($stage.'/catalog.json', json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    File::ensureDirectoryExists($root.'/versions', 0700, true);
                    if (! rename($stage, $destination)) {
                        throw new RuntimeException('Could not install the style catalog.');
                    }
                } finally {
                    if (is_dir($stage)) {
                        File::deleteDirectory($stage);
                    }
                }
            }
            // sharedly(), not DB::transaction(): this transaction creates folders, and every folder create
            // gives the new folder its parent's access list, so ADR 0010's fence wants the DAM_ACCESS_MODEL
            // row FOR SHARE as its first statement — before the tree lock GenerationDirectory::ensure()
            // takes, and so before any folder row (Codex review of PR #74, finding 2).
            app(AccessModel::class)->sharedly(function () use ($presets): void {
                foreach ($presets as $preset) {
                    $this->directories->ensure(segments: ['AI-Generations', 'Images', 'Pre-Built-Styles', $preset['category'], $preset['title']]);
                }
            });
            $pointer = $root.'/.current-'.Str::uuid();
            File::put($pointer, json_encode(['path'=>'versions/'.$identity.'/catalog.json'], JSON_THROW_ON_ERROR));
            if (! rename($pointer, $root.'/current.json')) {
                File::delete($pointer);
                throw new RuntimeException('Could not activate the style catalog.');
            }
            HomeTelemetry::record(event: 'stylepress.catalog.installed', ctx: ['count'=>count($presets), 'revision'=>$catalog['version']]);

            return $result;
        });
    }
}
