<?php

namespace Mothership\DAM\Services;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Support\LocalFile;
use Mothership\DAM\Support\RequestId;
use Mothership\DAM\Support\WritesContentAddressedObjects;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * The one owner of the content-addressed blob store (ADR 0009). Every byte a FileObject names lives at
 * `assets/.objects/<sha[0:2]>/<sha>[.<ext>]`, is written once through a verified temp path, and is never
 * moved or renamed afterwards, so no folder operation can stale a graph locator. Callers hand over bytes
 * (already on the asset disk, or in a local temp file) and get back the FileObject that names them; they
 * never assemble an object path themselves.
 *
 * It is also the one read seam: current() names an asset's bytes, open() streams them, localPath() stages
 * them for a tool that needs a real path, and deliver() answers an HTTP request with them. A read that
 * finds no bytes throws ContentUnavailable and records it to storage/logs/content-store.jsonl.
 */
class ContentStore
{
    use WritesContentAddressedObjects;

    public const ROOT = 'assets/.objects';

    /** Where uploads and writers stage bytes before adoptStored() moves them into a blob (slice 3). */
    public const STAGING = 'assets/.staging';

    public const LOG = 'logs/content-store.jsonl';

    /**
     * The local disk chunked uploads stage on when the asset disk is remote (ADR 0017). An assembled upload
     * stays on it under WARM, named by its sha256, so the first preview jobs read it instead of downloading.
     */
    public const STAGING_DISK = 'mothership-staging';

    public const WARM = 'warm';

    /** localPath() directories for warm copies: on the staging disk, so a hard link reaches the warm file. */
    private const WARM_LINKS = 'local';

    /** How long a delivered remote (S3) URL stays valid. */
    private const TEMPORARY_URL_MINUTES = 5;

    /** Advisory-lock namespace for blobs (DirectoryTreeLock uses 74190001); never reuse it for anything else. */
    public const LOCK_NAMESPACE = 74190002;

    /** The one layout rule. The optional extension is a hint for tools that sniff suffixes, never identity. */
    public function objectPath(string $sha256, ?string $extension = null): string
    {
        $sha256 = $this->normalizeSha($sha256);
        $path = self::ROOT.'/'.substr($sha256, 0, 2).'/'.$sha256;
        $extension = $this->normalizeExtension($extension);

        return $extension === null ? $path : $path.'.'.$extension;
    }

    /** True only for `objectPath($sha256)` with an optional `.<ext>` suffix. */
    public function isObjectPath(?string $path, ?string $sha256): bool
    {
        if (! is_string($path) || ! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return false;
        }

        $base = self::ROOT.'/'.substr($sha256, 0, 2).'/'.$sha256;

        return $path === $base || preg_match('/^'.preg_quote($base, '/').'\.[a-z0-9]{1,16}$/', $path) === 1;
    }

    /**
     * Ingest bytes already on `$disk` at `$sourcePath`. Hashes only when `$sha256` is null, reuses an intact
     * blob, and repoints an existing row whose blob is missing.
     *
     * @param  array<string, mixed>  $attributes  Extra FileObject columns (sniffed_type, technical) for a new row.
     */
    public function putStored(
        string $disk,
        string $sourcePath,
        ?string $sha256 = null,
        ?string $mimeType = null,
        ?string $extension = null,
        array $attributes = [],
    ): FileObject {
        $storage = Storage::disk($disk);
        $size = $this->diskSizeOrNull($disk, $sourcePath);

        if ($size === null) {
            throw new RuntimeException("Content source [{$disk}:{$sourcePath}] is unreadable.");
        }

        $sha256 = $sha256 === null ? $this->checksum($disk, $sourcePath) : $this->normalizeSha($sha256);

        return $this->record(
            disk: $disk,
            sha256: $sha256,
            size: $size,
            mimeType: $mimeType ?? ($storage->mimeType($sourcePath) ?: null),
            extension: $extension,
            attributes: $attributes,
            write: fn (string $objectPath) => $this->ensureStoredObjectWritten(
                disk: $disk,
                objectPath: $objectPath,
                sourcePath: $sourcePath,
                size: $size,
            ),
        );
    }

    /**
     * Ingest a local file (a worker output, an upload temp file, an import). Same contract as putStored().
     *
     * @param  array<string, mixed>  $attributes  Extra FileObject columns (sniffed_type, technical) for a new row.
     */
    public function putLocal(
        string $disk,
        string $localPath,
        ?string $sha256 = null,
        ?string $mimeType = null,
        ?string $extension = null,
        array $attributes = [],
    ): FileObject {
        $size = @filesize($localPath);

        if ($size === false) {
            throw new RuntimeException('source_unreadable');
        }

        if ($sha256 === null) {
            $sha256 = @hash_file('sha256', $localPath);

            if ($sha256 === false) {
                throw new RuntimeException('checksum_failed');
            }
        }

        return $this->record(
            disk: $disk,
            sha256: $this->normalizeSha($sha256),
            size: $size,
            mimeType: $mimeType,
            extension: $extension,
            attributes: $attributes,
            write: fn (string $objectPath) => $this->ensureObjectWritten($disk, $objectPath, $localPath, $size),
        );
    }

