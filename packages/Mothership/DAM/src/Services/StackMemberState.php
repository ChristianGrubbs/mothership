<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Support\StackFileThumbnail;

/** Metadata belonging to one exact revision, retained when the stack changes its first file. */
final class StackMemberState
{
    public const KEY = 'stack_member_states';

    public static function key(AssetFile $file): string
    {
        return $file->id.':'.$file->fileObject?->sha256;
    }

    public static function remembered(Asset $asset, AssetFile $file): ?array
    {
        $state = $asset->meta_data[self::KEY][self::key($file)] ?? null;

        return is_array($state) ? $state : null;
    }

    /** Resolve the selected member's existing picture before it becomes the representative. */
    public static function incoming(Asset $asset, AssetFile $file): array
    {
        $state = self::remembered(asset: $asset, file: $file);
        if ($state !== null) {
            return $state;
        }

        $state = [];
        $origin = $file->attachment?->origin_attachment_id;
        if ($origin !== null) {
            $source = AssetFile::where('attachment_id', $origin)->where('is_current', true)
                ->where('file_object_id', $file->file_object_id)->whereHas('attachment', fn ($q) => $q->where('is_representative', true))
                ->with('asset')->first();
            // A saved camera belongs to these bytes. Never inherit the unrelated stack cover's view.
            $view = $source?->asset?->meta_data['glb_primary_view'] ?? null;
            if (is_array($view) && ($view['sha256'] ?? null) === $file->fileObject?->sha256
                && $asset->files()->whereKey($view['source_file_id'] ?? 0)->where('attachment_id', $file->attachment_id)->exists()) {
                $state['glb_primary_view'] = $view;
            }
        }

        $picture = StackFileThumbnail::source(file: $file, asset: $asset);
        if (is_string($picture['path'] ?? null)) {
            $state['stack_cover'] = ['file_id' => $file->id, 'sha256' => $file->fileObject?->sha256, ...$picture];
        }

        return [...$state, ...ModelFileThumbnailService::adoptable(sha256: $file->fileObject?->sha256, assetId: (int) $asset->id)];
    }

    /** Called with the asset and current revisions locked; snapshots never recursively contain snapshots. */
    public static function switchTo(Asset $asset, AssetFile $outgoing, array $incoming): array
    {
        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        $states = self::retainedStates($asset);
        unset($meta[self::KEY], $incoming[self::KEY]);
        $states[self::key($outgoing)] = $meta;

        return [...$incoming, self::KEY => $states];
    }

    /** Historical revisions retain their saved views; removed and foreign file identities cannot pin art. */
    public static function retainedStates(Asset $asset): array
    {
        $states = $asset->meta_data[self::KEY] ?? [];
        $keys = AssetFile::where('dam_asset_id', $asset->id)->with('fileObject')->get()->map(fn (AssetFile $file) => self::key($file))->all();

        return is_array($states) ? array_intersect_key($states, array_fill_keys($keys, true)) : [];
    }

    public static function hasCustomCover(array $meta): bool
    {
        return preg_match('#^custom-thumbnails/\d+/[a-f0-9-]{36}\.(?:jpg|png|gif)$#D', $meta['stack_cover']['path'] ?? '') === 1;
    }

    /** Asset-owned artwork referenced by active or saved member state, including copied artwork. */
    public static function artworkPaths(array $meta): array
    {
        $states = [$meta, ...array_values(is_array($meta[self::KEY] ?? null) ? $meta[self::KEY] : [])];
        $paths = [];
        foreach ($states as $state) {
            if (! is_array($state)) {
                continue;
            }
            foreach ([$state['thumbnail_path'] ?? null, $state['cover_art_path'] ?? null,
                $state['stack_cover']['path'] ?? null, $state['custom_thumbnail']['path'] ?? null] as $path) {
                if (is_string($path) && preg_match('#^(?:covers/\\d+(?:-[a-f0-9]+)?\\.[a-z0-9]+|thumbnails/models/\\d+-[a-f0-9]{12}\\.jpg|custom-thumbnails/\\d+/[a-f0-9-]{36}\\.(?:jpg|png|gif))$#D', $path)) {
                    $paths[] = $path;
                    if (str_starts_with($path, 'custom-thumbnails/')) {
                        $derivative = CustomThumbnailService::derivativePath(['path' => $path]);
                        if ($derivative !== null) {
                            $paths[] = $derivative;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
