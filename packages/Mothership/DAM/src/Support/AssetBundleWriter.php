<?php

namespace Mothership\DAM\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use RuntimeException;

/**
 * Streams DAM asset binaries into the folder an export job is assembling.
 *
 * The export folder is built on the export disk, so this cannot use a same-disk copy.
 * Destination paths are the asset paths verbatim, which is what makes the resulting
 * archive self-describing on import. `$sourcePath` is only the lookup key (ADR 0009):
 * the bytes streamed always come from that asset's current original through
 * ContentStore, never a raw read of the Library path, which is not guaranteed to name
 * a file once slice 4 lands.
 */
class AssetBundleWriter
{
    /**
     * The disk the tracker builds and zips an export folder on. Named here rather than
     * read from core so the package runs against a released Mothership, where the exporters
     * reach for this disk literally.
     */
    public const EXPORT_DISK = 'private';

    /**
     * Destinations already written by this instance. An asset referenced by many rows
     * resolves to one destination, so it is streamed once rather than once per reference.
     *
     * @var array<string, bool>
     */
    protected array $written = [];

    /**
     * Copy one asset into the export folder, skipping work already done.
     *
     * Destination existence is checked as well as the in-memory set: batches run as
     * separate queued jobs, so the set alone cannot dedupe an asset a sibling batch
     * of the same export has already written. `false` covers two different outcomes a
     * caller that does not read recordMissing()'s visible trace could otherwise conflate:
     * ordinary dedup (the destination already exists) and content genuinely unavailable
     * (see recordMissing()).
     *
     * @return bool whether this call wrote the file
     */
    public function write(string $sourcePath, string $destinationPath): bool
    {
        if (isset($this->written[$destinationPath])) {
            return false;
        }

        $targetDisk = Storage::disk(self::EXPORT_DISK);

        if ($targetDisk->exists($destinationPath)) {
            $this->written[$destinationPath] = true;

            return false;
        }

        $object = $this->currentObjectForPath($sourcePath);

        if ($object === null) {
            $this->recordMissing($targetDisk, $sourcePath, $destinationPath, 'no_original');

            return false;
        }

        try {
            $stream = app(ContentStore::class)->open($object);
        } catch (ContentUnavailable $exception) {
            $this->recordMissing($targetDisk, $sourcePath, $destinationPath, $exception->reason);

            return false;
        }

        try {
            $targetDisk->writeStream($destinationPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->written[$destinationPath] = true;

        return true;
    }

    /** The FileObject the asset at Library path `$path` currently reads from, or null when there is none. */
    private function currentObjectForPath(string $path): ?FileObject
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

    /**
     * Make a missing original visible in the export instead of a silent gap: a `.MISSING.txt` member
     * lands next to where the asset's bytes would have gone (consistent with StreamsZipDownload's zip
     * manifest entries), so whatever later zips the export folder carries the failure along with it
     * even though copyMedia()'s caller does not itself inspect write()'s return value. Also logged, so
     * the failure is findable without opening the archive.
     *
     * @throws RuntimeException when the marker itself cannot be written
     */
    private function recordMissing(Filesystem $targetDisk, string $sourcePath, string $destinationPath, string $reason): void
    {
        Log::warning('DAM export: asset content unavailable.', [
            'source_path'      => $sourcePath,
            'destination_path' => $destinationPath,
            'reason'           => $reason,
        ]);

        $markerPath = $destinationPath.'.MISSING.txt';
        $written = $targetDisk->put(
            $markerPath,
            "The original for [{$sourcePath}] is unavailable ({$reason}) and could not be included in this export.\n",
        );

        if (! $written) {
            // The export disk (EXPORT_DISK) runs with `throw => false`, so put() answering false is
            // the ordinary way a write failure reports here — never an exception this method could
            // catch. Marking the destination handled anyway would leave neither the original nor its
            // manifest entry on disk while the batch kept going: exactly the silent omission this
            // marker exists to prevent. Throw instead, so the failure propagates through
            // CopiesDamMedia::copyMedia() and fails the export job.
            throw new RuntimeException("DAM export: could not write the missing-content marker [{$markerPath}] for [{$sourcePath}].");
        }

        $this->written[$destinationPath] = true;
    }
}