    /**
     * Ingest bytes already on `$disk` at `$sourcePath` by moving them, not copying: the upload and staging
     * path (ADR 0009 slice 3). On a local disk the source is renamed into place, O(1) for any size; remote
     * disks fall back to Flysystem's copy+delete. The source is always consumed on success, including when
     * an intact blob for the same bytes already exists. On failure it is moved back where possible, so the
     * caller's own cleanup still finds it.
     *
     * `$sourceDisk` names a different, local disk the source sits on: an upload staged locally for a remote
     * asset disk (ADR 0017). Its bytes are streamed once to the blob's key. Then the source becomes the
     * blob's warm copy if it is on STAGING_DISK, and is deleted otherwise.
     *
     * @param  array<string, mixed>  $attributes  Extra FileObject columns (sniffed_type, technical) for a new row.
     */
    public function adoptStored(
        string $disk,
        string $sourcePath,
        ?string $sha256 = null,
        ?string $mimeType = null,
        ?string $extension = null,
        array $attributes = [],
        ?string $sourceDisk = null,
    ): FileObject {
        $sourceDisk ??= $disk;
        $source = Storage::disk($sourceDisk);
        $crossDisk = $sourceDisk !== $disk;

        if ($crossDisk && ! $this->isLocalDisk($source)) {
            throw new InvalidArgumentException("Content source disk [{$sourceDisk}] must be local to adopt across disks.");
        }

        $size = $this->diskSizeOrNull($sourceDisk, $sourcePath);

        if ($size === null) {
            throw new RuntimeException("Content source [{$sourceDisk}:{$sourcePath}] is unreadable.");
        }

        $sha256 = $sha256 === null ? $this->checksum($sourceDisk, $sourcePath) : $this->normalizeSha($sha256);

        $object = $this->record(
            disk: $disk,
            sha256: $sha256,
            size: $size,
            mimeType: $mimeType ?? ($source->mimeType($sourcePath) ?: null),
            extension: $extension,
            attributes: $attributes,
            write: $crossDisk
                ? fn (string $objectPath) => $this->ensureObjectWritten(
                    disk: $disk,
                    objectPath: $objectPath,
                    localPath: $source->path($sourcePath),
                    localSize: $size,
                )
                : fn (string $objectPath) => $this->moveStoredObjectIntoPlace(
                    disk: $disk,
                    objectPath: $objectPath,
                    sourcePath: $sourcePath,
                    size: $size,
                ),
        );

        if (! $source->exists($sourcePath)) {
            return $object;
        }

        // Cross-disk, the source is the only local copy of what the first preview jobs are about to read.
        // Otherwise an intact blob already held these bytes (record() skipped the write): the duplicate goes.
        if ($crossDisk && $sourceDisk === self::STAGING_DISK) {
            $this->keepWarm(sourcePath: $sourcePath, sha256: $sha256);
        } else {
            $this->deleteQuietly($sourceDisk, $sourcePath);
        }

        return $object;
    }

    /**
     * The disk chunked uploads stage on: the asset disk itself when it is local (parts and the assembly are
     * renamed into place, as before), else the local STAGING_DISK, so parts never cross the network and the
     * assembly crosses it once (ADR 0017). Read from the disk's configured driver, so no disk is built per chunk.
     */
    public function uploadStagingDisk(string $assetDisk): string
    {
        return config("filesystems.disks.{$assetDisk}.driver") === 'local' ? $assetDisk : self::STAGING_DISK;
    }

    /**
     * Delete warm copies older than `dam.staging.keep_hours`, and localPath() directories a killed job left on
     * the staging disk a day or more ago. Eviction is by age: a job that runs later downloads the blob, as it
     * did before warm copies existed. Returns how many warm copies went.
     */
    public function sweepWarm(?int $olderThanHours = null): int
    {
        if (config('filesystems.disks.'.self::STAGING_DISK) === null) {
            return 0;
        }

        $storage = Storage::disk(self::STAGING_DISK);
        $olderThanHours ??= (int) config('dam.staging.keep_hours', 6);
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();
        $swept = 0;

        foreach ($storage->files(self::WARM) as $path) {
            if ($storage->lastModified($path) < $cutoff && $storage->delete($path)) {
                $swept++;
            }
        }

        $linkCutoff = now()->subHours(max($olderThanHours, 24))->getTimestamp();

        foreach ($storage->directories(self::WARM_LINKS) as $directory) {
            if ((int) @filemtime($storage->path($directory)) < $linkCutoff) {
                $storage->deleteDirectory($directory);
            }
        }

        if ($swept > 0) {
            $this->log('info', 'warm.swept', 'ContentStore::sweepWarm', ['swept' => $swept, 'older_than_hours' => $olderThanHours]);
        }

        return $swept;
    }

    /** A private staging path on the asset disk, outside every folder (names starting with `.` are reserved). */
    public function stagingPath(?string $extension = null): string
    {
        // A client's extension (a chunked upload's is unvalidated) only decorates the name: drop a bad one.
        $extension = $this->stagingExtension($extension);
        $path = self::STAGING.'/'.Str::uuid();

        return $extension === null ? $path : $path.'.'.$extension;
    }

    /**
     * A fresh staging path on `$disk`, already open for writing (chunk-upload finalize, 2026-09-26): a
     * writer that wants to fill the eventual staging file directly, instead of assembling somewhere else
     * first and copying it in afterward. Ensures `assets/.staging` exists, then opens a new staging path
     * exclusively (`'xb'`), so two callers can never collide on the same generated name. Null on a disk
     * that is not local (S3): the caller keeps assembling into its own temp file and stages it afterward
     * with writeStream(), same as before this existed.
     *
     * @return array{path: string, local_path: string, handle: resource}|null
     */
    public function openLocalStaging(string $disk, ?string $extension = null): ?array
    {
        $storage = Storage::disk($disk);

        if (! $this->isLocalDisk($storage)) {
            return null;
        }

        if (! $storage->exists(self::STAGING)) {
            $storage->makeDirectory(self::STAGING);
        }

        $path = $this->stagingPath($extension);
        $localPath = $storage->path($path);
        $handle = @fopen($localPath, 'xb');

        if (! is_resource($handle)) {
            throw new RuntimeException('local_staging_open_failed');
        }

        return ['path' => $path, 'local_path' => $localPath, 'handle' => $handle];
    }

