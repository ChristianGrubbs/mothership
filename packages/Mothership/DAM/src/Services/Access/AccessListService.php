<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Support\FolderAccessLog;
use Mothership\User\Models\Admin;
use RuntimeException;

/**
 * Every write to an access list (ADR 0010, sharing slice 3): the Share dialog's read and save, and the
 * cascade a folder's save runs through its subtree.
 *
 * The rule the spec gives for writes: only the owner (`created_by`) or a full admin sets a list, whatever
 * their role capabilities. The routes carry no ACL key — an unmapped route name is open to every signed-in
 * admin (Bouncer::checkIfAuthorized()) — so that check lives here, not in the middleware. An actor who
 * cannot open the item gets 404 rather than 403, so the endpoint never confirms that an item exists.
 *
 * Lock order, extending ADR 0010's — every caller takes these stages in exactly this order, and a caller
 * that needs only a later stage skips the earlier ones rather than reordering them:
 *   1. the DAM_ACCESS_MODEL row, FOR SHARE, through AccessModel::sharedly(), so no list write commits
 *      across a switch's activation;
 *   2. the directory tree lock (ADR 0004), for a folder cascade;
 *   3. the subtree's folder rows, FOR UPDATE, in `_lft` order, the root first;
 *   4. the affected file rows, in ascending id order, through Asset::lockedForGraph().
 *
 * Stage 4 is the serialization point two list writers share, and it is why a file save takes a row lock of
 * its own. Without it, two saves of one file both delete an empty list and then insert their own
 * principals, and the file ends up with the union of two requests — neither of the lists anyone asked for
 * (Codex review of PR #69, finding 1). The same race runs between a file save and a cascade over a folder
 * holding that file. Ascending id matches Asset::lockedForGraph()'s existing callers
 * (ClonesOriginalFileObjects::lockSourceAssets), so a copy and a cascade queue rather than cross.
 *
 * Stage 4 is FOR NO KEY UPDATE on PostgreSQL, not FOR UPDATE: it excludes other list writers and plain
 * updates while still letting a foreign key to the asset take its KEY SHARE lock, so attaching a tag or a
 * placement never queues behind a large cascade.
 *
 * Deadlock surface, stated rather than assumed: a writer that takes files and *then* the tree lock — a copy
 * job that locks its sources and then creates a folder — can close a cycle with a cascade holding the tree
 * lock and waiting for those files. PostgreSQL rolls one side back, which is a safe failure: a rolled-back
 * cascade has written nothing. Sharing slice 2's callers of applyToSubtree() inherit this order, so a
 * writer of theirs that also needs one file's rows must take it at stage 4 and by ascending id.
 *
 * Every rewrite is set-based: one delete and one insert-select per list table, never a query per item.
 */
class AccessListService
{
    /** An empty list: only the owner and full admins open the item. */
    public const PRIVATE = 'private';

    /** The named users and groups. */
    public const PEOPLE = 'people';

    /** The one built-in Everyone group row. */
    public const EVERYONE = 'everyone';

    public const MODES = [self::PRIVATE, self::PEOPLE, self::EVERYONE];

    /** The cap the folder-access panel already applied per principal kind (FolderAccessController). */
    public const MAX_PRINCIPALS = 1000;

    public function __construct(
        protected AccessModel $model,
        protected AccessListReader $reader,
        protected DirectoryTreeLock $treeLock,
    ) {}

