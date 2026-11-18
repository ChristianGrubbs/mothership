<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\User\Models\Admin;

/**
 * The owner-controlled access rule (ADR 0010), which answers every DirectoryPermissionService method
 * while the access model is `lists`. An admin can open a file or folder they own, or one whose access
 * list names them, one of their groups, or Everyone. Full admins reach everything, and so does an admin
 * with no role, for parity with the grants model. A file has its own list; its folders never widen it.
 * A disabled admin opens nothing, whatever their role, ownership or lists.
 *
 * A folder the admin cannot open still shows as a path (name only) when it leads to one they can
 * open, or to a folder holding a file they can open. Like LegacyGrantReader, the caches hold one admin
 * at a time.
 */
class AccessListReader
{
    /** dam_groups.system_key of the built-in group every active admin belongs to. */
    public const EVERYONE = 'everyone';

    protected ?array $viewableIdsCache = null;

    protected ?int $cachedForAdminId = null;

    protected ?array $directlyGrantedCache = null;

    protected ?int $directlyGrantedForAdminId = null;

    protected ?array $groupIdsCache = null;

    protected ?int $groupIdsForAdminId = null;

    protected ?int $everyoneId = null;

    protected bool $everyoneResolved = false;

    public function bypass(?Admin $admin): bool
    {
        return $this->roleBypass(admin: $admin);
    }

    public function bypassesDirectory(?Admin $admin, int $directoryId): bool
    {
        return $this->roleBypass(admin: $admin);
    }

    /**
     * Full admins, and the no-role parity case, reach everything; a disabled admin never does.
     * dam_role_settings.all_directories no longer bypasses: the rebuild turns such a role into a group
     * listed on every folder and file.
     */
    public function roleBypass(?Admin $admin): bool
    {
        if (! $admin) {
            return true;
        }

        if ($this->disabled(admin: $admin)) {
            return false;
        }

        $role = $admin->role;

        return ! $role || $role->permission_type === 'all';
    }

