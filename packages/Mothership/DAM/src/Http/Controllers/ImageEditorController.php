<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Models\LineageInput;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ImageEditorLog;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\DAM\Services\ImageEditorWorkingCopy;
use Mothership\DAM\Services\Lineage;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Services\Stack\StackMembers;
use Mothership\DAM\Support\ImageEditing;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Support\StackFileName;
use Mothership\DAM\Traits\AssetAccessControl;

class ImageEditorController
{
    use AssetAccessControl;

    private const USABLE = ['ready', 'saved'];

    public function __construct(private ImageEditorSchema $schema) {}

    private function authorize(int $id): Asset
    {
        $asset = $this->authorizeAccess($id);
        // Before any document, resource or content is touched: what the Details page offers (ImageEditing) is what this accepts.
        if (! ImageEditing::originalEditable($asset)) {
            $this->trace('refused_unsupported_original', ['asset_id' => $id, 'reason' => $asset->file_type === 'image' ? 'format' : 'not_image']);
            abort(422, $asset->file_type === 'image' ? 'The image editor supports PNG, JPEG and WebP images only.' : 'This editor supports image assets only.');
        }

        return $asset;
    }

    private function authorizeAccess(int $id): Asset
    {
        $asset = $this->damVisibleAssetOrFail($id);
        abort_unless($this->damCanEditAsset($id), 403);

        return $asset;
    }

    /**
     * The stacked file a request names with `attachment` (query or body), or null for the main file, which is also
     * what a slot naming the main file means. A named file must still be a member of the asset and be a type the
     * editor reads; the main file is then not asked, so a stack whose cover is an SVG can still edit its PNG.
     *
     * @return array{0: Asset, 1: ?AssetFile}
     */
    private function authorizeFor(Request $request, int $id): array
    {
        $attachmentId = $request->validate(['attachment' => ['nullable', 'integer', 'min:1']])['attachment'] ?? null;

        if ($attachmentId === null) {
            return [$this->authorize($id), null];
        }

        $asset = $this->authorizeAccess($id);
        $members = app(StackMembers::class);

        if ((int) $attachmentId === $members->mainAttachmentId($asset)) {
            return [$this->authorize($id), null];
        }

        $member = $members->find(asset: $asset, attachmentId: (int) $attachmentId);
        abort_if($member === null, 404, 'That file is no longer in this stack.');

        if (! ImageEditing::memberEditable($member, $asset)) {
            $this->trace('refused_unsupported_member', ['asset_id' => $id, 'attachment_id' => (int) $attachmentId]);
            abort(422, 'The image editor supports PNG, JPEG and WebP images only.');
        }

        return [$asset, $member];
    }

    private function admin(): int
    {
        return (int) auth()->guard('admin')->id();
    }

    private function trace(string $event, array $ctx = []): void
    {
        ImageEditorLog::write(event: $event, ctx: $ctx);
    }

    /**
     * Working sources and uploads stay within 8192 pixels a side and 32 megapixels. A schema v2 render is the one
     * image allowed more: its output may be up to 16384 a side and 64 megapixels (ImageEditorSchema::renderLimits()).
     */
    private function decode(string $bytes, int $maxSide = 8192, int $maxPixels = 32000000): array
    {
        $info = @getimagesizefromstring($bytes);
        abort_unless($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true), 422, 'Upload a PNG, JPEG or WebP image.');
        abort_unless($info[0] <= $maxSide && $info[1] <= $maxSide && $maxPixels >= $info[0] * $info[1], 422, 'Image exceeds the '.$maxSide.'-pixel or '.intdiv($maxPixels, 1000000).'-megapixel limit.');
        $image = @imagecreatefromstring($bytes);
        abort_unless($image, 422, 'Image could not be decoded.');
        imagedestroy($image);

