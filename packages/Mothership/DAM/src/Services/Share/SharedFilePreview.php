<?php

namespace Mothership\DAM\Services\Share;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DngPreviewService;
use Mothership\DAM\Services\HeifPreviewService;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\PdfPoster;
use Mothership\DAM\Support\StackFileThumbnail;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * A-contract 3 (2026-10-19): what a share link shows of one of its files (SharedFiles). Thumbnails and still previews
 * are sha-keyed derivatives of the file's own bytes, never a path read from meta_data, so a link can never be pointed at
 * another file. Video, audio and PDF previews are the original inline, and only while the link allows downloads. A
 * model or any other type has no preview (404); the page offers its download instead. No answer here is an attachment.
 */
final class SharedFilePreview
{
    /** Kinds whose preview is the original itself, so a preview-only link refuses it (403). */
    private const ORIGINAL_KINDS = ['video', 'audio', 'pdf'];

    /** PD8: the derivative recipes of a GIF's first-frame still (gifStill()), apart from the animated `thumbnail.gif` / `preview-1920.gif`. */
    private const GIF_STILL_THUMBNAIL = 'still-thumbnail';

    private const GIF_STILL_PREVIEW = 'still-preview-1920';

    /** Still kinds a screen-sized derivative is made for. */
    private const STILL_PREVIEWS = [StackFileThumbnail::KIND_RASTER, StackFileThumbnail::KIND_HEIF, StackFileThumbnail::KIND_DNG];

    public function __construct(
        private readonly ContentStore $contentStore,
        private readonly DngPreviewService $dngPreviewService,
        private readonly HeifPreviewService $heifPreviewService,
    ) {}

    /** 'image', 'video', 'audio', 'pdf', 'model', 'text' or 'other' (SharedFileRow.kind). */
    public static function kind(AssetFile $file, Asset $asset): string
    {
        $mime = self::mime($file);
        $still = StackFileThumbnail::kindOf($file, $asset);

        return match (true) {
            $still === StackFileThumbnail::KIND_MODEL                                                         => 'model',
            $still !== null && $still !== StackFileThumbnail::KIND_PDF, $mime === 'image/svg+xml'             => 'image',
            str_starts_with($mime, 'video/')                                                                  => 'video',
            str_starts_with($mime, 'audio/')                                                                  => 'audio',
            $still === StackFileThumbnail::KIND_PDF, AdobeFormats::isPdfRenderable($file->extension(), $mime) => 'pdf',
            str_starts_with($mime, 'text/'), in_array($mime, ['application/json', 'application/xml'], true)   => 'text',
            default                                                                                           => 'other',
        };
    }

    /** What the page can show of the file (SharedFileRow.preview_kind): 'image', 'video', 'audio', 'pdf' or 'none'. */
    public static function previewKind(Share $share, AssetFile $file, Asset $asset): string
    {
        $kind = self::kind($file, $asset);

        if ($kind === 'image') {
            return self::mime($file) === 'image/svg+xml' || in_array(StackFileThumbnail::kindOf($file, $asset), self::STILL_PREVIEWS, true) ? 'image' : 'none';
        }

        return in_array($kind, self::ORIGINAL_KINDS, true) && $share->allowsDownload() && AssetHelper::isInlineSafeMime(self::mime($file))
            ? $kind
            : 'none';
    }

