<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Support\FolderAccessLog;

/**
 * Keeps the access lists current as items are created, attached, moved and copied (ADR 0010, sharing
 * slice 2a): a new item takes the list of the folder it lands in (spec D3), and a moved one takes its
 * destination's. Every write happens under both access models, because a switch to `lists` must find the
 * lists already true of the library; nothing here is visible to any read while the model is `grants`.
 *
 * AccessListService owns the other half — the Share dialog's deliberate change of a list, and the cascade
 * that follows it. This class never decides who may see anything: it only propagates what a folder already
 * says, so it takes no actor and authorizes nothing. Its callers have already authorized the create, the
 * attach or the move that it follows.
 *
 * Lock order, AccessListService's, and every method here takes only the stages it needs, in that order:
 *   1. the DAM_ACCESS_MODEL row, FOR SHARE — its caller's, checked rather than taken (see fenced());
 *   2. the directory tree lock, for adoptForSubtree(), whose cascade rewrites a subtree;
 *   3. the folder rows a list is read from, FOR SHARE, in `_lft` order;
 *   4. the file rows whose list is replaced rather than added to, in ascending id order, through
 *      Asset::lockedForGraph().
 *
 * Stage 3 is what makes a create safe against a concurrent cascade: the cascade holds those same folder
 * rows FOR UPDATE, so either it finishes first and the new item copies its list, or it waits for the new
 * item and then rewrites it (spec, "Inherit on create"). Stage 4 is why a replacement locks its files
 * first: two unlocked replacements of an empty list both delete nothing and then insert their own
 * principals, leaving the union of the two (Codex review of PR #69, finding 1). A file created in the
 * caller's own transaction is invisible to every other transaction, so inheritAssets() needs no stage 4 of
 * its own — and being additive, two of them over one file are each other's right answer anyway.
 *
 * Deadlock surface, stated rather than assumed. No caller reaches stage 3 while holding a stage-4 row any
 * more: the two that used to — ImageEditorController::save(), which locks the asset it is editing, and
 * GenerationPackageImporter, which updates an asset it found — now go through fencedAndTreeLocked() and
 * holdPlacementFolders(), so the folders come first and the file rows after. SphereSeamController::import()
 * never did: its existing-asset branch returns before the inheritance call, so only a brand-new asset,
 * invisible to every other transaction, is ever inherited there. No caller takes the tree lock after a file
 * row, which is the cycle AccessListService's own docblock names.
 *
 * Every write is set-based and idempotent: one insert-select per list table, skipping rows the item already
 * has, never a query per item or per principal.
 */
class AccessListWriter
{
    /**
     * The two principal kinds, each as [the folder table, the file table, the principal column]. Both
     * tables of a kind carry the same principal column, which is what lets one insert-select copy a
     * folder's list onto a file.
     */
    protected const LISTS = [
        ['dam_directory_user_access', 'dam_asset_user_access', 'admin_id'],
        ['dam_directory_group_access', 'dam_asset_group_access', 'group_id'],
    ];

    /**
     * How many ids go into one statement, matching the copy jobs' own batches.
     *
     * Read as `static::BATCH` everywhere, so a subclass can lower it and a test can cross a real batch
     * boundary without 500 fixtures (Codex review of PR #74 round 2, finding 2).
     */
    protected const BATCH = 500;

    /** How many times fencedAndTreeLocked() runs a body whose placements moved under it. */
    protected const PLACEMENT_ATTEMPTS = 2;

    public function __construct(
        protected AccessModel $model,
        protected AccessListService $lists,
        protected DirectoryTreeLock $treeLock,
    ) {}

    /**
     * A new folder takes its parent's list (spec D3). A root folder has no parent and so starts empty:
     * only its owner and full admins open it until someone shares it.
     */
    public function inheritFolder(int $directoryId): void
    {
        $this->fenced(writer: 'inheritFolder', items: 1, body: function () use ($directoryId): void {
            $started = microtime(true);
            $parentId = DB::table('dam_directories')->where('id', $directoryId)->value('parent_id');

            if ($parentId === null) {
                return;
            }

            $this->holdFolders(directoryIds: [(int) $parentId]);

            $rows = 0;

            foreach (self::LISTS as [$folderTable, , $column]) {
                $rows += DB::table($folderTable)->insertUsing(
                    ['directory_id', $column],
                    DB::table('dam_directories as target')
                        ->crossJoin("{$folderTable} as source")
                        ->where('target.id', $directoryId)
                        ->where('source.directory_id', $parentId)
                        ->whereNotExists(fn ($mine) => $mine->from("{$folderTable} as mine")
                            ->whereColumn('mine.directory_id', 'target.id')
                            ->whereColumn("mine.{$column}", "source.{$column}"))
                        ->select(['target.id', "source.{$column}"])
                );
            }

            $this->logged(subjectId: $directoryId, isDirectory: true, counts: ['folders' => 1, 'rows' => $rows], started: $started);
        });
    }

