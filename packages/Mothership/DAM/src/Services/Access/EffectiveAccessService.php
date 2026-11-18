<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/**
 * "People with access" for the folder-access panel (`grants`) and the Share dialog (`lists`): every active
 * admin who can open a folder or file right now, each with the reasons they can.
 *
 * Who is listed comes from DirectoryPermissionService itself, evaluated per admin through forAdmin() — the
 * same rule every DAM surface asks, under whichever access model is current — so the list and the real check
 * cannot drift. The reasons are only labels: they read the grant or list tables to explain an answer the
 * service already gave, and an admin the service lets in with no recognised reason still appears, as
 * "Has access". A disabled admin is never listed; Bouncer turns them away under either model.
 */
class EffectiveAccessService
{
    public function __construct(protected AccessModel $model) {}

    /**
     * @return list<array{id: int, name: string, email: string, reasons: list<string>}>
     */
    public function people(string $type, int $id): array
    {
        $item = $type === 'asset' ? 'asset' : 'directory';
        $admins = Admin::query()->with('role')->where('status', true)->whereNotIn('id', app(AccessListService::class)->integrationAdminIds())->orderBy('name')->orderBy('id')->get();

        $people = [];

        // One permission evaluation per active admin: the deliberate price of never drifting from the real
        // check. Fine at this install's admin count; a batched, item-scoped check in the readers is the fix
        // if that grows. TODO 2026-09-26: revisit if active admins exceed a few hundred (Codex review PR #77).
        foreach ($admins as $admin) {
            // A fresh instance per admin: forAdmin() is sticky, and the request's own service must keep
            // answering for the signed-in admin (the same reason AccessListService::item() does this).
            $permissions = (new DirectoryPermissionService)->forAdmin($admin);
            $allowed = $item === 'directory' ? $permissions->canAccess($id) : $permissions->canAccessAsset($id);

            if ($allowed) {
                $people[] = ['id' => (int) $admin->id, 'name' => (string) $admin->name, 'email' => (string) $admin->email, 'admin' => $admin];
            }
        }

        if ($people === []) {
            return [];
        }

        $reasons = $this->model->lists()
            ? $this->listReasons(item: $item, id: $id, people: $people)
            : $this->grantReasons(directoryIds: $this->directoryIds(item: $item, id: $id), people: $people);

        return array_map(function ($person) use ($reasons) {
            $labels = array_values(array_unique($reasons[$person['id']] ?? []));

            return [
                'id'      => $person['id'],
                'name'    => $person['name'],
                'email'   => $person['email'],
                'reasons' => $labels === [] ? [trans('dam::app.admin.dam.access.reason-other')] : $labels,
            ];
        }, $people);
    }

    /**
     * Under `lists`: owner, full admin, a user-list entry, or a group-list entry (Everyone included).
     *
     * @return array<int, list<string>>
     */
    protected function listReasons(string $item, int $id, array $people): array
    {
        $table = $item === 'asset' ? 'dam_assets' : 'dam_directories';
        $owner = DB::table($table)->where('id', $id)->value('created_by');
        $userListed = DB::table("dam_{$item}_user_access")->where("{$item}_id", $id)->pluck('admin_id')->map(fn ($v) => (int) $v)->all();
        $groups = DB::table("dam_{$item}_group_access as listed")
            ->join('dam_groups as listed_group', 'listed_group.id', '=', 'listed.group_id')
            ->where("listed.{$item}_id", $id)
            ->orderBy('listed_group.name')
            ->get(['listed_group.id', 'listed_group.name', 'listed_group.system_key']);
        $memberships = $this->memberships(groupIds: $groups->pluck('id')->map(fn ($v) => (int) $v)->all());

        $reasons = [];

        foreach ($people as $person) {
            $pid = $person['id'];
            $reasons[$pid] = $this->roleReasons(admin: $person['admin'], allDirectoriesBypasses: false);

            if ($owner !== null && (int) $owner === $pid) {
                $reasons[$pid][] = trans('dam::app.admin.dam.access.reason-owner');
            }

            if (in_array($pid, $userListed, true)) {
                $reasons[$pid][] = trans('dam::app.admin.dam.access.reason-direct');
            }

            foreach ($groups as $group) {
                if ($group->system_key === AccessListReader::EVERYONE) {
                    $reasons[$pid][] = trans('dam::app.admin.dam.access.reason-everyone');
                } elseif (in_array($pid, $memberships[(int) $group->id] ?? [], true)) {
                    $reasons[$pid][] = trans('dam::app.admin.dam.access.reason-group', ['group' => $group->name]);
                }
            }
        }

        return $reasons;
    }

