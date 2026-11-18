<?php

namespace Mothership\DAM\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Exceptions\AssetDeletionException;
use Mothership\DAM\Exceptions\MergeActiveException;
use Mothership\DAM\Models\ActionRequest;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\LibraryPaths;
use Mothership\DAM\Support\TrashLog;
use RuntimeException;
use Throwable;

/**
 * The 30-day trash (P2 S4). Every user-facing delete (web, mass, REST, folder) moves an asset here instead of
 * deleting it: the row, its files, attachments, revisions, tags, properties, relationships and share links all stay,
 * and the global SoftDeletes scope plus the access seam make it missing everywhere. Restore puts it back; a
 * permanent delete (the Trash page, or dam:trash:purge after 30 days) is AssetDeletionService::delete(), so blob
 * reclaim is exactly what a delete did before the trash.
 */
class AssetTrashService
{
    private const FOLDER_DELETE_WINDOW_MINUTES = 15;

    public function __construct(private AssetDeletionService $deletions) {}

    /**
     * Moves the asset to the trash. Dependent assets block it as they blocked a delete, unless $force. Returns the
     * same analysis a delete returned.
     *
     * @return array{blocking: array<int, array<string, int|string|null>>, warnings: array<int, array<string, int|string|null>>}
     */
    public function trash(Asset $asset, bool $force = false, ?int $deletedBy = null): array
    {
        return DB::transaction(function () use ($asset, $force, $deletedBy): array {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            $analysis = $this->deletions->analyze($locked);

            if (! $force && $analysis['blocking'] !== []) {
                TrashLog::write(event: 'trash.blocked', ctx: ['asset_id' => (int) $locked->id, 'blocking' => count($analysis['blocking'])]);

                throw new AssetDeletionException($analysis);
            }

            $locked->trashedBy($deletedBy)->delete();

            // The caller's model reads as trashed too.
            $asset->setRawAttributes($locked->getAttributes(), sync: true);

            TrashLog::write(event: 'trash.moved', ctx: ['asset_id' => (int) $locked->id, 'deleted_by' => (int) $locked->deleted_by, 'forced' => $force]);

            return $analysis;
        });
    }

    /**
     * True while a folder delete is queued or running. Jobs\DeleteDirectory trashes everything in the subtree it
     * reads when it starts, so an asset restored into a doomed folder in the meantime is trashed again, and
     * its second restore lands in the Library root. A request left pending by a worker that died stops counting
     * after FOLDER_DELETE_WINDOW_MINUTES.
     */
    public function folderDeleteInProgress(): bool
    {
        return ActionRequest::query()
            ->where('event_type', EventType::DELETE_DIRECTORY->value)
            ->where('status', 'pending')
            ->where('updated_at', '>=', now()->subMinutes(self::FOLDER_DELETE_WINDOW_MINUTES))
            ->exists();
    }

    /**
     * Puts a trashed asset back: in the folder it was in (the one its old path named, if it is still one of its
     * folders), or in the Library root when every folder it was in is gone; under its old name, or the next free
     * `name(1).ext` when that name is taken now. Its own access list is kept, so the same people see it again.
     */
    public function restore(Asset $asset): Asset
    {
        return Directory::withTreeLock(function () use ($asset): Asset {
            $locked = Asset::onlyTrashed()->lockForUpdate()->findOrFail($asset->getKey());
            $fromPath = (string) $locked->trashed_from_path;
            [$folder, $fellBackToRoot] = $this->restoreFolder(asset: $locked, fromPath: $fromPath);
            $folderPath = Directory::ASSETS_DIRECTORY.'/'.$folder->generatePath();
            $name = LibraryPaths::uniqueFileName(directoryPath: $folderPath, fileName: (string) $locked->file_name, ignoreAssetId: (int) $locked->id);

            if ($fellBackToRoot) {
                $locked->directories()->attach($folder->id);
            }

            $locked->forceFill([
                'path'              => $folderPath.'/'.$name,
                'file_name'         => $name,
                'trashed_from_path' => null,
                'deleted_by'        => null,
            ]);

            // updated_at is left as it was, as trashing left it: the row comes back exactly as it went.
            $locked->timestamps = false;

            try {
                $restored = $locked->restore();
            } finally {
                $locked->timestamps = true;
            }

            if (! $restored) {
                throw new RuntimeException('The asset could not be restored.');
            }

            TrashLog::write(event: 'trash.restored', ctx: [
                'asset_id'     => (int) $locked->id,
                'directory_id' => (int) $folder->id,
                'root'         => $fellBackToRoot,
                'renamed'      => $locked->path !== $fromPath,
            ]);

            return $locked;
        });
    }

