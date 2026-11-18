<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Exceptions\CaptureLockUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Support\DerivativeImages;
use Mothership\DAM\Support\StackFileThumbnail;

/**
 * The picture of one stacked 3D file (ADR 0006, per file instead of per asset).
 *
 * A stack's other files are not assets of their own: the asset thumbnail ModelThumbnailService keeps is the
 * stack's cover, and a stacked file must never overwrite it. So a capture of a stacked file sits beside the file's
 * blob (ADR 0009), `derivatives/<sha[0:2]>/<sha>/model-capture-a<assetId>.jpg`: the sha binds it to exactly the bytes
 * the browser rendered and it is reclaimed with the blob. Nothing is written to any asset row.
 *
 * It is keyed by the asset too (PERM-01): the same bytes stacked in another asset never share a capture written now,
 * so a caller can only ever change the picture of an asset they may edit, with no lock to hold against attachments or
 * access changes. A capture written before that, keyed by the blob alone (`model-capture.jpg`), is still read by
 * every asset holding the blob until that asset has its own; it is never written again.
 */
class ModelFileThumbnailService
{
    /** Seconds a 503 asks the browser to wait before it tries again. */
    public const RETRY_AFTER = 5;

    /** A capture is a small JPEG; anything larger is not one this service wrote. */
    private const MAX_BYTES = 8_000_000;

    /** The most pixels a capture is decoded at (a 2048 by 2048 picture); it bounds the memory a check can take. */
    private const MAX_PIXELS = 4_200_000;

    /** Whether a stacked file is one the 3D viewer renders, so one a browser can capture. */
    public static function supports(AssetFile $file, Asset $asset): bool
    {
        return StackFileThumbnail::kind($file->fileObject?->mime_type, $file->label ?: $asset->file_name) === StackFileThumbnail::KIND_MODEL;
    }

    /** Whether the asset has a capture of this blob, its own or the shared one; false for a file with no blob. */
    public static function exists(?string $sha256, int $assetId): bool
    {
        return self::path($sha256, $assetId) !== null;
    }

    /** The capture the asset shows for this blob: its own when it has one, else the shared one, else null. */
    public static function path(?string $sha256, int $assetId): ?string
    {
        if ($sha256 === null || preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            return null;
        }

        $disk = Storage::disk(Directory::getAssetDisk());

        foreach ([DerivativeImages::modelCapture($sha256, $assetId), DerivativeImages::modelCapture($sha256)] as $path) {
            if (self::usable($disk, $path)) {
                return $path;
            }
        }

        return null;
    }

    /** Whether the file at `$path` on the asset disk is a usable capture (the same rule `path()` and the AI apply). */
    public static function isUsable(string $path): bool
    {
        return self::usable(Storage::disk(Directory::getAssetDisk()), $path);
    }

    /**
     * Whether a stored capture is a picture and not the remains of a failed write: present, within a size cap, a JPEG or
     * PNG that ends where its format ends, whose header gives a bounded size and which GD decodes. A signature alone
     * is not enough (`FF D8 FF 00` and a cut-off file both begin like a picture). Writes are verified and moved into
     * place, so a half-written file is never published; this is for one left by an older writer or a crash. It is decoded
     * on every call: captures are small, and a stale "good" answer would be worse than the read.
     */
    private static function usable(Filesystem $disk, string $path): bool
    {
        try {
            $size = $disk->exists($path) ? (int) $disk->size($path) : 0;

            if ($size <= 0 || $size > self::MAX_BYTES) {
                return false;
            }

            return self::decodes((string) $disk->get($path));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether these bytes are a complete JPEG or PNG of a bounded size that GD can decode. */
    private static function decodes(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return false;
        }

        // Where the format ends: JPEG's end-of-image marker (padding after it allowed), PNG's IEND chunk with its CRC.
        $ends = $info[2] === IMAGETYPE_JPEG
            ? str_ends_with(rtrim($bytes, "\0"), "\xFF\xD9")
            : str_ends_with($bytes, "\0\0\0\0IEND\xAE\x42\x60\x82");

        if (! $ends) {
            return false;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return false;
        }

        unset($image);

        return true;
    }

    /**
     * The `meta_data` keys that make an asset's card show the capture that exists for these bytes, or none: the card
     * reads `thumbnail_path`, which a change of representative drops along with the old file's picture. Two families
     * hold such a capture: the stack's per-file one beside the blob, and the asset-level one ModelThumbnailService keeps
     * (`thumbnails/models/<asset>-<sha12>.jpg`), which is what a plain GLB entry's own capture is.
     *
     * @return array<string, string>
     */
    public static function adoptable(?string $sha256, int $assetId): array
    {
        $path = self::path($sha256, $assetId);

        if ($path === null && $sha256 !== null && preg_match('/^[a-f0-9]{64}$/i', $sha256) === 1) {
            $assetLevel = ModelThumbnailService::DIRECTORY.$assetId.'-'.substr(strtolower($sha256), 0, 12).'.jpg';
            $path = self::usable(Storage::disk(Directory::getAssetDisk()), $assetLevel) ? $assetLevel : null;
        }

        return $path === null ? [] : ['thumbnail_path' => $path, 'thumbnail_revision' => substr(strtolower((string) $sha256), 0, 12)];
    }

    /**
     * Copies an existing picture to `$assetId`'s own capture of the blob, only when it has none: never over one, and
     * never beside a browser capture that lands meanwhile, because the copy and store() take the same lock. A lock that
     * cannot be had skips the copy (the capture is made again on the next merge or view); there is no unlocked write.
     *
     * @return bool whether this call created the capture
     */
    public static function copyIfAbsent(string $from, string $sha256, int $assetId): bool
    {
        $disk = Storage::disk(Directory::getAssetDisk());
        $own = DerivativeImages::modelCapture($sha256, $assetId);

        if (! self::usable($disk, $from)) {
            return false;
        }

        $created = false;

        try {
            self::locked($own, function (\Closure $held) use ($disk, $from, $own, $sha256, $assetId, &$created): void {
                if (self::exists($sha256, $assetId)) {
                    return;
                }

                $created = self::publish($disk, $own, (string) $disk->get($from), $held);
            }, wait: 0);
        } catch (\Throwable) {
            return false;
        }

        return $created;
    }

    /**
     * Runs `$work` holding the capture's lock, which is also what the browser capture takes. `$work` is given a check that
     * the lock is still its own: the lease can lapse under a stalled disk, and a write that has lost it must not go on to
     * publish beside whoever holds it now. The check also fails past a deadline well inside the lease, so a slow write
     * stops before the lease could run out under it. A lock that cannot be had is an exception, never an unlocked run.
     *
     * @param  \Closure(\Closure(): bool): void  $work
     */
    private static function locked(string $own, \Closure $work, int $wait): void
    {
        $lease = max(10, (int) config('dam.model_capture_lock_lease', 120));
        $deadline = microtime(true) + $lease / 2;

        try {
            $lock = Cache::lock('model-capture:'.sha1(Directory::getAssetDisk().':'.$own), $lease);
            // Throws LockTimeoutException when it is still held after `$wait` seconds.
            $lock->block($wait);
        } catch (LockTimeoutException $e) {
            throw new CaptureLockUnavailable('The capture lock is held by another writer.', previous: $e);
        } catch (\Throwable $e) {
            // The cache that holds locks is down: a retry may find it back, and nothing is written without the lock.
            throw new CaptureLockUnavailable('The capture lock cannot be reached.', previous: $e);
        }

        try {
            $work(function () use ($deadline, $lock): bool {
                try {
                    return microtime(true) < $deadline && $lock->isOwnedByCurrentProcess();
                } catch (\Throwable $e) {
                    // The cache stopped answering mid-write: ownership cannot be shown, so nothing is published.
                    throw new CaptureLockUnavailable('The capture lock could not be checked.', previous: $e);
                }
            });
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // The lease frees it; a failed release must not turn a finished write into an error.
            }
        }
    }