    /**
     * Under `grants`: a full-access or all-folders role, a role grant, or a user or group grant — on the
     * folder itself or inherited from an ancestor whose grant includes subfolders. A file answers through
     * the folders it sits in.
     *
     * @return array<int, list<string>>
     */
    protected function grantReasons(array $directoryIds, array $people): array
    {
        $folders = DB::table('dam_directories')->whereIn('id', $directoryIds)->get(['id', '_lft', '_rgt']);
        $folderIds = $folders->pluck('id')->map(fn ($v) => (int) $v)->all();

        $userRows = $this->grantRows(folders: $folders, table: 'dam_directory_user', key: 'admin_id', inheritColumn: 'grants.inherit_children');
        $groupRows = $this->grantRows(folders: $folders, table: 'dam_directory_group', key: 'group_id', inheritColumn: 'grants.inherit_children');
        $roleRows = $this->grantRows(folders: $folders, table: 'dam_directory_role', key: 'role_id', inheritColumn: 'settings.inherit_children', roleSettings: true);

        $groupIds = $groupRows->pluck('principal_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groupNames = DB::table('dam_groups')->whereIn('id', $groupIds)->pluck('name', 'id');
        $memberships = $this->memberships(groupIds: $groupIds);
        $inherited = fn ($row) => ! in_array((int) $row->folder_id, $folderIds, true);

        $reasons = [];

        foreach ($people as $person) {
            $pid = $person['id'];
            $admin = $person['admin'];
            $reasons[$pid] = $this->roleReasons(admin: $admin, allDirectoriesBypasses: true);

            foreach ($userRows as $row) {
                if ((int) $row->principal_id === $pid) {
                    $reasons[$pid][] = $inherited($row)
                        ? trans('dam::app.admin.dam.access.reason-inherited', ['folder' => $row->folder_name])
                        : trans('dam::app.admin.dam.access.reason-direct');
                }
            }

            foreach ($groupRows as $row) {
                if (in_array($pid, $memberships[(int) $row->principal_id] ?? [], true)) {
                    $group = (string) ($groupNames[$row->principal_id] ?? '');
                    $reasons[$pid][] = $inherited($row)
                        ? trans('dam::app.admin.dam.access.reason-group-inherited', ['group' => $group, 'folder' => $row->folder_name])
                        : trans('dam::app.admin.dam.access.reason-group', ['group' => $group]);
                }
            }

            foreach ($roleRows as $row) {
                if ($admin->role_id !== null && (int) $row->principal_id === (int) $admin->role_id) {
                    $role = (string) $admin->role?->name;
                    $reasons[$pid][] = $inherited($row)
                        ? trans('dam::app.admin.dam.access.reason-role-inherited', ['role' => $role, 'folder' => $row->folder_name])
                        : trans('dam::app.admin.dam.access.reason-role', ['role' => $role]);
                }
            }
        }

        return $reasons;
    }

    /**
     * Every grant in $table on one of $folders, or on an ancestor of one whose grant includes subfolders.
     */
    protected function grantRows($folders, string $table, string $key, string $inheritColumn, bool $roleSettings = false)
    {
        if ($folders->isEmpty()) {
            return collect();
        }

        return DB::table($table.' as grants')
            ->join('dam_directories as folder', 'folder.id', '=', 'grants.directory_id')
            ->when($roleSettings, fn ($query) => $query->leftJoin('dam_role_settings as settings', 'settings.role_id', '=', 'grants.role_id'))
            ->where(function ($query) use ($folders, $inheritColumn) {
                foreach ($folders as $folder) {
                    $query->orWhere('folder.id', $folder->id)->orWhere(fn ($ancestor) => $ancestor
                        ->where('folder._lft', '<', $folder->_lft)->where('folder._rgt', '>', $folder->_rgt)
                        ->where($inheritColumn, true));
                }
            })
            ->orderBy('folder._lft')
            ->get(["grants.{$key} as principal_id", 'folder.id as folder_id', 'folder.name as folder_name']);
    }

    /**
     * The role-wide bypasses: no role, a full-access role, and (under `grants` only) an all-folders role.
     *
     * @return list<string>
     */
    protected function roleReasons(Admin $admin, bool $allDirectoriesBypasses): array
    {
        $role = $admin->role;

        if (! $role) {
            return [trans('dam::app.admin.dam.access.reason-no-role')];
        }

        if ($role->permission_type === 'all') {
            return [trans('dam::app.admin.dam.access.reason-full-admin', ['role' => $role->name])];
        }

        if ($allDirectoriesBypasses && DB::table('dam_role_settings')->where('role_id', $role->id)->where('all_directories', true)->exists()) {
            return [trans('dam::app.admin.dam.access.reason-all-folders', ['role' => $role->name])];
        }

        return [];
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, list<int>> group id => member admin ids
     */
    protected function memberships(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $members = [];

        foreach (DB::table('dam_group_members')->whereIn('group_id', $groupIds)->get(['group_id', 'admin_id']) as $row) {
            $members[(int) $row->group_id][] = (int) $row->admin_id;
        }

        return $members;
    }

    /**
     * @return list<int>
     */
    protected function directoryIds(string $item, int $id): array
    {
        if ($item === 'directory') {
            return [$id];
        }

        return DB::table('dam_asset_directory')->where('asset_id', $id)->pluck('directory_id')->map(fn ($v) => (int) $v)->all();
    }
}