    /**
     * Files take the union of the lists of every folder they sit in, which is what the any-directory rule
     * gave them before ADR 0010. Runs after an attach, in the attaching transaction, and adds only rows a
     * file does not already have, so a second folder's attach widens the file's list rather than replacing
     * it (spec, "Inherit on create", point 4).
     *
     * It takes stage 4 as well, for the same reason a replacement does. NOT EXISTS makes the write
     * idempotent, not exclusive: two inherits can both miss the same principal and one then loses to the
     * primary key, and a concurrent replacement can delete an empty list, let this insert land, and insert
     * its own principals over the top — leaving a union neither writer asked for. Not every file here is
     * invisible to other transactions either: the shared attach in Traits\Directory attaches files that
     * createUnderFreeName() committed moments earlier (Codex review of PR #74, finding 4).
     *
     * @param  list<int>  $assetIds
     */
    public function inheritAssets(array $assetIds): void
    {
        $assetIds = $this->ids($assetIds);

        if ($assetIds === []) {
            return;
        }

        $this->fenced(writer: 'inheritAssets', items: count($assetIds), body: function () use ($assetIds): void {
            $started = microtime(true);

            $this->holdFolders(directoryIds: $this->foldersHolding(assetIds: $assetIds));
            $this->holdFiles(assetIds: $assetIds);

            $this->logged(isDirectory: false, counts: ['files' => count($assetIds), 'rows' => $this->copyFolderListsOnto(assetIds: $assetIds)], started: $started);
        });
    }

    /**
     * The files' lists, rebuilt from the folders they sit in now. For a writer that replaced a file's
     * folder membership rather than added to it — a detach and an attach — where the rows left over from
     * the old folders are no longer the union of anything.
     *
     * @param  list<int>  $assetIds
     */
    public function recomputeAssets(array $assetIds): void
    {
        $assetIds = $this->ids($assetIds);

        if ($assetIds === []) {
            return;
        }

        $this->fenced(writer: 'recomputeAssets', items: count($assetIds), body: function () use ($assetIds): void {
            $started = microtime(true);

            $this->holdFolders(directoryIds: $this->foldersHolding(assetIds: $assetIds));
            $this->holdFiles(assetIds: $assetIds);
            $this->clearFileLists(assetIds: $assetIds);

            $this->logged(isDirectory: false, counts: ['files' => count($assetIds), 'rows' => $this->copyFolderListsOnto(assetIds: $assetIds)], started: $started);
        });
    }

    /**
     * Moved files take the destination folder's list, and nothing of where they came from: a move changes
     * who can see a file, and the UI says so before it runs (spec, "Move"). Their owners do not change.
     *
     * $actorId: the admin who moved them. Each moved file is its own security event (P1a review), written in
     * this transaction, so a move that rolls back records nothing; the list writes stay batched.
     *
     * @param  list<int>  $assetIds
     */
    public function adoptForAssets(array $assetIds, int $destinationId, ?int $actorId = null): void
    {
        $assetIds = $this->ids($assetIds);

        if ($assetIds === []) {
            return;
        }

        $this->fenced(writer: 'adoptForAssets', items: count($assetIds), body: function () use ($assetIds, $destinationId, $actorId): void {
            $started = microtime(true);

            $this->holdFolders(directoryIds: [$destinationId]);
            $this->holdFiles(assetIds: $assetIds);
            $this->clearFileLists(assetIds: $assetIds);

            $rows = 0;

            foreach (self::LISTS as [$folderTable, $fileTable, $column]) {
                foreach (array_chunk($assetIds, static::BATCH) as $batch) {
                    $rows += DB::table($fileTable)->insertUsing(
                        ['asset_id', $column],
                        DB::table('dam_assets as target')
                            ->crossJoin("{$folderTable} as source")
                            ->whereIn('target.id', $batch)
                            ->where('source.directory_id', $destinationId)
                            ->select(['target.id', "source.{$column}"])
                    );
                }
            }

            $this->logged(subjectId: $destinationId, isDirectory: false, counts: ['files' => count($assetIds), 'rows' => $rows], started: $started, moved: true);
            $this->recordMoves(actorId: $actorId, destinationId: $destinationId, moved: array_map(fn (int $id) => ['asset_id' => $id], $assetIds));
        });
    }