    /** The file's picture, or null (the caller answers 404) when it has none or its bytes are gone. */
    public function thumbnail(AssetFile $file, Asset $asset): ?Response
    {
        $object = $this->readable($file);

        if ($object === null) {
            return null;
        }

        try {
            if (self::mime($file) === 'image/svg+xml') {
                // An SVG is its own thumbnail; no copy is cached (as the share card does).
                return $this->contentStore->deliver(object: $object, filename: SharedFiles::fileName($file, $asset), disposition: HeaderUtils::DISPOSITION_INLINE, mimeType: 'image/svg+xml');
            }

            $still = StackFileThumbnail::kindOf($file, $asset);
            $extension = strtolower($file->extension());

            if (self::mime($file) === 'image/gif') {
                return $this->gifStill(object: $object, recipe: self::GIF_STILL_THUMBNAIL, maxSize: 300);
            }

            if (in_array($still, [StackFileThumbnail::KIND_RASTER, StackFileThumbnail::KIND_HEIF], true)) {
                return $this->derivative(object: $object, destination: DerivativeImages::shareStill((string) $object->sha256, 'thumbnail', $extension), sourceExtension: $extension, maxSize: 300);
            }

            $path = match (true) {
                $still === StackFileThumbnail::KIND_DNG                                                                => $this->dngPreviewService->ensureForObject(object: $object, thumbnail: true, budgetSeconds: 10),
                $still === StackFileThumbnail::KIND_MODEL                                                              => ModelFileThumbnailService::path((string) $object->sha256, (int) $file->dam_asset_id),
                $still === StackFileThumbnail::KIND_IMAGE                                                              => $this->libraryPicture((string) $object->sha256),
                $still === StackFileThumbnail::KIND_PDF, AdobeFormats::isPdfRenderable($extension, $object->mime_type) => PdfPoster::ensure($object),
                default                                                                                                => DerivativeImages::poster((string) $object->sha256),
            };

            return $path !== null && Storage::disk(Directory::getAssetDisk())->exists($path) ? $this->storedFile($path) : null;
        } catch (\Throwable $e) {
            Log::warning('DAM share file thumbnail failed: '.$e->getMessage(), ['asset_file' => (int) $file->id]);

            return null;
        }
    }

    /** The file shown in the page, or 403/404 per the policy above. */
    public function preview(Share $share, AssetFile $file, Asset $asset): Response
    {
        $kind = self::kind($file, $asset);
        $previewKind = self::previewKind($share, $file, $asset);

        if ($previewKind === 'none') {
            abort_if(in_array($kind, self::ORIGINAL_KINDS, true) && ! $share->allowsDownload(), 403);
            abort(404);
        }

        $object = $this->readable($file);
        abort_if($object === null, 404);

        if ($previewKind !== 'image' || self::mime($file) === 'image/svg+xml') {
            // previewKind() admitted only an inline-safe type, so ContentStore keeps the inline disposition.
            return $this->contentStore->deliver(object: $object, filename: SharedFiles::fileName($file, $asset), disposition: HeaderUtils::DISPOSITION_INLINE, mimeType: self::mime($file));
        }

        try {
            $response = $this->stillPreview(object: $object, file: $file, asset: $asset);
        } catch (\Throwable $e) {
            Log::warning('DAM share file preview failed: '.$e->getMessage(), ['asset_file' => (int) $file->id]);
            $response = null;
        }

        abort_if($response === null, 404);

        return $response;
    }

