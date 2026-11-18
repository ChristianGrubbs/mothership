<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\ZipEntryNames;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

trait StreamsZipDownload
{
    /**
     * Stream a zip of `$query`'s assets, one entry per asset's current original (ADR 0009). Entry names
     * keep the Library-path folder layout under `$folderBase` for display only; bytes always come through
     * ContentStore. An asset whose content is unavailable never breaks or silently shrinks the archive: it
     * gets a `.MISSING.txt` manifest entry naming the reason, and the failure is logged.
     */
    protected function buildZipStreamFromAssets(
        Builder $query,
        string $folderBase,
        string $disk,
        string $zipName,
    ): StreamedResponse {
        $parentBase = dirname($folderBase).'/';
        $this->ensureZipAssetColumns($query);

        return $this->streamZip(
            zipName: $zipName,
            compression: CompressionMethod::STORE,
            fill: function (ZipStream $zip) use ($query, $parentBase): void {
                $contentStore = app(ContentStore::class);
                $taken = [];

                /** @var Asset $asset */
                foreach ($query->lazy(500) as $asset) {
                    $this->addAssetToZip($zip, $contentStore, $asset, $parentBase, $taken);
                }
            },
        );
    }

    /**
     * A zip written straight to the client as `$fill` adds entries, so the first byte leaves long before the last
     * entry is read and nothing is held in memory or staged on disk. `$compression` is the default for every
     * entry (STORE for already-compressed originals, DEFLATE for the "compressed" single-file download).
     */
    protected function streamZip(string $zipName, CompressionMethod $compression, Closure $fill): StreamedResponse
    {
        return response()->stream(function () use ($compression, $fill): void {
            set_time_limit(0);

            $zip = new ZipStream(
                outputStream: fopen('php://output', 'wb'),
                sendHttpHeaders: false,
                defaultCompressionMethod: $compression,
                flushOutput: true,
            );

            $fill($zip);

            $zip->finish();
        }, Response::HTTP_OK, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => $this->zipContentDisposition($zipName),
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** RFC 6266: a printable-ASCII `filename` fallback (no `%`, `/` or `\`) plus `filename*` carrying the real UTF-8 name. */
    private function zipContentDisposition(string $zipName): string
    {
        $name = trim(str_replace(["\r", "\n", "\0", '/', '\\'], '_', $zipName));
        $name = $name === '' || $name === '.' || $name === '..' ? 'download.zip' : $name;
        $fallback = preg_replace('/[^\x20-\x7e]|[%"]/', '_', Str::ascii($name)) ?: 'download.zip';

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback);
    }

    /**
     * Add one asset's current original to the zip, or a `.MISSING.txt` manifest entry naming why it could
     * not be read. The zip has already started streaming to the client by the time any one asset's content
     * proves unavailable, so a failed HTTP response is not an option; the manifest entry plus the logged
     * record are how that asset's absence surfaces instead of being skipped without a trace.
     */
    private function addAssetToZip(ZipStream $zip, ContentStore $contentStore, Asset $asset, string $parentBase, array &$taken): void
    {
        $entryName = ZipEntryNames::unique($this->zipEntryName($asset, $parentBase), $taken);

        try {
            $object = $contentStore->current($asset);
            $stream = $contentStore->open($object);
        } catch (ContentUnavailable $e) {
            Log::warning('DAM zip download: asset content unavailable.', [
                'asset_id' => $asset->getKey(),
                'reason'   => $e->reason,
            ]);

            $zip->addFile(
                // Registered like any entry, so a real file already called "x.MISSING.txt" (or one added later) cannot share it.
                fileName: ZipEntryNames::uniqueBeside($entryName.'.MISSING.txt', $taken),
                data: "This file's content is unavailable ({$e->reason}) and could not be included in this archive.\n",
            );

            return;
        }

        try {
            $zip->addFileFromStream(fileName: $entryName, stream: $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** The zip's Library-path-shaped folder layout is display only; bytes never come from this name. */
    private function zipEntryName(Asset $asset, string $parentBase): string
    {
        $path = (string) $asset->path;

        return str_starts_with($path, $parentBase)
            ? substr($path, strlen($parentBase))
            : ((string) $asset->file_name ?: basename($path));
    }

    /**
     * current() needs the asset's primary key (to query its `original` file) and its original FileObject;
     * make sure both are selected/eager-loaded no matter what columns a caller's query asked for.
     */
    private function ensureZipAssetColumns(Builder $query): void
    {
        $columns = $query->getQuery()->columns;
        $table = $query->getModel()->getTable();

        if ($columns !== null && ! in_array('id', $columns, true) && ! in_array('*', $columns, true) && ! in_array($table.'.id', $columns, true)) {
            $query->addSelect('id');
        }

        $query->with(['files' => fn ($relation) => $relation->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')]);
    }
}
