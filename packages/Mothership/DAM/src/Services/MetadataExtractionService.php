<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\PreviewKinds;

class MetadataExtractionService
{
    private array $strippedExiftoolKeys = [
        'ExifToolVersion'     => true,
        'SourceFile'          => true,
        'Directory'           => true,
        'FilePermissions'     => true,
        'FileAccessDate'      => true,
        'FileInodeChangeDate' => true,
    ];

    /**
     * exiftool's own commentary on the run. Never counts as metadata when deciding
     * whether a non-zero exit salvaged anything.
     */
    private const DIAGNOSTIC_EXIFTOOL_KEYS = [
        'Error'   => true,
        'Warning' => true,
    ];

    /**
     * exiftool diagnostics are free text and routinely quote the file they were pointed
     * at, so they are matched against these markers and reported as a bounded code. The
     * diagnostic itself is never copied anywhere.
     */
    private const EXIFTOOL_REASON_CODES = [
        'unknown file type'  => 'unknown-file-type',
        'unsupported'        => 'unsupported-file-type',
        'file format error'  => 'file-format-error',
        'not a valid'        => 'invalid-file',
        'truncated'          => 'truncated-file',
        'file is empty'      => 'empty-file',
        'error opening file' => 'unreadable-file',
        'file not found'     => 'file-not-found',
    ];

    public function __construct() {}

    /**
     * $extension, when given, decides routing (image/video/audio/model) instead of sniffing
     * $path's own suffix — a content-addressed blob's path carries no reliable extension
     * (ADR 0009), so a caller reading an asset's current original passes the asset's own
     * extension (or MIME-derived one) here.
     */
    public function extractMetadata(string $path, string $disk = 'local', ?string $localPath = null, bool $isPartial = false, ?string $originalFileName = null, ?string $extension = null): array
    {
        if (empty($path) && empty($localPath)) {
            return [];
        }

        $tempPath = $localPath ?: $this->getFileTempPath($path, $disk, $isPartial);
        $originalFileName = $originalFileName ?: ($path !== '' ? basename($path) : '');

        try {
            if (! $tempPath) {
                return [];
            }

            $mime = @mime_content_type($tempPath) ?: '';
            $ext = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
            Log::info(sprintf('MetadataExtractionService: detected mime "%s" for %s', $mime, $path));

            if (AssetHelper::isForbiddenFile($ext, $mime, $originalFileName)) {
                Log::info(sprintf('MetadataExtractionService: skipping forbidden file (ext="%s", mime="%s") for %s', $ext, $mime, $path));

                return [];
            }

            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tif', 'tiff', 'svg', 'heic', 'heif', 'avif'];
            $videoExts = ['mp4', 'mov', 'mkv', 'webm', 'avi', 'wmv', 'flv', 'm4v', '3gp', 'mpg', 'mpeg'];
            $audioExts = ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'wma', 'opus'];
            // PLY is read for model metadata although the Library does not file it under the 3D chip.
            $modelExts = [...PreviewKinds::MODEL_3D, 'ply'];

            if (str_starts_with($mime, 'image/') || in_array($ext, $imageExts, true)) {
                $fullMetadata = $this->extractFullMetadata($path, $disk, $tempPath, $isPartial, $originalFileName);

                if ($this->isErrorResponse($fullMetadata)) {
                    $fullMetadata = [];
                }

                return array_merge($this->handleArrayMetadata($fullMetadata), ['exif' => $fullMetadata]);
            }

            if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')
                || in_array($ext, $videoExts, true) || in_array($ext, $audioExts, true)) {
                return $this->extractMediaMetadata($tempPath, $originalFileName);
            }

            if (str_starts_with($mime, 'model/') || in_array($ext, $modelExts, true)) {
                return $this->extractModelMetadata($tempPath, $originalFileName);
            }