        return $info;
    }

    /** A resource row naming a blob (ADR 0009): its bytes are that FileObject's, never a file of its own. */
    private function createResource(int $assetId, int $fileObjectId, int $width, int $height, string $kind, ?array $details = null): ImageEditorResource
    {
        return ImageEditorResource::create(['id'=>(string) Str::uuid(), 'asset_id'=>$assetId, 'admin_id'=>$this->admin(), 'kind'=>$kind, 'status'=>'ready', 'file_object_id'=>$fileObjectId, 'width'=>$width, 'height'=>$height, 'details'=>$details]);
    }

    /**
     * Ingest an uploaded image as a blob, then attach it. putLocal() commits the blob and its FileObject row in
     * its own transaction, so a concurrent delete of another asset holding the same bytes can reap that row
     * before the attach: retain() re-finds or recreates it under the blob lock, taken after the asset row's.
     */
    private function ingestResource(int $assetId, string $localPath, array $info, ?array $details = null): ImageEditorResource
    {
        $store = app(ContentStore::class);
        $object = $store->putLocal(
            disk: Directory::getAssetDisk(),
            localPath: $localPath,
            mimeType: $info['mime'],
            extension: image_type_to_extension($info[2], false) ?: null,
        );

        return DB::transaction(function () use ($assetId, $object, $info, $details, $store) {
            Asset::lockedForGraph()->findOrFail($assetId);
            // Access again, on the locked row. The file being edited (main or stacked) was checked by the request.
            $this->authorizeAccess($assetId);

            return $this->createResource(assetId: $assetId, fileObjectId: (int) $store->retain($object)->getKey(), width: $info[0], height: $info[1], kind: 'upload', details: $details);
        });
    }

    /** Called inside the caller's transaction, with the asset row locked. */
    private function current(Asset $asset): ImageEditorDocument
    {
        $version = $asset->versions()->latest('number')->first();
        $existing = ImageEditorDocument::where('asset_id', $asset->id)->whereNull('attachment_id')->where('content_path', $asset->path)->where('asset_version_id', $version?->id)->latest('id')->first();
        abort_unless($existing, 409, 'The source changed. Reopen the editor to load the latest version.');

        return $existing;
    }

    public function document(Request $request, int $id)
    {
        [, $member] = $this->authorizeFor($request, $id);

        if ($member !== null) {
            return $this->memberDocument(id: $id, attachmentId: (int) $member->attachment_id);
        }

        $store = app(ContentStore::class);
        // Stage a private copy under the asset lock: deletion/version changes cannot remove the source
        // midway through staging. Processing and blob ingestion then run outside the attachment transaction.
        $snapshot = DB::transaction(function () use ($id, $store) {
            $asset = Asset::lockedForGraph()->findOrFail($id);
            $versionId = $asset->versions()->latest('number')->value('id');
            $existing = ImageEditorDocument::where('asset_id', $id)->whereNull('attachment_id')->where('content_path', $asset->path)->where('asset_version_id', $versionId)->latest('id')->first();
            if ($existing) {
                return null;
            }
            try {
                $object = $store->current($asset);

                return ['path' => $asset->path, 'version_id' => $versionId, 'sha256' => $object->sha256, 'object' => $object, 'local' => $store->localPath(object: $object, copy: true)];
            } catch (ContentUnavailable) {
                abort(422, 'The source image is not readable.');
            }
        });
        if ($snapshot) {
            $working = null;
            try {
                $working = app(ImageEditorWorkingCopy::class)->prepare(path: $snapshot['local']->path);
                $info = $working['info'];
                $object = $working['temporary'] !== null ? $store->putLocal(disk: Directory::getAssetDisk(), localPath: $working['path'], mimeType: $info['mime'], extension: 'png') : $snapshot['object'];
                DB::transaction(function () use ($id, $store, $snapshot, $working, $object, $info) {
                    $asset = Asset::lockedForGraph()->findOrFail($id);
                    $this->authorize($id);
                    $versionId = $asset->versions()->latest('number')->value('id');
                    try {
                        $sha256 = $store->current($asset)->sha256;
                    } catch (ContentUnavailable) {
                        abort(409, 'The source changed. Reopen the editor to load the latest version.');
                    }
                    abort_unless($asset->path === $snapshot['path'] && $versionId === $snapshot['version_id'] && $sha256 === $snapshot['sha256'], 409, 'The source changed. Reopen the editor to load the latest version.');
                    $existing = ImageEditorDocument::where('asset_id', $id)->whereNull('attachment_id')->where('content_path', $asset->path)->where('asset_version_id', $versionId)->latest('id')->first();
                    if ($existing) {
                        return;
                    }
                    $base = $this->createResource(assetId: $id, fileObjectId: (int) $store->retain($object)->getKey(), width: $info[0], height: $info[1], kind: 'base', details: $working['details']);
                    ImageEditorDocument::create(['asset_id'=>$id, 'asset_version_id'=>$versionId, 'revision'=>(string) Str::uuid(), 'content_path'=>$asset->path, 'original_base'=>$base->id, 'document'=>$this->schema->initial($base->id, $base->width, $base->height)]);
                });
            } finally {
                ($working['temporary'] ?? null)?->release();
                $snapshot['local']->release();
            }
        }

        return DB::transaction(function () use ($id) {
            $asset = Asset::lockedForGraph()->findOrFail($id);
            $record = $this->current($asset);
            $ids = array_unique([$record->original_base, ...$this->schema->resourceIds($record->document)]);
            $resources = [];
            foreach ($ids as $resource) {
                $resources[$resource] = route('admin.dam.assets.editor.resource', ['id' => $id, 'resource' => $resource]);
            }

            return response()->json([
                'document'      => $record->document,
                'revision'      => $record->revision,
                'original_base' => $record->original_base,
                'resources'     => $resources,
                'source'        => ImageEditorResource::find($record->original_base)?->details,
            ]);
        });
    }

    /** Called inside the caller's transaction, with the asset row locked: the stacked file in that slot, still a member. */
    private function lockedMember(Asset $asset, int $attachmentId): AssetFile
    {
        $member = app(StackMembers::class)->find(asset: $asset, attachmentId: $attachmentId);
        abort_if($member === null, 404, 'That file is no longer in this stack.');
        // Promoted to the main file since it was opened: an edit of it now is an edit of the entry, made from the cover.
        abort_if((bool) $member->attachment?->is_representative, 409, 'The source changed. Reopen the editor to load the latest version.');

        return $member;
    }

    /** The document opened on this stacked file's current revision, or null. A newer revision has none until it is opened. */
    private function memberRecord(Asset $asset, AssetFile $member): ?ImageEditorDocument
    {
        return ImageEditorDocument::where('asset_id', $asset->id)->where('attachment_id', $member->attachment_id)->where('source_asset_file_id', $member->id)->latest('id')->first();
    }

    /**
     * document() for one stacked file: the same staging as the main file's, keyed by the slot and the revision opened
     * instead of the entry's path and version. Its resources are the entry's own, so uploads and the base are shared
     * with the main file's documents the way they already are between its versions.
     */
    private function memberDocument(int $id, int $attachmentId)
    {
        $store = app(ContentStore::class);
        $snapshot = DB::transaction(function () use ($id, $attachmentId, $store) {
            $asset = Asset::lockedForGraph()->findOrFail($id);
            $member = $this->lockedMember(asset: $asset, attachmentId: $attachmentId);
            if ($this->memberRecord(asset: $asset, member: $member)) {
                return null;
            }
            $object = $member->fileObject;
            abort_unless($object instanceof FileObject, 422, 'The source image is not readable.');
            try {
                return ['revision_id' => (int) $member->id, 'object' => $object, 'local' => $store->localPath(object: $object, copy: true)];
            } catch (ContentUnavailable) {
                abort(422, 'The source image is not readable.');
            }
        });
        if ($snapshot) {
            $working = null;
            try {
                $working = app(ImageEditorWorkingCopy::class)->prepare(path: $snapshot['local']->path);
                $info = $working['info'];
                $object = $working['temporary'] !== null ? $store->putLocal(disk: Directory::getAssetDisk(), localPath: $working['path'], mimeType: $info['mime'], extension: 'png') : $snapshot['object'];
                DB::transaction(function () use ($id, $attachmentId, $store, $snapshot, $working, $object, $info) {
                    $asset = Asset::lockedForGraph()->findOrFail($id);
                    $this->authorizeAccess($id);
                    $member = $this->lockedMember(asset: $asset, attachmentId: $attachmentId);
                    abort_unless((int) $member->id === $snapshot['revision_id'], 409, 'The source changed. Reopen the editor to load the latest version.');
                    if ($this->memberRecord(asset: $asset, member: $member)) {
                        return;
                    }
                    $base = $this->createResource(assetId: $id, fileObjectId: (int) $store->retain($object)->getKey(), width: $info[0], height: $info[1], kind: 'base', details: $working['details']);
                    ImageEditorDocument::create(['asset_id'=>$id, 'attachment_id'=>$attachmentId, 'source_asset_file_id'=>$member->id, 'asset_version_id'=>null, 'revision'=>(string) Str::uuid(), 'content_path'=>$asset->path, 'original_base'=>$base->id, 'document'=>$this->schema->initial($base->id, $base->width, $base->height)]);
                });
            } finally {
                ($working['temporary'] ?? null)?->release();
                $snapshot['local']->release();
            }
        }

        return DB::transaction(function () use ($id, $attachmentId) {
            $asset = Asset::lockedForGraph()->findOrFail($id);
            $record = $this->memberRecord(asset: $asset, member: $this->lockedMember(asset: $asset, attachmentId: $attachmentId));
            abort_if($record === null, 409, 'The source changed. Reopen the editor to load the latest version.');
            $ids = array_unique([$record->original_base, ...$this->schema->resourceIds($record->document)]);
            $resources = [];
            foreach ($ids as $resource) {
                $resources[$resource] = route('admin.dam.assets.editor.resource', ['id' => $id, 'resource' => $resource, 'attachment' => $attachmentId]);
            }
            $this->trace('member_opened', ['asset_id' => $id, 'attachment_id' => $attachmentId, 'revision_id' => (int) $record->source_asset_file_id]);

            return response()->json([
                'document'      => $record->document,
                'revision'      => $record->revision,
                'original_base' => $record->original_base,
                'resources'     => $resources,
                'source'        => ImageEditorResource::find($record->original_base)?->details,
            ]);
        });
    }

    /**
     * save() for one stacked file. "version" writes the render as that file's next revision
     * (AttachmentLedger::appendRevision; the old one stays in its history), so it must keep the file's type: a JPEG
     * saves a JPEG and a PNG a PNG, and a WebP, which the editor saves as PNG, only as a new asset. "new" makes a new
     * entry beside the stack the way the main file's save-as-new does, named after the stacked file.
     */
    private function memberSave(Request $request, int $id, int $attachmentId)
    {
        $request->validate(['revision'=>'required|uuid', 'mode'=>'required|in:version,new', 'document'=>'required|string|max:1000000', 'render'=>'required|file|max:49152']);
        $new = $request->input('mode') === 'new';
        if (! $new && ($refusal = app(RoleBuilder::class)->refusal(Asset::query()->findOrFail($id)))) {
            abort(403, $refusal);
        }
        $document = $this->schema->decode($request->input('document'));
        $this->schema->validate($document);
        $bytes = file_get_contents($request->file('render')->getRealPath());
        $limits = $this->schema->renderLimits($document);
        $info = $this->decode($bytes, $limits['side'], $limits['pixels']);
        [$width,$height] = $this->schema->dimensions($document);
        abort_unless(in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) && $info[0] === $width && $info[1] === $height, 422, 'Rendered image dimensions do not match the document.');
        $isJpeg = $info[2] === IMAGETYPE_JPEG;
        $renderMime = $isJpeg ? 'image/jpeg' : 'image/png';
        $renderExtension = $isJpeg ? 'jpg' : 'png';

        $temp = tempnam(sys_get_temp_dir(), 'dam_editor_save_');
        file_put_contents($temp, $bytes);
        try {
            $object = app(ContentStore::class)->putLocal(disk: Directory::getAssetDisk(), localPath: $temp, sha256: hash('sha256', $bytes), mimeType: $renderMime, extension: $renderExtension);
        } finally {
            @unlink($temp);
        }

        // The same lock order as save() (ADR 0010): a save-as-new gives its copy the stack's folders' access lists.
        $writer = app(AccessListWriter::class);

        return $writer->fencedAndTreeLocked(function () use ($writer, $id, $attachmentId, $request, $document, $bytes, $width, $height, $object, $renderMime, $renderExtension, $isJpeg, $new) {
            $placements = $writer->holdPlacementFolders(assetIds: [$id]);
            $source = Asset::lockedForGraph()->findOrFail($id);
            $writer->assertPlacementsUnchanged(assetIds: [$id], locked: $placements);
            if (! $new && ($refusal = app(RoleBuilder::class)->refusal($source))) {
                abort(403, $refusal);
            }
            $member = $this->lockedMember(asset: $source, attachmentId: $attachmentId);
            // Save-as-new stays open to an editor opened on an older revision, as for the main file.
            $current = $new
                ? (ImageEditorDocument::where('asset_id', $id)->where('attachment_id', $attachmentId)->where('revision', $request->input('revision'))->first() ?? $this->memberRecord(asset: $source, member: $member))
                : $this->memberRecord(asset: $source, member: $member);
            abort_if($current === null, 409, 'The source changed. Reopen the editor to load the latest version.');
            if (! $new) {
                abort_unless(hash_equals($current->revision, $request->input('revision')), 409, 'A newer edit exists. Reopen it or save as a new asset.');
                $extension = StackFileName::extension($member, $source);
                abort_unless($isJpeg ? in_array($extension, ['jpg', 'jpeg'], true) : $extension === 'png', 422, 'This file type is saved as PNG. Save it as a new asset instead.');
            }
            $resourceIds = array_values(array_unique([...$this->schema->resourceIds($document), $current->original_base]));
            $refs = [];
            foreach ($resourceIds as $resourceId) {
                $r = Str::isUuid($resourceId) ? ImageEditorResource::where('asset_id', $id)->lockForUpdate()->find($resourceId) : null;
                if (! $r || ! in_array($r->status, self::USABLE) || $r->file_object_id === null) {
                    $this->schema->invalid('An image resource is unavailable.');
                }
                $refs[$resourceId] = $r;
            }
            $base = $refs[$document['base']];
            if ($base->width !== $document['sourceWidth'] || $base->height !== $document['sourceHeight']) {
                $this->schema->invalid('Source dimensions do not match the stored image.');
            }

            if (! $new) {
                $written = app(AttachmentLedger::class)->appendRevision(asset: $source, attachmentId: $attachmentId, object: $object, note: 'Image editor') ?? $member;
                $record = ImageEditorDocument::create(['asset_id'=>$id, 'attachment_id'=>$attachmentId, 'source_asset_file_id'=>$written->id, 'asset_version_id'=>null, 'revision'=>(string) Str::uuid(), 'content_path'=>$source->path, 'original_base'=>$current->original_base, 'document'=>$document]);
                $this->trace('member_saved', ['asset_id'=>$id, 'attachment_id'=>$attachmentId, 'revision_id'=>(int) $written->id]);

                return response()->json(['asset_id'=>$id, 'revision'=>$record->revision, 'version'=>(int) $written->revision_number]);
            }

            abort_unless(bouncer()->hasPermission('dam.asset.upload'), 403);
            $folderPath = dirname($source->path);
            $stem = pathinfo((string) $member->label, PATHINFO_FILENAME) ?: pathinfo($source->file_name, PATHINFO_FILENAME);
            $fileName = LibraryPaths::uniqueFileName($folderPath, str_replace(['/', '\\'], '-', $stem).' - edited.'.$renderExtension, null);
            $path = $folderPath.'/'.$fileName;
            $asset = new Asset;
            $asset->fill(['path'=>$path, 'file_name'=>$fileName, 'file_type'=>'image', 'extension'=>$renderExtension, 'mime_type'=>$renderMime, 'file_size'=>strlen($bytes), 'meta_data'=>['width'=>$width, 'height'=>$height]]);
            $asset->withGraphContent($object);
            $asset->save();
            $asset->directories()->sync($source->directories()->pluck('dam_directories.id'));
            $asset->tags()->sync($source->tags()->pluck('dam_tags.id'));
            $writer->inheritAssets([$asset->id]);
            $version = $asset->versions()->latest('number')->first()
                ?? AssetVersion::create(['dam_asset_id'=>$asset->id, 'number'=>1, 'created_by'=>$this->admin(), 'primary_asset_file_id'=>$asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'), 'note'=>'Image editor']);
            // The copy names the stack's blobs through rows of its own, as the main file's save-as-new does.
            $original = $current->original_base;
            foreach ($refs as $old=>$r) {
                $copied = $this->createResource(assetId: $asset->id, fileObjectId: (int) $r->file_object_id, width: $r->width, height: $r->height, kind: 'saved', details: $r->details);
                if ($document['base'] === $old) {
                    $document['base'] = $copied->id;
                }
                if (($document['background']['image'] ?? null) === $old) {
                    $document['background']['image'] = $copied->id;
                }
                foreach ($document['objects'] ?? [] as $index => $item) {
                    if (($item['type'] ?? null) === 'image' && $item['resource'] === $old) {
                        $document['objects'][$index]['resource'] = $copied->id;
                    }
                }
                if ($original === $old) {
                    $original = $copied->id;
                }
            }
            app(Lineage::class)->record(
                assetId: $source->id,
                recipe: Rendition::RECIPE_EDITOR_COPY,
                source: $member,
                worker: 'image-editor',
                outputAssetId: (int) $asset->id,
                parameters: ['attachment_id' => $attachmentId],
            );
            $record = ImageEditorDocument::create(['asset_id'=>$asset->id, 'asset_version_id'=>$version->id, 'revision'=>(string) Str::uuid(), 'content_path'=>$path, 'original_base'=>$original, 'document'=>$document]);
            $this->trace('member_saved_as_new', ['asset_id'=>$asset->id, 'source_asset_id'=>$id, 'attachment_id'=>$attachmentId]);

            return response()->json(['asset_id'=>$asset->id, 'revision'=>$record->revision, 'version'=>$version->number]);
        });
    }

    public function resource(Request $request, int $id, string $resource)
    {
        $this->authorizeFor($request, $id);
        $r = ImageEditorResource::where('asset_id', $id)->with('fileObject')->findOrFail($resource);
        // A row with no blob is pre-migration data whose file was never found (dam:storage:check reports it).
        abort_unless(in_array($r->status, self::USABLE) && $r->fileObject, 404);

        // deliver() adds nosniff and the CSP; a blob it cannot find is a 404 (ContentUnavailable). Same origin even
        // on S3: the editor draws these onto a canvas it exports, which a cross-origin redirect would taint.
        return app(ContentStore::class)->deliver(
            object: $r->fileObject,
            filename: $r->id,
            headers: ['Cache-Control'=>'private, no-store'],
            sameOrigin: true,
        );
    }

    public function upload(Request $request, int $id)
    {
        [$asset] = $this->authorizeFor($request, $id);
        $request->validate(['image'=>'required|file|max:32768']);
        $local = $request->file('image')->getRealPath();
        $working = app(ImageEditorWorkingCopy::class)->prepare(path: $local);
        try {
            $r = $this->ingestResource(assetId: $asset->id, localPath: $working['path'], info: $working['info'], details: $working['details']);
        } finally {
            $working['temporary']?->release();
        }

        $this->trace('resource_uploaded', ['asset_id'=>$id, 'resource_id'=>$r->id]);

        return response()->json(['id'=>$r->id, 'url'=>route('admin.dam.assets.editor.resource', array_filter(['id'=>$id, 'resource'=>$r->id, 'attachment'=>$request->input('attachment')])), 'source'=>$r->details, 'width'=>$r->width, 'height'=>$r->height]);
    }

    /**
     * Lineage (P3a S6): a save-as-new copy is an output of its source entry. The row names the source revision
     * the base image came from (the current one when the base is an editor-made resource) and lists the base
     * and any background image by checksum, with the revision when one holds those bytes.
     */
    private function recordCopyLineage(Asset $source, Asset $copy, ImageEditorResource $base, ?ImageEditorResource $background): void
    {
        $lineage = app(Lineage::class);
        $inputs = [];

        foreach ([LineageInput::ROLE_BASE => $base, LineageInput::ROLE_BACKGROUND => $background] as $role => $resource) {
            if ($resource === null) {
                continue;
            }
            $sha = FileObject::query()->whereKey($resource->file_object_id)->value('sha256');
            $inputs[] = ['role' => $role, 'asset_file_id' => $lineage->revisionWithSha(assetId: $source->id, sha256: $sha)?->id, 'sha256' => $sha];
        }

        $lineage->record(
            assetId: $source->id,
            recipe: Rendition::RECIPE_EDITOR_COPY,
            source: $lineage->revisionWithSha(assetId: $source->id, sha256: $inputs[0]['sha256']) ?? $lineage->currentRevision(assetId: $source->id),
            worker: 'image-editor',
            outputAssetId: (int) $copy->id,
            inputs: $inputs,
        );
    }

    public function save(Request $request, int $id)
    {
        [, $member] = $this->authorizeFor($request, $id);

        if ($member !== null) {
            return $this->memberSave(request: $request, id: $id, attachmentId: (int) $member->attachment_id);
        }
        $request->validate(['revision'=>'required|uuid', 'mode'=>'required|in:version,new', 'document'=>'required|string|max:1000000', 'render'=>'required|file|max:49152']);
        // Saving over the asset as a new version replaces someone else's file when it is not yours; a role limited to
        // its own items does that only to files it created. Save-as-new makes a copy and stays open to everyone.
        if ($request->input('mode') === 'version' && ($refusal = app(RoleBuilder::class)->refusal(Asset::query()->findOrFail($id)))) {
            abort(403, $refusal);
        }
        $document = $this->schema->decode($request->input('document'));
        $this->schema->validate($document);
        $bytes = file_get_contents($request->file('render')->getRealPath());
        $limits = $this->schema->renderLimits($document);
        $info = $this->decode($bytes, $limits['side'], $limits['pixels']);
        [$width,$height] = $this->schema->dimensions($document);
        // PNG always; JPEG when the source is a JPEG, so a version save keeps the file's type (QRAI-13).
        abort_unless(in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) && $info[0] === $width && $info[1] === $height, 422, 'Rendered image dimensions do not match the document.');
        $isJpeg = $info[2] === IMAGETYPE_JPEG;
        $renderMime = $isJpeg ? 'image/jpeg' : 'image/png';
        $renderExtension = $isJpeg ? 'jpg' : 'png';
        $hash = hash('sha256', $bytes);

        // Ingested before the transaction (ADR 0009 slice 3): ContentStore::putLocal commits the blob and
        // its FileObject row in its own transaction, so a later rollback here never strands or re-hashes it.
        $temp = tempnam(sys_get_temp_dir(), 'dam_editor_save_');
        file_put_contents($temp, $bytes);
        try {
            $object = app(ContentStore::class)->putLocal(
                disk: Directory::getAssetDisk(),
                localPath: $temp,
                sha256: $hash,
                mimeType: $renderMime,
                extension: $renderExtension,
            );
        } finally {
            @unlink($temp);
        }

        // $object was ingested and committed above, so a rollback here leaves a content-addressed blob with no
        // Asset yet, not a stray file: nothing to clean up. Resources name blobs too, so none are written here.
        // ADR 0010's lock order, in full (Codex review of PR #74, finding 3): the DAM_ACCESS_MODEL row, the
        // directory tree lock, this asset's placement folders FOR SHARE, then the asset rows, then the graph
        // and the blob. A save-as-new gives its copy those folders' access lists, and asking for a folder row
        // after an asset row is the one cycle a cascade closes. Everything inside is database-only: the
        // render's own blob was committed above, and the observer's retain() takes only rows and the blob's
        // advisory lock.
        $writer = app(AccessListWriter::class);

        return $writer->fencedAndTreeLocked(function () use ($writer, $id, $request, $document, $bytes, $width, $height, $object, $renderMime, $renderExtension) {
            $placements = $writer->holdPlacementFolders(assetIds: [$id]);
            $source = Asset::lockedForGraph()->findOrFail($id);
            // Read before the asset was locked, so they are only good if nothing moved the file meanwhile;
            // fencedAndTreeLocked() runs this body again from the start if something did.
            $writer->assertPlacementsUnchanged(assetIds: [$id], locked: $placements);
            // Asked again of the locked row: ownership is the one thing the first, unlocked check cannot hold still.
            if ($request->input('mode') === 'version' && ($refusal = app(RoleBuilder::class)->refusal($source))) {
                abort(403, $refusal);
            }
            // Save-as-new remains available for a stale editor after the original was replaced elsewhere.
            // Its already-opened revision supplies the bounded Revert base, without processing a new original here.
            $current = $request->input('mode') === 'new'
                ? (ImageEditorDocument::where('asset_id', $id)->whereNull('attachment_id')->where('revision', $request->input('revision'))->first() ?? $this->current($source))
                : $this->current($source);
            if ($request->input('mode') === 'version') {
                abort_unless(hash_equals($current->revision, $request->input('revision')), 409, 'A newer edit exists. Reopen it or save as a new asset.');
            }
            // Every image the document names must be this asset's own: the base, the background and each image object.
            $resourceIds = array_values(array_unique([...$this->schema->resourceIds($document), $current->original_base]));
            $refs = [];
            foreach ($resourceIds as $resourceId) {
                // Resource keys are uuids; anything else the editor's schema lets through is not one of this asset's.
                $r = Str::isUuid($resourceId) ? ImageEditorResource::where('asset_id', $id)->lockForUpdate()->find($resourceId) : null;
                if (! $r || ! in_array($r->status, self::USABLE) || $r->file_object_id === null) {
                    $this->schema->invalid('An image resource is unavailable.');
                }$refs[$resourceId] = $r;
            }
            $base = $refs[$document['base']];
            $background = $document['background']['image'] ?? null;
            if ($base->width !== $document['sourceWidth'] || $base->height !== $document['sourceHeight']) {
                $this->schema->invalid('Source dimensions do not match the stored image.');
            }
            $new = $request->input('mode') === 'new';
            if ($new) {
                abort_unless(bouncer()->hasPermission('dam.asset.upload'), 403);
            }
            $asset = $new ? new Asset : $source;
            // Off-tree physical paths go (ADR 0009 slice 3): the saved asset's Library path is a real
            // folder-tree name, the source's own folder, checked against dam_assets.path for uniqueness
            // since a version-mode save's extension flip (to .png, or .jpg for a JPEG render) can collide with an existing sibling.
            $folderPath = dirname($source->path);
            $fileName = LibraryPaths::uniqueFileName(
                $folderPath,
                pathinfo($source->file_name, PATHINFO_FILENAME).($new ? ' - edited' : '').'.'.$renderExtension,
                $new ? null : $asset->id,
            );
            $path = $folderPath.'/'.$fileName;
            $asset->fill(['path'=>$path, 'file_name'=>$fileName, 'file_type'=>'image', 'extension'=>$renderExtension, 'mime_type'=>$renderMime, 'file_size'=>strlen($bytes), 'title'=>$source->title, 'meta_data'=>array_merge($source->meta_data ?? [], ['width'=>$width, 'height'=>$height])]);
            $asset->withGraphContent($object);
            $asset->save();
            if ($new) {
                $asset->directories()->sync($source->directories()->pluck('dam_directories.id'));
                $asset->tags()->sync($source->tags()->pluck('dam_tags.id'));
                // A new asset takes the access list of the folders it lands in (ADR 0010, spec D3). A
                // version-mode save keeps the source asset and so keeps its own list.
                app(AccessListWriter::class)->inheritAssets([$asset->id]);
            }
            $version = $asset->versions()->latest('number')->first();
            if (! $version || ImageEditorDocument::where('asset_version_id', $version->id)->exists()) {
                $version = AssetVersion::create(['dam_asset_id'=>$asset->id, 'number'=>($version?->number ?? 0) + 1, 'created_by'=>$this->admin(), 'primary_asset_file_id'=>$asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('id'), 'note'=>'Image editor']);
            }
            $original = $current->original_base;
            if ($new) {
                // The copy shares the source's blobs rather than copying bytes: its rows name the same
                // FileObjects, which the source's rows (locked above) keep referenced until these commit.
                foreach ($refs as $old=>$r) {
                    $copied = $this->createResource(assetId: $asset->id, fileObjectId: (int) $r->file_object_id, width: $r->width, height: $r->height, kind: 'saved', details: $r->details);
                    if ($document['base'] === $old) {
                        $document['base'] = $copied->id;
                    }
                    if (($document['background']['image'] ?? null) === $old) {
                        $document['background']['image'] = $copied->id;
                    }
                    // Image objects point at the copy's own rows too, whether one object names a resource or many do.
                    foreach ($document['objects'] ?? [] as $index => $object) {
                        if (($object['type'] ?? null) === 'image' && $object['resource'] === $old) {
                            $document['objects'][$index]['resource'] = $copied->id;
                        }
                    }
                    if ($original === $old) {
                        $original = $copied->id;
                    }
                }
            }
            if ($new) {
                $this->recordCopyLineage(source: $source, copy: $asset, base: $base, background: $refs[$background] ?? null);
            }
            $record = ImageEditorDocument::create(['asset_id'=>$asset->id, 'asset_version_id'=>$version->id, 'revision'=>(string) Str::uuid(), 'content_path'=>$path, 'original_base'=>$original, 'document'=>$document]);
            $this->trace('saved', ['asset_id'=>$asset->id, 'version_id'=>$version->id]);

            return response()->json(['asset_id'=>$asset->id, 'revision'=>$record->revision, 'version'=>$version->number]);
        });
    }
}
