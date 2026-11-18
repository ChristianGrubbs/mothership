<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Support\LibraryPaths;
use RuntimeException;

class GenerationPackageImporter
{
    /** Marker on relationship metadata for edges this importer created and may reconcile. */
    public const LINK_OWNER = 'stylepress-importer';

    public function __construct(private StylePressPackageFiles $files, private GenerationDirectory $directories) {}

    /**
     * @param  int|null  $ownerId  the admin who asked for this generation, since a queue worker has no
     *                             session: CompleteStylePressGeneration reads it from the generation row
     *                             (`dam_home_generations.admin_id`). Null leaves the asset and its folders
     *                             unowned, and full admins manage them (ADR 0010, spec "Inherit on create").
     */
    public function importManifest(string $manifestPath, bool $dryRun = false, ?int $ownerId = null): array
    {
        $manifest = $this->files->read(manifestPath: $manifestPath);
        Validator::make($manifest, [
            'generation_id'    => 'required|string|max:255', 'title'=>'required|string|max:255',
            'kind'             => 'required|in:image,video', 'provider'=>'nullable|string|max:255', 'model'=>'nullable|string|max:255',
            'prompt'           => 'present|nullable|string|max:2000000', 'original_prompt'=>'nullable|string|max:2000000',
            'enhanced_prompt'  => 'nullable|string|max:2000000', 'style'=>'nullable|array', 'settings'=>'nullable|array', 'source'=>'nullable|array',
            'output'           => 'required|array', 'output.path'=>'required|string', 'output.sha256'=>'required|string|size:64',
            'references'       => 'present|array|max:2000', 'references.*.path'=>'required|string', 'references.*.sha256'=>'required|string|size:64',
            'references.*.name'=> 'nullable|string|max:255', 'references.*.role'=>'nullable|string|max:30', 'references.*.asset_id'=>'nullable|integer|min:1',
        ])->validate();
        $root = dirname($manifestPath);
        $output = $this->files->resolve(root: $root, relative: $manifest['output']['path'], sha256: $manifest['output']['sha256']);
        $references = [];
        foreach ($manifest['references'] as $reference) {
            $references[] = ['descriptor'=>$reference, 'path'=>$this->files->resolve(root: $root, relative: $reference['path'], sha256: $reference['sha256'])];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($output);
        $extension = strtolower(pathinfo($output, PATHINFO_EXTENSION));
        $vector = $extension === 'svg' && $mime === 'image/svg+xml';
        $document = $extension === 'pdf' && $mime === 'application/pdf';
        if ($manifest['kind'] === 'image' && ! $vector && ! $document && (! str_starts_with($mime, 'image/') || ! @getimagesize($output))) {
            throw new RuntimeException('The output is not a readable image.');
        }
        if ($manifest['kind'] === 'video' && ! in_array($extension, ['mp4', 'mov', 'webm', 'm4v', 'mkv', 'avi'], true)) {
            throw new RuntimeException('Unsupported generated video format.');
        }
        if ($dryRun) {
            return ['ok'=>true, 'asset_id'=>null, 'action'=>'planned'];
        }

        // ADR 0010's lock order, in full (Codex review of PR #74, finding 3): the DAM_ACCESS_MODEL row, the
        // directory tree lock, then this asset's placement folders and the destination FOR SHARE — all of it
        // before the existing asset is written, because reconcileDirectory() below rebuilds that asset's
        // access list from the folders it then sits in, and a folder row asked for after an asset row is the
        // one cycle a cascade closes. A re-import into folders that already exist used not to take the tree
        // lock at all, since firstOrCreate() found them.
        $writer = app(AccessListWriter::class);

        return Cache::lock('stylepress.package-import', 600)->block(30, fn () => $writer->fencedAndTreeLocked(function () use ($writer, $manifest, $output, $references, $mime, $extension, $document, $ownerId) {
            $sha = strtolower($manifest['output']['sha256']);
            $record = GenerationAsset::where('generation_id', $manifest['generation_id'])->lockForUpdate()->first();
            if ($record && strtolower($record->manifest['output']['sha256']) !== $sha) {
                throw new RuntimeException('This generation ID already belongs to another output.');
            }
            $asset = $record ? Asset::findOrFail($record->asset_id) : Asset::whereHas('files', fn ($query) => $query->where('role', 'original')->whereHas('fileObject', fn ($q) => $q->where('sha256', $sha)))->orderBy('id')->first();
            $created = ! $asset;
            $directory = $this->directories->forManifest(manifest: $manifest, ownerId: $ownerId);
            // Stage 3 for an asset that already exists: where it sits now, and where this manifest routes it.
            // A brand-new asset has no placements and is invisible to every other transaction anyway.
            $placements = $writer->holdPlacementFolders(assetIds: $asset === null ? [] : [$asset->id], directoryIds: [$directory->id]);
            if (! $asset) {
                // The generation output goes into a blob, never assets/generated/<sha>.<ext> (ADR 0009
                // slice 3): the sha is already known from the manifest, so putLocal ingests from $output
                // directly. The Library path becomes a real folder-tree name under the generation folder.
                $object = app(ContentStore::class)->putLocal(disk: Directory::getAssetDisk(), localPath: $output, sha256: $sha, mimeType: $mime, extension: $extension);
                $folderPath = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath();
                $fileName = LibraryPaths::uniqueFileName($folderPath, basename($manifest['output']['path']));
                $asset = new Asset(['title'=>$manifest['title'], 'file_name'=>$fileName, 'file_type'=>$document ? 'document' : $manifest['kind'], 'file_size'=>filesize($output), 'path'=>$folderPath.'/'.$fileName, 'mime_type'=>$mime, 'extension'=>$extension, 'asset_kind'=>'generated', 'created_by'=>$ownerId, 'meta_data'=>['AI Model'=>$manifest['model'] ?? null, 'AI Provider'=>$manifest['provider'] ?? null]]);
                $asset->withGraphContent($object);
                $asset->save();
            }
            $asset->meta_data = array_merge($asset->meta_data ?? [], [
                'AI Prompt'         => $manifest['prompt'] ?? null,
                'AI Original Prompt'=> $manifest['original_prompt'] ?? null,
                'AI Enhanced Prompt'=> $manifest['enhanced_prompt'] ?? null,
                'AI Model'          => $manifest['model'] ?? null,
                'AI Provider'       => $manifest['provider'] ?? null,
                'AI Settings'       => $manifest['settings'] ?? [],
                'AI Style'          => $manifest['style'] ?? null,
                'AI References'     => $manifest['references'],
                'AI Generation ID'  => $manifest['generation_id'],
            ]);
            $asset->save();
            // The asset's rows are locked now (CompoundAssetObserver takes them inside save()), so this is the
            // point at which the stage-3 read above is either still true or the whole import has to start
            // over: fencedAndTreeLocked() runs it once more if the file moved meanwhile.
            $writer->assertPlacementsUnchanged(assetIds: $created ? [] : [$asset->id], locked: $placements);
            $this->reconcileDirectory(asset: $asset, directory: $directory);
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $digest = hash('sha256', $json);
            $this->attachBytes(asset: $asset, bytes: $json, role: 'sidecar', label: 'Generation details · '.$manifest['generation_id'], mime: 'application/json');
            $this->attachBytes(asset: $asset, bytes: (string) ($manifest['prompt'] ?? ''), role: 'prompt', label: 'Full generation prompt · '.$manifest['generation_id'], mime: 'text/plain');
            foreach ($references as $reference) {
                $descriptor = $reference['descriptor'];
                $role = in_array($descriptor['role'] ?? 'source', ['source', 'mask', 'other'], true) ? ($descriptor['role'] ?? 'source') : 'source';
                $this->attachPath(asset: $asset, path: $reference['path'], role: $role, label: $descriptor['name'] ?? basename($descriptor['path']));
            }
            $this->linkSources(asset: $asset, manifest: $manifest);
            $action = $created ? 'created' : ($record?->manifest_sha256 === $digest ? 'unchanged' : 'updated');
            GenerationAsset::updateOrCreate(['generation_id'=>$manifest['generation_id']], ['asset_id'=>$asset->id, 'manifest'=>$manifest, 'manifest_sha256'=>$digest]);
            HomeTelemetry::record(event: 'generation.imported', ctx: ['asset_id'=>$asset->id, 'action'=>$action, 'references'=>count($references)]);

            return ['ok'=>true, 'asset_id'=>$asset->id, 'action'=>$action];
        }));
    }

    /**
     * A reference taken from the library makes the generated image "derived from" that asset, so both
     * relationship panels show the link. Because identical output bytes are deduplicated onto one asset,
     * the wanted edges are the union over every generation recorded for the asset (oldest first, the
     * re-imported one kept in place, so the role and name come from the earliest generation); only edges
     * carrying the importer's ownership marker
     * are reconciled: missing ones created, changed ones updated, dropped ones removed. Links made by people
     * or by other tools are never touched, whatever their metadata; a source that no longer exists is skipped.
     */
    private function linkSources(Asset $asset, array $manifest): void
    {
        // Every generation recorded for this asset, oldest first; the one being imported is replaced in place so
        // a re-import keeps its original position (role and name always come from the earliest generation).
        $manifests = GenerationAsset::query()->where('asset_id', $asset->id)->orderBy('id')->get(['generation_id', 'manifest'])
            ->map(fn ($record) => $record->generation_id === $manifest['generation_id'] ? $manifest : $record->manifest)->all();
        if (! GenerationAsset::query()->where('asset_id', $asset->id)->where('generation_id', $manifest['generation_id'])->exists()) {
            $manifests[] = $manifest;
        }
        $wanted = [];
        foreach ($manifests as $record) {
            $record = is_array($record) ? $record : (array) $record;
            foreach ($record['references'] ?? [] as $reference) {
                $sourceId = (int) ($reference['asset_id'] ?? 0);
                if ($sourceId <= 0 || $sourceId === $asset->id) {
                    continue;
                }
                $entry = $wanted[$sourceId] ?? ['owner' => self::LINK_OWNER, 'generation_ids' => [], 'role' => $reference['role'] ?? 'source', 'reference' => $reference['name'] ?? basename($reference['path'] ?? '')];
                if (! in_array($record['generation_id'], $entry['generation_ids'], true)) {
                    $entry['generation_ids'][] = $record['generation_id'];
                }
                $wanted[$sourceId] = $entry;
            }
        }
        foreach (array_keys($wanted) as $sourceId) {
            if (! Asset::query()->whereKey($sourceId)->exists()) {
                unset($wanted[$sourceId]);
            }
        }
        $owned = AssetRelationship::query()->where('from_asset_id', $asset->id)->where('type', AssetRelationship::TYPE_DERIVED_FROM)->get()
            ->filter(fn (AssetRelationship $edge) => ($edge->metadata['owner'] ?? null) === self::LINK_OWNER);
        foreach ($owned as $edge) {
            if (! isset($wanted[$edge->to_asset_id])) {
                $edge->delete();
            } elseif ($edge->metadata !== $wanted[$edge->to_asset_id]) {
                $edge->update(['metadata' => $wanted[$edge->to_asset_id]]);
            }
        }
        foreach ($wanted as $sourceId => $metadata) {
            $exists = AssetRelationship::query()->where('from_asset_id', $asset->id)->where('to_asset_id', $sourceId)->where('type', AssetRelationship::TYPE_DERIVED_FROM)->exists();
            if (! $exists) {
                AssetRelationship::create(['from_asset_id' => $asset->id, 'to_asset_id' => $sourceId, 'type' => AssetRelationship::TYPE_DERIVED_FROM, 'metadata' => $metadata, 'created_by' => null]);
            }
        }
    }

    private function reconcileDirectory(Asset $asset, Directory $directory): void
    {
        $generationRoot = $directory->ancestorsAndSelfAndDefaultOrder($directory->id)
            ->firstWhere('name', 'AI-Generations');
        $stale = $asset->directories()->get()
            ->filter(function (Directory $candidate) use ($directory, $generationRoot) {
                if ($candidate->id === $directory->id || ! $generationRoot) {
                    return false;
                }

                return $candidate->ancestorsAndSelfAndDefaultOrder($candidate->id)
                    ->contains('id', $generationRoot->id);
            })
            ->pluck('id');

        $replaced = $stale->isNotEmpty();

        if ($replaced) {
            $asset->directories()->detach($stale);
            // The Library path is logical (ADR 0009 slice 3): leaving the old AI-Generations folder
            // renames it into the new one, checked against dam_assets.path like any other move.
            $folderPath = Directory::ASSETS_DIRECTORY.'/'.$directory->generatePath();
            $fileName = LibraryPaths::uniqueFileName($folderPath, $asset->file_name, $asset->id);
            $asset->update(['path' => $folderPath.'/'.$fileName, 'file_name' => $fileName]);
        }
        $asset->directories()->syncWithoutDetaching([$directory->id]);

        // A detach plus an attach replaces the file's membership, so its list is rebuilt from the folders it
        // sits in now rather than widened (ADR 0010); a plain attach only adds.
        $replaced
            ? app(AccessListWriter::class)->recomputeAssets([$asset->id])
            : app(AccessListWriter::class)->inheritAssets([$asset->id]);
    }

    private function attachPath(Asset $asset, string $path, string $role, string $label): void
    {
        $object = app(ContentStore::class)->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $path,
            mimeType: (new \finfo(FILEINFO_MIME_TYPE))->file($path),
        );
        AssetFile::firstOrCreate(['dam_asset_id'=>$asset->id, 'file_object_id'=>$object->id, 'role'=>$role], ['label'=>mb_substr($label, 0, 255), 'sort'=>10]);
    }

    private function attachBytes(Asset $asset, string $bytes, string $role, string $label, string $mime): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'stylepress-attach-');
        if ($temp === false) {
            throw new RuntimeException('Could not save generation details.');
        }

        try {
            if (file_put_contents($temp, $bytes) === false) {
                throw new RuntimeException('Could not save generation details.');
            }

            $object = app(ContentStore::class)->putLocal(
                disk: Directory::getAssetDisk(),
                localPath: $temp,
                mimeType: $mime,
            );
        } finally {
            @unlink($temp);
        }

        $label = mb_substr($label, 0, 255);
        // Reconcile only this generation's attachment; unrelated sidecars and
        // shared immutable objects remain available to their existing owners.
        AssetFile::where('dam_asset_id', $asset->id)->where('role', $role)->where('label', $label)->where('file_object_id', '!=', $object->id)->delete();
        AssetFile::firstOrCreate(['dam_asset_id'=>$asset->id, 'file_object_id'=>$object->id, 'role'=>$role], ['label'=>$label, 'sort'=>5]);
    }
}