    /**
     * Deletes a trashed asset for good, through the same reference-counted reclaim a delete always used. Whether
     * it is still in the trash is decided under the delete's own row lock, so a Restore that commits first wins:
     * the purge is refused with ModelNotFoundException, as for an asset that was never trashed.
     */
    public function purge(Asset $asset): void
    {
        $this->deletions->delete($asset, force: true, onlyIf: fn (Asset $locked): bool => $locked->trashed());

        TrashLog::write(event: 'trash.purged', ctx: ['asset_id' => (int) $asset->getKey()]);
    }

    /**
     * Deletes for good every asset trashed more than TRASH_RETENTION_DAYS ago (dam:trash:purge, daily). Each is its
     * own delete, so one that fails is counted and logged and the rest still go. The list is only a list of
     * candidates: each is checked again under its delete's row lock, so one restored, or restored and trashed again
     * (a new 30 days), since the list was read is skipped.
     *
     * @return array{purged: int, skipped: int, failed: int}
     */
    public function purgeExpired(?CarbonInterface $now = null): array
    {
        $cutoff = ($now ?? now())->copy()->subDays(Asset::TRASH_RETENTION_DAYS);
        $purged = 0;
        $skipped = 0;
        $failed = 0;

        $ids = Asset::onlyTrashed()->where('deleted_at', '<', $cutoff)->orderBy('id')->pluck('id');
        $stillExpired = fn (Asset $locked): bool => $locked->trashed() && $locked->deleted_at !== null && $locked->deleted_at->lt($cutoff);

        foreach ($ids as $id) {
            try {
                $this->deletions->delete(Asset::withTrashed()->findOrFail($id), force: true, onlyIf: $stillExpired);
                $purged++;
                TrashLog::write(event: 'trash.purged', ctx: ['asset_id' => (int) $id, 'expired' => true]);
            } catch (ModelNotFoundException) {
                // Restored, trashed again, or purged by someone else since the list was read.
                $skipped++;
                TrashLog::write(event: 'trash.purge_skipped', ctx: ['asset_id' => (int) $id]);
            } catch (MergeActiveException) {
                // P3b-1: held until its merge is finalized or split; the next run asks again.
                $skipped++;
                TrashLog::write(event: 'trash.purge_held_by_merge', ctx: ['asset_id' => (int) $id]);
            } catch (Throwable $e) {
                $failed++;
                report($e);
                TrashLog::write(event: 'trash.purge_failed', ctx: ['asset_id' => (int) $id], level: 'error');
            }
        }

        TrashLog::write(event: 'trash.purge_run', ctx: ['purged' => $purged, 'skipped' => $skipped, 'failed' => $failed]);

        return ['purged' => $purged, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Where Restore puts the asset: of the folders it is still filed in (a folder delete removes its own rows), the
     * one its old path named, else the first; with none left, the Library root, flagged so the caller files it there.
     *
     * @return array{0: Directory, 1: bool}
     */
    private function restoreFolder(Asset $asset, string $fromPath): array
    {
        $folders = $asset->directories()->get();
        $oldFolderPath = dirname($fromPath);

        foreach ($folders as $folder) {
            if (Directory::ASSETS_DIRECTORY.'/'.$folder->generatePath() === $oldFolderPath) {
                return [$folder, false];
            }
        }

        if ($folders->isNotEmpty()) {
            return [$folders->first(), false];
        }

        $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first();

        if ($root === null) {
            throw new RuntimeException('The Library has no root folder to restore into.');
        }

        return [$root, true];
    }
}
