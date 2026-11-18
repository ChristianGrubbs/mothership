<?php

declare(strict_types=1);

namespace Mothership\DAM\Support;

use Illuminate\Support\Facades\Cache;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Symfony\Component\Mime\MimeTypes;

/**
 * The name and type one file of a stack goes by. The representative is the entry itself, so it keeps the Library
 * name. Any other file is named by its label, which people write as words ("Brochure PDF (6 pages)"); a label
 * without an extension takes one from the file's stored type, so a download or zip entry opens in the right app.
 * Before this, every file reported the cover's name and downloads had no extension (SETUP-08).
 */
final class StackFileName
{
    /** Types whose registered first extension is not the one people know the file by. */
    private const KNOWN = [
        'image/jpeg'                                             => 'jpg',
        'image/svg+xml'                                          => 'svg',
        'image/tiff'                                             => 'tif',
        'image/x-adobe-dng'                                      => 'dng',
        'image/vnd.adobe.photoshop'                              => 'psd',
        'video/quicktime'                                        => 'mov',
        'audio/mpeg'                                             => 'mp3',
        'text/plain'                                             => 'txt',
        'model/gltf-binary'                                      => 'glb',
        'model/gltf+json'                                        => 'gltf',
        'model/stl'                                              => 'stl',
        'application/vnd.ms-pki.stl'                             => 'stl',
        'model/3mf'                                              => '3mf',
        'application/vnd.ms-package.3dmanufacturing-3dmodel+xml' => '3mf',
    ];

    /** Stored types that only name a container (JSON, zip, XML, text), never the format an app wrote inside it. */
    private const CONTAINER_MIMES = ['application/json', 'application/zip', 'application/x-zip-compressed', 'application/xml', 'text/xml', 'text/plain'];

    /**
     * Text formats an app in the Creative Hub registry opens that have a registered MIME of their own, which the sniffer
     * still reports as plain text or XML (an ASCII DXF or a CSV has no magic bytes; an SVG without an XML declaration
     * reads as XML), so the stored type cannot name them and a label's suffix does: CADCraft's Open with needs `dxf`.
     * A test keeps this list equal to the registry's text formats.
     */
    public const TEXT_FORMATS = ['csv', 'dxf', 'svg'];

    /** Stored types that name only text, which a TEXT_FORMATS suffix names more exactly. */
    private const TEXT_MIMES = ['text/plain', 'text/xml', 'application/xml'];

    /**
     * Stored types that only name a compression wrapper, never the format inside it: Blender 3+ saves a `.blend`
     * zstd-compressed, which the sniffer reports as `application/zstd` (`zst`), so the name says what it is.
     */
    private const COMPRESSED_MIMES = ['application/zstd', 'application/x-zstd', 'application/gzip', 'application/x-gzip', 'application/x-xz', 'application/x-bzip2'];

    /** Stored types that name a zip, which several formats are inside. */
    private const ZIP_MIMES = ['application/zip', 'application/x-zip-compressed'];

    /**
     * Formats that are a zip inside, with a registered MIME of their own that the sniffer may still report as a zip: a
     * Bambu `.3mf` stored as `application/zip` read "ZIP" in the strip. Only these keep the name over a zip; any other
     * suffix with a registered type (`.pdf`) does not.
     */
    private const ZIP_FORMATS = [
        '3mf', 'usdz', 'kmz', 'epub',
        'docx', 'docm', 'dotx', 'dotm', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'pptx', 'pptm', 'potx', 'potm', 'ppsx', 'ppsm',
        'odt', 'ods', 'odp', 'odg', 'odf', 'ott', 'ots', 'otp', 'otg',
    ];

    /** The DNGVersion tag every DNG carries in its first IFD (DNG 1.7 spec, tag 50706). */
    private const DNG_VERSION_TAG = 0xC612;

    /** TIFF field type 1: an unsigned byte. DNGVersion is four of them. */
    private const TIFF_TYPE_BYTE = 1;

    /** A first IFD with more entries than this is not a camera header; the scan stays bounded. */
    private const MAX_IFD_ENTRIES = 1000;

    /** The name a file downloads, zips and lists under. */
    public static function fileName(AssetFile $file, Asset $asset): string
    {
        if ($file->attachment?->is_representative || ! $file->label) {
            return (string) $asset->file_name;
        }

        return self::forLabel(label: (string) $file->label, extension: self::extension($file, $asset));
    }

    /**
     * A label as a file name: verbatim when it ends in any suffix (`.gitignore`, `report.customext`, `archive.001`,
     * `Rev 2.0` are all left as they are), else with the file type's extension. No rule decides what counts as a "real"
     * extension: that would mangle a name someone chose.
     */
    public static function forLabel(string $label, string $extension): string
    {
        return pathinfo($label, PATHINFO_EXTENSION) === '' && $extension !== '' ? "{$label}.{$extension}" : $label;
    }