            return $this->extractDocumentMetadata($tempPath, $originalFileName);
        } finally {
            if (! $localPath) {
                $this->cleanupTempFile($tempPath, $disk);
            }
        }
    }

    private function runExiftool(string $tempPath, string $context, ?string $originalFileName = null): array
    {
        if (! $this->exiftoolExists()) {
            return [];
        }

        $command = sprintf(
            'exiftool -j -f -q -charset iptc=utf8 %s',
            escapeshellarg($tempPath)
        );

        $outputLines = [];
        $returnVar = 0;
        exec($command, $outputLines, $returnVar);

        $output = implode("\n", $outputLines);
        $decoded = null;
        $parsed = false;

        if ($output !== '') {
            $decoded = json_decode($output, true);
            $parsed = json_last_error() === JSON_ERROR_NONE;
        }

        $record = $this->metadataRecord($decoded);
        $shapeFailure = $this->shapeFailure($output, $parsed, $record);

        // exiftool exits 1 for a container it has no parser for - GLB and STL are the ones
        // this DAM ingests - yet it still writes the File* system group to stdout as valid
        // JSON and puts the reason in an "Error" key. Keying only on the exit status threw
        // that record away, so every ingested 3D model logged a production.ERROR (249 of
        // them in three days on the deployed runtime, 2026-09-16..19) and stored no metadata
        // at all. A record that survives shape validation and still carries tags is a
        // partial success; everything else is the failure it always was.
        $data = $record === null ? [] : array_diff_key($record, $this->strippedExiftoolKeys);
        $usable = array_diff_key($data, self::DIAGNOSTIC_EXIFTOOL_KEYS);

        if ($returnVar !== 0 && $usable === []) {
            // An empty object, a record holding nothing but SourceFile and exiftool's own
            // Error, or one made up entirely of keys this service already discards, all
            // retain no metadata - downgrading those to an info line would hide the very
            // failure the caller has always been told about.
            $this->logExtractionFailure(
                context: $context,
                tempPath: $tempPath,
                exitCode: $returnVar,
                failure: $shapeFailure ?? 'no-usable-metadata',
                diagnostic: $record['Error'] ?? null,
            );

            return [];
        }

        if ($record === null) {
            $this->logExtractionFailure(
                context: $context,
                tempPath: $tempPath,
                exitCode: $returnVar,
                failure: $shapeFailure ?? 'unparseable-output',
            );

            return [];
        }

        if ($returnVar !== 0) {
            $this->logPartialExtraction($context, $tempPath, $returnVar, $record, count($usable));
        }

        // exiftool's own diagnostic, not asset metadata, and it is already in the log line.
        unset($data['Error']);

        if (! empty($originalFileName) && array_key_exists('FileName', $data)) {
            $data['FileName'] = $originalFileName;
        }

        return $data;
    }

    protected function extractMediaMetadata(string $tempPath, ?string $originalFileName = null): array
    {
        $data = $this->runExiftool($tempPath, 'media', $originalFileName);

        return $data ? array_merge($this->handleArrayMetadata($data), ['exif' => $data]) : [];
    }

    protected function extractDocumentMetadata(string $tempPath, ?string $originalFileName = null): array
    {
        $data = $this->runExiftool($tempPath, 'generic', $originalFileName);

        return $data ? array_merge($this->handleArrayMetadata($data), ['exif' => $data]) : [];
    }

    protected function extractModelMetadata(string $tempPath, ?string $originalFileName = null): array
    {
        $data = $this->runExiftool($tempPath, 'model', $originalFileName);

        return $data ? array_merge($this->handleArrayMetadata($data), ['exif' => $data]) : [];
    }

    /**
     * Record that exiftool recognised no parser for this container but still returned the
     * basic file metadata. Carries the extension only - never the asset path, which is
     * user content.
     */
    private function logPartialExtraction(
        string $context,
        string $tempPath,
        int $exitCode,
        array $record,
        int $usableKeys,
    ): void {
        Log::info(json_encode([
            'ts'     => now()->toIso8601String(),
            'run_id' => (string) Str::uuid(),
            'level'  => 'info',
            'event'  => 'metadata-extraction.partial',
            'loc'    => self::class,
            'ctx'    => [
                'context'   => $context,
                'exit_code' => $exitCode,
                'reason'    => $this->reasonCode($record['Error'] ?? null),
                'file_type' => $this->boundedToken($record['FileType'] ?? null, 16, 'unrecognised'),
                'extension' => $this->boundedToken(pathinfo($tempPath, PATHINFO_EXTENSION), 8, 'none'),
                'kept_keys' => $usableKeys,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * `exiftool -j` writes a JSON array holding one object per file, keyed by tag name.
     * Accepting anything array-shaped let a *list* through - `[["not a metadata record"]]`
     * decodes to a numerically keyed array that array_diff_key against named keys cannot
     * reject, so it scored one usable key and was reported as salvaged metadata. A record
     * is now only a record when the top level is a list and its first entry is a map whose
     * every key is a non-numeric string.
     */
    private function metadataRecord(mixed $decoded): ?array
    {
        if (! is_array($decoded) || ! array_is_list($decoded) || ! array_key_exists(0, $decoded)) {
            return null;
        }

        $record = $decoded[0];

        if (! is_array($record)) {
            return null;
        }

        foreach (array_keys($record) as $key) {
            if (! is_string($key) || is_numeric($key)) {
                return null;
            }
        }

        return $record;
    }

    /**
     * Name the way the output failed to be a record, so the distinct failure classes the
     * caller has always been given survive as machine-readable context. Output that is
     * valid JSON but not a metadata object is a different fault from output that is not
     * JSON at all, and the two want different follow-up.
     */
    private function shapeFailure(string $output, bool $parsed, ?array $record): ?string
    {
        if ($record !== null) {
            return null;
        }

        if ($output === '') {
            return 'empty-output';
        }

        return $parsed ? 'invalid-record-shape' : 'unparseable-output';
    }

    /**
     * Report a failed extraction with bounded context only. The temp path is the asset's
     * own name and exiftool's diagnostic routinely quotes it, so neither is logged: what
     * survives is the extraction context, the exit status, the failure class, a bounded
     * reason code and the extension.
     */
    private function logExtractionFailure(
        string $context,
        string $tempPath,
        int $exitCode,
        string $failure,
        mixed $diagnostic = null,
    ): void {
        Log::error(json_encode([
            'ts'     => now()->toIso8601String(),
            'run_id' => (string) Str::uuid(),
            'level'  => 'error',
            'event'  => 'metadata-extraction.failed',
            'loc'    => self::class,
            'ctx'    => [
                'context'   => $context,
                'exit_code' => $exitCode,
                'failure'   => $failure,
                'reason'    => $this->reasonCode($diagnostic),
                'extension' => $this->boundedToken(pathinfo($tempPath, PATHINFO_EXTENSION), 8, 'none'),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Reduce an exiftool diagnostic to one of a fixed set of codes. The diagnostic can
     * embed the asset path, the uploader's text, or anything else exiftool decided to
     * quote, so it is matched and discarded rather than copied.
     */
    private function reasonCode(mixed $diagnostic): string
    {
        if (! is_string($diagnostic) || trim($diagnostic) === '') {
            return 'unspecified';
        }

        $needle = strtolower($diagnostic);

        foreach (self::EXIFTOOL_REASON_CODES as $marker => $code) {
            if (str_contains($needle, $marker)) {
                return $code;
            }
        }

        return 'other';
    }

    /**
     * Keep a short identifier-shaped value, or say so. Guards the log line against an
     * attacker- or uploader-controlled string arriving through a field that is normally
     * a bounded enumeration.
     */
    private function boundedToken(mixed $value, int $maxLength, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $token = strtolower(trim($value));

        return preg_match('/^[a-z0-9_-]{1,'.$maxLength.'}$/', $token) === 1 ? $token : $fallback;
    }

    protected function isErrorResponse($data): bool
    {
        return is_array($data) && isset($data['success']) && $data['success'] === false;
    }

    protected function handleArrayMetadata(array $metadata): array
    {
        $result = [];

        foreach ($metadata as $key => $value) {
            if (! is_array($value)) {
                $result[$key] = $value;

                continue;
            }

            if (array_is_list($value)) {
                $result[$key] = implode(',', $value);
            } else {
                foreach ($value as $subKey => $subValue) {
                    $result["{$key}:{$subKey}"] = $subValue;
                }
            }
        }

        return $result;
    }

    public function getExifMetadata(string $path, string $disk = 'local', ?string $localPath = null, bool $isPartial = false): array
    {
        try {
            $tempPath = $localPath ?: $this->getFileTempPath($path, $disk, $isPartial);

            if (! $tempPath) {
                return $this->errorResponse("File not found: $path");
            }

            $image = (new ImageManager(new Driver))->decode($tempPath);
            $exif = $image->exif();

            if ($exif && ! is_array($exif)) {
                $exif = $exif->toArray();
            }

            if (is_array($exif) && array_key_exists('ExtensibleMetadataPlatform', $exif)) {
                unset($exif['ExtensibleMetadataPlatform']);
                unset($exif['ImageResourceInformation']);
                unset($exif['ICC_Profile']);
            }

            if (! $localPath) {
                $this->cleanupTempFile($tempPath, $disk);
            }

            return $exif ?: [];
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to read EXIF metadata: '.$e->getMessage());
        }
    }

    public function extractFullMetadata(string $path, string $disk = 'local', ?string $localPath = null, bool $isPartial = false, ?string $originalFileName = null): array
    {
        try {
            $tempPath = $localPath ?: $this->getFileTempPath($path, $disk, $isPartial);

            if (! $tempPath) {
                return $this->errorResponse("File not found: $path");
            }

            $originalFileName = $originalFileName ?: ($path !== '' ? basename($path) : '');

            $data = $this->runExiftool($tempPath, 'image', $originalFileName);

            if (! $localPath) {
                $this->cleanupTempFile($tempPath, $disk);
            }

            if (empty($data)) {
                return $this->errorResponse('ExifTool failed to extract metadata');
            }

            return $this->expandExifGroupAliases($data);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to extract metadata: '.$e->getMessage());
        }
    }

    private function expandExifGroupAliases(array $data): array
    {
        $exifGroups = ['IFD0', 'ExifIFD', 'GPS', 'InteropIFD', 'IPTC', 'XMP', 'Composite'];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'System:')) {
                $fileKey = 'File:'.substr($key, 7);
                if (! isset($data[$fileKey])) {
                    $data[$fileKey] = $value;
                }
            }

            foreach ($exifGroups as $group) {
                if (str_starts_with($key, $group.':')) {
                    $normalizedKey = strtoupper($group).':'.substr($key, strlen($group) + 1);
                    if (! isset($data[$normalizedKey])) {
                        $data[$normalizedKey] = $value;
                    }
                }
            }
        }

        return $data;
    }

    private $curlHandle = null;

    private $s3Disk = null;

    public function getFileTempPath(string $path, string $disk, bool $isPartial = false): ?string
    {
        if ($disk !== 's3') {
            return file_exists($path) ? $path : null;
        }

        $startTime = microtime(true);
        try {
            if (! $this->s3Disk) {
                $this->s3Disk = Storage::disk('s3');
            }

            $url = $this->s3Disk->temporaryUrl($path, now()->addMinutes(10));

            $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'tmp';
            $tempPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'s3img_'.uniqid('', true).'.'.$extension;

            if (! $this->curlHandle) {
                $this->curlHandle = curl_init();
            } else {
                curl_reset($this->curlHandle);
            }

            $fp = fopen($tempPath, 'w+');

            $curlOptions = [
                CURLOPT_URL            => $url,
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $isPartial ? 10 : 0,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_ENCODING       => '',
                CURLOPT_TCP_FASTOPEN   => 1,
                CURLOPT_BUFFERSIZE     => 1048576,
            ];

            if ($isPartial) {
                $curlOptions[CURLOPT_RANGE] = '0-65535';
            }

            curl_setopt_array($this->curlHandle, $curlOptions);
            curl_exec($this->curlHandle);

            $httpCode = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
            fclose($fp);

            if (! in_array($httpCode, [200, 206]) || ! file_exists($tempPath) || filesize($tempPath) === 0) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                return null;
            }

            Log::info(sprintf('Time taken getFileTempPath (%s): %sms', $isPartial ? 'Partial' : 'Full', round((microtime(true) - $startTime) * 1000)));

            return $tempPath;
        } catch (\Exception $e) {
            Log::error('getFileTempPath failed: '.$e->getMessage());

            return null;
        }
    }

    public function exiftoolExists(): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        exec('exiftool -ver 2>/dev/null', $out, $code);

        return $exists = ($code === 0 && ! empty($out));
    }

    public function extractCoverArtData(string $localPath): ?string
    {
        if (! $this->exiftoolExists() || ! file_exists($localPath)) {
            return null;
        }

        $tmpFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dam_cover_'.uniqid('', true).'.tmp';

        try {
            exec(sprintf(
                'exiftool -b -Picture %s > %s 2>/dev/null',
                escapeshellarg($localPath),
                escapeshellarg($tmpFile)
            ));

            if (! file_exists($tmpFile) || filesize($tmpFile) === 0) {
                return null;
            }

            return file_get_contents($tmpFile) ?: null;
        } finally {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Stores cover art as `covers/<asset id>.<ext>`. With `$immutable` it goes to `covers/<asset id>-<hash>.<ext>`, a name
     * that carries its bytes, so publishing one never overwrites the cover a row points at; bytes already held under that
     * name are reused. Either way the path is returned only once the stored size is the size given; a write that failed,
     * stored less, or threw leaves nothing of its own behind.
     */
    public function storeCoverArt(string $imageData, int $assetId, string $disk, bool $immutable = false): ?string
    {
        if (empty($imageData)) {
            return null;
        }

        $sniffFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dam_cover_sniff_'.uniqid('', true).'.tmp';
        file_put_contents($sniffFile, $imageData);
        $mime = @mime_content_type($sniffFile) ?: 'image/jpeg';
        @unlink($sniffFile);

        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $storagePath = $immutable ? CoverArt::path($assetId, $imageData, $ext) : 'covers/'.$assetId.'.'.$ext;
        $temporary = $immutable ? 'covers/.tmp-'.bin2hex(random_bytes(6)).'-'.$assetId : null;

        try {
            $storage = Storage::disk($disk);

            // The name is the bytes' hash: a file of the right size under it is these bytes already.
            if ($immutable && $storage->exists($storagePath) && (int) $storage->size($storagePath) === strlen($imageData)) {
                return $storagePath;
            }

            // An immutable cover is written under a private name, checked, and moved onto its hash name (the same bytes
            // as any file already there). Whatever happens, only that private file is ever removed here.
            $written = $temporary ?? $storagePath;

            // A write that failed or stored less than it was given has no path to report.
            if ($storage->put($written, $imageData) !== true || (int) $storage->size($written) !== strlen($imageData)) {
                Log::error('DAM: cover art for asset '.$assetId.' was not stored completely.');

                return null;
            }

            if ($temporary !== null && $storage->move($temporary, $storagePath) !== true) {
                Log::error('DAM: cover art for asset '.$assetId.' could not be published.');

                return null;
            }

            return $storagePath;
        } catch (\Exception $e) {
            Log::error('DAM: cover art storage failed for asset '.$assetId.': '.$e->getMessage());

            return null;
        } finally {
            if ($temporary !== null) {
                try {
                    Storage::disk($disk)->delete($temporary);
                } catch (\Throwable) {
                }
            }
        }
    }

    protected function cleanupTempFile(?string $tempPath, string $disk): void
    {
        if ($disk === 's3' && $tempPath && file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    protected function errorResponse(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
