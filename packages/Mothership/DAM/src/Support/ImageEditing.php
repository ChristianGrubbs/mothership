<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\RoleBuilder;

/**
 * What the image editor may be offered for an asset: one answer for the Details page's buttons and for the endpoints
 * behind them, so a button can never promise what the request then refuses.
 *
 * The original is edited in place only when ImageEditorController::decode() can read it (PNG, JPEG, WebP): an SVG, GIF,
 * TIFF, DNG or PSD is `file_type` image too but fails that decoder. Any other asset (a 3D model, a PDF, a video) is never
 * edited itself; its thumbnail is, through the existing custom-thumbnail path (CustomThumbnailController), and only when
 * a real raster thumbnail exists, never the type placeholder the thumbnail route answers 200 with.
 */
final class ImageEditing
{
    /** The file extensions ImageEditorController::decode() accepts (it reads the bytes, so a renamed file still fails there). */
    public const ORIGINAL_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** The MIME types the decoder reads; an empty or generic recorded MIME (a legacy row) is not a conflict, the bytes decide. */
    private const ORIGINAL_MIMES = ['image/png', 'image/x-png', 'image/jpeg', 'image/jpg', 'image/pjpeg', 'image/webp'];

    public static function originalEditable(Asset $asset): bool
    {
        if ($asset->file_type !== 'image' || ! in_array(strtolower((string) $asset->extension), self::ORIGINAL_EXTENSIONS, true)) {
            return false;
        }

        // A GIF or SVG renamed to .png carries its real type in the recorded MIME: no offer, the same as the decoder's refusal.
        $mime = strtolower(trim(explode(';', (string) $asset->mime_type)[0]));

        return $mime === '' || $mime === 'application/octet-stream' || in_array($mime, self::ORIGINAL_MIMES, true);
    }

    /**
     * The same answer for one stacked file that is not the main file: its stored type (StackFileName, the type the
     * Library shows for it) is one the decoder reads, and its recorded MIME does not say otherwise.
     */
    public static function memberEditable(AssetFile $member, Asset $asset): bool
    {
        if (! in_array(StackFileName::extension($member, $asset), self::ORIGINAL_EXTENSIONS, true)) {
            return false;
        }

        $mime = strtolower(trim(explode(';', (string) $member->fileObject?->mime_type)[0]));

        return $mime === '' || $mime === 'application/octet-stream' || in_array($mime, self::ORIGINAL_MIMES, true);
    }

    /** An asset whose own picture is its thumbnail (an image) never takes a custom one: CustomThumbnailController refuses it. */
    public static function takesCustomThumbnail(Asset $asset): bool
    {
        return $asset->file_type !== 'image' && ! str_starts_with(strtolower((string) $asset->mime_type), 'image/');
    }

    /**
     * The revisioned thumbnail URL when the thumbnail route (FileController::thumbnail) would serve a real raster picture
     * for this asset, else null: a user's custom one (wins, even when the original is gone), or, for a readable original,
     * a captured 3D view, audio cover art, a PDF/video/panorama poster, or a Blender/Unity embedded thumbnail already on
     * disk. Nothing is generated here, so a poster that is only made lazily on first view is not offered until it exists.
     * `v` changes with every save, so a cached copy never outlives it. It is the bare revision: the editor loads the RAW
     * custom bytes, never the 300 px card derivative (`<revision>.w300`), so a save cannot replace the full-size picture with a copy.
     */
    public static function thumbnailUrl(Asset $asset): ?string
    {
        if (! self::takesCustomThumbnail($asset) || ! $asset->path) {
            return null;
        }

        $revision = self::storedThumbnailRevision($asset);

        return $revision === null
            ? null
            : route('admin.dam.file.thumbnail', array_filter(['path' => urlencode($asset->path), 'v' => $revision ?: null]));
    }

    private static function storedThumbnailRevision(Asset $asset): ?string
    {
        $disk = Storage::disk(Directory::getAssetDisk());
        $custom = CustomThumbnailService::location($asset);

        if ($custom && $disk->exists($custom['path'])) {
            // An animated GIF would be flattened to its first frame by an edit; only still pictures are offered.
            return $custom['mime_type'] === 'image/gif' ? null : (string) ($custom['revision'] ?? '');
        }

        // Everything else is derived from the original: an unavailable one gets the type placeholder from the route.
        $object = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')->orderBy('id')->first()?->fileObject;

        if (! $object instanceof FileObject || ! app(ContentStore::class)->holdsBlob($object)) {
            return null;
        }

        $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
        $stored = fn (mixed $path): ?string => is_string($path) && $path !== '' && $disk->exists($path) ? $path : null;
        $extension = strtolower((string) $asset->extension);
        $found = null;

        if (ModelThumbnailService::supports($asset)) {
            $found = $stored($meta['thumbnail_path'] ?? null);
        } elseif ($asset->file_type === 'audio') {
            $found = $stored($meta['cover_art_path'] ?? null);
        } elseif ($asset->file_type === 'video' || $asset->asset_kind === 'pano360' || $extension === 'pdf') {
            // The route picks the recorded path, else the poster keyed by the original's bytes, and only then checks it exists.
            $found = $stored($meta['thumbnail_path'] ?? DerivativeImages::poster($object->sha256));
        } elseif (PreviewKinds::hasEmbeddedThumbnail($extension)) {
            $found = $stored(DerivativeImages::poster($object->sha256));
        }

        return $found === null ? null : (string) ($meta['thumbnail_revision'] ?? pathinfo($found, PATHINFO_FILENAME));
    }

    /**
     * The fields the Details page adds to its preview data. The thumbnail may be edited by whoever may set one (the
     * custom-thumbnail request's own gate: edit permission, and an own-items role only on what it created) and who
     * holds the editor permission; the URL is sent only then.
     *
     * @return array{mayUseEditor: bool, canEditOriginal: bool, canEditThumbnail: bool, thumbnailEditUrl: string|null}
     */
    public static function previewFields(Asset $asset): array
    {
        $mayEdit = (bool) bouncer()->hasPermission('dam.asset.editor') && (bool) bouncer()->hasPermission('dam.asset.edit');
        $url = $mayEdit && app(RoleBuilder::class)->refusal($asset, 'own-only-thumbnail') === null ? self::thumbnailUrl($asset) : null;

        return [
            // Whether this person may use the editor at all: the page decides per stacked file whether its type can be edited.
            'mayUseEditor'     => $mayEdit,
            'canEditOriginal'  => $mayEdit && self::originalEditable($asset),
            'canEditThumbnail' => $url !== null,
            'thumbnailEditUrl' => $url,
        ];
    }
}
