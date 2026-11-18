<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\StackMemberState;

/**
 * Which files in a stack can show a small picture of themselves, and how it is made. The stack's picker
 * and file strip ask for `?thumb=1` on a file only when this says there is a derivative to serve, so a
 * tile never pulls an original (a 10 MB JPEG, a 50 MB DNG) just to draw 170 pixels.
 */
final class StackFileThumbnail
{
    /** Browser-decodable rasters the thumbnail pipeline (libvips or GD) resizes. */
    private const RASTER_MIMES = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
    ];

    /** HEIC and HEIF stills. */
    private const HEIF_MIMES = ['image/heic', 'image/heif'];

    /**
     * The stored types the preview modal opens in the 3D viewer (grid-preview-modal's `variantPreviewData`
     * derives a stacked file's extension from the same list). A type outside it, generic bytes aside, never
     * reaches the viewer, so it can never be captured.
     */
    private const MODEL_MIMES = [
        'model/gltf-binary'                                            => 'glb',
        'model/stl'                                                    => 'stl',
        'application/vnd.ms-pki.stl'                                   => 'stl',
        'model/3mf'                                                    => '3mf',
        'application/vnd.ms-package.3dmanufacturing-3dmodel+xml'       => '3mf',
    ];

    public const KIND_RASTER = 'raster';

    public const KIND_DNG = 'dng';

    /**
     * A HEIC or HEIF still: no browser or GD decodes it, so libvips makes a WebP thumbnail and screen-sized preview on request
     * (HeifPreviewService) and the original stays the download.
     */
    public const KIND_HEIF = 'heif';

    /** A GLB, 3MF or STL: no server derivative, the picture is whatever a browser captured of it (ADR 0006). */
    public const KIND_MODEL = 'model';

    /**
     * Any other still the thumbnail pipeline may be able to resize (TIFF, PSD): the card already tries the same
     * resize for them (FileController::thumbnail), so a stacked copy of the file gets the same picture. It is not offered
     * as a screen-sized preview, which keeps opening the original as before.
     */
    public const KIND_IMAGE = 'image';

    /**
     * PDF-compatible Illustrator bytes (an .ai whose stored type is application/pdf): drawn from the first page by the
     * PDF renderer, like a PDF's card. A genuine PDF member is unchanged (it shows its poster once one exists).
     */
    public const KIND_PDF = 'pdf';

    /** `raster`, `heif`, `image`, `dng`, `model`, `pdf`, or null when the file has no derivative and keeps its type tile. */
    public static function kind(?string $mime, ?string $label): ?string
    {
        $mime = self::mime($mime);
        $extension = strtolower(pathinfo((string) $label, PATHINFO_EXTENSION));

        if ($extension === 'dng' || str_contains($mime, 'dng')) {
            return self::KIND_DNG;
        }

        if (self::modelExtension($mime, $label) !== null) {
            return self::KIND_MODEL;
        }

        if (in_array($mime, self::RASTER_MIMES, true)) {
            return self::KIND_RASTER;
        }

        if (in_array($mime, self::HEIF_MIMES, true)) {
            return self::KIND_HEIF;
        }

        if (AdobeFormats::rendersAsPdf($extension, $mime)) {
            return self::KIND_PDF;
        }

        // An SVG is drawn from its own bytes (the strip and fan show it directly), never resized.
        return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' ? self::KIND_IMAGE : null;
    }

    /** The kind of one stacked file, a DNG recognised by its bytes as well as by its type or label (PROD-13). */
    public static function kindOf(AssetFile $file, Asset $asset): ?string
    {
        if ($file->fileObject !== null && StackFileName::isDng($file->fileObject)) {
            return self::KIND_DNG;
        }

        return self::kind($file->fileObject?->mime_type, $file->label ?: $asset->file_name);
    }

    /**
     * Whether the preview shows this file through a screen-sized copy (`?preview=1`): a DNG, which no browser
     * decodes, a HEIC/HEIF, and a still raster. A GIF keeps its original so it still moves.
     */
    public static function previewable(AssetFile $file, Asset $asset): bool
    {
        return match (self::kindOf($file, $asset)) {
            self::KIND_DNG, self::KIND_HEIF => true,
            self::KIND_RASTER               => self::mime($file->fileObject?->mime_type) !== 'image/gif',
            default                         => false,
        };
    }

    /**
     * `glb`, `3mf` or `stl` for a file the 3D viewer renders, else null. The stored type decides; a label is
     * editable text, so its suffix only names a file whose type was stored as generic bytes.
     */
    public static function modelExtension(?string $mime, ?string $label): ?string
    {
        $mime = self::mime($mime);
        $generic = $mime === '' || in_array($mime, ['application/octet-stream', 'binary/octet-stream'], true);
        $extension = $generic ? strtolower(pathinfo((string) $label, PATHINFO_EXTENSION)) : (self::MODEL_MIMES[$mime] ?? null);

        return in_array($extension, ModelThumbnailService::EXTENSIONS, true) ? $extension : null;
    }

    /**
     * What a stacked file's small picture is made from, found without making anything: null while the file has none, so
     * a tile keeps its type badge and the page never asks for a picture that would answer 404.
     *
     * Every stored picture is found the way the Library card finds it, in this order: a custom thumbnail of the asset
     * the file stands for; for a 3D file the capture of this very blob made for this stack (ADR 0006); the cover art or
     * capture of the asset the file came from (a stack's other files were assets of their own before the merge); the
     * poster of the blob (video, PDF, panorama, Blender, Unity: `derivatives/<sha>/poster.jpg`, ADR 0009); then a resize
     * of the still, which the server makes on first request for a plain raster and which exists already for any other
     * still the card showed. A source asset's picture belongs to the bytes it held, so it is used only while the file
     * still holds those bytes: a slot revised to new bytes shows nothing of the old picture.
     *
     * @param  array<string, mixed>|null  $context  from context(), when many files of one payload are resolved together
     * @return array{kind: string, path: ?string, disk: ?string, mime: ?string}|null `path` is null for a picture made on request
     */
    public static function source(AssetFile $file, Asset $asset, ?array $context = null): ?array
    {
        $context ??= self::context(collect([$file]), $asset);
        $disk = Directory::getAssetDisk();
        $sha = $file->fileObject?->sha256;
        $hasSha = is_string($sha) && preg_match('/^[a-f0-9]{64}$/i', $sha) === 1;
        $kind = self::kindOf($file, $asset);
        $state = $file->attachment?->is_representative ? $asset->meta_data : StackMemberState::remembered(asset: $asset, file: $file);
        $cover = $state['stack_cover'] ?? null;
        if (is_array($cover) && ($cover['file_id'] ?? null) === $file->id && ($cover['sha256'] ?? null) === $sha
            && is_string($cover['path'] ?? null) && self::stored($cover['path'], cache: false)) {
            return ['kind' => 'art', 'path' => $cover['path'], 'disk' => $disk, 'mime' => $cover['mime'] ?? null];
        }
        $origin = self::origin($file, $asset, $context, $hasSha ? strtolower($sha) : null);
        $custom = $origin === null ? null : CustomThumbnailService::location($origin);

        if ($custom !== null && self::stored($custom['path'], cache: false)) {
            return ['kind' => 'art', 'path' => $custom['path'], 'disk' => $disk, 'mime' => $custom['mime_type']];
        }

        if ($kind === self::KIND_MODEL) {
            // The stack's own capture of these bytes outranks the picture the source asset had.
            $capture = ModelFileThumbnailService::path($sha, (int) $asset->id);

            if ($capture !== null) {
                return ['kind' => $kind, 'path' => $capture, 'disk' => $disk, 'mime' => 'image/jpeg'];
            }
        }

        if ($origin !== null) {
            $art = match (true) {
                $origin->file_type === 'audio' => ($origin->meta_data['cover_art_path'] ?? null),
                $kind === self::KIND_MODEL     => ($origin->meta_data['thumbnail_path'] ?? null),
                default                        => null,
            };

            if (is_string($art) && $art !== '' && self::stored($art, cache: false)) {
                return ['kind' => 'art', 'path' => $art, 'disk' => $disk, 'mime' => $origin->file_type === 'audio' ? null : 'image/jpeg'];
            }
        }

        if ($kind === self::KIND_MODEL) {
            return null;
        }

        if ($kind === self::KIND_DNG) {
            return ['kind' => $kind, 'path' => null, 'disk' => null, 'mime' => 'image/jpeg'];
        }

        if ($hasSha && ($kind === null || $kind === self::KIND_IMAGE || $kind === self::KIND_PDF) && self::stored(DerivativeImages::poster($sha))) {
            return ['kind' => 'poster', 'path' => DerivativeImages::poster($sha), 'disk' => $disk, 'mime' => 'image/jpeg'];
        }

        if ($kind === self::KIND_HEIF) {
            // Made on first request; the copy is served as it is once it exists.
            $made = $hasSha ? DerivativeImages::thumbnail($sha, 'webp') : null;

            return ['kind' => $kind, 'path' => $made !== null && self::stored($made) ? $made : null, 'disk' => $disk, 'mime' => 'image/webp'];
        }

        if ($kind === self::KIND_IMAGE) {
            // Nothing here can decode these (TIFF, HEIC, PSD); only the copy the card already made is a picture.
            $made = $hasSha ? DerivativeImages::thumbnail($sha, 'webp') : null;

            return $made !== null && self::stored($made)
                ? ['kind' => $kind, 'path' => $made, 'disk' => $disk, 'mime' => 'image/webp']
                : null;
        }

        return $kind === null ? null : ['kind' => $kind, 'path' => null, 'disk' => null, 'mime' => null];
    }

    /**
     * What resolving a payload's files together needs: the source asset of every merged-in file, and the bytes its
     * slot holds, read in three queries however many files there are.
     *
     * @param  Collection<int, AssetFile>  $files
     * @return array{slots: array<int, array{asset: ?Asset, sha: ?string, representative: bool}>}
     */
    public static function context(Collection $files, Asset $asset): array
    {
        $originSlotIds = $files->map(fn (AssetFile $file) => $file->attachment?->is_representative ? null : $file->attachment?->origin_attachment_id)
            ->filter()->unique()->values()->all();

        if ($originSlotIds === []) {
            return ['slots' => []];
        }

        // Only a source's representative has its asset's picture (custom thumbnail, cover art, capture).
        $slots = Attachment::query()->whereIn('id', $originSlotIds)->where('is_representative', true)->get(['id', 'dam_asset_id']);
        $assets = Asset::withoutGlobalScopes()->withTrashed()->whereIn('id', $slots->pluck('dam_asset_id')->unique())->get()->keyBy('id');
        $shas = AssetFile::query()
            ->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
            ->whereIn('dam_asset_files.attachment_id', $slots->pluck('id'))->where('dam_asset_files.is_current', true)
            ->pluck('dam_file_objects.sha256', 'dam_asset_files.attachment_id');

        return ['slots' => $slots->mapWithKeys(fn (Attachment $slot) => [(int) $slot->id => [
            'asset'          => $assets->get($slot->dam_asset_id),
            'sha'            => isset($shas[$slot->id]) ? strtolower((string) $shas[$slot->id]) : null,
            'representative' => true,
        ]])->all()];
    }

    /**
     * The asset whose own picture stands for this file: the stack itself for its cover, so the strip's cover tile shows the
     * card's picture as the fan does; else the asset a merged-in file came from, while it still exists and the file still
     * holds the bytes that asset's picture was made for.
     */
    private static function origin(AssetFile $file, Asset $asset, array $context, ?string $sha): ?Asset
    {
        if ($file->attachment?->is_representative) {
            return $asset;
        }

        $state = StackMemberState::remembered(asset: $asset, file: $file);
        if ($state !== null) {
            $remembered = clone $asset;
            $remembered->meta_data = $state;
            $remembered->file_type = str_starts_with((string) $file->fileObject?->mime_type, 'audio/') ? 'audio' : $asset->file_type;

            return $remembered;
        }

        $slot = $context['slots'][(int) $file->attachment?->origin_attachment_id] ?? null;

        return $slot !== null && $sha !== null && $slot['sha'] === $sha ? $slot['asset'] : null;
    }

    /**
     * Whether a stored picture exists. A content-addressed derivative never changes, so a positive answer is remembered
     * for a few minutes instead of asking the disk (it may be remote) for every file of every payload.
     */
    private static function stored(string $path, bool $cache = true): bool
    {
        $disk = Directory::getAssetDisk();
        $key = self::existsKey(disk: $disk, path: $path);

        // A cache that is down is a cache miss, never a failed payload.
        if ($cache && self::remembered($key) === true) {
            return true;
        }

        $exists = Storage::disk($disk)->exists($path);

        if ($cache && $exists) {
            try {
                Cache::put($key, true, 600);
            } catch (\Throwable) {
            }
        }

        return $exists;
    }

    /** Drops what was remembered about a picture that turned out to be gone, so the next payload asks the disk. */
    public static function forget(string $disk, string $path): void
    {
        try {
            Cache::forget(self::existsKey(disk: $disk, path: $path));
        } catch (\Throwable) {
        }
    }

    private static function existsKey(string $disk, string $path): string
    {
        return 'dam-stack-thumb-exists:'.$disk.':'.md5($path);
    }

    private static function remembered(string $key): ?bool
    {
        try {
            return Cache::get($key) === true ? true : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Where a stacked file's small picture is served from; a `$version` (see version()) lets the browser keep it for good. */
    public static function url(int $assetId, int $assetFileId, ?string $version = null): string
    {
        return route('admin.dam.file.asset-file', array_filter(['asset' => $assetId, 'assetFile' => $assetFileId, 'thumb' => 1, 'v' => $version]));
    }

    /** The versioned picture URL of one stacked file, or null while it has no picture. */
    public static function urlFor(AssetFile $file, Asset $asset, ?array $context = null): ?string
    {
        $source = self::source($file, $asset, $context);

        return $source === null ? null : self::url(assetId: (int) $asset->id, assetFileId: (int) $file->id, version: self::version(source: $source, sha: $file->fileObject?->sha256));
    }

    /**
     * What names the exact bytes a source serves, or null when nothing does (the response then keeps revalidating).
     * A stored picture at a content-addressed derivative path or a custom thumbnail's own revisioned path never changes
     * under that path, so the path names it; a picture made on request is keyed by the file's blob sha. Cover art and
     * 3D captures are rewritten in place, so they have no version.
     *
     * @param  array{kind: string, path: ?string, disk: ?string, mime: ?string}  $source
     */
    public static function version(array $source, ?string $sha): ?string
    {
        $path = $source['path'];

        if ($path !== null) {
            $stable = str_starts_with($path, 'custom-thumbnails/')
                || (str_starts_with($path, DerivativeImages::ROOT.'/') && ! str_contains($path, DerivativeImages::RECIPE_MODEL_CAPTURE));

            return $stable ? substr(hash('sha256', 'stack-thumb|'.$path), 0, 32) : null;
        }

        return is_string($sha) && preg_match('/^[a-f0-9]{64}$/i', $sha) === 1 ? strtolower($sha) : null;
    }

    /** Where the preview loads a stacked file's screen-sized copy from. */
    public static function previewUrl(int $assetId, int $assetFileId): string
    {
        return route('admin.dam.file.asset-file', ['asset' => $assetId, 'assetFile' => $assetFileId, 'preview' => 1]);
    }

    private static function mime(?string $mime): string
    {
        return strtolower(trim(explode(';', (string) $mime)[0]));
    }
}
