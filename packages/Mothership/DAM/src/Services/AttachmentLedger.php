<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Jobs\ComputeAssetPhash;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Support\CoverArt;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Support\StackFileName;
use Symfony\Component\Mime\MimeTypes;

/**
 * The one writer of an asset entry's attachment slots (P3a S3, S5): attach, remove, promote, edit, reorder and
 * restore, shared by the admin UI and the REST API so both apply the same rules. Callers authorize first.
 *
 * It keeps S2's role invariant: a current revision carries its slot's role and a superseded one carries
 * `version`; the representative slot's role is `original` and no other slot has it. Lock order is the
 * asset row (lockedForGraph), then its slots, then their revisions, then blobs (ContentStore::lockBlob).
 */
class AttachmentLedger
{
    /** Roles that are derived previews of the representative, never a file that can stand for the entry. */
    public const UNPROMOTABLE_ROLES = [AssetFile::ROLE_PROXY, AssetFile::ROLE_THUMBNAIL];

    public function attach(Asset $asset, UploadedFile $upload, string $role, ?string $label): AssetFile
    {
        $sha256 = hash_file('sha256', $upload->getRealPath());
        $disk = Directory::getAssetDisk();
        $realPath = $upload->getRealPath();
        $mime = $upload->getMimeType();
        $store = app(ContentStore::class);

        return DB::transaction(function () use ($asset, $upload, $role, $label, $disk, $realPath, $sha256, $mime, $store) {
            // Locked before any blob (ContentStore::lockBlob's lock order) — also serializes
            // additions on this asset, including its attachment order.
            Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();

            // Inside this transaction, so a FileObject record() creates or reuses commits (or
            // rolls back) together with the AssetFile reference below — a later failure in this
            // same request (the asset vanishing concurrently, a constraint violation) can never
            // strand an unreferenced row.
            $object = $store->putLocal(disk: $disk, localPath: $realPath, sha256: $sha256, mimeType: $mime);

            // record() takes ContentStore::lockBlob() itself, held until this transaction
            // commits — but the row it returned can still be one a concurrent last-reference
            // destroy() deleted (or already unlinked) before this transaction started. Re-lock
            // it and, if it's gone or its blob is missing, write it again before attaching.
            $locked = FileObject::whereKey($object->id)->lockForUpdate()->first();

            if ($locked === null || ! $store->holdsBlob($locked)) {
                $object = $store->putLocal(disk: $disk, localPath: $realPath, sha256: $sha256, mimeType: $mime);
                $locked = FileObject::whereKey($object->id)->lockForUpdate()->firstOrFail();
            }

            return $this->openSlot(asset: $asset, object: $locked, role: $role, label: $label ?? mb_substr($upload->getClientOriginalName(), 0, 191));
        });
    }

    /**
     * Attaches bytes already in the store (P3b-2 Stack instead: the blob of an upload that duplicates this entry).
     * The blob stays referenced by the row that holds it now, so no write to storage is needed. Same rules as
     * attach(): its own new slot at the end, and the same bytes already current under the role are a no-op.
     */
    public function attachObject(Asset $asset, FileObject $object, string $role, string $label): AssetFile
    {
        return DB::transaction(function () use ($asset, $object, $role, $label) {
            Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            $locked = FileObject::whereKey($object->id)->lockForUpdate()->firstOrFail();

            return $this->openSlot(asset: $asset, object: $locked, role: $role, label: mb_substr($label, 0, 191));
        });
    }

