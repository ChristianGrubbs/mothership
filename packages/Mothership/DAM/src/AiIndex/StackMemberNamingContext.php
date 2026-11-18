<?php

declare(strict_types=1);

namespace Mothership\DAM\AiIndex;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\MetadataExtractionService;
use Mothership\DAM\Support\PreviewKinds;
use Mothership\DAM\Support\StackFileName;
use Throwable;

class StackMemberNamingContext
{
    /** Embedded game formats retain their explicit format even when libmagic calls the package gzip. */
    public static function extension(Asset $asset, AssetFile $file): string
    {
        $suffix = strtolower(pathinfo($file->attachment?->is_representative ? (string) $asset->file_name : (string) $file->label, PATHINFO_EXTENSION));

        return PreviewKinds::hasEmbeddedThumbnail($suffix) ? $suffix : StackFileName::extension(file: $file, asset: $asset);
    }

    public function eligibility(Asset $asset, AssetFile $file): array
    {
        $extension = self::extension(asset: $asset, file: $file);
        $mime = (string) $file->fileObject?->mime_type;
        $direct = in_array($extension, AiEligibility::RASTER, true) || $extension === 'pdf' || str_starts_with($mime, 'video/');
        if ($direct || app(MediaSampler::class)->memberPicturePath(asset: $asset, file: $file) !== null) {
            return ['state' => AiEligibility::ELIGIBLE, 'message' => '', 'scope' => 'member'];
        }
        $preview = $extension === 'dng' || str_starts_with($mime, 'image/') || in_array($extension, PreviewKinds::MODEL_VIEWER, true) || PreviewKinds::hasEmbeddedThumbnail($extension);

        return ['state' => $preview ? AiEligibility::AWAITING_PREVIEW : AiEligibility::UNSUPPORTED,
            'message'   => $preview ? 'This file needs its own preview before AI naming.' : 'AI naming does not support this file type.', 'scope' => 'member'];
    }

    public function build(Asset $asset, AssetFile $file): array
    {
        $local = null;
        try {
            $name = StackFileName::fileName(file: $file, asset: $asset);
            $extension = self::extension(asset: $asset, file: $file);
            $local = app(ContentStore::class)->localPath(object: $file->fileObject, extension: $extension ?: null);
            $sample = app(MediaSampler::class)->sampleLocalFile(asset: $asset, file: $file, path: $local->path);
            if ($sample->images === []) {
                throw new AiBridgeUnavailable('This member has no readable preview. Retry when its preview is ready.');
            }
            $folder = (string) ($asset->directories()->first()?->generatePath() ?? '');
            $caption = app(AiBridgeClient::class)->caption(images: $sample->images,
                context: ['file_name' => $name, 'folder_path' => $folder, 'kind' => $sample->kind],
                vocabulary: array_keys(app(AiVocabulary::class)->enabledLabels()));
            if (trim($caption['caption']) === '') {
                throw new AiBridgeUnavailable('The member description was empty. Retry this row.');
            }
            $metadata = app(MetadataExtractionService::class)->extractMetadata(path: $file->fileObject->path,
                disk: $file->fileObject->disk, localPath: $local->path, originalFileName: $name, extension: $extension);

            return $caption + ['metadata' => $metadata, 'file_name' => $name, 'extension' => $extension, 'folder_path' => $folder];
        } catch (Throwable $error) {
            throw new AiBridgeUnavailable('This member could not be described. Retry this row.', previous: $error);
        } finally {
            $local?->release();
        }
    }
}
