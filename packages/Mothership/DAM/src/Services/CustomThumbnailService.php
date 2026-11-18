<?php

namespace Mothership\DAM\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\ImageCompleteness;
use Mothership\DAM\Support\RequestId;

class CustomThumbnailService
{
    public static function location(Asset $asset): ?array
    {
        $thumbnail = $asset->meta_data['custom_thumbnail'] ?? null;
        if (! is_array($thumbnail)
            || ! preg_match('#^custom-thumbnails/'.$asset->id.'/[a-f0-9-]{36}\.(jpg|png|gif)$#D', $thumbnail['path'] ?? '')
            || ! in_array($thumbnail['mime_type'] ?? '', ['image/jpeg', 'image/png', 'image/gif'], true)) {
            return null;
        }

        return $thumbnail;
    }

    /**
     * The grid-sized (300 px) derivative of a JPEG or PNG custom thumbnail: a sibling of the raw file in the asset's own
     * `custom-thumbnails/<id>/` folder, named by the revision, so a new upload never meets an old derivative. Null for
     * a GIF, which stays raw bytes to keep its animation. Every flow that deletes the raw file deletes this too.
     */
    public static function derivativePath(array $thumbnail): ?string
    {
        $path = (string) ($thumbnail['path'] ?? '');

        return preg_match('#^(custom-thumbnails/\d+/[a-f0-9-]{36})\.(jpg|png)$#D', $path, $m) === 1 ? $m[1].'-300.webp' : null;
    }

    /**
     * The `v` a custom thumbnail's URL carries and the thumbnail route compares: the revision, plus the recipe for a
     * JPEG or PNG (`<revision>.w300`) so the 300 px representation never shares a URL or validator with the raw bytes
     * served on a transient failure. A GIF is always its raw bytes: the bare revision. The URL builder cannot know the
     * pixel size, so a JPEG or PNG always carries the suffix, and a source of 300 px or less is served raw under it
     * (raw is then its 300 px representation).
     */
    public static function servedVersion(array $thumbnail): ?string
    {
        $revision = $thumbnail['revision'] ?? null;

        if (! is_string($revision) || $revision === '') {
            return null;
        }

        return self::derivativePath($thumbnail) === null ? $revision : $revision.'.w300';
    }

    /** Every stored file of one custom thumbnail: the raw upload and its derivative, if it can have one. */
    public static function storedPaths(array $thumbnail): array
    {
        return array_values(array_filter([$thumbnail['path'] ?? null, self::derivativePath($thumbnail)]));
    }

    /**
     * Whether the image file is whole by its format's own structure (see ImageCompleteness). A truncated file still
     * passes the `image` rule and GD decodes it into a half-grey picture without a warning (G1-17).
     */
    public static function isComplete(UploadedFile $upload): bool
    {
        $path = $upload->getRealPath();
        $bytes = $path === false ? false : file_get_contents($path);

        return $bytes !== false && ImageCompleteness::check($bytes, $upload->getMimeType());
    }

    public function store(Asset $asset, UploadedFile $upload): array
    {
        $disk = Directory::getAssetDisk();
        $storage = Storage::disk($disk);
        $revision = (string) Str::uuid();
        $mime = $upload->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'
        };
        $path = 'custom-thumbnails/'.$asset->id.'/'.$revision.'.'.$extension;
        $thumbnail = ['path' => $path, 'mime_type' => $mime, 'revision' => $revision];
        $deletion = app(AssetDeletionService::class);

        try {
            // Store the original image bytes: re-encoding here would flatten animated GIFs.
            abort_unless($storage->putFileAs(dirname($path), $upload, basename($path)), 500, 'Could not save the thumbnail.');
            $tombstones = DB::transaction(function () use ($asset, $thumbnail, $deletion, $disk) {
                $current = Asset::lockForUpdate()->findOrFail($asset->id);
                // The controller asked before the lock; ask again on the locked row (a rejected upload deletes its stored file below).
                app(RoleBuilder::class)->abortUnlessOwn(model: $current, reason: 'own-only-thumbnail');
                $previous = self::location($current);
                $meta = $current->meta_data ?? [];
                unset($meta['stack_cover']);
                $meta['custom_thumbnail'] = $thumbnail;
                $current->update(['meta_data' => $meta]);

                return $previous ? $deletion->queueFileDeletions(array_map(
                    fn (string $stored): array => ['disk' => $disk, 'path' => $stored],
                    array_values(array_diff(self::storedPaths($previous), StackMemberState::artworkPaths([StackMemberState::KEY => $meta[StackMemberState::KEY] ?? []]))),
                )) : [];
            });
        } catch (\Throwable $e) {
            $storage->delete($path);
            throw $e;
        }

        try {
            $deletion->processPendingFileDeletions($tombstones);
        } catch (\Throwable) {
            // The saved thumbnail remains valid; the durable tombstone retries cleanup.
        }
        if (config('app.debug')) {
            try {
                file_put_contents(storage_path('logs/custom-thumbnail.jsonl'), json_encode([
                    'ts'    => now()->toIso8601String(), 'run_id' => $revision, 'level' => 'info',
                    'event' => 'saved', 'loc' => 'custom-thumbnail', 'ctx' => RequestId::ctx(['asset_id' => $asset->id, 'mime_type' => $mime]),
                ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            } catch (\Throwable) {
                // Optional diagnostics cannot fail a successful save.
            }
        }

        return $thumbnail;
    }
}