    /**
     * Delete staging files older than `$olderThanHours`: what a writer killed between staging and
     * adoptStored() leaves behind. Writers delete their own staging file on every handled failure, and a
     * finalize never runs for hours, so an old file there is always abandoned. Returns how many went.
     */
    public function sweepStaging(string $disk, int $olderThanHours = 24): int
    {
        $storage = Storage::disk($disk);
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();
        $swept = 0;

        foreach ($storage->files(self::STAGING) as $path) {
            if ($storage->lastModified($path) < $cutoff && $storage->delete($path)) {
                $swept++;
            }
        }

        if ($swept > 0) {
            $this->log('info', 'staging.swept', 'ContentStore::sweepStaging', ['disk' => $disk, 'swept' => $swept, 'older_than_hours' => $olderThanHours]);
        }

        return $swept;
    }

    /** Whether uploads can be staged on `$disk`: the one writability check left once folders are metadata. */
    public function canStage(string $disk): bool
    {
        $storage = Storage::disk($disk);

        if (! $this->isLocalDisk($storage)) {
            return true;
        }

        if (! $storage->exists(self::STAGING)) {
            $storage->makeDirectory(self::STAGING);
        }

        return is_writable($storage->path(self::STAGING));
    }

    /**
     * Under the blob lock, the live row for `$object`'s bytes. A writer ingests in its own transaction and
     * attaches later, so a concurrent delete of another asset holding the same bytes can reap the row in
     * between. While the blob is intact the row is recreated, and the reaper, which unlinks only when no
     * row names the path under this same lock, then keeps it. Call inside the transaction that attaches.
     *
     * @throws ContentUnavailable blob_missing when the bytes are already gone
     */
    public function retain(FileObject $object): FileObject
    {
        $sha256 = $this->normalizeSha((string) $object->sha256);
        $this->lockBlob($sha256);

        // A locking read, ordered against AssetDeletionService::delete(), which holds this row FOR UPDATE until
        // its transaction ends. Lock order is asset row, blob (above), then this row; the deleter never waits for
        // a blob while it holds the row, because its tombstones are reaped only after it commits.
        // - Deleter first: this read waits for its commit, then finds no row (PostgreSQL and InnoDB skip a row a
        //   locking read finds deleted). The blob is still there, because the reaper unlinks only under the blob
        //   lock this transaction holds, so the row is recreated below. The reaper then finds it and keeps the blob.
        //   (A reaper that took the blob lock before this transaction did has already unlinked: blob_missing below.)
        // - Retain first: this transaction holds the row (KEY SHARE, as the attachment's foreign key check would)
        //   until it commits. The deleter's FOR UPDATE waits, then counts the new attachment, so it neither deletes
        //   the row nor tombstones the blob.
        // A plain read returned the committed row the deleter was about to remove: the attachment's foreign key
        // check then waited for that delete and failed.
        $query = FileObject::query()->where('sha256', $sha256);
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->lock('for key share');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $query->sharedLock();
        }

        $live = $query->first();

        if ($live !== null) {
            return $live;
        }

        if (! $this->isObjectPath($object->path, $sha256)
            || ! $this->objectIsIntact((string) $object->disk, (string) $object->path, (int) $object->size)) {
            throw $this->unavailable('blob_missing', 'ContentStore::retain', $object);
        }

        $this->log('warning', 'file_object.retained', 'ContentStore::retain', ['sha256' => $sha256, 'path' => $object->path]);

