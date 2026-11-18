<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Models\Directory as ModelDirectory;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Traits\ActionRequest as ActionRequestTrait;
use Mothership\DAM\Traits\Directory as DirectoryTrait;

class CopyDirectoryStructure implements ShouldQueue
{
    use ActionRequestTrait, DirectoryTrait, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $directoryId, protected int $userId) {}

    public function handle()
    {
        if (! $this->checkedUser($this->userId)) {
            throw new \Exception('User not found');
        }

        $directoryRepository = app(DirectoryRepository::class);

        try {
            // Source subtree and the inserted copies all run under the tree lock (ADR 0004).
            ModelDirectory::withTreeLock(function () use ($directoryRepository) {
                $originalDirectory = $directoryRepository->find($this->directoryId);

                if (! $originalDirectory) {
                    return;
                }

                // Codex review of PR #75, finding 1: the controller's canAccess() check ran before this
                // job was even dispatched, so it is stale by the time the tree lock is granted. Re-checked
                // here, fresh, before anything is replicated.
                if (app(AccessModel::class)->lists()) {
                    $permissions = app(DirectoryPermissionService::class)->forAdmin($this->user);

                    if (! $permissions->bypass() && ! $permissions->canAccess($originalDirectory->id)) {
                        throw new \RuntimeException(trans('dam::app.admin.dam.index.directory.cannot-copy'));
                    }
                }

                $newDirectory = $originalDirectory->replicate();
                $newDirectory->name = $this->setDirectoryNameForCopy($newDirectory->name, $originalDirectory->parent_id);
                $newDirectory->parent_id = $originalDirectory->parent_id;
                $newDirectory->created_by = $this->userId;
                $newDirectory->save();

                $this->copyDirectoryAndChildren($originalDirectory, $newDirectory);
            });

            $this->completed(EventType::COPY_DIRECTORY_STRUCTURE->value, $this->userId);
        } catch (\Exception $e) {
            $this->markFailed(EventType::COPY_DIRECTORY_STRUCTURE->value, $this->userId, $e->getMessage());
        }
    }

    public function copyDirectoryAndChildren(ModelDirectory $root, ModelDirectory $newRoot): void
    {
        // Under `lists` a folder the actor cannot open is skipped together with its whole subtree,
        // path-only included (spec, "Copies"); a no-op under `grants`, where copyDirectoryAndChildren()
        // has never had a per-descendant check.
        $lists = app(AccessModel::class)->lists();
        $permissions = $lists ? app(DirectoryPermissionService::class)->forAdmin($this->user) : null;

        $queue = [[$root, $newRoot]];

        while (! empty($queue)) {
            $nextLevel = [];

            $sourceIds = array_map(fn ($pair) => $pair[0]->id, $queue);
            $childrenByParent = ModelDirectory::whereIn('parent_id', $sourceIds)
                ->get()
                ->groupBy('parent_id');

            foreach ($queue as [$source, $newParent]) {
                foreach ($childrenByParent->get($source->id, collect()) as $child) {
                    if ($lists && ! $permissions->bypass() && ! $permissions->canAccess($child->id)) {
                        continue;
                    }

                    $newChild = $child->replicate();
                    $newChild->created_by = $this->userId;
                    $newChild->appendToNode($newParent)->save();

                    $nextLevel[] = [$child, $newChild];
                }
            }

            $queue = $nextLevel;
        }
    }
}
