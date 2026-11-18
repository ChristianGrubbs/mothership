<?php

namespace Mothership\DAM\Helpers\Exporters\Concerns;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Support\AssetBundleWriter;

/**
 * Media handling shared by the DAM product and category exporters.
 *
 * Asset-type values are backed by the DAM disk and need streaming; every other media
 * type is left to the core exporter.
 */
trait CopiesDamMedia
{
    protected ?AssetBundleWriter $assetBundleWriter = null;

    public function copyMedia(string $sourcePath, string $destinationPath, bool $isAssetField = false): void
    {
        if (! $isAssetField) {
            parent::copyMedia($sourcePath, $destinationPath);

            return;
        }

        // write()'s bool return does not distinguish ordinary dedup from content genuinely
        // unavailable, and this signature (matching the core exporter's void copyMedia()) has
        // nowhere to surface that distinction anyway — a missing original still has to be visible
        // in the export, so AssetBundleWriter leaves a `.MISSING.txt` member next to where the file
        // would have gone instead of depending on this call site to check a return value. Deliberately
        // uncaught here: if even that marker cannot be written, write() throws, and the failure must
        // propagate up through this call to fail the export job rather than be swallowed.
        $this->assetBundleWriter()->write($sourcePath, $destinationPath);
    }

    public function makePublicUrlMedia(string $filePath, bool $isAssetField = false): string
    {
        if ($isAssetField) {
            return route('admin.dam.file.fetch', ['path' => $filePath]);
        }

        return Storage::url($filePath);
    }

    /**
     * Resolved lazily and held per exporter instance so the dedupe set survives a batch.
     */
    protected function assetBundleWriter(): AssetBundleWriter
    {
        return $this->assetBundleWriter ??= app(AssetBundleWriter::class);
    }
}
