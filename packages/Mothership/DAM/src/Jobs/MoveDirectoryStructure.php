<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Exceptions\DirectoryMoveRefusedException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory as ModelDirectory;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Support\AssetPathPrefixGuard;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\Directory as DirectoryTrait;

class MoveDirectoryStructure implements ShouldQueue
{
    use ActionRequestTrait, DirectoryTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(protected int $directoryId, protected int $newParentId, protected int $userId) {}

    public function handle(): void
    {
        try {
            if (! $this->checkedUser($this->userId)) {
                throw new DirectoryMoveRefusedException('User not found');
            }

            // The whole move — reading the subtree and target and the nested-set rewrite — runs under
            // the directory tree lock so nothing it read can shift underneath it (ADR 0004).
            ModelDirectory::withTreeLock(fn () => $this->move());
        } catch (DirectoryMoveRefusedException $e) {
            // A cycle, moving into itself, or a missing directory: none of that changes on retry, so
            // fail the request now — the same "no point retrying" call CopyDirectoryStructure and
            // MassMove already make by catching and not rethrowing. Without this the user would wait
            // through the queue's remaining attempts before failed() finally reported a refusal that
            // was true on attempt one.
            $this->markFailed(EventType::MOVE_DIRECTORY_STRUCTURE->value, $this->userId, $e->getMessage());
        }

        // Anything else (a DB deadlock or a worker timeout) is left to propagate uncaught: the action
        // request stays 'pending' so a later successful attempt can still complete it. Marking it
        // 'failed' here was the bug — a retry that then succeeded had no pending row left for
        // completed() to update, so the UI reported failure forever even though the folder had moved.
        // failed() below records the terminal failure only once the queue exhausts its attempts.
    }

    /**
     * Covers what handle() cannot recover from: a worker timeout, a fatal error, or a release
     * that exhausts the job's attempts after every retry hit a transient failure. markFailed()
     * only matches a still-pending row, so this is a no-op when handle() already reported a
     * DirectoryMoveRefusedException or a retry already completed the move.
     */
    public function failed(?\Throwable $exception): void
    {
        $message = $exception?->getMessage() ?: trans('dam::app.admin.dam.index.directory.cannot-move');

        $this->markFailed(EventType::MOVE_DIRECTORY_STRUCTURE->value, $this->userId, $message);
    }

    private function move(): void
    {
        $directoryRepository = app(DirectoryRepository::class);

        $directory = $directoryRepository->find($this->directoryId);

        if (! $directory) {
            throw new DirectoryMoveRefusedException(trans('dam::app.admin.dam.index.directory.not-found'));
        }

        $oldRelativePath = $directory->generatePath();
        $oldStoragePath = sprintf('%s/%s', ModelDirectory::ASSETS_DIRECTORY, $oldRelativePath);

        $name = $this->setDirectoryNameForCopy($directory->name, $this->newParentId);

        $newParentDirectory = $directoryRepository->find($this->newParentId);

        if (! $newParentDirectory || $newParentDirectory->isDescendantOf($directory) || $directory->id === $newParentDirectory->id) {
            throw new DirectoryMoveRefusedException(trans('dam::app.admin.dam.index.directory.cannot-move'));
        }

        // Re-checked here, inside the tree lock the caller already holds (handle()'s withTreeLock), so
        // the subtree cannot change between the controller's check and this one (ADR 0010, spec "Move").
        // checkLocked() locks the subtree's folder and file rows first (Codex review of PR #75, findings
        // 1 and 2), so the answer is authoritative and those same rows stay locked through the move
        // below.
        $locked = app(AccessMoveGuard::class)->checkLocked(admin: $this->user, directoryId: $directory->id);

        if ($locked['blocked']) {
            throw new DirectoryMoveRefusedException(trans('dam::app.admin.dam.access.move-blocked'));
        }

        // The own-items rule, asked of the locked subtree: something of someone else's that arrived since the request.
        if ($locked['foreign'] > 0) {
            throw new DirectoryMoveRefusedException(trans('dam::app.admin.permissions.own-only-subtree', ['count' => $locked['foreign']]));
        }

        // Everything from here on is a real move attempt: failures propagate to handle() unconverted,
        // since a DB deadlock is worth retrying rather than failing the request on the first attempt.
        DB::transaction(function () use ($directory, $newParentDirectory, $name) {
            $directory->name = $name;
            $directory->parent()->associate($newParentDirectory)->save();

            $this->rebuildDescendantNodes($directory);

            // The moved folder and its whole subtree take the destination's access list, through the same
            // cascade the Share dialog runs (ADR 0010, spec "Move"). handle() holds the tree lock, and so
            // the fence's row, around all of this.
            app(AccessListWriter::class)->adoptForSubtree(movedFolderId: $directory->id, destinationId: $newParentDirectory->id, actorId: $this->userId);
        });

        $directory->refresh();

        $newRelativePath = $directory->generatePath();
        $newStoragePath = sprintf('%s/%s', ModelDirectory::ASSETS_DIRECTORY, $newRelativePath);

        $this->batchReplaceAssetPaths($directory, $oldStoragePath, $newStoragePath);

        $this->completed(EventType::MOVE_DIRECTORY_STRUCTURE->value, $this->userId);
    }

    private function rebuildDescendantNodes(ModelDirectory $root): void
    {
        $queue = [];

        foreach ($root->children as $child) {
            $queue[] = [$child, $root];
        }

        while (! empty($queue)) {
            [$node, $parent] = array_shift($queue);

            $node->appendToNode($parent)->save();

            foreach ($node->children as $grandchild) {
                $queue[] = [$grandchild, $node];
            }
        }
    }

    private function batchReplaceAssetPaths(ModelDirectory $directory, string $oldPrefix, string $newPrefix): void
    {
        if ($oldPrefix === $newPrefix) {
            return;
        }

        $subtreeDirIds = $directory->descendants()->toBase()->pluck('id')->prepend($directory->id);

        $pivotTable = (new Asset)->directories()->getTable();
        $assetIds = DB::table($pivotTable)
            ->whereIn('directory_id', $subtreeDirIds)
            ->distinct()
            ->pluck('asset_id');

        if ($assetIds->isEmpty()) {
            return;
        }

        [$oldPrefix, $newPrefix, $likePattern] = AssetPathPrefixGuard::boundedPrefixes($oldPrefix, $newPrefix);

        DB::table((new Asset)->getTable())
            ->whereIn('id', $assetIds)
            ->whereRaw('path LIKE ? ESCAPE ?', [$likePattern, '\\'])
            ->update([
                'path' => DB::raw(
                    'REPLACE(path, '.DB::getPdo()->quote($oldPrefix).', '.DB::getPdo()->quote($newPrefix).')'
                ),
            ]);
    }
}
