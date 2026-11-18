<?php

namespace Mothership\DAM\Support;

use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DataTransfer\Contracts\JobTrack as JobTrackContract;

/**
 * Unpacks an export archive and brings its assets into the DAM before any row is read.
 *
 * The archive produced by a media-enabled export stores each asset under its DAM path,
 * and the data file references those same paths. Ingesting first therefore means the
 * importers' existing path lookups resolve without any separate mapping structure.
 */
class AssetBundleReader
{
    public const ASSET_ENTRY_PREFIX = Directory::ASSETS_DIRECTORY.'/';

    protected const DATA_FILE_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /** The only values `dam_assets.file_type` accepts. */
    protected const STORED_FILE_TYPES = ['image', 'video', 'audio', 'document', 'web'];

    /**
     * The admin who started this import, because a queued import has no session to fill `created_by` from
     * (ADR 0010, spec "Inherit on create"). `job_track.user_id` is nullable, and a bundle imported without
     * one leaves its assets and folders unowned, for full admins to manage.
     */
    protected ?int $ownerId = null;

    /**
     * Extract the archive, ingest its assets, and return the data file's path on the
     * private disk, ready to be handed to a CSV or Excel source.
     *
     * @throws \RuntimeException when the archive is unreadable, unsafe, or carries no data file
     */
    public function prepare(JobTrackContract $import): string
    {
        // `?? null`, because JobTrack is an empty contract: only the model carries `user_id`, and an
        // import driven from a double or from a job row with no user leaves the owner unset.
        $this->ownerId = ($import->user_id ?? null) === null ? null : (int) $import->user_id;

        $relativeRoot = 'imports/bundles/'.$import->id;
        $extractPath = Storage::disk('private')->path($relativeRoot);

        $entries = $this->extract($import->file_path, $extractPath);

        $this->ingestAssets($entries, $extractPath);

        return $relativeRoot.'/'.$this->locateDataFile($entries);
    }

    /**
     * @return list<string> relative paths present in the extraction directory
     */
    protected function extract(string $archivePath, string $extractPath): array
    {
        $zip = new \ZipArchive;

        if ($zip->open(Storage::disk('private')->path($archivePath)) !== true) {
            throw new \RuntimeException(trans('dam::app.data-transfer.bundle.invalid-archive'));
        }

        $extractor = $this->extractor();

        $rejection = $extractor->rejectionReason($zip);

        if ($rejection !== null) {
            $zip->close();

            throw new \RuntimeException(trans('dam::app.data-transfer.bundle.'.$rejection['key'], $rejection['replace']));
        }

        $entries = $extractor->extract(
            $zip,
            $extractPath,
            fn (string $path, string $extension, string $staged): bool => $this->isPermittedEntry($path, $extension, $staged)
        );

        $zip->close();

        return $entries;
    }

    /**
     * Apply the same file-type policy a DAM upload goes through, so an archive cannot
     * introduce anything the explorer would have refused.
     */
    protected function isPermittedEntry(string $relativePath, string $extension, string $stagedPath): bool
    {
        if (! str_starts_with($relativePath, self::ASSET_ENTRY_PREFIX)) {
            return in_array($extension, self::DATA_FILE_EXTENSIONS, true);
        }

        // A root folder named .objects or .staging would collide with the content store's own
        // reserved namespaces (ADR 0009): refuse any entry under one before it is even staged.
        foreach (explode('/', trim(substr($relativePath, strlen(self::ASSET_ENTRY_PREFIX)), '/')) as $segment) {
            if (ReservedDirectoryNames::isReserved($segment)) {
                return false;
            }
        }

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($stagedPath);

        return ! AssetHelper::isForbiddenFile($extension, $mimeType, basename($relativePath), $stagedPath);
    }