        return FileObject::query()->create(array_merge(
            Arr::except($object->getAttributes(), ['id', 'created_at', 'updated_at']),
            ['sha256' => $sha256],
        ));
    }

    /** Whether `$object` names an intact blob: a content-addressed locator holding exactly `$object->size` bytes. */
    public function holdsBlob(FileObject $object): bool
    {
        return $this->isObjectPath($object->path, $object->sha256)
            && $this->objectIsIntact((string) $object->disk, (string) $object->path, (int) $object->size);
    }

    /**
     * Hold, until the surrounding transaction ends, the lock that orders writing a row for this blob against
     * unlinking it. record() writes the blob and its FileObject row under it; tombstone cleanup takes it for
     * one blob at a time and unlinks only when no row names the path. A re-ingest therefore either commits its
     * row before cleanup looks, or waits for the unlink and writes the blob again.
     *
     * Lock order: an asset row before a blob, never two blobs while waiting on a third party (cleanup holds
     * one at a time; the only multi-blob writer, GenerationPackageImporter, is serialized by its own lock).
     * PostgreSQL only: a transaction-scoped advisory lock, released on commit or rollback. MySQL (an advisory
     * target) and SQLite take none.
     */
    public function lockBlob(string $sha256): void
    {
        $sha256 = $this->normalizeSha($sha256);
        $connection = DB::connection();

        if ($connection->getDriverName() === 'pgsql') {
            // The first 31 bits of the digest as the int4 key; a collision only serializes two blobs.
            $connection->statement('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, hexdec(substr($sha256, 0, 8)) & 0x7FFFFFFF]);
        }
    }

    /**
     * The asset's current bytes: the FileObject of its `original` attachment (the observer keeps at most
     * one). Uses the asset's loaded `files` relation when there is one.
     *
     * @throws ContentUnavailable no_original when the asset has no original with a FileObject
     */
    public function current(Asset $asset): FileObject
    {
        $original = $asset->relationLoaded('files')
            ? $asset->files->firstWhere('role', AssetFile::ROLE_ORIGINAL)
            : $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')->orderBy('id')->first();

        $object = $original?->fileObject;

        if (! $object instanceof FileObject) {
            throw $this->unavailable(reason: ContentUnavailable::NO_ORIGINAL, loc: 'current', assetId: (int) $asset->getKey());
        }

        return $object;
    }

    /**
     * The same cheap check a read makes (own locator, present, recorded size), for a caller that must
     * surface a missing blob without reading it, e.g. a job whose source selection skipped the original.
     *
     * @throws ContentUnavailable blob_missing, recorded like any other unavailable read
     */
    public function ensureAvailable(FileObject $object): FileObject
    {
        $this->assertAvailable($object, 'ensureAvailable');

        return $object;
    }

    /**
     * A read stream over the blob's bytes; the caller closes it. Checks presence and size only (a stat),
     * never the checksum.
     *
     * @return resource
     *
     * @throws ContentUnavailable blob_missing or blob_unreadable
     */
    public function open(FileObject $object)
    {
        $this->assertAvailable($object, 'open');

        try {
            $stream = Storage::disk((string) $object->disk)->readStream((string) $object->path);
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            throw $this->unavailable(reason: ContentUnavailable::BLOB_UNREADABLE, loc: 'open', object: $object);
        }

        return $stream;
    }

    /**
     * A real filesystem path to the blob's bytes whose basename is `source.<extension>`. The extension comes
     * from the caller (the asset's extension column, AssetFile::extension() or the MIME type), never from the
     * blob's name, because one blob can back assets of different types. A local disk gets a symlink to the
     * blob in a private temp directory (no copy); any other disk streams a copy there. Release it when the
     * tool is done (or let it go out of scope). `$under` names an existing directory to stage in instead of the
     * system temp directory, and `$copy` always stages a copy, never a symlink, so nothing a tool does to the staged
     * file can reach the blob (batch recipes, P6a, keep all their work under storage and originals byte-identical).
     *
     * @throws ContentUnavailable blob_missing or blob_unreadable
     */
    public function localPath(FileObject $object, ?string $extension = null, ?string $under = null, bool $copy = false): LocalFile
    {
        $this->assertAvailable($object, 'localPath');

        $storage = Storage::disk((string) $object->disk);
        $warm = $this->isLocalDisk($storage) ? null : $this->warmCopy($object);

        if ($warm !== null && $under === null) {
            // Beside the warm copy, so the hard link below stays on one filesystem.
            $under = $this->warmLinkRoot();
        }

        $directory = rtrim($under ?? sys_get_temp_dir(), '/').'/mothership-content-'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('local_staging_failed');
        }

        $extension = $this->stagingExtension($extension);
        $file = new LocalFile(path: $directory.'/source'.($extension === null ? '' : '.'.$extension), directory: $directory);

        if ($warm !== null && $this->stageWarm(warm: $warm, file: $file, copy: $copy, size: (int) $object->size)) {
            $this->log('debug', 'content_staged', 'localPath', ['file_object_id' => $object->getKey(), 'mode' => 'warm'], verbose: true);

            return $file;
        }

        if (! $copy && $this->isLocalDisk($storage)) {
            $blob = $storage->path((string) $object->path);

            if (@symlink($blob, $file->path)) {
                $this->log('debug', 'content_staged', 'localPath', ['file_object_id' => $object->getKey(), 'mode' => 'symlink'], verbose: true);

                return $file;
            }
        }

        $this->download($object, $file);
        $this->log('debug', 'content_staged', 'localPath', ['file_object_id' => $object->getKey(), 'mode' => 'copy'], verbose: true);

        return $file;
    }

    /**
     * Answer an HTTP request with the blob's bytes. Always carries AssetHelper::assetResponseHeaders();
     * Content-Type is `$mimeType`, else the FileObject's, else application/octet-stream; a MIME that is not
     * inline-safe is always an attachment; Content-Disposition names `$filename` (the asset's file_name) with
     * an ASCII fallback. A local disk goes to nginx with X-Accel-Redirect when an accel prefix is configured
     * (the private disk only, which is what the internal `/__dam_media/` location aliases), else a ranged file
     * response; a disk with temporary URLs (S3) redirects to one carrying the same type and disposition, but
     * only for attachments and inert inline media (raster images, video, audio): anything else inline (SVG,
     * PDF) streams through open() with the security headers, because the object store cannot send the CSP.
     * Any other disk streams too. A redirected blob is not probed first: its store answers 404 itself.
     * With `dam.content_store.remote_accel_prefix` set, a blob that would be redirected goes to nginx with
     * X-Accel-Redirect instead, same-origin callers included (ADR 0017): nginx fetches the signed URL itself.
     * `$sameOrigin` never redirects: a caller whose page draws the bytes onto a canvas and exports it (the
     * layered image editor) needs them from the app's own origin, or the browser taints the canvas.
     *
     * @param  array<string, string>  $headers  Extra headers; they never replace Content-Type or Content-Disposition.
     *
     * @throws ContentUnavailable blob_missing when a local blob is absent or the wrong size
     */
    public function deliver(
        FileObject $object,
        string $filename,
        string $disposition = HeaderUtils::DISPOSITION_INLINE,
        ?string $mimeType = null,
        array $headers = [],
        bool $sameOrigin = false,
    ): Response {
        $mimeType = trim((string) ($mimeType ?: $object->mime_type)) ?: 'application/octet-stream';

        if ($disposition !== HeaderUtils::DISPOSITION_INLINE || ! AssetHelper::isInlineSafeMime($mimeType)) {
            $disposition = HeaderUtils::DISPOSITION_ATTACHMENT;
        }

        $contentDisposition = $this->contentDisposition($disposition, $filename);
        $headers = array_merge(AssetHelper::assetResponseHeaders(), $headers, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => $contentDisposition,
        ]);
        $disk = (string) $object->disk;
        $storage = Storage::disk($disk);

        if ($this->isLocalDisk($storage)) {
            $this->assertAvailable($object, 'deliver');
            $prefix = config('mothership.media_accel_prefix');

            if (! blank($prefix) && $disk === Directory::ASSETS_DISK_PRIVATE) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim((string) $object->path, '/'))));
                $headers['X-Accel-Redirect'] = rtrim((string) $prefix, '/').'/'.$encoded;
                unset($headers['X-Accel-Buffering']);
                $this->log('debug', 'content_delivered', 'deliver', ['file_object_id' => $object->getKey(), 'mode' => 'accel'], verbose: true);

                return new Response('', 200, $headers);
            }

            $this->log('debug', 'content_delivered', 'deliver', ['file_object_id' => $object->getKey(), 'mode' => 'file'], verbose: true);

            $response = new BinaryFileResponse($storage->path((string) $object->path), 200, $headers, false, null, true, true);

            // ETag and Last-Modified are set above; answer a matching conditional request with a 304 here, as the
            // accel path gets from nginx, so a browser revalidating the same bytes never downloads them again.
            $response->isNotModified(request());

            return $response;
        }

        $accel = $this->remoteAccelRedirect(object: $object, filename: $filename, disposition: $disposition, mimeType: $mimeType);

        if ($accel !== null) {
            $headers['X-Accel-Redirect'] = $accel;
            unset($headers['X-Accel-Buffering']);
            $this->log('debug', 'content_delivered', 'deliver', ['file_object_id' => $object->getKey(), 'mode' => 'remote_accel'], verbose: true);

            return new Response('', 200, $headers);
        }

        $url = $sameOrigin ? null : $this->temporaryUrl(object: $object, filename: $filename, disposition: $disposition, mimeType: $mimeType);

        if ($url !== null) {
            $this->log('debug', 'content_delivered', 'deliver', ['file_object_id' => $object->getKey(), 'mode' => 'temporary_url'], verbose: true);

            return new RedirectResponse($url);
        }

        $stream = $this->open($object);
        $headers['Content-Length'] = (string) $object->size;
        $this->log('debug', 'content_delivered', 'deliver', ['file_object_id' => $object->getKey(), 'mode' => 'stream'], verbose: true);

        return new StreamedResponse(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, $headers);
    }

    /**
     * Answer a request with a file the app wrote to a remote asset disk itself (a derivative, custom thumbnail or
     * cover), which no FileObject names. With `dam.content_store.remote_accel_prefix` set, nginx fetches a signed URL
     * (ADR 0017) because the browser may not reach the store; a key nginx cannot carry unchanged, or content needing
     * the app's CSP, streams through PHP. Without the prefix the browser is redirected to the store.
     *
     * Object ACLs are optional: S3-compatible stores such as Garage do not implement GetObjectAcl.
     *
     * @throws NotFoundHttpException when the file is not on the disk
     */
    public function deliverStoredFile(string $disk, string $path, ?string $mimeType = null): Response
    {
        $storage = Storage::disk($disk);

        if (blank(config('dam.content_store.remote_accel_prefix'))) {
            return redirect()->away($this->storedFileUrl(storage: $storage, path: $path));
        }

        if (! $storage->exists($path)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = trim((string) $mimeType) ?: (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream');
        $headers = array_merge(AssetHelper::assetResponseHeaders(), ['Content-Type' => $mimeType]);

        if ($this->isAccelSafeKey($path) && ! $this->needsApplicationHeaders(disposition: HeaderUtils::DISPOSITION_INLINE, mimeType: $mimeType)) {
            $url = $storage->temporaryUrl($path, now()->addMinutes(self::TEMPORARY_URL_MINUTES), ['ResponseContentType' => $mimeType]);
            $accel = $this->remoteAccelTarget($url);

            if ($accel !== null) {
                $headers['X-Accel-Redirect'] = $accel;
                unset($headers['X-Accel-Buffering']);
                $this->log('debug', 'stored_file_delivered', 'deliverStoredFile', ['path' => $path, 'mode' => 'remote_accel'], verbose: true);

                return new Response('', 200, $headers);
            }
        }

        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            abort(404);
        }

        $this->log('debug', 'stored_file_delivered', 'deliverStoredFile', ['path' => $path, 'mode' => 'stream'], verbose: true);

        return new StreamedResponse(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, $headers);
    }

    /**
     * Where the browser fetches a stored file from when nginx does not proxy the store. A store that refuses the ACL
     * lookup gets a signed URL; the same lookup also fails for a missing object, which is a 404.
     */
    private function storedFileUrl(mixed $storage, string $path): string
    {
        try {
            if ($storage->getVisibility($path) === 'public') {
                return $storage->url($path);
            }
        } catch (UnableToRetrieveMetadata) {
            if (! $storage->exists($path)) {
                abort(404);
            }
        }

        return $storage->temporaryUrl($path, now()->addMinutes(self::TEMPORARY_URL_MINUTES));
    }

    /** nginx matches the decoded URI, so only a key that encoding leaves unchanged still matches its signature. */
    private function isAccelSafeKey(string $path): bool
    {
        return preg_match('#^[A-Za-z0-9._/-]+$#D', $path) === 1 && ! str_contains($path, '..');
    }

    /**
     * A short-lived direct URL to the blob on a disk that issues them (S3), carrying the same Content-Type
     * and Content-Disposition rules as deliver(); null on a disk that does not (local), and null for inline
     * content that needs the app's CSP (SVG, PDF: anything but raster images, video and audio), which callers
     * must serve through an application route instead. The blob is not probed: the remote store answers 404.
     *
     * @throws ContentUnavailable blob_missing when the row names no content-addressed locator
     */
    public function temporaryUrl(
        FileObject $object,
        ?string $filename = null,
        string $disposition = HeaderUtils::DISPOSITION_INLINE,
        ?string $mimeType = null,
        int $minutes = self::TEMPORARY_URL_MINUTES,
    ): ?string {
        $storage = Storage::disk((string) $object->disk);

        if (! $storage instanceof FilesystemAdapter || ! $storage->providesTemporaryUrls() || $this->isLocalDisk($storage)) {
            return null;
        }

        if (! $this->isObjectPath($object->path, $object->sha256)) {
            throw $this->unavailable(reason: ContentUnavailable::BLOB_MISSING, loc: 'temporaryUrl', object: $object);
        }

        $mimeType = trim((string) ($mimeType ?: $object->mime_type)) ?: 'application/octet-stream';

        if ($disposition !== HeaderUtils::DISPOSITION_INLINE || ! AssetHelper::isInlineSafeMime($mimeType)) {
            $disposition = HeaderUtils::DISPOSITION_ATTACHMENT;
        }

        // The object store cannot carry the app's CSP and security headers, so content a browser could
        // execute inline (SVG, PDF) never gets a direct URL: it must go through the application.
        if ($this->needsApplicationHeaders(disposition: $disposition, mimeType: $mimeType)) {
            return null;
        }

        $options = ['ResponseContentType' => $mimeType];

        if ($filename !== null) {
            $options['ResponseContentDisposition'] = $this->contentDisposition($disposition, $filename);
        }

        return $storage->temporaryUrl((string) $object->path, now()->addMinutes($minutes), $options);
    }

    /** The sha256 an object path names, or null for any other path. */
    public function objectSha(string $path): ?string
    {
        $sha256 = explode('.', basename($path), 2)[0];

        return $this->isObjectPath($path, $sha256) ? $sha256 : null;
    }

    /** Stream-hash bytes already on a disk. */
    public function checksum(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to open stream for [{$disk}:{$path}].");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Closure(string): void  $write
     */
    private function record(
        string $disk,
        string $sha256,
        int $size,
        ?string $mimeType,
        ?string $extension,
        array $attributes,
        Closure $write,
    ): FileObject {
        // Own transaction (a savepoint inside a caller's), so the blob lock covers the lookup, the write and
        // the row until that row commits; see lockBlob().
        return DB::transaction(function () use ($disk, $sha256, $size, $mimeType, $extension, $attributes, $write): FileObject {
            $this->lockBlob($sha256);

            $existing = FileObject::query()->where('sha256', $sha256)->first();

            // An intact blob is authoritative for its sha256: never rewrite it from a source that may
            // already hold other bytes (a caller-supplied checksum can describe an earlier read).
            if ($existing !== null && $this->holdsBlob($existing)) {
                return $existing;
            }

            $objectPath = $this->objectPath($sha256, $extension);
            $write($objectPath);

            $object = FileObject::query()->firstOrCreate(['sha256' => $sha256], array_merge($attributes, [
                'size'      => $size,
                'disk'      => $disk,
                'path'      => $objectPath,
                'mime_type' => $mimeType,
            ]));

            if (! $object->wasRecentlyCreated && ((int) $object->size !== $size || ! $this->holdsBlob($object))) {
                // The row predates this blob (missing bytes or a legacy locator): point it at the verified copy.
                $object->forceFill(['disk' => $disk, 'path' => $objectPath, 'size' => $size])->save();
            }

            return $object;
        });
    }

    /**
     * The on-disk sibling of ensureObjectWritten(): copy within one disk to a private temp path, verify the
     * size, then move into place, so an interrupted copy can never masquerade as an intact blob.
     */
    private function ensureStoredObjectWritten(string $disk, string $objectPath, string $sourcePath, int $size): void
    {
        if ($this->objectIsIntact($disk, $objectPath, $size)) {
            return;
        }

        $storage = Storage::disk($disk);

        if (! $this->isLocalDisk($storage)) {
            // An object store's server-side copy shows the key only once complete (ADR 0017): no temp key.
            if (! $storage->copy($sourcePath, $objectPath)) {
                throw new RuntimeException('object_write_failed');
            }

            $this->assertPlacedRemoteObject($disk, $objectPath, $size);

            return;
        }

        $tempPath = $objectPath.'.uploading-'.bin2hex(random_bytes(8)).'.tmp';

        try {
            if (! $storage->copy($sourcePath, $tempPath)) {
                throw new RuntimeException('object_write_failed');
            }

            if ($this->diskSizeOrNull($disk, $tempPath) !== $size) {
                throw new RuntimeException('object_write_incomplete');
            }

            if (! $storage->move($tempPath, $objectPath)) {
                throw new RuntimeException('object_move_failed');
            }
        } catch (Throwable $e) {
            $this->deleteQuietly($disk, $tempPath);

            throw $e;
        }
    }

    /**
     * adoptStored()'s writer: move the source to a private temp path beside the blob, verify its size, then
     * move it into place. Both moves are renames on a local disk. On failure the bytes go back to the source.
     * An object store copies straight to the blob's key and verifies it (ADR 0017). Its move is a copy plus a
     * delete of the source, which would come before the check, so the source stays until adoptStored() deletes
     * it after a verified write, and a failed write leaves it for the caller's retry.
     */
    private function moveStoredObjectIntoPlace(string $disk, string $objectPath, string $sourcePath, int $size): void
    {
        if ($this->objectIsIntact($disk, $objectPath, $size)) {
            return;
        }

        $storage = Storage::disk($disk);

        if (! $this->isLocalDisk($storage)) {
            if (! $storage->copy($sourcePath, $objectPath)) {
                throw new RuntimeException('object_write_failed');
            }

            $this->assertPlacedRemoteObject($disk, $objectPath, $size);

            return;
        }

        $tempPath = $objectPath.'.uploading-'.bin2hex(random_bytes(8)).'.tmp';

        if (! $storage->move($sourcePath, $tempPath)) {
            throw new RuntimeException('object_move_failed');
        }

        try {
            if ($this->diskSizeOrNull($disk, $tempPath) !== $size) {
                throw new RuntimeException('object_write_incomplete');
            }

            if (! $storage->move($tempPath, $objectPath)) {
                throw new RuntimeException('object_move_failed');
            }
        } catch (Throwable $e) {
            try {
                if ($storage->exists($tempPath) && ! $storage->exists($sourcePath)) {
                    $storage->move($tempPath, $sourcePath);
                }
            } catch (Throwable) {
                // The original exception is what the caller needs.
            }

            if ($storage->exists($sourcePath)) {
                $this->deleteQuietly($disk, $tempPath);
            } else {
                // Never delete the only copy: leave it visible for an operator rather than lose the bytes.
                $this->log('error', 'adopt.stranded', 'ContentStore::moveStoredObjectIntoPlace', ['temp' => $tempPath, 'source' => $sourcePath]);
            }

            throw $e;
        }
    }

    /**
     * A read needs the row's own blob (an unrepaired legacy locator names none) holding exactly its size.
     * One stat on a local disk, never a hash.
     */
    private function assertAvailable(FileObject $object, string $loc): void
    {
        if (! $this->holdsBlob($object)) {
            throw $this->unavailable(reason: ContentUnavailable::BLOB_MISSING, loc: $loc, object: $object);
        }
    }

    private function unavailable(string $reason, string $loc, ?FileObject $object = null, ?int $assetId = null): ContentUnavailable
    {
        $objectId = $object?->getKey();
        $exception = new ContentUnavailable(reason: $reason, fileObjectId: $objectId === null ? null : (int) $objectId, assetId: $assetId);

        $this->log('warning', 'content_unavailable', $loc, array_filter([
            'reason'         => $reason,
            'file_object_id' => $exception->fileObjectId,
            'asset_id'       => $assetId,
            'disk'           => $object?->disk,
        ], fn ($value) => $value !== null));

        return $exception;
    }

    /**
     * One JSONL record (ts, run_id, level, event, loc, ctx). Unavailable content is always recorded; the
     * per-read debug records need DAM_CONTENT_STORE_VERBOSE_LOG. The context carries ids, never paths.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function log(string $level, string $event, string $loc, array $ctx, bool $verbose = false): void
    {
        if ($verbose && ! config('dam.content_store.verbose_log')) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();
        $runId = $request?->attributes->get('content_store_run_id') ?? (string) Str::uuid();
        $request?->attributes->set('content_store_run_id', $runId);

        try {
            $line = json_encode([
                'ts'     => now()->toIso8601String(),
                'run_id' => $runId,
                'level'  => $level,
                'event'  => $event,
                'loc'    => self::class.'::'.$loc,
                'ctx'    => RequestId::ctx($ctx),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            @file_put_contents(storage_path(self::LOG), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            Log::warning('DAM content store record could not be written.', ['event' => $event, 'exception' => $e]);
        }
    }

    /**
     * Whether a response is only safe with the app's headers (CSP, nosniff): anything served inline that is
     * not inert media. Raster images, video and audio cannot run script; SVG (and any XML image), PDF and
     * everything else inline might.
     */
    private function needsApplicationHeaders(string $disposition, string $mimeType): bool
    {
        if ($disposition !== HeaderUtils::DISPOSITION_INLINE) {
            return false;
        }

        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        if (str_starts_with($mimeType, 'video/') || str_starts_with($mimeType, 'audio/')) {
            return false;
        }

        return ! in_array($mimeType, [
            'image/png', 'image/jpeg', 'image/pjpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
            'image/tiff', 'image/heic', 'image/heif', 'image/x-icon', 'image/vnd.microsoft.icon',
        ], true);
    }

    /**
     * The X-Accel-Redirect target for a remote blob, when `dam.content_store.remote_accel_prefix` is set:
     * `<prefix>/<scheme>/<host[:port]>/<path>?<signed query>`, which the internal nginx location proxies with the
     * store's own Host header. Null when the prefix is unset, or when temporaryUrl() gives no URL (content needing
     * the app's CSP, which an X-Accel-Redirect response would drop, keeps streaming through PHP). The object key
     * is hex and an extension, so nginx's decoded path is byte-identical to the one that was signed.
     */
    private function remoteAccelRedirect(FileObject $object, string $filename, string $disposition, string $mimeType): ?string
    {
        $prefix = config('dam.content_store.remote_accel_prefix');

        if (blank($prefix)) {
            return null;
        }

        $url = $this->temporaryUrl(object: $object, filename: $filename, disposition: $disposition, mimeType: $mimeType);

        return $url === null ? null : $this->remoteAccelTarget($url);
    }

    /** `<prefix>/<scheme>/<host[:port]>/<path>?<signed query>` for a signed URL, or null when it cannot be proxied. */
    private function remoteAccelTarget(string $url): ?string
    {
        $prefix = config('dam.content_store.remote_accel_prefix');
        $parts = parse_url($url);

        if (blank($prefix) || ! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            return null;
        }

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return rtrim((string) $prefix, '/').'/'.$parts['scheme'].'/'.$authority.'/'.ltrim($parts['path'] ?? '', '/').$query;
    }

    /** Rename an adopted upload to its blob's warm copy; a failure only costs the first job a download. */
    private function keepWarm(string $sourcePath, string $sha256): void
    {
        $storage = Storage::disk(self::STAGING_DISK);
        $warmPath = self::WARM.'/'.$sha256;

        try {
            if (! $storage->exists(self::WARM)) {
                $storage->makeDirectory(self::WARM);
            }

            if (! @rename($storage->path($sourcePath), $storage->path($warmPath))) {
                $storage->delete($sourcePath);

                return;
            }

            @touch($storage->path($warmPath));
        } catch (Throwable) {
            $this->deleteQuietly(self::STAGING_DISK, $sourcePath);
        }
    }

    /** The warm copy of a remote blob, as an absolute path, when one of exactly the blob's size is on hand. */
    private function warmCopy(FileObject $object): ?string
    {
        if (config('filesystems.disks.'.self::STAGING_DISK) === null) {
            return null;
        }

        try {
            $path = Storage::disk(self::STAGING_DISK)->path(self::WARM.'/'.$this->normalizeSha((string) $object->sha256));
        } catch (Throwable) {
            return null;
        }

        clearstatcache(true, $path);

        return is_file($path) && filesize($path) === (int) $object->size ? $path : null;
    }

    private function warmLinkRoot(): ?string
    {
        $root = Storage::disk(self::STAGING_DISK)->path(self::WARM_LINKS);

        return is_dir($root) || @mkdir($root, 0700, true) ? $root : null;
    }

    /**
     * Stage `$warm` at `$file`: a hard link (O(1); it keeps its bytes when the sweep removes the warm name), else
     * a local copy, and always a copy for `$copy` callers, which may change the file. False when neither works;
     * the caller then downloads.
     */
    private function stageWarm(string $warm, LocalFile $file, bool $copy, int $size): bool
    {
        if (! $copy && @link($warm, $file->path)) {
            return true;
        }

        if (@copy($warm, $file->path)) {
            clearstatcache(true, $file->path);

            if (filesize($file->path) === $size) {
                return true;
            }
        }

        @unlink($file->path);

        return false;
    }

    /** Whether `$disk` is a local directory (symlinks, renames, nginx delivery) rather than an object store. */
    public function isLocal(string $disk): bool
    {
        return $this->diskIsLocal($disk);
    }

    private function isLocalDisk(Filesystem $storage): bool
    {
        return $storage instanceof FilesystemAdapter && $storage->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /** A caller's extension for the staged name; anything that is not 1-16 letters or digits is dropped. */
    private function stagingExtension(?string $extension): ?string
    {
        try {
            return $this->normalizeExtension($extension);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Stream the blob into the staging path and check the byte count, so a short read never reaches a tool. */
    private function download(FileObject $object, LocalFile $file): void
    {
        $source = $this->open($object);
        $copied = false;

        try {
            $target = @fopen($file->path, 'xb');

            if ($target === false) {
                throw new RuntimeException('local_staging_failed');
            }

            try {
                $copied = stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);
            }
        } catch (Throwable $e) {
            $file->release();

            throw $e;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        if ($copied !== (int) $object->size) {
            $file->release();

            throw $this->unavailable(reason: ContentUnavailable::BLOB_UNREADABLE, loc: 'localPath', object: $object);
        }
    }

    private function contentDisposition(string $disposition, string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = $name === '' || $name === '.' || $name === '..' ? 'file' : $name;
        // HeaderUtils needs a printable-ASCII fallback without `%`, `/` or `\`; the UTF-8 name rides in filename*.
        $fallback = preg_replace('/[^\x20-\x7e]|[%\/\\\\]/', '_', Str::ascii($name)) ?: 'file';

        return HeaderUtils::makeDisposition($disposition, $name, $fallback);
    }

    private function normalizeSha(string $sha256): string
    {
        $sha256 = strtolower($sha256);

        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Content checksums must be 64 hexadecimal characters.');
        }

        return $sha256;
    }

    private function normalizeExtension(?string $extension): ?string
    {
        $extension = strtolower(ltrim(trim((string) $extension), '.'));

        if ($extension === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new InvalidArgumentException("Object extension [{$extension}] must be 1-16 letters or digits.");
        }

        return $extension;
    }
}