    /**
     * Writes `$bytes` to `$path` through a temporary name, checks what was written, and moves it into place, so a failed
     * or partial write is never published and an existing file is only replaced by a complete one. The bytes must be a
     * picture a viewer can show, and the lock must still be held at the moment of the move.
     *
     * @param  \Closure(): bool  $held
     *
     * @throws CaptureLockUnavailable when the lock was lost or ran past its deadline before the move
     */
    private static function publish(Filesystem $disk, string $path, string $bytes, \Closure $held): bool
    {
        if (! self::decodes($bytes)) {
            return false;
        }

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            if ($disk->put($temporary, $bytes) !== true || (int) $disk->size($temporary) !== strlen($bytes)) {
                return false;
            }

            if (! $held()) {
                throw new CaptureLockUnavailable('The capture lock was lost before the capture was published.');
            }

            // The size was checked on the temporary file, above; on a local disk the move is an atomic rename, so what lands
            // is exactly what was checked. Residual, for a non-local disk (S3 copy-then-delete) where a move can outlast the
            // lease: a stalled move could land beside a newer writer. The bytes are the same picture of the same blob, and the
            // next capture or view re-checks and replaces it, so no further machinery is spent on it.
            return $disk->move($temporary, $path) === true;
        } catch (CaptureLockUnavailable $e) {
            throw $e;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $disk->delete($temporary);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Store `$assetId`'s own capture of `$object`.
     *
     * @return array{stored: bool, skipped: ?string}
     */
    public function store(FileObject $object, UploadedFile $upload, int $assetId, bool $replace = false): array
    {
        $disk = Storage::disk(Directory::getAssetDisk());
        $own = DerivativeImages::modelCapture($object->sha256, $assetId);
        $encoded = app(ModelThumbnailService::class)->encode($upload);
        $result = ['stored' => false, 'skipped' => 'exists'];

        try {
            // The bytes of a sha never change, so a capture that is already there is as good as a new one; only an
            // explicit replace re-makes it, which keeps a backfill or a second viewer idempotent.
            self::locked($own, function (\Closure $held) use ($disk, $own, $object, $assetId, $replace, $encoded, &$result): void {
                if (! $replace && self::exists($object->sha256, $assetId)) {
                    return;
                }

                abort_unless(self::publish($disk, $own, $encoded, $held), 500, 'Could not save the model thumbnail.');
                $result = ['stored' => true, 'skipped' => null];
            }, wait: (int) config('dam.model_capture_lock_wait', 10));
        } catch (CaptureLockUnavailable) {
            // Nothing was written. The browser retries after a pause (the viewer and the bulk queue both back off).
            abort(503, 'The model thumbnail is being saved. Try again in a moment.', ['Retry-After' => (string) self::RETRY_AFTER]);
        }

        // The AI looks at this picture; an index run that found no image for the file starts over once. Only a capture
        // that was really written counts: one that was already there was announced when it was made.
        if ($result['stored']) {
            AiIndexDispatcher::derivativeReady($assetId);
        }

        return $result;
    }
}
