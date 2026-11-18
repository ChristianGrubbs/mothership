<?php

namespace Mothership\DAM\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Writes a local file to a content-addressed object path exactly once,
 * safely. Shared by RenditionPublisher (panorama proxies) and
 * VideoProxyPublisher (1080p preview proxies) so the two can never drift
 * apart on the two incident fixes encoded here — see ensureObjectWritten().
 */
trait WritesContentAddressedObjects
{
    /**
     * Writes the local result file to $objectPath if, and only if, a
     * complete, correctly-sized copy is not already there. On a local disk
     * it never writes directly to the public $objectPath: it always streams into a
     * private, per-attempt temp path first, verifies the number of bytes
     * that actually landed matches the local source, and only then moves
     * the verified-complete file into place. This closes two related
     * risks a bare `exists($objectPath)` check had:
     *
     *  - a PREVIOUS write that was interrupted (crash, disk full, an
     *    aborted remote upload) leaves a truncated file at $objectPath;
     *    a bare exists() check would see that truncated file and skip
     *    writing forever, publishing every future rendition against a
     *    corrupt proxy;
     *  - a CONCURRENT publisher of byte-identical content (two attempts
     *    producing the same output) writes to its own private temp path,
     *    so neither one can ever observe the other's half-written stream
     *    at the public $objectPath — whichever finishes last simply
     *    replaces the path with an equally valid copy of the same bytes.
     *
     * The whole write+move sequence runs inside one try/catch: if
     * writeStream() throws mid-transfer, the size check fails, or move()
     * itself throws, $tempPath is deleted (best-effort) before the
     * exception is rethrown. Without this, a failure anywhere in that
     * sequence left a permanent, unreferenced
     * `assets/.objects/<xx>/<sha>.uploading-<hex>.tmp` behind — nothing in
     * the codebase garbage-collects orphans at that path shape (asset
     * deletion and the graph observer both
     * address objects by their exact sha-derived path), so every failed
     * publish would accumulate one forever.
     *
     * An object store takes the direct path instead: see putObjectDirectly().
     */
    protected function ensureObjectWritten(string $disk, string $objectPath, string $localPath, int $localSize): void
    {
        if ($this->objectIsIntact($disk, $objectPath, $localSize)) {
            return;
        }

        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('source_unreadable');
        }

        if (! $this->diskIsLocal($disk)) {
            $this->putObjectDirectly($disk, $objectPath, $stream, $localSize);

            return;
        }

        $tempPath = $objectPath.'.uploading-'.bin2hex(random_bytes(8)).'.tmp';

        try {
            try {
                Storage::disk($disk)->writeStream($tempPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($this->diskSizeOrNull($disk, $tempPath) !== $localSize) {
                throw new RuntimeException('object_write_incomplete');
            }

            // A local disk renames atomically, replacing any file already at $objectPath in place. A
            // non-throwing disk reports failure as false, which must not pass for a placed blob.
            if (! Storage::disk($disk)->move($tempPath, $objectPath)) {
                throw new RuntimeException('object_move_failed');
            }
        } catch (Throwable $e) {
            $this->deleteQuietly($disk, $tempPath);

            throw $e;
        }
    }

    /**
     * The object-store writer (ADR 0017). A key appears only once its PUT, single or multipart, completes, so
     * an interrupted upload never leaves a truncated object at $objectPath and the temp key a local disk needs
     * would only cost a server-side copy. The stored size is still checked, since a short read of the source
     * would otherwise be stored as complete. See assertPlacedRemoteObject() for why a bad key is never deleted.
     *
     * @param  resource  $stream
     */
    private function putObjectDirectly(string $disk, string $objectPath, $stream, int $size): void
    {
        try {
            if (! Storage::disk($disk)->writeStream($objectPath, $stream)) {
                throw new RuntimeException('object_write_failed');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->assertPlacedRemoteObject($disk, $objectPath, $size);
    }

    /**
     * A remote blob just placed at its key must hold exactly `$size` bytes, or the write fails and no row names it.
     * A wrong-size key is left in place, never deleted. Between this check and a delete, a concurrent writer of
     * the same bytes may already have replaced it with an intact copy, and only PostgreSQL's advisory lock
     * (lockBlob) rules that out. A bad key left behind is harmless: every reader checks the size, and the next
     * writer of those bytes sees it is not intact and overwrites it.
     */
    protected function assertPlacedRemoteObject(string $disk, string $objectPath, int $size): void
    {
        if ($this->diskSizeOrNull($disk, $objectPath) !== $size) {
            throw new RuntimeException('object_write_incomplete');
        }
    }

    /** Whether `$disk` is a plain local directory, where a crash can tear a write and a rename is atomic. */
    protected function diskIsLocal(string $disk): bool
    {
        $storage = Storage::disk($disk);

        return $storage instanceof FilesystemAdapter && $storage->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * Best-effort delete of a temp object — used only during failure
     * cleanup, where the ORIGINAL exception is what must propagate. A
     * secondary failure here (the temp file already gone, a transient
     * disk error) must never mask or replace it.
     */
    protected function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Nothing else to do — the caller is already unwinding with
            // the real error.
        }
    }

    protected function objectIsIntact(string $disk, string $path, int $expectedSize): bool
    {
        return Storage::disk($disk)->exists($path)
            && $this->diskSizeOrNull($disk, $path) === $expectedSize;
    }

    protected function diskSizeOrNull(string $disk, string $path): ?int
    {
        try {
            return Storage::disk($disk)->size($path);
        } catch (Throwable) {
            return null;
        }
    }
}
