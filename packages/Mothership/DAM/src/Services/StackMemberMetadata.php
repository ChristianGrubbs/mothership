<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Support\StackFileName;

class StackMemberMetadata
{
    public function __construct(
        private ContentStore $contentStore,
        private MetadataExtractionService $extractor,
    ) {}

    public function read(Asset $asset, int $fileId): array
    {
        $file = $asset->files()->whereKey($fileId)->where('is_current', true)
            ->whereHas('attachment', fn ($query) => $query->where('dam_asset_id', $asset->id))
            ->with(['fileObject', 'attachment'])->firstOrFail();
        abort_unless($file->fileObject, 404);

        $extension = StackFileName::extension(file: $file, asset: $asset);
        $name = $file->attachment->is_representative
            ? StackFileName::fileName(file: $file, asset: $asset)
            : StackFileName::forLabel(label: $file->label ?: 'File '.$fileId, extension: $extension);
        $local = $this->contentStore->localPath(object: $file->fileObject, extension: $extension);

        try {
            $metadata = $this->extractor->extractMetadata(
                path: $name,
                disk: $file->fileObject->disk,
                localPath: $local->path,
                isPartial: false,
                originalFileName: $name,
                extension: $extension,
            );
            if (($metadata['success'] ?? null) === false) {
                throw new \RuntimeException('Metadata extraction failed.');
            }
            if (isset($metadata['exif']) && is_array($metadata['exif'])) {
                $exif = $metadata['exif'];
                unset($metadata['exif']);
                $metadata = array_merge($exif, $metadata);
            }
            unset($metadata['UndefinedTag:0xEA1C']);

            // Replacement/detach during extraction must not publish a stale revision as current.
            abort_unless($asset->files()->whereKey($fileId)->where('is_current', true)
                ->where('file_object_id', $file->file_object_id)->exists(), 404);

            return ['data' => $metadata, 'file' => ['id' => $fileId, 'name' => $name]];
        } finally {
            $local->release();
        }
    }
}
