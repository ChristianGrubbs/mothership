<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Support\FolderAccessLog;

/**
 * Builds the access lists from today's grants (ADR 0010) so that every active admin keeps the access
 * they have. A role's folder grants become a "<role> (from role)" group; user, group and role grants are
 * written onto every folder they reach; each file takes the union of its folders' lists.
 *
 * It never changes a grants answer: the list tables are not read under grants, a generated group that
 * a folder grant uses keeps its members (or the rebuild refuses), and owners are filled in only by
 * `dam:access:switch lists`. One transaction under the directory tree lock, set-based, and idempotent:
 * a second run writes the same rows. Callers hold AccessModel::exclusively() and log the outcome once
 * it has committed. The grants stay authoritative until the switch.
 */
class AccessListRebuilder
{
    public const LIST_TABLES = ['dam_directory_user_access', 'dam_directory_group_access', 'dam_asset_user_access', 'dam_asset_group_access'];

    /**
     * @return array<string, int> rows per list table, the role groups and the Everyone id
     *
     * @throws AccessRebuildRefused when a generated group a folder grant uses would change; nothing is written
     */
    public function run(): array
    {
        return Directory::withTreeLock(function () {
            // Planned before any write, so a refusal leaves everything as it was.
            $plans = $this->planRoleGroups();
            $everyoneId = $this->ensureEveryone();
            $roleGroups = $this->applyRoleGroups(plans: $plans);

            foreach (self::LIST_TABLES as $table) {
                DB::table($table)->delete();
            }

            $this->writeFolderLists(roleGroups: $roleGroups['groups']);
            $this->writeFileLists(roleGroups: $roleGroups['groups']);

            $counts = [];
            foreach (self::LIST_TABLES as $table) {
                $counts[$table] = DB::table($table)->count();
            }

            return $counts + [
                'role_groups'         => count($roleGroups['groups']),
                'role_groups_created' => $roleGroups['created'],
                'role_groups_reset'   => $roleGroups['reset'],
                'everyone_group_id'   => $everyoneId,
            ];
        });
    }

