<?php

namespace Mothership\DAM\Traits;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory as ModelDirectory;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\LibraryPaths;

trait Directory
{
    protected function setDirectoryNameForCopy(string $originalName, int $parentId): string
    {
        $name = $originalName;

        while (ModelDirectory::where('name', $name)->where('parent_id', $parentId)->exists()) {
            $name = $this->replaceAndIncrementOrdinalCopies($name);
        }

        return $name;
    }

    /**
     * The next candidate name for a copy that collided: "Name" -> "Name copy" -> "Name copy 2" -> "Name copy 3".
     * Folders an earlier release named "Name 1th copy" continue the same sequence instead of gaining a second suffix.
     */
    protected function replaceAndIncrementOrdinalCopies(string $string): string
    {
        if (preg_match('/^(.*) (\d+)th copy$/su', $string, $matches)) {
            return $matches[1].' copy '.(((int) $matches[2]) + 1);
        }

        if (preg_match('/^(.*) copy(?: (\d+))?$/su', $string, $matches)) {
            return $matches[1].' copy '.(((int) ($matches[2] ?? 1)) + 1);
        }

        return $string.' copy';
    }

    /** A free name in `$directory`, checked against `dam_assets.path` only: folders are metadata (ADR 0009). */
    protected function generateUniqueFileName($directory, $fileName)
    {
        return LibraryPaths::uniqueFileName((string) $directory, (string) $fileName);
    }

    /**
     * Attach files to a folder, and give them that folder's access list in the same transaction (ADR 0010,
     * spec D3). The attach has no transaction of its own, so AccessModel::sharedly() opens one and takes
     * the fence's hold as its first statement.
     */
    public function mappedWithDirectory($assetIds, $directoryId): ?ModelDirectory
    {
        $directory = $this->directoryRepository->find($directoryId);

        if (! $directory) {
            return null;
        }

        app(AccessModel::class)->sharedly(function () use ($directory, $assetIds): void {
            $directory->assets()->attach($assetIds);

            app(AccessListWriter::class)->inheritAssets(is_array($assetIds) ? $assetIds : [$assetIds]);
        });

        return $directory;
    }

    /**
     * `$path` is the asset's Library path, used only as a lookup key (ADR 0009) — bytes always
     * come from that asset's current original through ContentStore. `$disk` is accepted for call
     * compatibility but unused: the FileObject's own disk decides where the bytes live.
     */
    public function getMetadata(string $path, string $disk)
    {
        $local = null;

        try {
            $asset = Asset::where('path', $path)->first();
            $object = $asset !== null ? app(ContentStore::class)->current($asset) : null;

            if ($object === null) {
                throw new \Exception(trans('dam::app.admin.dam.asset.edit.image-source-not-readable'));
            }

            $local = app(ContentStore::class)->localPath($object, $asset->extension);

            $imageInfo = @getimagesize($local->path) ?: [];

            $exif = function_exists('exif_read_data') ? (@exif_read_data($local->path) ?: []) : [];

            $metadata = [
                'FileName'      => basename($path),
                'FileDateTime'  => (int) (@filemtime($local->path) ?: 0),
                'FileSize'      => (int) $object->size,
                'FileType'      => $imageInfo[2] ?? null,
                'MimeType'      => $imageInfo['mime'] ?? ($object->mime_type ?: null),
                'SectionsFound' => implode(', ', array_keys($exif)),
                'COMPUTED'      => [
                    'html'    => $imageInfo[3] ?? null,
                    'Height'  => $imageInfo[1] ?? null,
                    'Width'   => $imageInfo[0] ?? null,
                    'IsColor' => $imageInfo['channels'] ?? null,
                ],
            ];

            return [
                'success' => true,
                'data'    => $metadata,
            ];
        } catch (\Exception $e) {
            report($e);

            return [
                'success' => false,
                'message' => trans('dam::app.admin.dam.asset.edit.failed-to-read', ['exception' => $e->getMessage()]),
            ];
        } finally {
            $local?->release();
        }
    }
}