    /**
     * A cached derivative of an image blob, made on first request and keyed by the blob's sha (moved from
     * SharedViewerController). Never wider than the source: a public route never upscales a small image.
     */
    public function derivative(FileObject $object, string $destination, string $sourceExtension, int $maxSize): Response
    {
        $disk = Directory::getAssetDisk();

        if (Storage::disk($disk)->exists($destination)) {
            return $this->storedFile($destination);
        }

        if ($made = $this->heifDerivative(object: $object, destination: $destination, sourceExtension: $sourceExtension, maxSize: $maxSize)) {
            return $made;
        }

        $stream = $this->contentStore->open($object);

        try {
            $bytes = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        $image = (new ImageManager(new Driver))->decode($bytes)->scaleDown(width: $maxSize);
        $imageData = DerivativeImages::encodeFor($image, $sourceExtension);

        Storage::disk($disk)->put($destination, $imageData);

        return response($imageData, 200)->header('Content-Type', DerivativeImages::contentType($sourceExtension));
    }

    /** The WebP derivative of a HEIC/HEIF blob (libvips only: GD cannot read it), or null for any other blob (moved). */
    public function heifDerivative(FileObject $object, string $destination, string $sourceExtension, int $maxSize): ?Response
    {
        if (preg_match('#^image/hei[cf]#i', (string) $object->mime_type) !== 1) {
            return null;
        }

        $local = $this->contentStore->localPath($object, $sourceExtension);

        try {
            $made = $this->heifPreviewService->generateIfHeif(
                disk: Directory::getAssetDisk(),
                localSource: $local->path,
                destinationPath: $destination,
                maxSize: $maxSize,
            );
        } finally {
            $local->release();
        }

        return $made ? $this->storedFile($destination, 'image/webp') : null;
    }

    /**
     * A TIFF, PSD or other image GD cannot decode: the poster, else the WebP thumbnail the library already made
     * (StackFileThumbnail's order), or null. Its bytes are never loaded or decoded here.
     */
    private function libraryPicture(string $sha256): ?string
    {
        foreach ([DerivativeImages::poster($sha256), DerivativeImages::thumbnail($sha256, 'webp')] as $path) {
            if (Storage::disk(Directory::getAssetDisk())->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** A derivative already on the asset disk (moved from SharedViewerController::fileResponse()). */
    public function storedFile(string $path, ?string $mimeType = null): Response
    {
        $disk = Directory::getAssetDisk();

        if ($disk === Directory::ASSETS_DISK_AWS) {
            return $this->contentStore->deliverStoredFile(disk: $disk, path: $path, mimeType: $mimeType);
        }

        $headers = AssetHelper::assetResponseHeaders();

        // A custom thumbnail declares its type when it is stored; everything else is sniffed as before.
        if ($mimeType !== null) {
            $headers['Content-Type'] = $mimeType;
        }

        return response()->file(Storage::disk($disk)->path($path), $headers);
    }

    private function stillPreview(FileObject $object, AssetFile $file, Asset $asset): ?Response
    {
        if (StackFileThumbnail::kindOf($file, $asset) === StackFileThumbnail::KIND_DNG) {
            $path = $this->dngPreviewService->ensureForObject(object: $object, thumbnail: false, budgetSeconds: 10);

            return $path === null ? null : $this->storedFile($path)->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);
        }

        if (self::mime($file) === 'image/gif') {
            return $this->gifStill(object: $object, recipe: self::GIF_STILL_PREVIEW, maxSize: 1920);
        }

        $extension = strtolower($file->extension());

        return $this->derivative(object: $object, destination: DerivativeImages::shareStill((string) $object->sha256, 'preview-1920', $extension), sourceExtension: $extension, maxSize: 1920);
    }

    /**
     * PD8 for a link's main file on a preview-only link: the first-frame still of the original (screen size), never the
     * animated bytes. Delivered by the share's default preview route and thumbnail route.
     */
    public function gifPreviewStill(FileObject $object): Response
    {
        return $this->gifStill(object: $object, recipe: self::GIF_STILL_PREVIEW, maxSize: 1920);
    }

    /** gifPreviewStill() at thumbnail size. */
    public function gifThumbnailStill(FileObject $object): Response
    {
        return $this->gifStill(object: $object, recipe: self::GIF_STILL_THUMBNAIL, maxSize: 300);
    }

    /**
     * PD8: a GIF's first frame as a still, never wider than the source. WebP, because it is ADR 0009's format for
     * generated rasters and keeps a GIF's transparency at a fraction of PNG's size. It is cached under its own recipe,
     * never the GIF's `thumbnail.gif` / `preview-1920.gif`, since derivative() serves whatever sits there and other
     * code caches an animated copy at that path.
     */
    private function gifStill(FileObject $object, string $recipe, int $maxSize): Response
    {
        $disk = Directory::getAssetDisk();
        $destination = DerivativeImages::key((string) $object->sha256, $recipe, 'webp');

        if (Storage::disk($disk)->exists($destination)) {
            return $this->storedFile(path: $destination, mimeType: 'image/webp');
        }

        $stream = $this->contentStore->open($object);

        try {
            $bytes = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        $image = (new ImageManager(new Driver, decodeAnimation: false))->decode($bytes)->scaleDown(width: $maxSize);
        $imageData = (string) $image->encode(new WebpEncoder);

        Storage::disk($disk)->put($destination, $imageData);

        return response($imageData, 200)->header('Content-Type', 'image/webp');
    }

    /** The file's blob checked like every read (own locator, present, recorded size), or null when it is gone. */
    private function readable(AssetFile $file): ?FileObject
    {
        $object = $file->fileObject;

        if (! $object instanceof FileObject) {
            return null;
        }

        try {
            return $this->contentStore->ensureAvailable($object);
        } catch (ContentUnavailable) {
            return null;
        }
    }

    private static function mime(AssetFile $file): string
    {
        return strtolower(trim(explode(';', (string) $file->fileObject?->mime_type)[0]));
    }
}
