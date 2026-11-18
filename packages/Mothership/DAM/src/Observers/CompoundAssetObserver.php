<?php

namespace Mothership\DAM\Observers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use RuntimeException;
use Throwable;

/**
 * Links an Asset to the compound-asset graph (ADR 0002):
 * New content is ingested into the content-addressed blob store before graph
 * state is switched, and every FileObject names its blob from its first row
 * (ADR 0009). The Asset path is only the user-visible Library name, so a
 * rename or folder move never touches the graph.
 */
class CompoundAssetObserver
{
    public function created(Asset $asset): void
    {
        $this->synchronizeContent($asset, true);
    }

    public function updated(Asset $asset): void
    {
        $explicitContentChange = $asset->hasPendingGraphContentChange();
        $contentDescriptorChanged = $asset->wasChanged('file_size')
            || ($asset->wasChanged('path') && $asset->wasChanged(['mime_type', 'extension']));

        if ($explicitContentChange || $contentDescriptorChanged) {
            $this->synchronizeContent($asset, false);
        }
    }

    protected function synchronizeContent(Asset $asset, bool $creating): void
    {
        try {
            // Slice 3: the writer already put the bytes in a blob (withGraphContent), so the Library path
            // names no file and is never read. Otherwise fall back to a Library file, which only data
            // written before slice 3 (reclaimed by slice 4) or an unconverted writer still has.
            $newFileObject = $asset->graphContentObject() ?? $this->ingestLibraryFile($asset);

            if ($newFileObject === null) {
                $asset->clearGraphContentChange();

                return;
            }

            $sha256 = (string) $newFileObject->sha256;

            DB::transaction(function () use ($asset, $creating, $sha256, &$newFileObject): void {
                // The caller already selected this model; a new map's Library link is assigned
                // after the created observer, inside the same transaction.
                Asset::whereKey($asset->getKey())->lockedForGraph()->firstOrFail();

                if ($asset->graphContentObject() !== null) {
                    // The writer ingested in its own transaction, so a concurrent delete of another asset
                    // with the same bytes may have reaped this row since. Asset row before blob, as above.
                    $newFileObject = $this->contentStore()->retain($newFileObject);
                }

                $attachments = AssetFile::query()
                    ->where('dam_asset_id', $asset->getKey())
                    ->with('fileObject')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $originals = $attachments->where('role', AssetFile::ROLE_ORIGINAL)->values();

                if ($originals->count() > 1) {
                    throw new RuntimeException("Asset [{$asset->getKey()}] has more than one current original.");
                }

                /** @var AssetFile|null $currentOriginal */
                $currentOriginal = $originals->first();
                $currentFileObject = $currentOriginal?->fileObject;

                if ($currentFileObject?->sha256 === $sha256) {
                    $asset->clearGraphContentChange();

                    return;
                }

                // P3a S2: the replaced content stays in the representative slot as a superseded revision
                // and the new content is appended as its next revision, so A -> B -> A is three revisions.
                $slot = Attachment::query()
                    ->where('dam_asset_id', $asset->getKey())
                    ->where('is_representative', true)
                    ->lockForUpdate()
                    ->first();

                if ($currentOriginal !== null) {
                    $this->demoteCurrentOriginal($asset, $currentOriginal);
                    $slot ??= $currentOriginal->attachment;
                }

                // A writer that names its actor wins over the session: the queued chunk finalizer has none.
                $actor = $asset->graphContentActor() ?? auth()->guard('admin')->id() ?? auth()->id() ?? null;

                $slot ??= Attachment::create([
                    'dam_asset_id'      => $asset->getKey(),
                    'role'              => AssetFile::ROLE_ORIGINAL,
                    'position'          => 0,
                    'is_representative' => true,
                    'created_by'        => $actor,
                ]);

                $newOriginal = AssetFile::create([
                    'dam_asset_id'    => $asset->getKey(),
                    'file_object_id'  => $newFileObject->getKey(),
                    'role'            => AssetFile::ROLE_ORIGINAL,
                    // Mirrors the slot's place, as AttachmentLedger::writeOrder() keeps it, after a reorder too.
                    'sort'            => (int) $slot->position,
                    'attachment_id'   => $slot->getKey(),
                    'revision_number' => (int) AssetFile::query()->where('attachment_id', $slot->getKey())->max('revision_number') + 1,
                    'is_current'      => true,
                    'created_by'      => $actor,
                ]);

                if (! $creating && $currentOriginal !== null) {
                    $this->afterAttachmentSwap($asset, $newOriginal);

                    $maxVersion = (int) (AssetVersion::query()
                        ->where('dam_asset_id', $asset->getKey())
                        ->max('number') ?? 0);

                    AssetVersion::create([
                        'dam_asset_id'          => $asset->getKey(),
                        'number'                => $maxVersion + 1,
                        'created_by'            => $actor,
                        'primary_asset_file_id' => $newOriginal->getKey(),
                    ]);
                }

                // Only the graph knows the original really changed (a same-size, same-format replacement
                // alters no asset column). afterCommit: a rolled-back transition indexes nothing. A new
                // asset is indexed by AiIndexObserver::created.
                if (! $creating) {
                    AiIndexDispatcher::contentReplaced((int) $asset->getKey());
                }
            });

            $asset->clearGraphContentChange();
        } catch (Throwable $e) {
            Log::error('DAM asset graph observer failed.', [
                'asset'     => $asset->getKey(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    /**
     * The pre-slice-3 ingest: hash the file at the asset's Library path and copy it into a blob. Returns
     * null when there is no such file, which after slice 3 is the normal case for a metadata-only save.
     */
    protected function ingestLibraryFile(Asset $asset): ?FileObject
    {
        if (empty($asset->path)) {
            return null;
        }

        $disk = Directory::getAssetDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($asset->path)) {
            return null;
        }

        $sha256 = $asset->graphContentChecksum() ?? $this->hashFile($storage, $asset->path);

        Log::notice('DAM asset graph ingested a Library-path file; writers should hand the observer a FileObject.', [
            'event' => 'legacy_library_ingest',
            'asset' => $asset->getKey(),
            'path'  => $asset->path,
        ]);

        // Asset row before blob (ContentStore::lockBlob's lock order): a content-only save runs no
        // UPDATE, so without this it would take the blob first and the row second, crossing a dirty save.
        Asset::whereKey($asset->getKey())->lockedForGraph()->first();

        // Runs inside Asset::save()'s transaction, so a failed transition also drops a new row.
        return $this->contentStore()->putStored(
            disk: $disk,
            sourcePath: $asset->path,
            sha256: $sha256,
        );
    }

    protected function demoteCurrentOriginal(Asset $asset, AssetFile $currentOriginal): void
    {
        $currentFileObject = $currentOriginal->fileObject;

        if ($currentFileObject === null) {
            throw new RuntimeException("Asset [{$asset->getKey()}] has an original attachment without a FileObject.");
        }

        $store = $this->contentStore();
        $historicalPath = $store->isObjectPath($currentFileObject->path, $currentFileObject->sha256)
            ? (string) $currentFileObject->path
            : $store->objectPath($currentFileObject->sha256);

        if (! Storage::disk($currentFileObject->disk)->exists($historicalPath)) {
            throw new RuntimeException("Immutable historical blob [{$currentFileObject->disk}:{$historicalPath}] is missing.");
        }

        if ($currentFileObject->path !== $historicalPath) {
            // A row older than the locator invariant: its blob was written at ingest.
            $currentFileObject->update(['path' => $historicalPath]);
        }

        $currentOriginal->update(['role' => AssetFile::ROLE_VERSION, 'is_current' => false]);
    }

    /**
     * Test seam for proving that an exception after the role swap rolls the
     * entire graph transition back.
     */
    protected function afterAttachmentSwap(Asset $asset, AssetFile $newOriginal): void {}

    protected function contentStore(): ContentStore
    {
        return app(ContentStore::class);
    }

    protected function hashFile(Filesystem $storage, string $path): string
    {
        $stream = $storage->readStream($path);

        if (! $stream) {
            throw new RuntimeException("Unable to open stream for [{$path}].");
        }

        $context = hash_init('sha256');

        hash_update_stream($context, $stream);

        fclose($stream);

        return hash_final($context);
    }
}
