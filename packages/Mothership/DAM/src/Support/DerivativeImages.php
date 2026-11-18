<?php

namespace Mothership\DAM\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

/**
 * Naming, encoding and cleanup of image derivatives (ADR 0009).
 *
 * Derivatives are keyed by the content they were made from:
 * `derivatives/<sha[0:2]>/<sha>/<recipe>.<ext>` with the recipes `thumbnail`, `preview-<size>`,
 * `poster`, `dng-preview`, `dng-thumbnail` (DngPreviewService's extracted/rendered JPEGs) and `model-capture`
 * (a stacked 3D file's browser-captured picture, ModelFileThumbnailService). A
 * folder move or rename never stales them, copies that share a blob share them, and a new asset
 * that reuses an old Library path can never be served the old asset's derivative. The bytes of a sha never
 * change, so a derivative is only purged when its blob is reclaimed.
 *
 * Generated rasters are encoded as WebP; GIF (animation) and sources that are already WebP or AVIF keep their
 * format. The rule reads the source's extension (the asset's extension column), never a path.
 *
 * The older path-keyed mirrors `thumbnails/<asset path>` and `preview/<size>/<asset path>` are never read or
 * written any more; `dam:storage:reclaim` (StorageIntegrity::reclaim()) removes the files.
 */
class DerivativeImages
{
    public const ROOT = 'derivatives';

    public const RECIPE_THUMBNAIL = 'thumbnail';

    public const RECIPE_POSTER = 'poster';

    public const RECIPE_DNG_PREVIEW = 'dng-preview';

    public const RECIPE_DNG_THUMBNAIL = 'dng-thumbnail';

    public const RECIPE_MODEL_CAPTURE = 'model-capture';

    private const WEBP_MIME = 'image/webp';

    private const KEPT_FORMATS = [
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    /** The directory that holds every derivative of one blob. */
    public static function directory(string $sha256): string
    {
        $sha256 = self::sha($sha256);

        return self::ROOT.'/'.substr($sha256, 0, 2).'/'.$sha256;
    }

    /** `derivatives/<sha[0:2]>/<sha>/<recipe>.<ext>`: the one derivative layout rule. */
    public static function key(string $sha256, string $recipe, string $extension): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $recipe) !== 1 || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new InvalidArgumentException("Derivative recipe [{$recipe}] or extension [{$extension}] is malformed.");
        }

        return self::directory($sha256).'/'.$recipe.'.'.$extension;
    }

    /** The grid thumbnail of a blob whose asset has `$sourceExtension`. */
    public static function thumbnail(string $sha256, string $sourceExtension): string
    {
        return self::key($sha256, self::RECIPE_THUMBNAIL, self::outputExtension($sourceExtension));
    }

    /** A bounded preview (`preview-<size>`) of a blob whose asset has `$sourceExtension`. */
    public static function preview(string $sha256, int $size, string $sourceExtension): string
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Preview size must be positive.');
        }

        return self::key($sha256, 'preview-'.$size, self::outputExtension($sourceExtension));
    }

    /**
     * A share link's own bounded copy of a stacked still (`share-thumbnail`, `share-preview-<size>`), never wider than
     * its source. It is kept apart from thumbnail()/preview(), which the admin writes at the full requested width, so a
     * copy an admin view upscaled is never what a public link serves.
     */
    public static function shareStill(string $sha256, string $recipe, string $sourceExtension): string
    {
        return self::key($sha256, 'share-'.$recipe, self::outputExtension($sourceExtension));
    }

    /** The JPEG still of a video, panorama or PDF blob. */
    public static function poster(string $sha256): string
    {
        return self::key($sha256, self::RECIPE_POSTER, 'jpg');
    }

    /** The full-size JPEG preview extracted or rendered from a DNG/raw blob. */
    public static function dngPreview(string $sha256): string
    {
        return self::key($sha256, self::RECIPE_DNG_PREVIEW, 'jpg');
    }

    /** The grid-thumbnail JPEG extracted or rendered from a DNG/raw blob. */
    public static function dngThumbnail(string $sha256): string
    {
        return self::key($sha256, self::RECIPE_DNG_THUMBNAIL, 'jpg');
    }

    /**
     * The JPEG a browser captured from its own render of a GLB, 3MF or STL blob (ADR 0006), reclaimed with the blob.
     * With `$assetId` it is that asset's own capture (`model-capture-a<id>.jpg`), the only kind written now (PERM-01);
     * without, the capture every stack holding the blob shared before, still read as a fallback.
     */
    public static function modelCapture(string $sha256, ?int $assetId = null): string
    {
        return self::key($sha256, self::RECIPE_MODEL_CAPTURE.($assetId === null ? '' : '-a'.$assetId), 'jpg');
    }

    /** The Content-Type of a thumbnail or preview made from a source with `$sourceExtension`. */
    public static function contentType(string $sourceExtension): string
    {
        return self::KEPT_FORMATS[self::normalizeExtension($sourceExtension)] ?? self::WEBP_MIME;
    }

    /** Encode a thumbnail or preview of a source with `$sourceExtension` (the kept-format rule). */
    public static function encodeFor(ImageInterface $image, string $sourceExtension): string
    {
        return (string) match (self::normalizeExtension($sourceExtension)) {
            'gif'   => $image->encode(new GifEncoder),
            'avif'  => $image->encode(new AvifEncoder),
            default => $image->encode(new WebpEncoder),
        };
    }

    /** Drop every derivative of a blob. Only for a blob being reclaimed: a sha's bytes never change. */
    public static function purgeContent(Filesystem $storage, string $sha256): void
    {
        $storage->deleteDirectory(self::directory($sha256));
    }

    private static function outputExtension(string $sourceExtension): string
    {
        $extension = self::normalizeExtension($sourceExtension);

        return isset(self::KEPT_FORMATS[$extension]) ? $extension : 'webp';
    }

    private static function normalizeExtension(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }

    private static function sha(string $sha256): string
    {
        $sha256 = strtolower($sha256);

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Derivative keys need a 64-character hexadecimal sha256.');
        }

        return $sha256;
    }
}