    /**
     * The Share dialog's read: the item, its owner, its mode and its principals, plus whether $actor may
     * change them. A folder also carries the cascade preview the dialog shows before saving.
     *
     * 404 when $actor cannot open the item, because the response names people and their email addresses. A
     * folder $actor sees only as a path (an ancestor of something they can open) is not enough.
     *
     * @return array{type: string, id: int, name: string, owner: array{id: ?int, name: ?string}, mode: string, users: list<array{id: int, name: string, email: string}>, groups: list<array{id: int, name: string, member_count: int}>, can_edit: bool, owned_by_viewer: bool, cascade?: array{folders: int, files: int, owned_by_others: int}, people?: list<array{id: int, name: string, email: string, reasons: list<string>}>}
     */
    public function describe(string $type, int $id, Admin $actor): array
    {
        $item = $this->item(type: $type, id: $id, actor: $actor);
        $owner = $item->created_by === null ? null : DB::table('admins')->where('id', $item->created_by)->first(['id', 'name']);

        $data = [
            'type'     => $type,
            'id'       => $id,
            'name'     => (string) $item->name,
            'owner'    => ['id' => $owner === null ? null : (int) $owner->id, 'name' => $owner?->name],
            'mode'     => $this->modesFor(type: $type, ids: [$id])[$id] ?? self::PRIVATE,
            'users'    => $this->listedUsers(type: $type, id: $id),
            'groups'   => $this->listedGroups(type: $type, id: $id),
            'can_edit' => $this->canEdit(item: $item, actor: $actor),
            // can_edit is also true for a full admin, so the dialog needs the owner separately to choose
            // between "You own this" and "Shared with you by <owner>".
            'owned_by_viewer' => $item->created_by !== null && (int) $item->created_by === (int) $actor->id,
        ];

        if ($type === 'directory') {
            $data['cascade'] = $this->cascadePreview(directory: $item, actor: $actor);
        }

        // Only for an editor: the picker never discloses group membership, and this list expands it.
        if ($data['can_edit']) {
            $data['people'] = app(EffectiveAccessService::class)->people(type: $this->itemKind(type: $type), id: $id);
            $data['public_links'] = $this->publicLinks(type: $type, id: $id, actor: $actor);
        }

        return $data;
    }

    /**
     * The public links that still open this item, for the dialog's "Only me, but a link is live" warning.
     * `direct` counts the active links whose target is the item itself; `inherited` lists the active links
     * on folders that serve it — a folder link serves its whole subtree (the public viewer's subtree check),
     * so for a file those are the folders it sits in and their ancestors, for a folder its strict ancestors.
     * Active = Share::isActive(): not revoked, not expired.
     *
     * @return array{direct: int, inherited: list<array{folder_id: int, folder_name: ?string, count: int}>}
     */
    public function publicLinks(string $type, int $id, ?Admin $actor = null): array
    {
        $kind = $this->itemKind(type: $type);

        $direct = Share::query()->active()->where('share_type', $kind)->where('target_id', $id)->count();

        $serving = DB::table('dam_directories as folder');

        if ($kind === 'directory') {
            $serving->join('dam_directories as item', fn ($join) => $join
                ->on('folder._lft', '<', 'item._lft')
                ->on('folder._rgt', '>', 'item._rgt'))
                ->where('item.id', $id);
        } else {
            $serving->join('dam_directories as item', fn ($join) => $join
                ->on('folder._lft', '<=', 'item._lft')
                ->on('folder._rgt', '>=', 'item._rgt'))
                ->join('dam_asset_directory as placement', 'placement.directory_id', '=', 'item.id')
                ->where('placement.asset_id', $id);
        }

        $folders = $serving->distinct()->pluck('folder.name', 'folder.id');

        $inherited = [];

        // A folder the viewer cannot open (a path-only ancestor does not count) is still reported, since it
        // serves the item, but unnamed.
        $permissions = $actor === null ? app(DirectoryPermissionService::class) : (new DirectoryPermissionService)->forAdmin($actor);

        if ($folders->isNotEmpty()) {
            $counts = Share::query()->active()
                ->where('share_type', Share::TYPE_DIRECTORY)
                ->whereIn('target_id', $folders->keys()->all())
                ->selectRaw('target_id, count(*) as links')
                ->groupBy('target_id')
                ->orderBy('target_id')
                ->pluck('links', 'target_id');

            foreach ($counts as $folderId => $links) {
                $inherited[] = ['folder_id' => (int) $folderId, 'folder_name' => $permissions->canAccess((int) $folderId) ? (string) $folders[$folderId] : null, 'count' => (int) $links];
            }
        }

        return ['direct' => $direct, 'inherited' => $inherited];
    }

    /**
     * Revokes every active link whose target is the item itself — never an ancestor folder's, which is
     * revoked from that folder. Same owner-or-full-admin gate as set(); 404 when $actor cannot open it.
     *
     * @return array{direct: int, inherited: list<array{folder_id: int, folder_name: ?string, count: int}>}
     */
    public function revokePublicLinks(Admin $actor, string $type, int $id): array
    {
        $item = $this->item(type: $type, id: $id, actor: $actor);

        abort_unless($this->canEdit(item: $item, actor: $actor), 403, trans('dam::app.admin.dam.access.not-owner'));

        // One write, the same revoked_at stamp ShareRepository::revoke() sets, for every active link at once.
        DB::transaction(function () use ($type, $id): void {
            Share::query()->active()
                ->where('share_type', $this->itemKind(type: $type))
                ->where('target_id', $id)
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        });

        return $this->publicLinks(type: $type, id: $id, actor: $actor);
    }