    /** The file's type as a short lowercase extension (`glb`, `dng`, `pdf`), or '' when nothing names one. */
    public static function extension(AssetFile $file, Asset $asset): string
    {
        $object = $file->fileObject;
        $mime = strtolower(trim(explode(';', (string) $object?->mime_type)[0]));

        if ($object !== null && self::isDng($object)) {
            return 'dng';
        }

        $named = self::labelExtension($file->attachment?->is_representative ? (string) $asset->file_name : (string) $file->label)
            ?? ($file->attachment?->is_representative ? strtolower((string) $asset->extension) ?: null : null);
        $generic = $mime === '' || in_array($mime, ['application/octet-stream', 'binary/octet-stream'], true);

        // The stored type decides; a label is editable text, so its suffix only names a file stored as generic bytes.
        if ($generic) {
            return $named ?? '';
        }

        // An Adobe file whose bytes are what its application saves (Illustrator with PDF compatibility is a PDF to the
        // sniffer) keeps the extension it is named by: the stored type stays PDF, the file stays an .ai (it downloads as one).
        if ($named !== null && AdobeFormats::key($named, $mime) !== null) {
            return $named;
        }

        // An app's own project file (`.fcproj`, `.vectorcraft`) is JSON or a zip to the sniffer and has no registered
        // MIME of its own, so its name is the only thing that says what it is. A suffix with a registered MIME (`.pdf`)
        // is not trusted over the stored type.
        if ($named !== null && in_array($mime, self::CONTAINER_MIMES, true) && MimeTypes::getDefault()->getMimeTypes($named) === []) {
            return $named;
        }

        if ($named !== null && in_array($mime, self::ZIP_MIMES, true) && in_array($named, self::ZIP_FORMATS, true)) {
            return $named;
        }

        if ($named !== null && in_array($mime, self::COMPRESSED_MIMES, true)) {
            return $named;
        }

        if ($named !== null && in_array($mime, self::TEXT_MIMES, true) && in_array($named, self::TEXT_FORMATS, true)) {
            return $named;
        }

        return self::KNOWN[$mime] ?? (MimeTypes::getDefault()->getExtensions($mime)[0] ?? ($named ?? ''));
    }

    /**
     * A DNG, whatever it is called: its stored type names it, or it is a TIFF whose first IFD carries DNGVersion.
     * libmagic reports most DNGs as `image/tiff`, so a stacked RAW file labelled in words was never recognised.
     */
    public static function isDng(FileObject $object): bool
    {
        $mime = strtolower((string) $object->mime_type);

        if (str_contains($mime, 'dng')) {
            return true;
        }

        if ($mime !== 'image/tiff' || ! $object->sha256) {
            return false;
        }

        $key = 'dam-dng-sniff-v3-'.$object->sha256;
        $known = Cache::get($key);

        if (is_bool($known)) {
            return $known;
        }

        // Only an answer read from the bytes is remembered: a store that could not open the blob this time (a
        // missing mount, a timeout) says nothing about the content, so the next request reads again.
        try {
            $stream = app(ContentStore::class)->open($object);
        } catch (\Throwable) {
            return false;
        }

        try {
            $head = fread($stream, 65536);
        } finally {
            fclose($stream);
        }

        if ($head === false) {
            return false;
        }

        $isDng = self::tiffHeadIsDng($head);
        Cache::forever($key, $isDng);

        return $isDng;
    }

    /**
     * Whether the first bytes of a TIFF hold a first IFD with a well-formed DNGVersion entry: the IFD inside the
     * bytes read, every entry up to it complete, and the tag stored as the specification has it (4 BYTEs, major
     * version 1). A stray 0xC612 in a truncated or malformed header is not a DNG.
     */
    public static function tiffHeadIsDng(string $head): bool
    {
        $length = strlen($head);
        $order = substr($head, 0, 2);

        if (($order !== 'II' && $order !== 'MM') || $length < 8) {
            return false;
        }

        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        if (unpack($short, substr($head, 2, 2))[1] !== 42) {
            return false;
        }

        $offset = unpack($long, substr($head, 4, 4))[1];

        if ($offset < 8 || $offset + 2 > $length) {
            return false;
        }

        $count = unpack($short, substr($head, $offset, 2))[1];

        if ($count === 0 || $count > self::MAX_IFD_ENTRIES) {
            return false;
        }

        for ($index = 0; $index < $count; $index++) {
            $entry = $offset + 2 + $index * 12;

            // An entry is 12 bytes: tag, type, count, then the value (inline when it fits in 4 bytes).
            if ($entry + 12 > $length) {
                return false;
            }

            if (unpack($short, substr($head, $entry, 2))[1] !== self::DNG_VERSION_TAG) {
                continue;
            }

            return unpack($short, substr($head, $entry + 2, 2))[1] === self::TIFF_TYPE_BYTE
                && unpack($long, substr($head, $entry + 4, 4))[1] === 4
                && ord($head[$entry + 8]) === 1;
        }

        return false;
    }

    private static function labelExtension(string $name): ?string
    {
        return AssetFile::extensionFromLabel($name);
    }
}