    /**
     * A moved folder and everything beneath it takes the destination's list, through the same cascade the
     * Share dialog runs (AccessListService::applyToSubtree, sharing slice 3), so a move and a deliberate
     * change of a list write exactly the same rows in exactly the same order. $actorId: see adoptForAssets().
     */
    public function adoptForSubtree(int $movedFolderId, int $destinationId, ?int $actorId = null): void
    {
        $this->fenced(writer: 'adoptForSubtree', items: 1, body: function () use ($movedFolderId, $destinationId, $actorId): void {
            $started = microtime(true);

            // Stage 2 before stage 3, whoever calls this: every caller is already inside the tree lock a
            // folder move holds, and run() is re-entrant, so this costs nothing and cannot invert the order.
            $counts = $this->treeLock->run(function () use ($movedFolderId, $destinationId) {
                $this->holdFolders(directoryIds: [$destinationId]);

                [$userIds, $groupIds] = $this->principalsOf(directoryId: $destinationId);

                return $this->lists->applyToSubtree(directoryId: $movedFolderId, userIds: $userIds, groupIds: $groupIds);
            });

            $this->logged(subjectId: $destinationId, isDirectory: true, counts: $counts, started: $started, moved: true);
            $this->recordMoves(actorId: $actorId, destinationId: $destinationId, moved: [['directory_id' => $movedFolderId]]);
        });
    }