    /**
     * Replaces the item's access list, and a folder's whole subtree with it (spec D2). Only the owner or a
     * full admin: 404 when $actor cannot open the item, 403 when they can but neither owns it nor is a full
     * admin, 422 on an unknown mode or a principal id that is not an existing active user or a group.
     *
     * The state row is re-read under the hold and the write happens only while it is exactly `lists`; a
     * switch that moved the model since the request started leaves the tables untouched.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @return array{mode: string, users: int, groups: int, folders: int, files: int}
     */
    public function set(Admin $actor, string $type, int $id, string $mode, array $userIds, array $groupIds): array
    {
        $item = $this->item(type: $type, id: $id, actor: $actor);

        abort_unless($this->canEdit(item: $item, actor: $actor), 403, trans('dam::app.admin.dam.access.not-owner'));

        [$userIds, $groupIds] = $this->principalsFor(mode: $mode, userIds: $userIds, groupIds: $groupIds);

        $started = microtime(true);

        $counts = $this->model->sharedly(function (?string $raw) use ($type, $id, $userIds, $groupIds) {
            // The same mapping current() uses: a pending switch, a missing row and any unknown value are
            // grants, where the lists are not the record and this dialog does not exist.
            abort_unless(AccessModel::modelFor(raw: $raw) === AccessModel::LISTS, 404);

            return $type === 'directory'
                ? $this->rewriteSubtree(directoryId: $id, userIds: $userIds, groupIds: $groupIds)
                : $this->rewriteAsset(assetId: $id, userIds: $userIds, groupIds: $groupIds);
        });

        app(DirectoryPermissionService::class)->flush();

        FolderAccessLog::write(
            event: $type === 'directory' ? 'access_cascaded' : 'access_set',
            ctx: [
                'actor_id'     => (int) $actor->id,
                'subject_id'   => $id,
                'is_directory' => $type === 'directory',
                'users'        => count($userIds),
                'groups'       => count($groupIds),
                'folders'      => $counts['folders'],
                'files'        => $counts['files'],
                'ms'           => (int) round((microtime(true) - $started) * 1000),
            ]
        );

        return ['mode' => $mode, 'users' => count($userIds), 'groups' => count($groupIds)] + $counts;
    }