    /**
     * The accessible folders plus their path-only ancestors: every ancestor of a folder the admin can
     * open, or of a folder holding a file they can open, each such folder included.
     */
    public function viewableIds(?Admin $admin): array
    {
        if ($this->disabled(admin: $admin)) {
            return [];
        }

        if ($this->bypass(admin: $admin)) {
            return Directory::query()->pluck('id')->all();
        }

        if ($this->viewableIdsCache !== null && $this->cachedForAdminId === $admin->id) {
            return $this->viewableIdsCache;
        }

        $holdingFolders = DB::table('dam_asset_directory')
            ->select('directory_id')
            ->where(fn ($query) => $this->whereListed(query: $query, column: 'dam_asset_directory.asset_id', admin: $admin, item: 'asset'));

        $ids = DB::table('dam_directories as ancestor')
            ->join('dam_directories as descendant', function ($join) {
                $join->whereColumn('ancestor._lft', '<=', 'descendant._lft')
                    ->whereColumn('ancestor._rgt', '>=', 'descendant._rgt');
            })
            ->where(fn ($query) => $query
                ->where(fn ($listed) => $this->whereListed(query: $listed, column: 'descendant.id', admin: $admin, item: 'directory'))
                ->orWhereIn('descendant.id', $holdingFolders))
            ->distinct()
            ->pluck('ancestor.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->viewableIdsCache = $ids;
        $this->cachedForAdminId = $admin->id;

        return $ids;
    }

    /**
     * The folders the admin can open: owned, or listed for them, one of their groups or Everyone.
     */
    public function directlyGrantedIds(?Admin $admin): array
    {
        if ($this->disabled(admin: $admin)) {
            return [];
        }

        if ($this->roleBypass(admin: $admin)) {
            return Directory::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($this->directlyGrantedCache !== null && $this->directlyGrantedForAdminId === $admin->id) {
            return $this->directlyGrantedCache;
        }

        $ids = DB::table('dam_directories')
            ->where(fn ($query) => $this->whereListed(query: $query, column: 'dam_directories.id', admin: $admin, item: 'directory'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->directlyGrantedCache = $ids;
        $this->directlyGrantedForAdminId = $admin->id;

        return $ids;
    }

    public function canView(?Admin $admin, int $directoryId): bool
    {
        if ($this->disabled(admin: $admin)) {
            return false;
        }

        if ($this->bypass(admin: $admin)) {
            return true;
        }

        return in_array($directoryId, $this->viewableIds(admin: $admin), true);
    }

    public function canAccess(?Admin $admin, int $directoryId): bool
    {
        if ($this->disabled(admin: $admin)) {
            return false;
        }

        if ($this->bypass(admin: $admin)) {
            return true;
        }

        return in_array($directoryId, $this->directlyGrantedIds(admin: $admin), true);
    }

    /**
     * The file's own list, through the same condition the listing filter applies, so a file the index
     * shows is a file that opens (audit A1).
     */
    public function canAccessAsset(?Admin $admin, int $assetId): bool
    {
        if ($this->disabled(admin: $admin)) {
            return false;
        }

        if ($this->bypass(admin: $admin)) {
            return true;
        }

        return DB::table('dam_assets')
            ->where('dam_assets.id', $assetId)
            ->where(fn ($query) => $this->whereListed(query: $query, column: 'dam_assets.id', admin: $admin, item: 'asset'))
            ->exists();
    }

    /**
     * Restrict an asset query to the files the admin can open. The three branches sit in one closure,
     * so their ORs cannot leak into the caller's other conditions. A disabled admin gets one
     * always-false condition, wrapped the same way. $locked, $candidateIds: see whereListed().
     */
    public function applyAssetDirectoryGrantFilter(?Admin $admin, $query, string $column = 'dam_assets.id', bool $locked = false, mixed $candidateIds = null): void
    {
        if ($this->disabled(admin: $admin)) {
            $query->where(fn ($none) => $none->whereRaw('0 = 1'));

            return;
        }

        if ($this->bypass(admin: $admin)) {
            return;
        }

        $query->where(fn ($listed) => $this->whereListed(query: $listed, column: $column, admin: $admin, item: 'asset', locked: $locked, candidateIds: $candidateIds));
    }

    /**
     * The built-in Everyone group, found by its system key. Null only if the row is gone, and then
     * nobody is reached through it.
     */
    public function everyoneId(): ?int
    {
        if (! $this->everyoneResolved) {
            $id = DB::table('dam_groups')->where('system_key', self::EVERYONE)->value('id');
            $this->everyoneId = $id === null ? null : (int) $id;
            $this->everyoneResolved = true;
        }

        return $this->everyoneId;
    }

    public function flush(): void
    {
        $this->viewableIdsCache = null;
        $this->cachedForAdminId = null;
        $this->directlyGrantedCache = null;
        $this->directlyGrantedForAdminId = null;
        $this->groupIdsCache = null;
        $this->groupIdsForAdminId = null;
        $this->everyoneId = null;
        $this->everyoneResolved = false;
    }

    /**
     * Checked before any bypass, cached set or principal query: under lists a disabled account opens
     * nothing, the session-free forAdmin() path included. No admin at all keeps the parity bypass.
     */
    protected function disabled(?Admin $admin): bool
    {
        return $admin !== null && ! (bool) $admin->status;
    }

    /**
     * The rule for one item kind: $column is owned by the admin, or on their user list, or on the list
     * of one of their groups. Every branch is a subquery on the id column, so it holds under any alias.
     *
     * $locked (Codex review of PR #75 round 2, finding 2; restricted round 3, finding 2): a shared
     * lock on each of the three subqueries, for a caller re-validating from inside a lock it already
     * holds on the row $column names. On PostgreSQL (READ COMMITTED) every plain statement already
     * sees the latest committed data, so this changes nothing there; on MySQL/MariaDB (REPEATABLE
     * READ) a plain SELECT still answers from the transaction's snapshot even after the row it names
     * has been locked by an earlier statement — only a locking read of *these* list tables, in this
     * statement, forces a current read of them.
     *
     * $candidateIds: without it, each locked subquery was uncorrelated — every asset or directory the
     * admin owns or is listed on, library-wide, not just the ids $query is actually asking about. A
     * small pruned re-check then locked or waited on every unrelated row the admin can see anywhere,
     * including accessible files beneath a skipped ancestor no one asked about, defeating the lock's
     * whole point of touching only the rows this one answer depends on. Whatever whereIn() itself
     * accepts — an array, a Closure, or a Builder — restricting each locked subquery to exactly the
     * ids (or the same id-deriving subquery) the caller is re-validating. Unused unless $locked; the
     * non-locking path is unchanged.
     */
    protected function whereListed($query, string $column, Admin $admin, string $item, bool $locked = false, mixed $candidateIds = null): void
    {
        $items = $item === 'asset' ? 'dam_assets' : 'dam_directories';

        $owned = DB::table($items)->select('id')->where('created_by', $admin->id);
        $userListed = DB::table("dam_{$item}_user_access")->select("{$item}_id")->where('admin_id', $admin->id);
        $groupListed = DB::table("dam_{$item}_group_access")->select("{$item}_id")->whereIn('group_id', $this->groupIds(admin: $admin));

        if ($locked) {
            $owned->sharedLock();
            $userListed->sharedLock();
            $groupListed->sharedLock();

            if ($candidateIds !== null) {
                $owned->whereIn('id', $candidateIds);
                $userListed->whereIn("{$item}_id", $candidateIds);
                $groupListed->whereIn("{$item}_id", $candidateIds);
            }
        }

        $query->whereIn($column, $owned)
            ->orWhereIn($column, $userListed)
            ->orWhereIn($column, $groupListed);
    }

    /**
     * whereListed()'s negation: $column is owned by nobody but the admin's exclusion — not the admin's
     * own item, not on their user list, and not on any of their groups' lists. Every branch is a
     * NOT IN, so the whole condition is the AND De Morgan's law gives the three ORs above (sharing
     * slice 2b, the move/delete guard). $locked, $candidateIds: see whereListed().
     */
    protected function whereNotListed($query, string $column, Admin $admin, string $item, bool $locked = false, mixed $candidateIds = null): void
    {
        $items = $item === 'asset' ? 'dam_assets' : 'dam_directories';

        $owned = DB::table($items)->select('id')->where('created_by', $admin->id);
        $userListed = DB::table("dam_{$item}_user_access")->select("{$item}_id")->where('admin_id', $admin->id);
        $groupListed = DB::table("dam_{$item}_group_access")->select("{$item}_id")->whereIn('group_id', $this->groupIds(admin: $admin));

        if ($locked) {
            $owned->sharedLock();
            $userListed->sharedLock();
            $groupListed->sharedLock();

            if ($candidateIds !== null) {
                $owned->whereIn('id', $candidateIds);
                $userListed->whereIn("{$item}_id", $candidateIds);
                $groupListed->whereIn("{$item}_id", $candidateIds);
            }
        }

        $query->whereNotIn($column, $owned)
            ->whereNotIn($column, $userListed)
            ->whereNotIn($column, $groupListed);
    }

    /**
     * The move/delete guard's question (sharing slice 2b): how many folders and files in $directoryId's
     * subtree $admin cannot see. Full admins and the no-role parity case never ask (roleBypass()); a
     * disabled admin cannot see anything in the subtree, so every item in it counts.
     *
     * One statement (Codex review of PR #75, finding 9, extended round 2, finding 5): the root's own
     * `_lft`/`_rgt` bounds are read as a cross-joined subquery rather than a separate SELECT before this
     * one, so the whole question — bounds, candidate folder ids, candidate file ids, tagged by kind and
     * combined with UNION ALL, counted per kind by the outer query — is one round trip and one snapshot
     * of the rows it reads. A directory that no longer exists joins against zero rows and both counts
     * fall out at 0 through the same path as "nothing was inaccessible", with no separate early return
     * needed. The 'folder'/'file' tags are query bindings, not text spliced into the SQL, for the same
     * reason AccessListService::everyoneFirst() binds its own literal.
     *
     * Excluded from "one statement": roleBypass()/disabled() read $admin->role via Eloquent's lazy
     * relation, and whereNotListed()'s group branch calls groupIds(), each its own (cached) query —
     * principal membership, not subtree state, so nothing here races a concurrent write the way the
     * bounds and candidates did.
     *
     * @return array{folders: int, files: int}
     */
    public function inaccessibleSubtreeCounts(?Admin $admin, int $directoryId): array
    {
        if ($this->roleBypass(admin: $admin)) {
            return ['folders' => 0, 'files' => 0];
        }

        $counts = DB::table($this->inaccessibleSubtreeQuery(admin: $admin, directoryId: $directoryId), 'inaccessible')
            ->select('kind')
            ->selectRaw('count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return [
            'folders' => (int) ($counts['folder'] ?? 0),
            'files'   => (int) ($counts['file'] ?? 0),
        ];
    }

    /**
     * inaccessibleSubtreeCounts()'s candidate query, built but not executed — a testable seam so a
     * table-prefix compile check (Codex review of PR #75 round 2, finding 3: an earlier raw
     * `selectRaw('dam_directories.id as candidate_id')` bypassed prefix wrapping entirely, and was
     * unused besides — DB_PREFIX=wk_ compiled a FROM of `wk_dam_directories` beside a SELECT of
     * bare `dam_directories.id`, a name that table alias never had) can assert the compiled SQL
     * without needing prefixed tables to actually exist.
     */
    protected function inaccessibleSubtreeQuery(?Admin $admin, int $directoryId)
    {
        $disabled = $this->disabled(admin: $admin);
        $root = DB::table('dam_directories')->select('_lft', '_rgt')->where('id', $directoryId);

        // Folder checks take no lock of their own (round 2, finding 2 leaves this branch alone): every
        // folder-list writer takes the directory tree lock (ADR 0004) before it can commit, and every
        // caller of this method already holds that same lock before it asks — DirectoryTreeLock::run()
        // takes the fence's FOR SHARE read and then the tree lock as this transaction's first two
        // statements, before any folder or file row, so no plain read here can be answering from a
        // snapshot older than the lock. A caller that ever asks outside that lock must make this branch
        // a locking read too.
        $folders = DB::table('dam_directories')
            ->selectRaw('? as kind', ['folder'])
            ->crossJoinSub($root, 'root')
            ->whereColumn('dam_directories._lft', '>=', 'root._lft')
            ->whereColumn('dam_directories._lft', '<=', 'root._rgt')
            ->when(! $disabled, fn ($query) => $query->where(fn ($listed) => $this->whereNotListed(query: $listed, column: 'dam_directories.id', admin: $admin, item: 'directory')));

        // The file branch is a locking read (round 2, finding 2): a file's list is rewritten under only
        // its own row lock (AccessListService::rewriteAsset()), never the tree lock this method's
        // callers hold, so on MySQL/MariaDB's REPEATABLE READ a plain read here could still be answering
        // from a snapshot taken before a revocation committed — including, for
        // AccessMoveGuard::checkLocked(), one that committed before the file rows this counts were
        // locked, since checkLocked() locks them only after this method's caller decides to ask.
        //
        // The same subtree-asset-ids subquery is also this locked read's own candidate restriction
        // (round 3, finding 2): built once and used twice — once for the outer membership test, once
        // passed to whereNotListed() — so its locked subqueries touch only files in this subtree, not
        // every file the admin can see library-wide.
        $subtreeAssetIds = fn () => DB::table('dam_asset_directory')
            ->join('dam_directories as folder', 'folder.id', '=', 'dam_asset_directory.directory_id')
            ->crossJoinSub($root, 'root')
            ->whereColumn('folder._lft', '>=', 'root._lft')
            ->whereColumn('folder._lft', '<=', 'root._rgt')
            ->select('dam_asset_directory.asset_id');

        $files = DB::table('dam_assets')
            ->selectRaw('? as kind', ['file'])
            ->crossJoinSub($root, 'root')
            ->whereIn('dam_assets.id', $subtreeAssetIds())
            ->when(! $disabled, fn ($query) => $query->where(fn ($listed) => $this->whereNotListed(query: $listed, column: 'dam_assets.id', admin: $admin, item: 'asset', locked: true, candidateIds: $subtreeAssetIds())));

        return $folders->unionAll($files);
    }

    /**
     * The admin's principals besides themselves: the groups they belong to, plus Everyone.
     */
    protected function groupIds(Admin $admin): array
    {
        if ($this->groupIdsCache !== null && $this->groupIdsForAdminId === $admin->id) {
            return $this->groupIdsCache;
        }

        $ids = DB::table('dam_group_members')
            ->where('admin_id', $admin->id)
            ->pluck('group_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (($everyoneId = $this->everyoneId()) !== null) {
            $ids[] = $everyoneId;
        }

        $this->groupIdsCache = array_values(array_unique($ids));
        $this->groupIdsForAdminId = $admin->id;

        return $this->groupIdsCache;
    }
}
