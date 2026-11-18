<?php

namespace Mothership\DAM\Helpers;

use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\WebPageInspector;
use Mothership\DAM\Services\WebPageTelemetry;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Support\UploadRefusal;

class AssetHelper
{
    /** The server key public/index.php sets when PHP dropped files past `max_file_uploads` from this request. */
    public const FILE_UPLOADS_EXCEEDED = 'MOTHERSHIP_FILE_UPLOADS_EXCEEDED';

    /**
     * Why a multi-file upload request must be refused, or null: PHP kept only the first `max_file_uploads` files
     * and dropped the rest before the app started, so storing what arrived would report success for a partial
     * batch (SETUP-07). The message names the limit.
     */
    public static function droppedFileUploadsMessage(): ?string
    {
        if (request()->server(self::FILE_UPLOADS_EXCEEDED) !== '1') {
            return null;
        }

        return trans('dam::app.admin.dam.asset.datagrid.too-many-files', ['max' => (int) ini_get('max_file_uploads')]);
    }

    public static function getMaxUploadSizeKb(): int
    {
        $phpLimitKb = self::iniValueToKb((string) ini_get('upload_max_filesize'));
        $postLimitKb = self::iniValueToKb((string) ini_get('post_max_size'));

        $candidates = array_filter([
            $phpLimitKb ?: null,
            $postLimitKb ?: null,
        ]);

        return $candidates ? (int) min($candidates) : PHP_INT_MAX;
    }

    public static function humanReadableSize(int $kilobytes): string
    {
        if ($kilobytes >= 1024 * 1024) {
            return round($kilobytes / 1024 / 1024, 2).' GB';
        }

        if ($kilobytes >= 1024) {
            return round($kilobytes / 1024, 2).' MB';
        }

        return $kilobytes.' KB';
    }

