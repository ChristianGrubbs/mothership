<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory as ModelDirectory;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;

class DeleteDirectory implements ShouldQueue
{
    use ActionRequestTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $directoryId, protected int $userId) {}

    public function handle()
    {
        if (! $this->checkedUser($this->userId)) {
            throw new \Exception('User not found');
        }

        $directoryRepository = app(DirectoryRepository::class);

        try {
            $this->deleteDirectoryAndChildren($this->directoryId, $directoryRepository);

            $this->completed(EventType::DELETE_DIRECTORY->value, $this->userId);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: a TypeError must not leave the action request pending.
            $this->markFailed(EventType::DELETE_DIRECTORY->value, $this->userId, $e->getMessage());
        }
    }

    public function deleteDirectoryAndChildren(int $directoryId, DirectoryRepository $directoryRepository): void
    {
        // The subtree read, every asset's tombstoning and the raw interval compaction below all run
        // under the directory tree lock (ADR 0004); the row locks alone never excluded a concurrent
        // insert's shift.
        ModelDirectory::withTreeLock(fn () => $this->deleteLockedSubtree($directoryId, $directoryRepository));
    }

    private function deleteLockedSubtree(int $directoryId, DirectoryRepository $directoryRepository): void
    {
        $root = $directoryRepository->find($directoryId);

        if (! $root) {
            return;
        }

        $table = (new ModelDirectory)->getTable();

        $lockedRoot = ModelDirectory::query()->whereKey($directoryId)->lockForUpdate()->first();

        if (! $lockedRoot) {
            return;
        }

        if (! app(RoleBuilder::class)->canUserDeleteDirectoryTree(directory: $lockedRoot, admin: $this->user)) {
            throw new \RuntimeException('Directory deletion is no longer authorized.');
        }

        // Re-checked here, inside the tree lock, before the compaction starts: the subtree cannot change
        // between the controller's check and this one (ADR 0010, spec "Move"/"Move and delete guard").
        // checkLocked() locks the subtree's folder rows FOR UPDATE and its file rows through
        // Asset::lockedForGraph() before asking (Codex review of PR #75, findings 1 and 2), so the
        // answer is authoritative and those same rows — reused below as $subtreeDirIds/$assetIds — stay
        // locked through the compaction.
        $locked = app(AccessMoveGuard::class)->checkLocked(admin: $this->user, directoryId: $directoryId);

        if ($locked['blocked']) {
            throw new \RuntimeException(trans('dam::app.admin.dam.access.move-blocked'));
        }

        $subtreeDirIds = $locked['folderIds'];
        $assetIds = $locked['assetIds'];

        $rgt = $lockedRoot->_rgt;
        $width = $rgt - $lockedRoot->_lft + 1;

        if (! empty($assetIds)) {
            // Each file goes to the trash (P2 S4) through the same entry point a single-asset delete uses;
            // dam:trash:purge deletes it for good after 30 days, through AssetDeletionService as before. Force
            // skips the blocking-relationship check this subtree delete has never run, so today's "no new
            // blocking UX" is unchanged. The whole subtree already sits inside the directory tree lock's own
            // transaction (DirectoryTreeLock), so this shares its atomicity with the nested-set compaction below.
            $trash = app(AssetTrashService::class);

            foreach (Asset::whereIn('id', $assetIds)->get() as $asset) {
                $trash->trash(asset: $asset, force: true, deletedBy: $this->userId);
            }
        }

        // Every file's rows for these folders go, trashed ones included: Restore puts a file whose folders are
        // all gone in the Library root.
        DB::table('dam_asset_directory')->whereIn('directory_id', $subtreeDirIds)->delete();

        // A share link targets its folder by id with no foreign key, so it would outlive the folder.
        Share::query()->where('share_type', Share::TYPE_DIRECTORY)->whereIn('target_id', $subtreeDirIds)->delete();

        DB::table($table)->whereIn('id', $subtreeDirIds)->update(['parent_id' => null]);

        DB::table($table)->whereIn('id', $subtreeDirIds)->delete();

        DB::table($table)->where('_rgt', '>', $rgt)->decrement('_rgt', $width);
        DB::table($table)->where('_lft', '>', $rgt)->decrement('_lft', $width);
    }
}