    /** A new slot holding `$object` as revision 1, unless those bytes are already current under `$role`. Caller holds the locks. */
    private function openSlot(Asset $asset, FileObject $object, string $role, string $label): AssetFile
    {
        // The same bytes already current under this role are the same attachment: attaching twice is a no-op.
        $existing = AssetFile::query()
            ->where('dam_asset_id', $asset->id)
            ->where('file_object_id', $object->id)
            ->where('role', $role)
            ->where('is_current', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $position = (int) Attachment::query()->where('dam_asset_id', $asset->id)->max('position') + 1;
        $slot = Attachment::create([
            'dam_asset_id' => $asset->id,
            'role'         => $role,
            'label'        => $label,
            'position'     => $position,
            'created_by'   => $this->actor(),
        ]);

        $file = AssetFile::create([
            'dam_asset_id'    => $asset->id,
            'file_object_id'  => $object->id,
            'role'            => $role,
            'label'           => $label,
            'sort'            => $position,
            'attachment_id'   => $slot->id,
            'revision_number' => 1,
            'is_current'      => true,
            'created_by'      => $this->actor(),
        ]);

        // A video added to a stack gets a preview proxy of its own, as the cover does (feedback #6).
        GenerateVideoPreviewProxy::dispatchForMember($file);

        return $file;
    }

    /**
     * Removes one attachment. The representative and anything history points at stay: removing them would
     * break the asset's content or the records that name them, so both answer 422 rather than a 500.
     *
     * @return array<int, int> file-deletion tombstone ids for AssetDeletionService::processPendingFileDeletions()
     */
    public function remove(Asset $asset, int $assetFileId): array
    {
        return DB::transaction(function () use ($asset, $assetFileId) {
            $locked = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $file = AssetFile::where('dam_asset_id', $asset->id)->findOrFail($assetFileId);
            $this->abortUnlessOwn(asset: $asset, file: $file);
            abort_if($file->role === AssetFile::ROLE_ORIGINAL, 422, trans('dam::app.admin.dam.asset.files.original-protected'));
            // An earlier version, or a file a version or generated output points at, is history: removing it
            // would break those records (the composite owner keys refuse it), so it answers 422, not a 500.
            // A slot with more than one revision carries its own history (a demoted representative) too.
            abort_if(
                $file->role === AssetFile::ROLE_VERSION
                    || AssetVersion::where('primary_asset_file_id', $file->id)->exists()
                    || Rendition::where('source_asset_file_id', $file->id)->exists()
                    || ($file->attachment_id !== null && AssetFile::where('attachment_id', $file->attachment_id)->count() > 1),
                422,
                trans('dam::app.admin.dam.asset.files.history-protected')
            );
            $object = FileObject::whereKey($file->file_object_id)->lockForUpdate()->firstOrFail();
            $file->delete();

            if (isset($locked->meta_data[StackMemberState::KEY])) {
                $locked->update(['meta_data' => [...$locked->meta_data, StackMemberState::KEY => StackMemberState::retainedStates($locked)]]);
            }

            if ($object->isReferenced()) {
                return [];
            }

            $object->delete();

            // The object's own locator, plus the suffix-less twin the pre-ADR-0009 observer
            // used to write to (see AssetDeletionService::delete()); deduped since an
            // already-suffix-less original names the same path twice.
            $locations = collect([
                ['disk' => $object->disk, 'path' => $object->path],
                ['disk' => $object->disk, 'path' => app(ContentStore::class)->objectPath($object->sha256)],
            ])->unique(fn (array $location) => $location['disk']."\0".$location['path'])->values()->all();

            return app(AssetDeletionService::class)->queueFileDeletions($locations);
        });
    }

    /**
     * A role limited to its own items removes only what it owns: files on an asset it created, or a file it attached
     * itself (AssetFile.created_by is the uploader). Everyone else's file answers 403 in plain words and nothing is
     * touched, so no row goes and no blob is queued for unlinking. Full admins and unrestricted roles are never
     * restricted, and neither is a system caller with no signed-in admin.
     */
    private function abortUnlessOwn(Asset $asset, AssetFile $file): void
    {
        $roles = app(RoleBuilder::class);

        if (! $roles->restrictedToOwn()) {
            return;
        }

        $actor = (int) $roles->actingAdmin()?->id;

        if ($roles->mayChange($asset) || ($actor > 0 && (int) $file->created_by === $actor)) {
            return;
        }

        abort(403, trans('dam::app.admin.permissions.own-only-delete'));
    }

    /**
     * Promoting a file or restoring a revision changes what the entry itself is (its content, name and type), so a role
     * limited to its own items may do it only to an asset it created. Read on the locked row, before any write; web and
     * REST both pass here. A signed-out system caller and unrestricted roles are never restricted.
     */
    private function abortUnlessOwnAsset(Asset $asset): void
    {
        $roles = app(RoleBuilder::class);

        if ($roles->restrictedToOwn() && ! $roles->mayChange($asset)) {
            abort(403, trans('dam::app.admin.permissions.own-only-change'));
        }
    }

    /**
     * Makes another attachment the one that stands for the entry. The two slots swap roles, so the old
     * representative keeps the promoted slot's role and its own history, and the asset row is re-described
     * from the promoted file: its type, size and extension, and a Library name with that extension. The
     * card's thumbnail and preview follow, since they are keyed by the current original's bytes.
     */
    public function promote(Asset $asset, int $attachmentId, ?array $memberState = null): Asset
    {
        // The capture the incoming file already has is looked up before any row is locked: it asks the disk.
        $incomingSha = AssetFile::where('attachment_id', $attachmentId)->where('dam_asset_id', $asset->id)->where('is_current', true)->with('fileObject')->first()?->fileObject?->sha256;
        $adoptable = $memberState === null ? ModelFileThumbnailService::adoptable(sha256: $incomingSha, assetId: (int) $asset->id) : [];

        $promoted = DB::transaction(function () use ($asset, $attachmentId, $adoptable, $incomingSha, $memberState): ?Asset {
            $locked = Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            $this->abortUnlessOwnAsset(asset: $locked);
            $slots = Attachment::where('dam_asset_id', $asset->id)->lockForUpdate()->get()->keyBy('id');
            $target = $slots->get($attachmentId);
            abort_if($target === null, 404);

            if ($target->is_representative) {
                return null;
            }

            if (in_array($target->role, self::UNPROMOTABLE_ROLES, true)) {
                throw ValidationException::withMessages(['attachment' => [trans('dam::app.admin.dam.asset.files.promote-derived')]]);
            }

            $incoming = AssetFile::where('attachment_id', $target->id)->where('is_current', true)
                ->with('fileObject')->lockForUpdate()->firstOrFail();
            $representative = $slots->firstWhere('is_representative', true);
            $outgoing = $representative === null ? null
                : AssetFile::where('attachment_id', $representative->id)->where('is_current', true)->lockForUpdate()->first();
            $role = $target->role;
            $object = $incoming->fileObject;
            // Before any write: describing a zip can refuse it (classify()).
            // Making a file the representative is the one change that names the entry's download for it: the name the file
            // already goes by, never a historical label. A file with no label leaves the name as it is.
            $label = trim((string) ($incoming->label ?: $target->label));
            $description = $this->describe(asset: $locked, object: $object, label: $label, adoptable: $object->sha256 === $incomingSha ? $adoptable : null, rename: $label !== '');

            if ($memberState !== null && $outgoing !== null) {
                $description['meta_data'] = StackMemberState::switchTo(asset: $locked, outgoing: $outgoing, incoming: $memberState);
            }

            // Free `original` and the representative flag before taking them: both have a one-per-asset index.
            // The two slots also trade places, so the order a person set survives a promote.
            $positions = [$target->id => $representative?->position ?? 0];

            if ($representative !== null) {
                // The old original usually has no label (the Library name stood for it); name it now so the
                // file keeps reading as itself after the Library name changes.
                $name = $outgoing?->label ?: $representative->label ?: $locked->file_name;
                $positions[$representative->id] = $target->position;
                $representative->update(['is_representative' => false, 'role' => $role, 'label' => $representative->label ?: $name, 'position' => $target->position]);
                $outgoing?->update(['role' => $role, 'label' => $name]);
            }

            $target->update(['is_representative' => true, 'role' => AssetFile::ROLE_ORIGINAL, 'position' => $positions[$target->id]]);
            $incoming->update(['role' => AssetFile::ROLE_ORIGINAL]);

            foreach ($positions as $slotId => $position) {
                AssetFile::where('attachment_id', $slotId)->update(['sort' => $position]);
            }

            // The graph already holds this content as the current original, so the observer appends nothing.
            $locked->withGraphContent($object)->update($description);

            AiIndexDispatcher::contentReplaced((int) $locked->id);
            DB::afterCommit(fn () => $this->log('attachment.promoted', ['asset' => $locked->id, 'attachment' => $target->id, 'previous' => $representative?->id]));

            return $locked;
        });

        if ($promoted === null) {
            return $asset->fresh();
        }

        if ($memberState === null) {
            $this->refreshDerived($promoted->id, keepCustomThumbnail: true);
        }

        return $promoted->fresh();
    }

    /**
     * Makes an earlier revision of a slot current again by appending it as the slot's next revision: the
     * history only grows, and the new revision shares the old one's blob. Restoring in the representative
     * slot replaces the entry's content, so it goes through the graph like a re-upload: the asset row is
     * re-described, an asset version is logged and the thumbnail, preview and metadata follow.
     */
    public function restore(Asset $asset, int $attachmentId, int $revisionId): AssetFile
    {
        // What asks the disk happens before any row is locked: whether the old bytes are still held, and the capture they have.
        $earlier = AssetFile::where('attachment_id', $attachmentId)->where('dam_asset_id', $asset->id)->whereKey($revisionId)->with('fileObject')->first();
        $held = $earlier?->fileObject === null || app(ContentStore::class)->holdsBlob($earlier->fileObject);
        $adoptable = $earlier?->fileObject === null ? null : ModelFileThumbnailService::adoptable(sha256: $earlier->fileObject->sha256, assetId: (int) $asset->id);

        [$restored, $representative] = DB::transaction(function () use ($asset, $attachmentId, $revisionId, $held, $adoptable): array {
            $locked = Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            $this->abortUnlessOwnAsset(asset: $locked);
            $slot = Attachment::where('dam_asset_id', $asset->id)->lockForUpdate()->findOrFail($attachmentId);
            $revisions = AssetFile::where('attachment_id', $slot->id)->with('fileObject')->lockForUpdate()->get()->keyBy('id');
            $target = $revisions->get($revisionId);
            abort_if($target === null || $target->fileObject === null, 404);
            $current = $revisions->firstWhere('is_current', true);

            if ($target->is_current || $current?->fileObject?->sha256 === $target->fileObject->sha256) {
                throw ValidationException::withMessages(['revision' => [trans('dam::app.admin.dam.asset.files.restore-current')]]);
            }

            // Its bytes must still be there: retain() and the graph hand back a live row without reading its blob.
            if (! $held) {
                throw ValidationException::withMessages(['revision' => [trans('dam::app.admin.dam.asset.files.restore-missing')]]);
            }

            $note = trans('dam::app.admin.dam.asset.files.restored-note', ['number' => $target->revision_number]);
            $restored = $this->appendToSlot(locked: $locked, slot: $slot, revisions: $revisions, object: $target->fileObject, label: (string) $target->label, note: $note, adoptable: $adoptable);
            $this->log('attachment.restored', ['asset' => $locked->id, 'attachment' => $slot->id, 'from' => $target->id, 'revision' => $restored->id]);

            return [$restored, $slot->is_representative];
        });

        if ($representative) {
            $this->refreshDerived($asset->id, bytesChanged: true);
        }

        return $restored->fresh();
    }

    /**
     * P3b-1: appends bytes held elsewhere as a slot's next revision, the way a restore appends an earlier one, for a
     * writer that brings revisions in from another entry: a merge split copying a destination slot's newer revisions
     * back to the source slot they came from. The caller authorizes and runs inside its own transaction; when the
     * slot is the representative, the entry's content changed, so the caller runs refreshDerived() once it commits.
     * Null when those bytes already are the slot's current ones: there is nothing to append.
     */
    public function appendRevision(Asset $asset, int $attachmentId, FileObject $object, string $note): ?AssetFile
    {
        $adoptable = ModelFileThumbnailService::adoptable(sha256: $object->sha256, assetId: (int) $asset->id);

        return DB::transaction(function () use ($asset, $attachmentId, $object, $note, $adoptable): ?AssetFile {
            $locked = Asset::withTrashed()->whereKey($asset->id)->lockedForGraph()->firstOrFail();
            $slot = Attachment::where('dam_asset_id', $asset->id)->lockForUpdate()->findOrFail($attachmentId);
            $revisions = AssetFile::where('attachment_id', $slot->id)->with('fileObject')->lockForUpdate()->get()->keyBy('id');

            if ($revisions->firstWhere('is_current', true)?->fileObject?->sha256 === $object->sha256) {
                return null;
            }

            $appended = $this->appendToSlot(locked: $locked, slot: $slot, revisions: $revisions, object: $object, label: (string) $slot->label, note: $note, adoptable: $adoptable);
            $this->log('attachment.appended', ['asset' => $locked->id, 'attachment' => $slot->id, 'revision' => $appended->id]);

            return $appended;
        });
    }

    /**
     * Appends $object as the slot's next revision, sharing its blob. In the representative slot this replaces the
     * entry's content, so it goes through the graph like a re-upload: the asset row is re-described and the observer
     * demotes the current original and appends the revision and an asset version (touch() fires it even when no
     * descriptor changes). In any other slot the current revision is demoted to `version` here.
     *
     * @param  Collection<int, AssetFile>  $revisions  the slot's revisions, locked
     */
    private function appendToSlot(Asset $locked, Attachment $slot, Collection $revisions, FileObject $object, string $label, string $note, ?array $adoptable = null): AssetFile
    {
        if ($slot->is_representative) {
            $locked->withGraphContent($object)->fill($this->describe(asset: $locked, object: $object, label: $label, adoptable: $adoptable));
            $locked->touch();
            $appended = AssetFile::where('attachment_id', $slot->id)->where('is_current', true)->firstOrFail();
            $appended->update(['note' => $note]);
            AssetVersion::where('primary_asset_file_id', $appended->id)->update(['note' => $note]);

            return $appended;
        }

        $current = $revisions->firstWhere('is_current', true);
        $object = app(ContentStore::class)->retain($object);
        // Free the slot's one-current index before the new revision takes it.
        $current?->update(['role' => AssetFile::ROLE_VERSION, 'is_current' => false]);

        return AssetFile::create([
            'dam_asset_id'    => $locked->id,
            'file_object_id'  => $object->id,
            'role'            => $slot->role,
            'label'           => $current?->label ?? $slot->label,
            'sort'            => $slot->position,
            'attachment_id'   => $slot->id,
            'revision_number' => (int) $revisions->max('revision_number') + 1,
            'is_current'      => true,
            'created_by'      => $this->actor(),
            'note'            => $note,
        ]);
    }

    /**
     * The asset row's description of a new representative file: its type, size and extension. With `$rename` the Library
     * name becomes the file's own (its label, with its type's extension if it names none), unique in its folder, and the
     * name the entry had is pinned as its title; without it `file_name` and `path` are not part of the description at
     * all, so a restore or an append can never undo a rename.
     *
     * @param  array<string, string>|null  $adoptable  the capture these bytes already have, looked up before any lock
     */
    private function describe(Asset $asset, FileObject $object, string $label, ?array $adoptable = null, bool $rename = false): array
    {
        $extension = $this->extensionFor(label: $label, mimeType: (string) $object->mime_type);
        $names = [];

        if ($rename) {
            $directory = Str::beforeLast((string) $asset->path, '/');
            $fileName = LibraryPaths::uniqueFileName($directory, str_replace(['/', '\\'], '-', StackFileName::forLabel(label: $label, extension: $extension)), $asset->id);
            $names = ['file_name' => $fileName, 'path' => $directory.'/'.$fileName];

            // The stack keeps the name it had: the old one is pinned as the title, which the Library shows before the
            // file name. A title someone set stays as it is.
            if ($fileName !== $asset->file_name && trim((string) $asset->title) === '') {
                $names['title'] = $asset->display_name;
            }
        }

        return [
            ...$names,
            'extension' => $extension,
            'mime_type' => $object->mime_type,
            'file_size' => (int) $object->size,
            'file_type' => $this->classify(object: $object, extension: $extension),
            // Embedded metadata, posters and captures described the old file; finalisation re-reads them. A custom
            // thumbnail is art a person chose for the entry, independent of any file's bytes, so it stays.
            // A 3D file that was captured before (in this stack or as another entry) shows that picture at once, not a
            // blank until the viewer is opened again.
            'meta_data' => [...array_intersect_key(is_array($asset->meta_data) ? $asset->meta_data : [], ['custom_thumbnail' => true, StackMemberState::KEY => true]), ...($adoptable ?? ModelFileThumbnailService::adoptable(sha256: $object->sha256, assetId: (int) $asset->id))] ?: null,
        ];
    }

    /**
     * The file type of a new representative. A zip is a web page only when its archive holds one, which the
     * classifier must open, so a zip is classified from a local copy of its bytes (removed again afterwards).
     * An attachment skipped bundle inspection, but a representative web bundle is served, so a zip the upload
     * rule would refuse (a script entry, an unsafe path or symlink, past the limits) answers 422 here.
     */
    private function classify(FileObject $object, string $extension): string
    {
        if ($extension !== 'zip') {
            return AssetHelper::classifyFile(mimeType: (string) $object->mime_type, extension: $extension);
        }

        $local = app(ContentStore::class)->localPath(object: $object, extension: $extension);

        try {
            if (AssetHelper::isForbiddenFile(extension: $extension, mimeType: (string) $object->mime_type, realPath: $local->path)) {
                throw ValidationException::withMessages(['attachment' => [trans('dam::app.admin.dam.asset.files.unsafe-bundle')]]);
            }

            return AssetHelper::classifyFile(mimeType: (string) $object->mime_type, extension: $extension, realPath: $local->path);
        } finally {
            $local->release();
        }
    }

    /** After the representative's content changes: drop its cover and re-run upload finalisation. */
    public function refreshDerived(int $assetId, bool $keepCustomThumbnail = false, bool $bytesChanged = false): void
    {
        CoverArt::purgeLegacy(assetId: $assetId, disk: Directory::getAssetDisk());

        ProcessAssetUpload::dispatch($assetId, null, $keepCustomThumbnail, $bytesChanged);
    }

    /** Edits one slot's role, label or position. The representative's role is `original`; promote changes it. */
    public function update(Asset $asset, int $attachmentId, array $data): Attachment
    {
        return DB::transaction(function () use ($asset, $attachmentId, $data): Attachment {
            $locked = Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            // Renaming, re-roling or re-ordering a file on someone else's item changes their data: own-only.
            app(RoleBuilder::class)->abortUnlessOwn(model: $locked, reason: 'own-only-label');
            $slot = Attachment::where('dam_asset_id', $asset->id)->lockForUpdate()->findOrFail($attachmentId);
            $current = AssetFile::where('attachment_id', $slot->id)->where('is_current', true)->lockForUpdate()->first();

            if (array_key_exists('role', $data) && $data['role'] !== $slot->role) {
                if ($slot->is_representative) {
                    throw ValidationException::withMessages(['role' => [trans('dam::app.admin.dam.asset.files.representative-role')]]);
                }

                $slot->role = $data['role'];
                $current?->fill(['role' => $data['role']]);
            }

            if (array_key_exists('label', $data)) {
                $label = trim((string) $data['label']) === '' ? null : trim((string) $data['label']);
                $slot->label = $label;
                $current?->fill(['label' => $label]);
            }

            $slot->save();
            $current?->save();

            if (array_key_exists('position', $data)) {
                $order = Attachment::where('dam_asset_id', $asset->id)->orderBy('position')->orderBy('id')->pluck('id')
                    ->reject(fn ($id) => (int) $id === $slot->id)->values()->all();
                array_splice($order, max(0, min((int) $data['position'], count($order))), 0, [$slot->id]);
                $this->writeOrder($asset->id, $order);
            }

            $this->log('attachment.updated', ['asset' => $asset->id, 'attachment' => $slot->id, 'fields' => array_keys($data)]);

            return $slot->fresh();
        });
    }

    /**
     * Saves a full ordering of the entry's slots. The list must name every slot exactly once, so a client
     * working from a stale list is refused instead of silently dropping a file from the order.
     *
     * @param  array<int, int>  $attachmentIds
     */
    public function reorder(Asset $asset, array $attachmentIds, ?array $expectedFiles = null): void
    {
        $candidate = AssetFile::where('dam_asset_id', $asset->id)
            ->where('attachment_id', $attachmentIds[0] ?? 0)->where('is_current', true)->with(['fileObject', 'attachment'])->first();
        // Disk-dependent discovery stays outside row locks; remembered metadata is selected again under the lock.
        $discovery = clone $asset;
        $discovery->meta_data = array_diff_key($discovery->meta_data ?? [], [StackMemberState::KEY => true]);
        $discoveredState = $candidate && ! $candidate->attachment?->is_representative
            ? StackMemberState::incoming(asset: $discovery, file: $candidate) : [];
        $needsProcessing = false;
        $changedFirst = false;
        DB::transaction(function () use ($asset, $attachmentIds, $expectedFiles, $candidate, $discoveredState, &$needsProcessing, &$changedFirst): void {
            // A PostgreSQL deadlock rolls back the whole attempt, including the nested promotion.
            // Recheck identity/order from fresh rows and discard any flags from that failed attempt.
            $needsProcessing = false;
            $changedFirst = false;
            $locked = Asset::whereKey($asset->id)->lockedForGraph()->firstOrFail();
            app(RoleBuilder::class)->abortUnlessOwn(model: $locked, reason: 'own-only-label');
            $existing = Attachment::where('dam_asset_id', $asset->id)->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $given = collect($attachmentIds)->map(fn ($id) => (int) $id);

            if ($given->duplicates()->isNotEmpty() || $given->sort()->values()->all() !== $existing) {
                throw ValidationException::withMessages(['order' => [trans('dam::app.admin.dam.asset.files.order-stale')]]);
            }

            $current = AssetFile::where('dam_asset_id', $asset->id)->where('is_current', true)
                ->whereIn('attachment_id', $existing)->orderBy('sort')->orderBy('id')->lockForUpdate()->get();
            if ($expectedFiles !== null) {
                abort_unless($current->pluck('id')->map(fn ($id) => (int) $id)->all() === array_map('intval', $expectedFiles), 409,
                    trans('dam::app.admin.dam.asset.files.order-stale'));
            }
            $first = $current->firstWhere('attachment_id', (int) $given->first());
            abort_unless($first !== null && $candidate !== null && $first->id === $candidate->id
                && $first->file_object_id === $candidate->file_object_id, 409, trans('dam::app.admin.dam.asset.files.order-stale'));
            if ($first->id !== $current->first()?->id && ! $first->attachment->is_representative) {
                // Resolve saved state from the locked asset, never a caller's stale metadata snapshot.
                $memberState = StackMemberState::remembered(asset: $locked, file: $first) ?? $discoveredState;
                $this->promote(asset: $locked, attachmentId: (int) $given->first(), memberState: $memberState);
                $changedFirst = true;
                $needsProcessing = ($memberState['processed_sha256'] ?? null) !== $first->fileObject?->sha256;
            }

            $this->writeOrder($asset->id, $given->all());
            DB::afterCommit(fn () => $this->log('attachment.reordered', ['asset' => $asset->id, 'order' => $given->all()]));
        }, attempts: DB::connection()->getDriverName() === 'pgsql' ? 3 : 1);
        if ($needsProcessing) {
            // Finalize metadata and perceptual hash for these bytes while retaining existing member artwork.
            ProcessAssetUpload::dispatch($asset->id, null, true, false, true)->afterCommit();
        } elseif ($changedFirst && config('dam.similar.enabled', true)) {
            ComputeAssetPhash::dispatch($asset->id)->afterCommit();
        }
    }

    /** Slot positions are 0..n-1 in the given order; every revision's `sort` mirrors its slot so listings agree. */
    private function writeOrder(int $assetId, array $order): void
    {
        foreach (array_values($order) as $position => $slotId) {
            Attachment::whereKey($slotId)->where('dam_asset_id', $assetId)->update(['position' => $position]);
            AssetFile::where('attachment_id', $slotId)->update(['sort' => $position]);
        }
    }

    /** The promoted file's extension: from its label when that names one (labels default to the uploaded name), else its type. */
    private function extensionFor(string $label, string $mimeType): string
    {
        return AssetFile::extensionFromLabel($label)
            ?? ($mimeType === '' ? '' : (MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? ''));
    }

    private function actor(): ?int
    {
        $id = auth()->guard('admin')->id() ?? auth()->guard('api')->id();

        return $id === null ? null : (int) $id;
    }

    /** JSONL-shaped decision log, on only with app.debug (the ledger's writes are also audited on the asset). */
    private function log(string $event, array $context): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('dam.attachments', ['event' => $event, 'loc' => self::class, 'ctx' => $context]);
    }
}