    /**
     * The built-in group, found by its system key; recreated if an operator deleted it before the switch.
     */
    protected function ensureEveryone(): int
    {
        $id = DB::table('dam_groups')->where('system_key', AccessListReader::EVERYONE)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $name = DB::table('dam_groups')->where('name', 'Everyone')->exists() ? $this->freeGroupName(name: 'Everyone (all users)') : 'Everyone';

        return (int) DB::table('dam_groups')->insertGetId([
            'name'       => $name,
            'system_key' => AccessListReader::EVERYONE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * One plan per role that grants folders (a dam_directory_role row, or all_directories): its group,
     * keyed `role:<id>`, whose members become the role's admins as of this run (spec, Migration 2).
     *
     * A generated group is a machine-maintained preview until the switch, except where a folder grant
     * already uses it: there its members decide who opens folders under grants. When those members would
     * change, the rebuild refuses rather than change a grants answer.
     *
     * @return list<array{role: object, group_id: ?int, holders: list<int>, members: ?list<int>}>
     *
     * @throws AccessRebuildRefused
     */
    protected function planRoleGroups(): array
    {
        $roles = DB::table('roles')
            ->leftJoin('dam_role_settings as settings', 'settings.role_id', '=', 'roles.id')
            ->where(fn ($query) => $query
                ->whereExists(fn ($grants) => $grants->from('dam_directory_role')->whereColumn('dam_directory_role.role_id', 'roles.id'))
                ->orWhere('settings.all_directories', true))
            ->orderBy('roles.id')
            ->get(['roles.id', 'roles.name', 'settings.inherit_children', 'settings.all_directories']);

        // Every input of the drift check is a locking read, taken in one order: the generated group rows
        // first (FOR UPDATE, by id), then the folder grants that use them, their members and the role
        // holders. A locking read returns the latest committed rows even under REPEATABLE READ (MySQL and
        // MariaDB), where a plain read answers from the transaction's older snapshot and would miss a grant
        // committed since. While the group rows are locked, a new grant or membership for them waits on its
        // foreign-key check until this rebuild commits. A concurrent legacy writer that locks the same rows
        // in another order can deadlock with it; the database then rolls one side back, a safe failure.
        $generated = DB::table('dam_groups')->where('system_key', 'like', 'role:%')->orderBy('id')->lockForUpdate()
            ->pluck('id', 'system_key')->map(fn ($id) => (int) $id);
        $groupIds = $generated->values()->all();

        // No DISTINCT or GROUP BY here: PostgreSQL refuses either alongside a row lock.
        $granted = DB::table('dam_directory_group')->whereIn('group_id', $groupIds)->orderBy('group_id')->orderBy('directory_id')
            ->sharedLock()->pluck('group_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $membersByGroup = DB::table('dam_group_members')->whereIn('group_id', $groupIds)->orderBy('group_id')->orderBy('admin_id')
            ->sharedLock()->get(['group_id', 'admin_id'])->groupBy('group_id')
            ->map(fn ($rows) => $rows->pluck('admin_id')->map(fn ($id) => (int) $id)->all());
        $holdersByRole = DB::table('admins')->whereIn('role_id', $roles->pluck('id')->all())->orderBy('id')
            ->sharedLock()->get(['id', 'role_id'])->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('id')->map(fn ($id) => (int) $id)->all());

        $plans = [];
        $refused = [];

        foreach ($roles as $role) {
            $groupId = $generated['role:'.$role->id] ?? null;
            $holders = $holdersByRole[$role->id] ?? [];
            $members = $groupId === null ? null : ($membersByGroup[$groupId] ?? []);

            if ($members !== null && $members !== $holders && in_array($groupId, $granted, true)) {
                $refused[] = [
                    'group_id'          => $groupId,
                    'members_to_add'    => count(array_diff($holders, $members)),
                    'members_to_remove' => count(array_diff($members, $holders)),
                ];
            }

            $plans[] = ['role' => $role, 'group_id' => $groupId, 'holders' => $holders, 'members' => $members];
        }

        if ($refused !== []) {
            foreach ($refused as $group) {
                FolderAccessLog::write(event: 'access_rebuild_refused', ctx: $group);
            }

            throw new AccessRebuildRefused(groups: $refused);
        }

        return $plans;
    }

    /**
     * Creates the missing role groups and resets the members of those that changed; planRoleGroups()
     * has already refused if any of those is used by a folder grant. The Everyone group has no member
     * rows and is never touched here. An existing role group keeps its name.
     *
     * @return array{groups: list<array{role_id: int, group_id: int, inherit_children: bool, all_directories: bool}>, created: int, reset: int}
     */
    protected function applyRoleGroups(array $plans): array
    {
        $groups = [];
        $created = 0;
        $reset = 0;

        foreach ($plans as $plan) {
            $groupId = $plan['group_id'];

            if ($groupId === null) {
                $groupId = (int) DB::table('dam_groups')->insertGetId([
                    'name'       => $this->freeGroupName(name: $plan['role']->name.' (from role)'),
                    'system_key' => 'role:'.$plan['role']->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            } elseif ($plan['members'] !== $plan['holders']) {
                DB::table('dam_group_members')->where('group_id', $groupId)->delete();
                $reset++;
            }

            if ($plan['members'] !== $plan['holders']) {
                DB::table('dam_group_members')->insertUsing(['group_id', 'admin_id'], DB::table('admins')
                    ->selectRaw($groupId.' as group_id')
                    ->addSelect('id')
                    ->where('role_id', $plan['role']->id));
            }

            $groups[] = [
                'role_id'          => (int) $plan['role']->id,
                'group_id'         => $groupId,
                'inherit_children' => (bool) $plan['role']->inherit_children,
                'all_directories'  => (bool) $plan['role']->all_directories,
            ];
        }

        return ['groups' => $groups, 'created' => $created, 'reset' => $reset];
    }

    /**
     * A grant reaches the folder it names and, with inherit_children, every folder beneath it. A role's
     * inherit_children is role-wide; all_directories reaches every folder.
     */
    protected function writeFolderLists(array $roleGroups): void
    {
        DB::table('dam_directory_user_access')->insertUsing(['directory_id', 'admin_id'], $this->grantReach(grants: 'dam_directory_user', principal: 'admin_id'));
        DB::table('dam_directory_group_access')->insertUsing(['directory_id', 'group_id'], $this->grantReach(grants: 'dam_directory_group', principal: 'group_id'));

        foreach ($roleGroups as $roleGroup) {
            $folders = $roleGroup['all_directories']
                ? DB::table('dam_directories as folder')
                : $this->folderReach(DB::table('dam_directory_role as grants')->where('grants.role_id', $roleGroup['role_id']))
                    ->when(! $roleGroup['inherit_children'], fn ($query) => $query->whereColumn('folder.id', 'granted.id'));

            // An operator may already have granted this group a folder directly; that row is kept once.
            DB::table('dam_directory_group_access')->insertUsing(['directory_id', 'group_id'], $folders
                ->whereNotExists(fn ($listed) => $listed->from('dam_directory_group_access as listed')
                    ->whereColumn('listed.directory_id', 'folder.id')
                    ->where('listed.group_id', $roleGroup['group_id']))
                ->distinct()
                ->select('folder.id')
                ->selectRaw($roleGroup['group_id'].' as group_id'));
        }
    }

    /**
     * A file takes the union of its folders' lists: today's any-directory rule, made concrete.
     */
    protected function writeFileLists(array $roleGroups): void
    {
        DB::table('dam_asset_user_access')->insertUsing(['asset_id', 'admin_id'], DB::table('dam_asset_directory as placement')
            ->join('dam_directory_user_access as listed', 'listed.directory_id', '=', 'placement.directory_id')
            ->distinct()
            ->select(['placement.asset_id', 'listed.admin_id']));

        DB::table('dam_asset_group_access')->insertUsing(['asset_id', 'group_id'], DB::table('dam_asset_directory as placement')
            ->join('dam_directory_group_access as listed', 'listed.directory_id', '=', 'placement.directory_id')
            ->distinct()
            ->select(['placement.asset_id', 'listed.group_id']));

        // all_directories also reached files in no folder, so its group is listed on every file.
        foreach ($roleGroups as $roleGroup) {
            if (! $roleGroup['all_directories']) {
                continue;
            }

            DB::table('dam_asset_group_access')->insertUsing(['asset_id', 'group_id'], DB::table('dam_assets as asset')
                ->whereNotExists(fn ($listed) => $listed->from('dam_asset_group_access as listed')
                    ->whereColumn('listed.asset_id', 'asset.id')
                    ->where('listed.group_id', $roleGroup['group_id']))
                ->select('asset.id')
                ->selectRaw($roleGroup['group_id'].' as group_id'));
        }
    }

    /**
     * Items with no owner go to the lowest-id active full admin (open decision 4). Every such owner
     * already sees everything, so this widens no access. With no full admin they stay null. Called by
     * `dam:access:switch lists` after a passing comparison, never by a preparatory rebuild.
     *
     * @return array{assets: int, directories: int}
     */
    public function fillMissingOwners(): array
    {
        $ownerId = DB::table('admins')
            ->join('roles', 'roles.id', '=', 'admins.role_id')
            ->where('admins.status', true)
            ->where('roles.permission_type', 'all')
            ->min('admins.id');

        if ($ownerId === null) {
            return ['assets' => 0, 'directories' => 0];
        }

        return [
            'assets'      => DB::table('dam_assets')->whereNull('created_by')->update(['created_by' => (int) $ownerId]),
            'directories' => DB::table('dam_directories')->whereNull('created_by')->update(['created_by' => (int) $ownerId]),
        ];
    }

    /**
     * DISTINCT (folder, principal) pairs a user or group grant table reaches; inherit_children is per row.
     */
    protected function grantReach(string $grants, string $principal): Builder
    {
        return $this->folderReach(DB::table($grants.' as grants'))
            ->where(fn ($query) => $query->whereColumn('folder.id', 'granted.id')->orWhere('grants.inherit_children', true))
            ->distinct()
            ->select(['folder.id', 'grants.'.$principal]);
    }

    /**
     * Joins a grant query aliased `grants` to its folder (`granted`) and to that folder's subtree,
     * the folder included (`folder`).
     */
    protected function folderReach(Builder $grants): Builder
    {
        return $grants
            ->join('dam_directories as granted', 'granted.id', '=', 'grants.directory_id')
            ->join('dam_directories as folder', function ($join) {
                $join->whereColumn('folder._lft', '>=', 'granted._lft')
                    ->whereColumn('folder._rgt', '<=', 'granted._rgt');
            });
    }

    /**
     * $name, or "$name 2", "$name 3" and so on when a group already has it, within the 100-character column.
     */
    protected function freeGroupName(string $name): string
    {
        $name = mb_substr($name, 0, 100);
        $candidate = $name;

        for ($suffix = 2; DB::table('dam_groups')->where('name', $candidate)->exists(); $suffix++) {
            $candidate = mb_substr($name, 0, 100 - mb_strlen(' '.$suffix)).' '.$suffix;
        }

        return $candidate;
    }
}