    /**
     * The folder branch of set() without its authorization and without its model check: the state row's
     * shared hold, the tree lock, the subtree's folder rows FOR UPDATE in `_lft` order, and the set-based
     * rewrite. Sharing slice 2 reuses it so a moved folder or file takes its destination's list, under both
     * access models (ADR 0010), which is why no model check belongs here.
     *
     * Contract: it must run under a transaction that already holds the DAM_ACCESS_MODEL row FOR SHARE.
     * Called outside a transaction it opens one and takes that hold as its first statement; called inside
     * one, the caller must already hold it. set() authorizes, takes the hold, checks the model, then calls
     * this.
     *
     * A folder deleted since the caller read it has no subtree left to rewrite, and the counts come back
     * zero rather than failing a concurrent writer.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @return array{folders: int, files: int}
     */
    public function applyToSubtree(int $directoryId, array $userIds, array $groupIds): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->rewriteSubtree(directoryId: $directoryId, userIds: $userIds, groupIds: $groupIds);
        }

        return $this->model->sharedly(fn () => $this->rewriteSubtree(directoryId: $directoryId, userIds: $userIds, groupIds: $groupIds));
    }

    /**
     * The mode of each of $ids, in two queries over the page's ids rather than one query per item: the
     * Everyone group row makes it `everyone`, any other row `people`, no row at all `private`.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function modesFor(string $type, array $ids): array
    {
        $item = $this->itemKind(type: $type);
        $key = $item.'_id';

        if ($ids === []) {
            return [];
        }

        $modes = array_fill_keys($ids, self::PRIVATE);
        $everyoneId = $this->reader->everyoneId();

        foreach (DB::table("dam_{$item}_group_access")->whereIn($key, $ids)->get([$key, 'group_id']) as $row) {
            $id = (int) $row->$key;

            if ($everyoneId !== null && (int) $row->group_id === $everyoneId) {
                $modes[$id] = self::EVERYONE;
            } elseif ($modes[$id] === self::PRIVATE) {
                $modes[$id] = self::PEOPLE;
            }
        }

        foreach (DB::table("dam_{$item}_user_access")->whereIn($key, $ids)->distinct()->pluck($key) as $id) {
            if (($modes[(int) $id] ?? self::PRIVATE) === self::PRIVATE) {
                $modes[(int) $id] = self::PEOPLE;
            }
        }

        return $modes;
    }

    /**
     * The picker's candidates: up to $limit active users and $limit groups matching $query, Everyone first.
     * Open to any signed-in admin (spec, Groups), so it carries no membership rows — only each group's
     * member count, which for Everyone is the number of active admins, since that group has no member rows.
     *
     * @return array{users: list<array{id: int, name: string, email: string}>, groups: list<array{id: int, name: string, member_count: int}>}
     */
    public function principals(?string $query = null, int $limit = 20): array
    {
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = $query === null || trim($query) === '' ? null : '%'.str_replace(['%', '_'], ['\%', '\_'], trim($query)).'%';

        $users = DB::table('admins')
            ->where('status', true)
            ->whereNotIn('id', $this->integrationAdminIds())
            ->when($term !== null, fn ($builder) => $builder->where(fn ($match) => $match
                ->where('name', $like, $term)
                ->orWhere('email', $like, $term)))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'email'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'email' => (string) $row->email])
            ->all();

        $groups = DB::table('dam_groups')
            ->when($term !== null, fn ($builder) => $builder->where('name', $like, $term))
            // Everyone first, then by name.
            ->orderByRaw(...$this->everyoneFirst(column: 'system_key'))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'system_key'])
            ->all();

        $counts = $this->memberCounts(groupIds: array_map(fn ($group) => (int) $group->id, $groups));

        return [
            'users'  => $users,
            'groups' => array_map(fn ($group) => [
                'id'           => (int) $group->id,
                'name'         => (string) $group->name,
                'system_key'   => $group->system_key,
                'member_count' => $counts[(int) $group->id] ?? 0,
            ], $groups),
        ];
    }

    /**
     * The item, or 404 when $actor cannot open it. Answers under whichever access model is current: a folder
     * through `canAccess` (never `canView`, which a path-only ancestor satisfies), a file through the
     * AssetAccessDecision seam (its list or folders, and availability).
     */
    protected function item(string $type, int $id, Admin $actor): object
    {
        $table = $this->itemKind(type: $type) === 'asset' ? 'dam_assets' : 'dam_directories';
        $columns = $table === 'dam_assets' ? ['id', 'file_name as name', 'created_by'] : ['id', 'name', 'created_by'];

        $item = DB::table($table)->where('id', $id)->first($columns);

        abort_if($item === null, 404);

        // A file asks the seam for $actor: its list and its availability, so a draft or out-of-window file the
        // actor may not manage answers like a missing one (P2 S3 review). A folder asks a fresh instance, not the
        // scoped one: forAdmin() is sticky, and the request's own service must keep answering for the signed-in
        // admin after this check.
        abort_unless(
            $type === 'directory'
                ? (new DirectoryPermissionService)->forAdmin($actor)->canAccess($id)
                : app(AssetAccessDecision::class)->for(AccessContext::forAdmin(admin: $actor), $id)->visible,
            404
        );

        return $item;
    }

    /** Only the owner, or a full admin (spec D4 and "Rules for writes"), whatever their role capabilities. */
    protected function canEdit(object $item, Admin $actor): bool
    {
        return (int) $item->created_by === (int) $actor->id || $actor->role?->permission_type === 'all';
    }

    /**
     * The rows each mode writes. `private` writes none and `everyone` writes the one built-in group row,
     * whatever the request asked for; only `people` takes the given ids, and each must be an existing
     * group or an existing active user.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @return array{0: list<int>, 1: list<int>}
     */
    protected function principalsFor(string $mode, array $userIds, array $groupIds): array
    {
        abort_unless(in_array($mode, self::MODES, true), 422, trans('dam::app.admin.dam.access.unknown-mode'));

        if ($mode === self::PRIVATE) {
            return [[], []];
        }

        if ($mode === self::EVERYONE) {
            $everyoneId = $this->reader->everyoneId();

            if ($everyoneId === null) {
                throw new RuntimeException('The built-in Everyone group is missing; run dam:access:rebuild.');
            }

            return [[], [$everyoneId]];
        }

        $userIds = $this->distinctIds(ids: $userIds);
        $groupIds = $this->distinctIds(ids: $groupIds);

        abort_if(count($userIds) > self::MAX_PRINCIPALS || count($groupIds) > self::MAX_PRINCIPALS, 422, trans('dam::app.admin.dam.access.too-many'));

        // Validated before anything is written, so an invalid id leaves every list as it was.
        $existingUsers = $userIds === [] ? 0 : DB::table('admins')->whereIn('id', $userIds)->where('status', true)->count();
        $existingGroups = $groupIds === [] ? 0 : DB::table('dam_groups')->whereIn('id', $groupIds)->count();

        abort_if($existingUsers !== count($userIds) || $existingGroups !== count($groupIds), 422, trans('dam::app.admin.dam.access.unknown-principal'));

        return [$userIds, $groupIds];
    }

    /**
     * The folder, every folder beneath it and every file in any of them, rewritten to one list.
     *
     * Takes stages 2 to 4 of the class's lock order: the tree lock, the subtree's folder rows FOR UPDATE in
     * `_lft` order with the root first, then every file those folders hold, by ascending id. A concurrent
     * access writer over any of the same folders or files waits rather than interleaving its rows.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @return array{folders: int, files: int}
     */
    protected function rewriteSubtree(int $directoryId, array $userIds, array $groupIds): array
    {
        return $this->treeLock->run(function () use ($directoryId, $userIds, $groupIds) {
            // The root carries the subtree's lowest _lft, so locking it first keeps the _lft order.
            $root = DB::table('dam_directories')->where('id', $directoryId)->lockForUpdate()->first(['id', '_lft', '_rgt']);

            if ($root === null) {
                return ['folders' => 0, 'files' => 0];
            }

            $bounds = [(int) $root->_lft, (int) $root->_rgt];
            $folders = DB::table('dam_directories')->whereBetween('_lft', $bounds)->orderBy('_lft')->lockForUpdate()->pluck('id');

            $subtreeFolders = fn () => DB::table('dam_directories')->select('id')->whereBetween('_lft', $bounds);
            // A trashed file (P2 S4) keeps the list it had when it was deleted, for the Trash page and Restore:
            // the cascade neither locks nor rewrites it, so every statement below reads this same live set.
            $subtreeAssets = fn () => DB::table('dam_asset_directory')
                ->join('dam_directories as folder', 'folder.id', '=', 'dam_asset_directory.directory_id')
                ->join('dam_assets as live', 'live.id', '=', 'dam_asset_directory.asset_id')
                ->whereBetween('folder._lft', $bounds)
                ->whereNull('live.deleted_at')
                ->select('dam_asset_directory.asset_id');

            // Stage 4: the files these folders hold, by ascending id, the same rows and the same order a
            // file save takes. Holding them is what stops a save and this cascade from each deleting an
            // empty list and then inserting their own principals. pluck() rather than count(), because the
            // rows have to be read to be locked and PostgreSQL refuses a row lock alongside an aggregate;
            // counting the locked ids also reports exactly what was rewritten, and a file placed in two
            // folders of this subtree is one id here, since the lock reads dam_assets, not its placements.
            $assetIds = Asset::query()
                ->whereIn('dam_assets.id', $subtreeAssets())
                ->orderBy('dam_assets.id')
                ->lockedForGraph()
                ->pluck('dam_assets.id')
                ->all();

            DB::table('dam_directory_user_access')->whereIn('directory_id', $subtreeFolders())->delete();
            DB::table('dam_directory_group_access')->whereIn('directory_id', $subtreeFolders())->delete();
            DB::table('dam_asset_user_access')->whereIn('asset_id', $subtreeAssets())->delete();
            DB::table('dam_asset_group_access')->whereIn('asset_id', $subtreeAssets())->delete();

            // One insert-select per table: the principals are joined in from their own tables, so a subtree
            // of any size costs the same four statements. DISTINCT on the file side, because a file placed
            // in two folders of this subtree appears once per placement.
            foreach ([['dam_directory_user_access', 'admin_id', 'admins', $userIds], ['dam_directory_group_access', 'group_id', 'dam_groups', $groupIds]] as [$table, $column, $principals, $ids]) {
                if ($ids === []) {
                    continue;
                }

                DB::table($table)->insertUsing(['directory_id', $column], DB::table('dam_directories as folder')
                    ->crossJoin($principals.' as principal')
                    ->whereBetween('folder._lft', $bounds)
                    ->whereIn('principal.id', $ids)
                    ->select(['folder.id', 'principal.id']));
            }

            foreach ([['dam_asset_user_access', 'admin_id', 'admins', $userIds], ['dam_asset_group_access', 'group_id', 'dam_groups', $groupIds]] as [$table, $column, $principals, $ids]) {
                if ($ids === []) {
                    continue;
                }

                DB::table($table)->insertUsing(['asset_id', $column], DB::table('dam_asset_directory as placement')
                    ->join('dam_directories as folder', 'folder.id', '=', 'placement.directory_id')
                    ->join('dam_assets as live', 'live.id', '=', 'placement.asset_id')
                    ->crossJoin($principals.' as principal')
                    ->whereBetween('folder._lft', $bounds)
                    ->whereNull('live.deleted_at')
                    ->whereIn('principal.id', $ids)
                    ->distinct()
                    ->select(['placement.asset_id', 'principal.id']));
            }

            return ['folders' => $folders->count(), 'files' => count($assetIds)];
        });
    }

    /**
     * One file's own rows, replaced. Its folders are untouched: a file has its own list, and no folder of
     * its own widens it (ADR 0010).
     *
     * Takes stage 4 of the class's lock order and nothing else — it touches no nested-set row, so it never
     * waits on the tree lock and can never be the waiting side of a cycle with a cascade. A file deleted
     * since set() read it has no list left to write, and the counts come back zero.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $groupIds
     * @return array{folders: int, files: int}
     */
    protected function rewriteAsset(int $assetId, array $userIds, array $groupIds): array
    {
        // Before the delete, not after: an unlocked replacement of an empty list is a lost update, because
        // two writers both delete nothing and then insert different principals (finding 1).
        if (Asset::query()->whereKey($assetId)->lockedForGraph()->value('id') === null) {
            return ['folders' => 0, 'files' => 0];
        }

        DB::table('dam_asset_user_access')->where('asset_id', $assetId)->delete();
        DB::table('dam_asset_group_access')->where('asset_id', $assetId)->delete();

        if ($userIds !== []) {
            DB::table('dam_asset_user_access')->insert(array_map(fn (int $adminId) => ['asset_id' => $assetId, 'admin_id' => $adminId], $userIds));
        }

        if ($groupIds !== []) {
            DB::table('dam_asset_group_access')->insert(array_map(fn (int $groupId) => ['asset_id' => $assetId, 'group_id' => $groupId], $groupIds));
        }

        return ['folders' => 0, 'files' => 1];
    }

    /**
     * What the dialog warns about before a folder save: the folders and files the cascade would rewrite
     * beneath this folder, and how many of them someone other than $actor owns. `folders` counts the
     * descendants, not the folder being set, so the note reads as what the save applies to.
     *
     * @return array{folders: int, files: int, owned_by_others: int}
     */
    protected function cascadePreview(object $directory, Admin $actor): array
    {
        $row = DB::table('dam_directories')->where('id', $directory->id)->first(['_lft', '_rgt']);

        if ($row === null) {
            return ['folders' => 0, 'files' => 0, 'owned_by_others' => 0];
        }

        $bounds = [(int) $row->_lft, (int) $row->_rgt];

        $descendants = DB::table('dam_directories')->whereBetween('_lft', $bounds)->where('id', '!=', $directory->id);

        // The files the cascade rewrites: live ones only, as rewriteSubtree() leaves a trashed file's list alone.
        $files = DB::table('dam_assets')->whereNull('deleted_at')->whereIn('id', DB::table('dam_asset_directory')
            ->join('dam_directories as folder', 'folder.id', '=', 'dam_asset_directory.directory_id')
            ->whereBetween('folder._lft', $bounds)
            ->select('dam_asset_directory.asset_id'));

        $notActor = fn ($query) => $query->where(fn ($owner) => $owner->whereNull('created_by')->orWhere('created_by', '!=', $actor->id));

        return [
            'folders'         => (clone $descendants)->count(),
            'files'           => (clone $files)->count(),
            'owned_by_others' => $notActor(clone $descendants)->count() + $notActor(clone $files)->count(),
        ];
    }

    /**
     * The users on the item's list. Names and email addresses, as the picker endpoint already returns them
     * to every signed-in admin (spec, open decision 3).
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    protected function listedUsers(string $type, int $id): array
    {
        $item = $this->itemKind(type: $type);

        return DB::table("dam_{$item}_user_access as listed")
            ->join('admins as principal', 'principal.id', '=', 'listed.admin_id')
            ->where("listed.{$item}_id", $id)
            ->orderBy('principal.name')
            ->orderBy('principal.id')
            ->get(['principal.id', 'principal.name', 'principal.email'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => (string) $row->name, 'email' => (string) $row->email])
            ->all();
    }

    /**
     * The groups on the item's list, each with its member count and never its members.
     *
     * @return list<array{id: int, name: string, member_count: int}>
     */
    protected function listedGroups(string $type, int $id): array
    {
        $item = $this->itemKind(type: $type);

        $groups = DB::table("dam_{$item}_group_access as listed")
            ->join('dam_groups as principal', 'principal.id', '=', 'listed.group_id')
            ->where("listed.{$item}_id", $id)
            ->orderByRaw(...$this->everyoneFirst(column: 'principal.system_key'))
            ->orderBy('principal.name')
            ->orderBy('principal.id')
            ->get(['principal.id', 'principal.name', 'principal.system_key'])
            ->all();

        $counts = $this->memberCounts(groupIds: array_map(fn ($group) => (int) $group->id, $groups));

        return array_map(fn ($group) => [
            'id'           => (int) $group->id,
            'name'         => (string) $group->name,
            'system_key'   => $group->system_key,
            'member_count' => $counts[(int) $group->id] ?? 0,
        ], $groups);
    }

    /**
     * Admin rows that are integration/API users (Admin::isApi(): type = 'api'), which the Groups page hides.
     */
    public function integrationAdminIds(): Builder
    {
        return DB::table('admins')->where('type', 'api')->select('id');
    }

    /**
     * Members per group, in one grouped-count query over $groupIds — never a full read of
     * dam_group_members. Everyone has no member rows, so its count is the number of active admins (spec,
     * Groups). Public so the Groups page (GroupController::list()) shares this rather than re-deriving it
     * (Codex review of the Groups page, finding 3).
     *
     * $excludeIntegrations leaves integration/API users out of every count (display only, the Groups page).
     *
     * @param  list<int>  $groupIds
     * @return array<int, int>
     */
    public function memberCounts(array $groupIds, bool $excludeIntegrations = false): array
    {
        if ($groupIds === []) {
            return [];
        }

        $counts = DB::table('dam_group_members')
            ->whereIn('group_id', $groupIds)
            ->when($excludeIntegrations, fn ($query) => $query->whereNotIn('admin_id', $this->integrationAdminIds()))
            ->groupBy('group_id')
            ->select('group_id', DB::raw('count(*) as members'))
            ->pluck('members', 'group_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        $counts = array_combine(array_map('intval', array_keys($counts)), array_values($counts));

        $everyoneId = $this->reader->everyoneId();

        if ($everyoneId !== null && in_array($everyoneId, $groupIds, true)) {
            $counts[$everyoneId] = DB::table('admins')
                ->where('status', true)
                ->when($excludeIntegrations, fn ($query) => $query->where('type', '!=', 'api'))
                ->count();
        }

        return $counts;
    }

    /**
     * `case when <column> = ? then 0 else 1 end`, which sorts the built-in Everyone group ahead of the rest.
     *
     * The column goes through the connection's grammar rather than into the string. Laravel prefixes table
     * names and aliases when DB_PREFIX is set — `dam_groups as principal` compiles to
     * `wk_dam_groups as wk_principal` — but it passes a raw fragment through untouched, so a hand-written
     * `principal.system_key` is an unknown column on any prefixed deployment. That is how CI's MySQL leg
     * failed on PR #69 while PostgreSQL and SQLite, which run unprefixed there, passed. The key is a binding
     * for the ordinary reason a literal does not belong in SQL.
     *
     * @return array{0: string, 1: list<string>}
     */
    protected function everyoneFirst(string $column): array
    {
        $wrapped = DB::connection()->getQueryGrammar()->wrap($column);

        return ["case when {$wrapped} = ? then 0 else 1 end", [AccessListReader::EVERYONE]];
    }

    /**
     * @param  array<int|string, mixed>  $ids
     * @return list<int>
     */
    protected function distinctIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** `asset` or `directory`, the two item kinds every list table is named after. */
    protected function itemKind(string $type): string
    {
        abort_unless(in_array($type, ['asset', 'directory'], true), 404);

        return $type;
    }
}