    protected static function iniValueToKb(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g'     => $number * 1024 * 1024,
            'm'     => $number * 1024,
            'k'     => $number,
            default => ((float) $value) / 1024,
        };
    }

    public static function getFileType($file)
    {
        return self::classifyFile(
            mimeType: (string) $file->getMimeType(),
            extension: strtolower((string) $file->getClientOriginalExtension()),
            realPath: $file->getRealPath() ?: null,
        );
    }

    /**
     * The one classifier every ingress uses (form, chunked, API, re-upload). A zip is a web page
     * only when WebPageInspector finds index.html at its root or under one top-level folder.
     */
    public static function classifyFile(string $mimeType, string $extension, ?string $realPath = null): string
    {
        $mimeType = strtolower($mimeType);
        $extension = strtolower($extension);

        // A confidently identified media family (image, video, audio, PDF) wins over an incompatible extension: an mp4
        // named .html, a WAV named raw.dng or a PDF named clip.mp4 are what their bytes say. The extension rules below
        // only place bytes whose type is unknown (no mime, octet-stream, text/plain...).
        $family = self::confidentFamily($mimeType);

        if ($family !== null) {
            return $family;
        }

        if (config('dam.web_pages.enabled', true)) {
            if (in_array($extension, ['html', 'htm'], true)) {
                return 'web';
            }

            if ($extension === 'zip' && $realPath && app(WebPageInspector::class)->inspect($realPath)['is_web']) {
                return 'web';
            }
        }

        if ($extension === 'dng' || str_contains($mimeType, 'image')) {
            return 'image';
        } elseif (str_contains($mimeType, 'video')) {
            return 'video';
        } elseif (str_contains($mimeType, 'audio')) {
            return 'audio';
        }

        if (in_array($extension, ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'wma'], true)) {
            return 'audio';
        }

        if (in_array($extension, ['mp4', 'mkv', 'avi', 'mov', 'flv', 'webm', 'wmv'], true)) {
            return 'video';
        }

        return 'document';
    }

    /**
     * The file_type a mime type settles on its own: image, video, audio, or document for a PDF. Null when the mime says
     * nothing reliable (empty, octet-stream, text, archives), so the extension decides. image/tiff is an image here and
     * stays one for a .dng, whose own mime is often just TIFF or unknown.
     */
    public static function confidentFamily(?string $mimeType): ?string
    {
        $mimeType = strtolower(trim((string) $mimeType));

        return match (true) {
            $mimeType === 'application/pdf'       => 'document',
            str_starts_with($mimeType, 'image/')  => 'image',
            str_starts_with($mimeType, 'video/')  => 'video',
            str_starts_with($mimeType, 'audio/')  => 'audio',
            default                               => null,
        };
    }

    public static function getFileTypeUsingExtension(string $extension)
    {
        $extension = strtolower($extension);

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'bmp', 'webp', 'tiff', 'tif', 'jfif', 'dng'];
        $videoExtensions = ['mp4', 'mkv', 'avi', 'mov', 'flv'];
        $audioExtensions = ['mp3', 'wav', 'aac', 'flac'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'];
        $spreadsheetExtensions = ['xls', 'xlsx', 'ods', 'csv'];
        // The formats the browser viewer renders; `model` is an icon bucket like `file` and `sheet`,
        // never a stored file_type (dam_assets.file_type is an enum of image/video/document/audio).
        $modelExtensions = PreviewKinds::MODEL_VIEWER;
        $webExtensions = ['html', 'htm'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        } elseif (in_array($extension, $videoExtensions)) {
            return 'video';
        } elseif (in_array($extension, $audioExtensions)) {
            return 'audio';
        } elseif (in_array($extension, $spreadsheetExtensions)) {
            return 'sheet';
        } elseif (in_array($extension, $documentExtensions)) {
            return 'file';
        } elseif (in_array($extension, $modelExtensions)) {
            return 'model';
        } elseif (PreviewKinds::gameAssetKind($extension) === PreviewKinds::KIND_BLENDER) {
            return 'blender';
        } elseif (PreviewKinds::gameAssetKind($extension) !== null) {
            return 'unity';
        } elseif (in_array($extension, $webExtensions)) {
            return 'web';
        } else {
            return 'unspecified';
        }
    }

    public static function getDisplayFileName(string $fileName): string
    {
        // Character-based: a byte cut through a multibyte name leaves invalid UTF-8 and the JSON response 500s.
        if (mb_strlen($fileName) > 29) {
            $dot = mb_strrpos($fileName, '.');

            $fileName = mb_substr($fileName, 0, 20).'...'.($dot === false ? '' : mb_substr($fileName, $dot));
        }

        return $fileName;
    }

    /**
     * A preview URL for the Library path `$path`: a direct S3 URL of the asset's current original when
     * that shortcut is safe, else the seam-served preview route. `$path` is only the lookup key (ADR
     * 0009) — the direct URL, when built, always names the FileObject's own content-addressed locator,
     * never `$path` itself (a Library-path mirror is not guaranteed to exist once slice 4 lands, and a
     * content-deduplicated asset's bytes may not even live at that path today).
     */
    public static function getPreviewUrl(string $path, ?int $size = null): string
    {
        $previewUrl = route('admin.dam.file.preview', [
            'path' => urlencode($path),
            'size' => $size,
        ]);

        $disk = Directory::getAssetDisk();

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'dng' || $disk !== Directory::ASSETS_DISK_AWS) {
            return $previewUrl;
        }

        $object = self::currentObjectForLibraryPath($path);

        if ($object === null || ! self::isSupportedMediaFile((string) $object->mime_type)) {
            return $previewUrl;
        }

        // A browser cannot decode a HEIC/HEIF, so its preview must pass through the derivative the route makes.
        if (preg_match('#^image/hei[cf]\b#i', (string) $object->mime_type) === 1) {
            return $previewUrl;
        }

        try {
            $store = app(ContentStore::class);

            if (! $store->holdsBlob($object)) {
                return $previewUrl;
            }

            // Signed through the read seam, public bucket or not, with the same type rules as deliver(). It
            // is null for inline content that needs the app's CSP (SVG, PDF): the preview route serves those.
            return $store->temporaryUrl(object: $object, minutes: 10) ?? $previewUrl;
        } catch (\Throwable $exception) {
            return $previewUrl;
        }
    }

    /**
     * The first-page image a PDF's preview card shows, or null for any other asset. Built here rather than
     * in the browser because FileController::thumbnail() urldecodes a path the request already decoded:
     * only urlencode() inside route() survives that, so a `+` or `%` in a file name reaches the handler intact.
     */
    public static function pdfThumbnailUrl(Asset $asset): ?string
    {
        if (! AdobeFormats::isPdfRenderable($asset->extension, $asset->mime_type) || ! $asset->path) {
            return null;
        }

        return route('admin.dam.file.thumbnail', ['path' => urlencode($asset->path)]);
    }

    /**
     * What the preview modal shows for a Blender file or a Unity asset, or null for any other asset: the embedded
     * thumbnail (FileController::thumbnail(), which serves the type placeholder when there is none), the facts
     * GenerateGameAssetThumbnail recorded (gameAssetFacts()), and for Unity text assets the URL of their first
     * 256 KB. Built here for the same reason as pdfThumbnailUrl(): only urlencode() inside route() survives the
     * thumbnail route's second decode.
     *
     * @return array{kind: string, thumbnailUrl: string, textUrl: string|null, read: bool, readable: bool|null, blenderVersion: string|null, counts: array<string, int>, assets: list<array{path: string, has_preview: bool}>, assetCount: int|null, truncated: bool}|null
     */
    public static function gameAssetPreview(Asset $asset): ?array
    {
        $facts = self::gameAssetFacts($asset);

        if ($facts === null || ! $asset->path) {
            return null;
        }

        $path = urlencode($asset->path);

        return [
            'thumbnailUrl' => route('admin.dam.file.thumbnail', ['path' => $path]),
            'textUrl'      => $facts['kind'] === PreviewKinds::KIND_UNITY_YAML
                ? route('admin.dam.file.preview', ['path' => $path, 'text' => 1])
                : null,
        ] + $facts;
    }

    /**
     * The facts GenerateGameAssetThumbnail recorded for a Blender file or Unity asset, with no content URL (the
     * Details info endpoint carries them, so the modal can refresh them once the first thumbnail request has
     * read the file), or null for any other asset.
     *
     * @return array{kind: string, read: bool, readable: bool|null, blenderVersion: string|null, counts: array<string, int>, assets: list<array{path: string, has_preview: bool}>, assetCount: int|null, truncated: bool}|null
     */
    public static function gameAssetFacts(Asset $asset): ?array
    {
        $kind = PreviewKinds::gameAssetKind($asset->extension);

        if ($kind === null) {
            return null;
        }

        $facts = is_array($asset->meta_data['game_asset'] ?? null) ? $asset->meta_data['game_asset'] : [];

        return [
            'kind'           => $kind,
            'read'           => $facts !== [],
            'readable'       => isset($facts['readable']) ? (bool) $facts['readable'] : null,
            'blenderVersion' => $facts['blender_version'] ?? null,
            'counts'         => array_filter(is_array($facts['counts'] ?? null) ? $facts['counts'] : [], fn ($count) => $count > 0),
            'assets'         => is_array($facts['assets'] ?? null) ? $facts['assets'] : [],
            'assetCount'     => $facts['asset_count'] ?? null,
            'truncated'      => (bool) ($facts['truncated'] ?? false),
        ];
    }

    /** The FileObject a Library path's asset currently reads from, or null when there is none. */
    private static function currentObjectForLibraryPath(string $path): ?FileObject
    {
        $asset = Asset::query()->where('path', $path)->first();

        if ($asset === null) {
            return null;
        }

        try {
            return app(ContentStore::class)->current($asset);
        } catch (ContentUnavailable) {
            return null;
        }
    }

    public static function isSupportedMediaFile($mimeType)
    {
        return Str::startsWith($mimeType, 'image/') ||
            Str::startsWith($mimeType, 'application/pdf') ||
            Str::startsWith($mimeType, 'video/') ||
            Str::startsWith($mimeType, 'audio/');
    }

    public static function isInlineSafeMime(?string $mimeType): bool
    {
        $mimeType = strtolower(trim((string) $mimeType));

        if ($mimeType === '') {
            return false;
        }

        if ($mimeType === 'image/svg+xml') {
            return true;
        }

        return Str::startsWith($mimeType, ['image/', 'video/', 'audio/'])
            || $mimeType === 'application/pdf';
    }

    public static function assetResponseHeaders(): array
    {
        return [
            // nginx buffers a FastCGI response to a temp file before sending it, which stalls
            // large media (a 100 MB video was fetched three times and never played, 2026-09-02).
            // This header makes nginx stream the response as PHP produces it; byte ranges still
            // work because the response is a BinaryFileResponse with a known Content-Length.
            'X-Accel-Buffering'       => 'no',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; object-src 'none'; frame-ancestors 'self'",
        ];
    }

    /**
     * `$inspectWebBundles` is false for an attachment (AssetFile): an attached zip is only ever downloaded,
     * never extracted or served as a web page, so the ADR 0007 bundle inspection does not apply to it.
     */
    public static function isForbiddenFile(?string $extension, ?string $mimeType, ?string $fileName = null, ?string $realPath = null, bool $inspectWebBundles = true): bool
    {
        $forbiddenExtensions = [
            'php',
            'phtml',
            'phar',
            'js',
            'py',
            'sh',
            'bat',
            'pl',
            'cgi',
            'asp',
            'aspx',
            'jsp',
            'exe',
            'rb',
            'jar',
            'html',
            'htm',
            'xhtml',
            'shtml',
            'hta',
        ];

        $forbiddenMimeTypes = [
            'application/x-php',
            'application/x-javascript',
            'text/javascript',
            'application/javascript',
            'text/x-python',
            'application/x-sh',
            'application/x-bat',
            'application/x-perl',
            'application/x-cgi',
            'text/x-asp',
            'application/x-aspx',
            'application/x-jsp',
            'application/x-msdownload',
            'application/java-archive',
            'application/x-ruby',
            'text/html',
            'application/xhtml+xml',
            'text/x-php',
            'application/x-httpd-php',
        ];

        $forbiddenFileNames = [
            '.DS_Store',
            '._.DS_Store',
            'Thumbs.db',
            'desktop.ini',
        ];

        if ($extension) {
            $extension = strtolower($extension);
        }

        if ($mimeType) {
            $mimeType = strtolower($mimeType);
        }

        if ($fileName && in_array(basename($fileName), $forbiddenFileNames, true)) {
            return true;
        }

        if (self::isPlaceholderImage($extension, $mimeType, $fileName, $realPath)) {
            return true;
        }

        $forbiddenByList = ($extension && in_array($extension, $forbiddenExtensions, true))
            || ($mimeType && in_array($mimeType, $forbiddenMimeTypes, true));

        if (! $forbiddenByList) {
            return $inspectWebBundles && $extension === 'zip' && $realPath && self::zipIsRejectedWebBundle($realPath);
        }

        // ADR 0007: an .html/.htm upload is a web page, served only by the sandboxed page routes.
        // The exemption is an allow-list, never "absent from the deny-list": a MIME type nobody
        // listed must not buy its way in. When the bytes are readable they decide, because the
        // claimed type comes from the client.
        $webPageExempt = config('dam.web_pages.enabled', true)
            && in_array($extension, ['html', 'htm'], true)
            && (! $mimeType || in_array($mimeType, self::WEB_PAGE_ALLOWED_MIME_TYPES, true))
            && ! ($realPath && self::sniffsAsExecutable($realPath));

        return ! $webPageExempt;
    }

    /**
     * The claimed MIME types an .html upload may carry. Anything else - including a type absent
     * from every list - loses the web page exemption.
     */
    public const WEB_PAGE_ALLOWED_MIME_TYPES = [
        'text/html',
        'text/plain',
        'application/octet-stream',
        'inode/x-empty',
    ];

    /**
     * Read the real bytes and refuse the web page exemption when they are a program rather than
     * markup. A client-declared text/html is worth nothing against `<?php` on disk.
     */
    protected static function sniffsAsExecutable(string $realPath): bool
    {
        if (! is_file($realPath) || ! is_readable($realPath) || ! function_exists('finfo_open')) {
            return false;
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return false;
        }

        // No finfo_close(): it is deprecated as of PHP 8.5 and the object frees itself.
        $detected = strtolower((string) @finfo_file($finfo, $realPath));

        if ($detected === '') {
            return false;
        }

        foreach (['php', 'javascript', 'x-sh', 'x-executable', 'x-msdownload', 'java-archive', 'x-perl', 'x-python', 'x-ruby'] as $needle) {
            if (str_contains($detected, $needle)) {
                return true;
            }
        }

        return str_starts_with($detected, 'application/x-') && $detected !== 'application/x-empty';
    }

    /**
     * A zip that is a web bundle but carries a server/executable entry, an unsafe entry name or
     * more content than the bundle limits allow is rejected whole (422).
     */
    protected static function zipIsRejectedWebBundle(string $realPath): bool
    {
        if (! config('dam.web_pages.enabled', true)) {
            return false;
        }

        $report = app(WebPageInspector::class)->inspect($realPath);

        if (! $report['is_web']) {
            return false;
        }

        if ($report['forbidden_entry'] !== null) {
            WebPageTelemetry::record('web_pages.forbidden_entry', ['entry' => $report['forbidden_entry']]);

            return true;
        }

        if ($report['reason'] !== null) {
            WebPageTelemetry::record('web_pages.forbidden_entry', ['reason' => $report['reason']]);

            return true;
        }

        return false;
    }

    /**
     * Why a zip that is a web bundle was refused, in words the uploader can act on, or null when
     * it is not a refused bundle. isForbiddenFile() only answers yes/no; the upload response uses
     * this so a traversal or an oversized bundle is not reported as "script files".
     */
    public static function webBundleRefusal(?string $extension, ?string $realPath): ?string
    {
        $reason = self::webBundleRefusalReason($extension, $realPath);

        return $reason ? UploadRefusal::message($reason) : null;
    }

    /**
     * Why a web-page bundle is refused, as a structured reason ({code, key, params}) that can be stored and
     * rendered later in the reader's language. Null when the file is not a web bundle or is acceptable.
     *
     * @return array{code: string, key: string, params: array<string, int|string>}|null
     */
    public static function webBundleRefusalReason(?string $extension, ?string $realPath): ?array
    {
        if (strtolower((string) $extension) !== 'zip' || ! $realPath || ! config('dam.web_pages.enabled', true)) {
            return null;
        }

        $report = app(WebPageInspector::class)->inspect($realPath);

        if (! $report['is_web']) {
            return null;
        }

        $reasons = 'dam::app.admin.dam.web-pages.refused';

        return match (true) {
            $report['forbidden_entry'] !== null                                                           => UploadRefusal::make('forbidden_entry', 'dam::app.admin.dam.web-pages.forbidden-entry', ['entry' => (string) $report['forbidden_entry']]),
            $report['reason'] === null                                                                    => null,
            in_array($report['reason'], ['unsafe_path', 'symlink_entry'], true)                           => UploadRefusal::make('unsafe_path', $reasons.'.unsafe-path'),
            in_array($report['reason'], ['too_many_entries', 'zip-too-many-entries'], true)               => UploadRefusal::make('too_many_entries', $reasons.'.too-many-entries', ['limit' => (int) config('dam.web_pages.max_entries', 5000)]),
            in_array($report['reason'], ['entry_too_large', 'too_large', 'zip-contents-too-large'], true) => UploadRefusal::make('too_large', $reasons.'.too-large'),
            $report['reason'] === 'zip-compression-suspicious'                                            => UploadRefusal::make('suspicious_compression', $reasons.'.compression'),
            default                                                                                       => UploadRefusal::make('unreadable', $reasons.'.unreadable'),
        };
    }

    public static function isPlaceholderImage(?string $extension, ?string $mimeType, ?string $fileName = null, ?string $realPath = null): bool
    {
        $isSvg = $extension === 'svg' || $mimeType === 'image/svg+xml';

        if (! $isSvg) {
            return false;
        }

        if ($fileName) {
            $stem = strtolower(pathinfo(basename($fileName), PATHINFO_FILENAME));

            if (Str::startsWith($stem, 'no-records-found')) {
                return true;
            }
        }

        if (! $realPath || ! is_file($realPath)) {
            return false;
        }

        $contents = @file_get_contents($realPath, false, null, 0, 65536);

        if ($contents === false || $contents === '') {
            return false;
        }

        $normalize = fn (string $value): string => strtolower(preg_replace('/\s+/', '', $value));

        $haystack = $normalize($contents);

        if (! str_contains($haystack, '#7c3aec')) {
            return false;
        }

        $pathSignatures = [
            'M44 88H42.908C29.868 88 23.34 88 18.812 84.808',
            'M12 48C12 44.4641 13.4046 41.0731 15.9049 38.5729',
            'M76.9143 80.5715L84.1143 87.7715',
        ];

        foreach ($pathSignatures as $signature) {
            if (str_contains($haystack, $normalize($signature))) {
                return true;
            }
        }

        return false;
    }
}