    /**
     * @param  list<string>  $entries
     */
    protected function locateDataFile(array $entries): string
    {
        foreach ($entries as $entry) {
            if (str_contains($entry, '/')) {
                continue;
            }

            if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::DATA_FILE_EXTENSIONS, true)) {
                return $entry;
            }
        }

        throw new \RuntimeException(trans('dam::app.data-transfer.bundle.no-data-file'));
    }

    /**
     * Create a DAM asset for every archived binary, reusing anything already present at
     * the same path so a repeated import neither duplicates rows nor overwrites files
     * someone has since edited in the explorer.
     *
     * @param  list<string>  $entries
     * @return array<string, int> ingested asset ids keyed by DAM path
     */
    protected function ingestAssets(array $entries, string $extractPath): array
    {
        $assetEntries = array_values(array_filter(
            $entries,
            fn (string $entry): bool => str_starts_with($entry, self::ASSET_ENTRY_PREFIX)
        ));

        if ($assetEntries === []) {
            return [];
        }

        $existing = Asset::whereIn('path', $assetEntries)->pluck('id', 'path')->all();

        $ingested = [];

        foreach ($assetEntries as $entry) {
            if (isset($existing[$entry])) {
                $ingested[$entry] = $existing[$entry];

                continue;
            }

            $ingested[$entry] = $this->createAsset($entry, $extractPath.DIRECTORY_SEPARATOR.$entry);
        }

        return $ingested;
    }

    protected function createAsset(string $entry, string $sourceFile): int
    {
        $directory = $this->ensureDirectory($entry);

        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($sourceFile);

        // The bundled bytes go into a blob, never a physical write at the archive's own entry path
        // (ADR 0009 slice 3); $entry stays the asset's logical Library path.
        $object = app(ContentStore::class)->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $sourceFile,
            mimeType: $mimeType,
            extension: $extension,
        );

        $asset = new Asset([
            'file_name'  => basename($entry),
            'file_type'  => $this->fileTypeFor($extension, $mimeType, $sourceFile),
            'file_size'  => (int) filesize($sourceFile),
            'mime_type'  => $mimeType,
            'extension'  => $extension,
            'path'       => $entry,
            'created_by' => $this->ownerId,
        ]);
        $asset->withGraphContent($object);

        // sharedly(), because the create and the attach have no transaction of their own: ADR 0010's fence
        // wants the DAM_ACCESS_MODEL row FOR SHARE first, and the imported file takes its folder's access
        // list in the same transaction as its attach (spec D3).
        app(AccessModel::class)->sharedly(function () use ($asset, $directory): void {
            $asset->save();

            $directory->assets()->attach($asset->id);

            app(AccessListWriter::class)->inheritAssets([$asset->id]);
        });

        ProcessAssetUpload::dispatch($asset->id);

        return $asset->id;
    }

    /**
     * One classifier for every ingress (ADR 0007): an imported page has to become file_type
     * `web` here exactly as an uploaded one does, or the import would store it as a document
     * and no bundle would ever be built. The staged file is passed so a zip can be inspected.
     *
     * The shared classifier leads on the mime type, and a bundled fixture's bytes are often not
     * the real media, so a `document` verdict still falls back to the extension map the import
     * has always used rather than demoting an image or a clip.
     */
    protected function fileTypeFor(string $extension, string $mimeType, ?string $stagedPath = null): string
    {
        $type = AssetHelper::classifyFile(mimeType: $mimeType, extension: $extension, realPath: $stagedPath);

        if ($type !== 'document' && in_array($type, self::STORED_FILE_TYPES, true)) {
            return $type;
        }

        $mapped = AssetHelper::getFileTypeUsingExtension($extension);

        if (in_array($mapped, self::STORED_FILE_TYPES, true)) {
            return $mapped;
        }

        // `web` is deliberately absent here: 'image/webp' contains 'web' and must stay an image.
        foreach (['image', 'video', 'audio', 'document'] as $candidate) {
            if (str_contains($mimeType, $candidate)) {
                return $candidate;
            }
        }

        return 'document';
    }

    /**
     * Walk the archived path, creating any directory the target DAM is missing, so the
     * source tree is reproduced rather than flattened.
     */
    protected function ensureDirectory(string $entry): Directory
    {
        $segments = explode('/', trim(substr($entry, strlen(self::ASSET_ENTRY_PREFIX)), '/'));

        array_pop($segments);

        if ($segments === []) {
            throw new \RuntimeException("Bundled asset is not inside a directory: {$entry}");
        }

        $parentId = null;
        $directory = null;

        foreach ($segments as $name) {
            if (ReservedDirectoryNames::isReserved($name)) {
                throw new \RuntimeException("Bundled asset uses a reserved directory name: {$entry}");
            }

            $directory = Directory::firstOrCreate([
                'name'      => $name,
                'parent_id' => $parentId,
            ], ['created_by' => $this->ownerId]);

            $parentId = $directory->id;
        }

        return $directory;
    }

    protected function extractor(): SafeZipExtractor
    {
        return new SafeZipExtractor(
            maxEntrySize: (int) config('dam.import_bundle.max_entry_size', 524288000),
            maxTotalSize: (int) config('dam.import_bundle.max_total_size', 5368709120),
            maxEntries: (int) config('dam.import_bundle.max_entries', 50000),
            maxCompressionRatio: (float) config('dam.import_bundle.max_compression_ratio', 200),
        );
    }
}
