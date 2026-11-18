<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\FolderAccessLog;
use Mothership\User\Models\Admin;

/**
 * The move and delete refusal ADR 0010 adds under `lists` only (sharing slice 2b): a non-full-admin may
 * not move or delete a folder whose subtree holds any folder or file they cannot see. Under `grants` —
 * the default — every check here is a no-op, so move and delete behave exactly as they do today.
 *
 * $admin is always taken as a parameter, never read from the session or from DirectoryPermissionService:
 * a queued job's re-check evaluates its own userId on a plain Admin row, and AccessListReader answers
 * every question for whichever admin it is given, so there is no scoped/sticky state to worry about
 * here (unlike DirectoryPermissionService::forAdmin(), which mutates a shared instance).
 */
class AccessMoveGuard
{
    public function __construct(
        protected AccessModel $model,
        protected AccessListReader $reader,
    ) {}

    /**
     * Whether $directoryId's subtree holds anything $admin cannot see. Always false under `grants`. Every
     * refusal is logged once, here, so every caller (the controllers and the jobs' re-checks) shares one
     * `access_move_refused` line instead of each writing its own.
     */
    public function isBlocked(Admin $admin, int $directoryId): bool
    {
        if (! $this->model->lists()) {
            return false;
        }

        $counts = $this->reader->inaccessibleSubtreeCounts(admin: $admin, directoryId: $directoryId);

        if ($counts['folders'] + $counts['files'] === 0) {
            return false;
        }

        FolderAccessLog::write(event: 'access_move_refused', ctx: [
            'actor_id'   => (int) $admin->id,
            'subject_id' => $directoryId,
            'folders'    => $counts['folders'],
            'files'      => $counts['files'],
        ]);

        return true;
    }

    /**
     * What a role limited to its own items would drag along when it moves `$directoryId`: the folders and files in the
     * subtree that it did not create (a NULL owner is nobody's). A move re-parents every descendant and hands the whole
     * subtree the destination's access list, so it is refused when anything in it belongs to someone else. Zero for an
     * unrestricted admin, under either access model; the selected folder itself is checked by its own gate.
     *
     * @return array{folders: int, files: int}
     */
    public function foreignSubtreeCounts(Admin $admin, int $directoryId): array
    {
        if (! app(RoleBuilder::class)->restrictedToOwn($admin)) {
            return ['folders' => 0, 'files' => 0];
        }

        $root = DB::table('dam_directories')->where('id', $directoryId)->first(['_lft', '_rgt']);

        if ($root === null) {
            return ['folders' => 0, 'files' => 0];
        }

        $notMine = fn ($query, string $column) => $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '!=', (int) $admin->id));
        $folders = $notMine(DB::table('dam_directories')->whereBetween('_lft', [(int) $root->_lft, (int) $root->_rgt]), 'created_by')->count();
        $files = $notMine(
            DB::table('dam_assets')
                ->whereNull('deleted_at')
                ->whereIn('id', DB::table('dam_asset_directory')
                    ->join('dam_directories as folder', 'folder.id', '=', 'dam_asset_directory.directory_id')
                    ->whereBetween('folder._lft', [(int) $root->_lft, (int) $root->_rgt])
                    ->select('dam_asset_directory.asset_id')),
            'created_by',
        )->count();

        if ($folders + $files > 0) {
            FolderAccessLog::write(event: 'own_only_move_refused', ctx: [
                'actor_id'   => (int) $admin->id,
                'subject_id' => $directoryId,
                'folders'    => $folders,
                'files'      => $files,
            ]);
        }

        return ['folders' => $folders, 'files' => $files];
    }

    /**
     * The first of $directoryIds that isBlocked(), or null when none is — for a batch check
     * (MassMoveController's folder branch, DirectoryController::massDestroy) that must refuse the whole
     * request rather than act on part of it.
     *
     * @param  list<int>  $directoryIds
     */
    public function firstBlocked(Admin $admin, array $directoryIds): ?int
    {
        foreach ($directoryIds as $directoryId) {
            if ($this->isBlocked(admin: $admin, directoryId: $directoryId)) {
                return $directoryId;
            }
        }

        return null;
    }

    /**
     * The job-level re-check (Codex review of PR #75, findings 1 and 2): locks $directoryId's subtree
     * folder rows FOR UPDATE, in `_lft` order, root first, then its file rows through
     * Asset::lockedForGraph(), in ascending id order — the same stages and order
     * AccessListService's own lock order takes them in — and only then asks isBlocked(). Locking first
     * is what makes the answer authoritative: the directory tree lock the caller already holds
     * (ADR 0004) keeps every folder's list from changing, since any cascade must take that same lock
     * first, but a single file's list can still change without it —
     * AccessListService::rewriteAsset() locks only that one file's row, never the tree lock. Holding
     * these rows before reading means nothing the guard reads here can still change before the caller
     * acts on its answer.
     *
     * Callers already hold the directory tree lock before calling this; nothing here takes it again.
     * A missing $directoryId locks nothing and is never blocked — the caller's own read already 404s or
     * no-ops on a directory that is gone.
     *
     * @return array{blocked: bool, foreign: int, folderIds: list<int>, assetIds: list<int>}
     */
    public function checkLocked(Admin $admin, int $directoryId): array
    {
        $root = DB::table('dam_directories')->where('id', $directoryId)->lockForUpdate()->first(['id', '_lft', '_rgt']);

        if ($root === null) {
            return ['blocked' => false, 'foreign' => 0, 'folderIds' => [], 'assetIds' => []];
        }

        $bounds = [(int) $root->_lft, (int) $root->_rgt];

        $folderIds = DB::table('dam_directories')
            ->whereBetween('_lft', $bounds)
            ->orderBy('_lft')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $assetIds = Asset::query()
            ->whereIn('dam_assets.id', DB::table('dam_asset_directory')
                ->join('dam_directories as folder', 'folder.id', '=', 'dam_asset_directory.directory_id')
                ->whereBetween('folder._lft', $bounds)
                ->select('dam_asset_directory.asset_id'))
            ->orderBy('dam_assets.id')
            ->lockedForGraph()
            ->pluck('dam_assets.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // After the locks, like isBlocked(): a descendant added since the caller's own check is counted here.
        $foreign = $this->foreignSubtreeCounts(admin: $admin, directoryId: $directoryId);

        return [
            'blocked'   => $this->isBlocked(admin: $admin, directoryId: $directoryId),
            'foreign'   => $foreign['folders'] + $foreign['files'],
            'folderIds' => $folderIds,
            'assetIds'  => $assetIds,
        ];
    }
}