    /**
     * Stages 1 and 2 for a writer that has to lock file rows itself: the fence, then the directory tree lock,
     * around one transaction — and once more from the start when the files' placements moved while $body ran
     * (AccessPlacementsMoved).
     *
     * The retry exists because the folder ids have to be read before the file rows are locked, and one writer
     * still changes an existing file's placements without holding the tree lock: the shared attach in
     * Traits\Directory, which sits under AccessModel::sharedly() alone so that concurrent uploads into one
     * folder are not serialised system-wide. Every other placement writer — the asset move endpoint, both
     * MassMove branches, MoveDirectoryStructure, DeleteDirectory, the copy jobs and the generation importer —
     * holds the tree lock, so this hole is one attach wide. A folder lock after a file lock is never an
     * option: that is the cycle a cascade closes (Codex review of PR #74, finding 3).
     *
     * @template T
     *
     * @param  callable(): T  $body
     * @return T
     */
    public function fencedAndTreeLocked(callable $body): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->model->sharedly(fn () => $this->treeLock->run(fn () => $body()));
            } catch (AccessPlacementsMoved $moved) {
                if ($attempt >= static::PLACEMENT_ATTEMPTS) {
                    throw $moved;
                }
            }
        }
    }

    /**
     * Stage 3 for a writer that will take file rows at stage 4: the folders holding $assetIds, plus
     * $directoryIds, FOR SHARE in `_lft` order.
     *
     * Returns the placement folders it saw, so the caller can prove with assertPlacementsUnchanged() — once
     * its file rows are locked, and before it reads the placements again — that it is still inheriting from
     * folders it holds.
     *
     * @param  list<int>  $assetIds
     * @param  list<int>  $directoryIds  folders the caller needs held as well, such as a destination
     * @return list<int>
     */
    public function holdPlacementFolders(array $assetIds, array $directoryIds = []): array
    {
        $placements = $this->foldersHolding(assetIds: $this->ids($assetIds));

        $this->holdFolders(directoryIds: array_merge($placements, $directoryIds));

        return $placements;
    }

    /**
     * @param  list<int>  $assetIds
     * @param  list<int>  $locked  what holdPlacementFolders() returned
     *
     * @throws AccessPlacementsMoved
     */
    public function assertPlacementsUnchanged(array $assetIds, array $locked): void
    {
        $found = $this->foldersHolding(assetIds: $this->ids($assetIds));

        if ($found !== $locked) {
            throw new AccessPlacementsMoved(locked: $locked, found: $found);
        }
    }

    /**
     * ADR 0010's fence: $body writes list rows only inside a transaction that holds the `DAM_ACCESS_MODEL`
     * row, so no list write commits across a switch's activation.
     *
     * The hold is taken here, every time, through AccessModel::holding(). It is not read from the
     * bookkeeping and then trusted. When the caller fenced itself first — every caller that should — that
     * costs one idempotent FOR SHARE read of a row the transaction already holds, and waits for nothing, so
     * "the state row before any other row" still holds for them. When the caller did not, this is what makes
     * the write safe anyway, including when there is no state row yet to share (Codex review of PR #74 round
     * 2, finding 1).
     *
     * The bookkeeping decides only whether the caller is reported: in tests an unfenced caller is a writer to
     * fix and it throws, and in production it logs `access_fence_missing` and carries on under the hold taken
     * below. So a stale "held" can cost an unreported caller and never an unfenced write, which is why the
     * lost-connection and afterCommit cases the review reproduced cannot reach the list tables.
     *
     * Late is still worse than first — the switch holds the same row FOR UPDATE and then writes `created_by`
     * and the list tables, so a hold taken after other rows can deadlock with it — but better than a list
     * write that straddles the activation. `dam:access:switch lists` runs in maintenance mode with
     * `--drain`, which bounds how long such a writer can still be running at all.
     */
    protected function fenced(string $writer, int $items, callable $body): void
    {
        // Asked before the hold below makes it true.
        if (! $this->model->holdsRow()) {
            if ($this->strict()) {
                throw new AccessFenceMissing(writer: $writer);
            }

            FolderAccessLog::write(event: 'access_fence_missing', ctx: ['items' => $items]);
        }

        $this->model->holding(fn () => $body());
    }

    /**
     * Whether a missing hold throws. Tests are strict by default, so any DAM test that creates or moves an
     * item through an unfenced path fails rather than silently logging; `dam.access_fence_strict` lets one
     * of them prove the production path instead.
     */
    protected function strict(): bool
    {
        return (bool) (config('dam.access_fence_strict') ?? app()->runningUnitTests());
    }

    /**
     * Stage 3: the folder rows whose lists are about to be read, FOR SHARE and in `_lft` order, the same
     * rows and the same order a cascade takes FOR UPDATE. pluck() rather than exists() or count(), because
     * PostgreSQL refuses a row lock alongside an aggregate and the rows have to be read to be locked.
     *
     * The order is global, not per batch: `_lft` is read for the whole set first, so chunk boundaries cannot
     * reorder two writers against each other above BATCH folders (Codex review of PR #74, finding 4).
     *
     * @param  list<int>  $directoryIds
     */
    protected function holdFolders(array $directoryIds): void
    {
        $directoryIds = $this->ids($directoryIds);

        if ($directoryIds === []) {
            return;
        }

        $order = [];

        foreach (array_chunk($directoryIds, static::BATCH) as $batch) {
            foreach (DB::table('dam_directories')->whereIn('id', $batch)->get(['id', '_lft']) as $row) {
                $order[(int) $row->id] = (int) $row->_lft;
            }
        }

        asort($order);

        foreach (array_chunk(array_keys($order), static::BATCH) as $batch) {
            DB::table('dam_directories')->whereIn('id', $batch)->orderBy('_lft')->sharedLock()->pluck('id');
        }
    }

    /**
     * Stage 4: the file rows whose lists this writer is about to change, in ascending id order, before the
     * delete rather than after it — an unlocked replacement of an empty list is a lost update, because two
     * writers both delete nothing and then insert different principals.
     *
     * $assetIds arrives from ids(), which sorts, so the order holds across chunk boundaries too.
     *
     * @param  list<int>  $assetIds
     */
    protected function holdFiles(array $assetIds): void
    {
        foreach (array_chunk($assetIds, static::BATCH) as $batch) {
            Asset::query()->whereIn('dam_assets.id', $batch)->orderBy('dam_assets.id')->lockedForGraph()->pluck('dam_assets.id');
        }
    }

    /**
     * The folders holding $assetIds, so their lists can be read and held. A file in two folders of the same
     * move or upload appears once.
     *
     * @param  list<int>  $assetIds
     * @return list<int>
     */
    protected function foldersHolding(array $assetIds): array
    {
        $ids = [];

        foreach (array_chunk($assetIds, static::BATCH) as $batch) {
            $ids = array_merge($ids, DB::table('dam_asset_directory')->whereIn('asset_id', $batch)->distinct()->pluck('directory_id')->all());
        }

        return $this->ids($ids);
    }

    /**
     * The union of the lists of every folder each of $assetIds sits in, added to whatever the file already
     * has. Returns how many rows were written.
     *
     * @param  list<int>  $assetIds
     */
    protected function copyFolderListsOnto(array $assetIds): int
    {
        $rows = 0;

        foreach (self::LISTS as [$folderTable, $fileTable, $column]) {
            foreach (array_chunk($assetIds, static::BATCH) as $batch) {
                $rows += DB::table($fileTable)->insertUsing(
                    ['asset_id', $column],
                    DB::table('dam_asset_directory as placement')
                        ->join("{$folderTable} as source", 'source.directory_id', '=', 'placement.directory_id')
                        ->whereIn('placement.asset_id', $batch)
                        // A file in two folders that list the same principal is one row, not two: the
                        // primary key would reject the second, and DISTINCT is what makes the union a union.
                        ->distinct()
                        ->whereNotExists(fn ($mine) => $mine->from("{$fileTable} as mine")
                            ->whereColumn('mine.asset_id', 'placement.asset_id')
                            ->whereColumn("mine.{$column}", "source.{$column}"))
                        ->select(['placement.asset_id', "source.{$column}"])
                );
            }
        }

        return $rows;
    }

    /**
     * @param  list<int>  $assetIds
     */
    protected function clearFileLists(array $assetIds): void
    {
        foreach (self::LISTS as [, $fileTable]) {
            foreach (array_chunk($assetIds, static::BATCH) as $batch) {
                DB::table($fileTable)->whereIn('asset_id', $batch)->delete();
            }
        }
    }

    /**
     * The users and groups on one folder's list, for a cascade that writes them onto a subtree.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    protected function principalsOf(int $directoryId): array
    {
        return [
            $this->ids(DB::table('dam_directory_user_access')->where('directory_id', $directoryId)->pluck('admin_id')->all()),
            $this->ids(DB::table('dam_directory_group_access')->where('directory_id', $directoryId)->pluck('group_id')->all()),
        ];
    }

    /**
     * One opt-in folder-access.jsonl entry per write, however many items it covered. $moved: written by a move.
     *
     * @param  array<string, int>  $counts
     */
    protected function logged(bool $isDirectory, array $counts, float $started, ?int $subjectId = null, bool $moved = false): void
    {
        FolderAccessLog::write(event: 'access_inherited', ctx: array_merge(
            $subjectId === null ? [] : ['subject_id' => $subjectId],
            ['is_directory' => $isDirectory],
            $moved ? ['moved' => true] : [],
            $counts,
            ['ms' => (int) round((microtime(true) - $started) * 1000)]
        ));
    }

    /**
     * The durable record of a move, whatever dam.access_logging says: one security event per moved file, or per
     * moved folder (its subtree follows it), naming the mover, the destination and the item by id only.
     * Written in the move's transaction and allowed to throw: a move whose events cannot be written fails, and
     * a move that rolls back takes its events with it.
     *
     * @param  list<array{asset_id: int}|array{directory_id: int}>  $moved
     */
    protected function recordMoves(?int $actorId, int $destinationId, array $moved): void
    {
        $guard = auth('admin')->check() ? 'admin' : null;

        app(SecurityEventRecorder::class)->recordAll(events: array_map(fn (array $item) => new SecurityEvent(
            name: SecurityEvent::ACCESS_ADOPTED_ON_MOVE,
            guard: $guard,
            actorAdminId: $actorId,
            ctx: $item + ['moved' => true, 'subject_id' => $destinationId, 'is_directory' => isset($item['directory_id'])],
        ), $moved));
    }

    /**
     * Distinct ids, ascending. Sorted, because every lock this class takes has to be in one order across the
     * whole set and not merely within a batch.
     *
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    protected function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }
}
