<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Support\LibraryPaths;

/**
 * Files a recipe's output (P6a) and records its lineage. By default the output is a new attachment slot of the
 * SOURCE entry (role `output`, labelled "GIF 0:05–0:08.gif"), so a selection of a hundred files does not add a
 * hundred Library entries; "Save as new asset" files it as its own entry in the source's folders instead, with
 * those folders' access lists. Either way a rendition on the source names the exact source revision, the recipe
 * and its parameters, and its inputs (the source and any watermark) are listed with their checksums.
 *
 * The source entry is only ever added to: its existing files, revisions and blobs are never written.
 */
class OutputPublisher
{
    public function __construct(
        private readonly ContentStore $store,
        private readonly Lineage $lineage,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  what the rendition records (recipe parameters plus batch ids)
     * @param  list<array{role: string, asset_file_id: ?int, sha256: ?string}>  $inputs
     * @return array{asset_file_id: ?int, asset_id: ?int, rendition_id: int}
     */
    public function publish(
        Asset $source,
        AssetFile $sourceRevision,
        Recipe $recipe,
        RecipeOutput $output,
        string $label,
        bool $asNewAsset,
        int $requesterId,
        string $fingerprint,
        array $parameters,
        array $inputs,
    ): array {
        $sha256 = (string) hash_file('sha256', $output->path);
        // Committed in its own short transaction (ADR 0009 slice 3), so a rollback below never strands a blob.
        $object = $this->store->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $output->path,
            sha256: $sha256,
            mimeType: $output->mimeType,
            extension: $output->extension,
            attributes: ['sniffed_type' => $output->mimeType],
        );

        return $asNewAsset
            ? $this->asNewAsset(source: $source, sourceRevision: $sourceRevision, recipe: $recipe, object: $object, output: $output, label: $label, requesterId: $requesterId, fingerprint: $fingerprint, parameters: $parameters, inputs: $inputs)
            : $this->attach(source: $source, sourceRevision: $sourceRevision, recipe: $recipe, object: $object, label: $label, requesterId: $requesterId, fingerprint: $fingerprint, parameters: $parameters, inputs: $inputs);
    }

    private function attach(Asset $source, AssetFile $sourceRevision, Recipe $recipe, FileObject $object, string $label, int $requesterId, string $fingerprint, array $parameters, array $inputs): array
    {
        return DB::transaction(function () use ($source, $sourceRevision, $recipe, $object, $label, $requesterId, $fingerprint, $parameters, $inputs): array {
            // Lock order (AttachmentLedger): the asset row, then its slots and revisions, then the blob.
            Asset::whereKey($source->id)->lockedForGraph()->firstOrFail();
            $locked = FileObject::whereKey($object->id)->lockForUpdate()->firstOrFail();

            // The same bytes already current under this role are the same output: a re-run adds no second file.
            $file = AssetFile::query()
                ->where('dam_asset_id', $source->id)->where('file_object_id', $locked->id)
                ->where('role', $recipe->outputRole())->where('is_current', true)->first()
                ?? AssetFile::create([
                    'dam_asset_id'   => $source->id,
                    'file_object_id' => $locked->id,
                    'role'           => $recipe->outputRole(),
                    'label'          => mb_substr($label, 0, 191),
                    'sort'           => 1000,
                    'created_by'     => $requesterId,
                ]);

            $rendition = $this->record(source: $source, sourceRevision: $sourceRevision, recipe: $recipe, outputFileId: (int) $file->id, outputAssetId: null, fingerprint: $fingerprint, parameters: $parameters, inputs: $inputs);

            return ['asset_file_id' => (int) $file->id, 'asset_id' => null, 'rendition_id' => (int) $rendition->id];
        });
    }

    private function asNewAsset(Asset $source, AssetFile $sourceRevision, Recipe $recipe, FileObject $object, RecipeOutput $output, string $label, int $requesterId, string $fingerprint, array $parameters, array $inputs): array
    {
        $writer = app(AccessListWriter::class);

        // ADR 0010's lock order, as the image editor's save-as-new: the access model, the tree lock, the source's
        // placement folders FOR SHARE, then the asset rows and the graph. The copy inherits those folders' lists.
        return $writer->fencedAndTreeLocked(function () use ($writer, $source, $sourceRevision, $recipe, $object, $output, $label, $requesterId, $fingerprint, $parameters, $inputs): array {
            $placements = $writer->holdPlacementFolders(assetIds: [$source->id]);
            $locked = Asset::lockedForGraph()->findOrFail($source->id);
            $writer->assertPlacementsUnchanged(assetIds: [$source->id], locked: $placements);

            $folderPath = dirname((string) $locked->path);
            $stem = pathinfo((string) $locked->file_name, PATHINFO_FILENAME) ?: 'file';
            // A Library name, so no path separators or colons from the label ("GIF 0:05–0:08").
            $suffix = trim(str_replace([':', '/', '\\'], ['.', '-', '-'], pathinfo($label, PATHINFO_FILENAME)));
            $fileName = LibraryPaths::uniqueFileName($folderPath, mb_substr($stem.' - '.$suffix, 0, 180).'.'.$output->extension);
            $copy = new Asset;
            $copy->fill([
                'path'       => $folderPath.'/'.$fileName,
                'file_name'  => $fileName,
                'file_type'  => $this->fileType($output->mimeType),
                'extension'  => $output->extension,
                'mime_type'  => $output->mimeType,
                'file_size'  => (int) $object->size,
                'title'      => $locked->title,
                'created_by' => $requesterId,
            ]);
            $copy->withGraphContent($object);
            $copy->save();
            $copy->directories()->sync($locked->directories()->pluck('dam_directories.id'));
            $writer->inheritAssets([$copy->id]);

            $rendition = $this->record(source: $locked, sourceRevision: $sourceRevision, recipe: $recipe, outputFileId: null, outputAssetId: (int) $copy->id, fingerprint: $fingerprint, parameters: $parameters, inputs: $inputs);

            return ['asset_file_id' => null, 'asset_id' => (int) $copy->id, 'rendition_id' => (int) $rendition->id];
        });
    }

    private function record(Asset $source, AssetFile $sourceRevision, Recipe $recipe, ?int $outputFileId, ?int $outputAssetId, string $fingerprint, array $parameters, array $inputs): Rendition
    {
        return $this->lineage->record(
            assetId: (int) $source->id,
            recipe: $recipe->key(),
            source: $sourceRevision,
            worker: 'batch',
            outputFileId: $outputFileId,
            outputAssetId: $outputAssetId,
            parameters: $parameters,
            inputs: $inputs,
            fingerprint: $fingerprint,
        );
    }

    private function fileType(string $mimeType): string
    {
        return match (strtok($mimeType, '/')) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            default => 'document',
        };
    }

    /** The source and, for a watermark, the overlay, as lineage inputs with their checksums. */
    public static function inputs(AssetFile $source, ?AssetFile $overlay): array
    {
        $rows = [['role' => LineageInput::ROLE_SOURCE, 'asset_file_id' => (int) $source->id, 'sha256' => $source->fileObject?->sha256]];

        if ($overlay !== null) {
            $rows[] = ['role' => LineageInput::ROLE_OVERLAY, 'asset_file_id' => (int) $overlay->id, 'sha256' => $overlay->fileObject?->sha256];
        }

        return $rows;
    }
}
