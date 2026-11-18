<?php

namespace Mothership\DAM\Services;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\EffectiveAccessService;
use Mothership\DAM\Support\FolderAccessLog;

class FolderAccessService
{
    /**
     * Personal/group access must not create a grant for unrelated role peers.
     * Return false only when the legacy role-based creator grant should apply.
     */
    public function grantToCreator(int $adminId, int $directoryId): bool
    {
        $ids = $this->grantedIds(adminId: $adminId);
        $directory = Directory::findOrFail($directoryId);
        if (in_array($directoryId, $ids, true)) {
            app(DirectoryPermissionService::class)->flush();

            return true;
        }
        if (! in_array((int) $directory->parent_id, $ids, true)) {
            return false;
        }
        DB::table('dam_directory_user')->updateOrInsert(
            ['directory_id' => $directoryId, 'admin_id' => $adminId],
            ['inherit_children' => false]
        );
        app(DirectoryPermissionService::class)->flush();
        FolderAccessLog::write(event: 'creator_granted', ctx: ['admin_id' => $adminId, 'directory_id' => $directoryId]);

        return true;
    }

    public function grantedIds(int $adminId): array
    {
        $grants = DB::table('dam_directory_user')->where('admin_id', $adminId)
            ->get(['directory_id', 'inherit_children'])
            ->concat(DB::table('dam_directory_group as grants')
                ->join('dam_group_members as members', 'members.group_id', '=', 'grants.group_id')
                ->where('members.admin_id', $adminId)->get(['grants.directory_id', 'grants.inherit_children']));
        $ids = $grants->pluck('directory_id')->map(fn ($id) => (int) $id)->all();
        $parents = $grants->where('inherit_children', true)->pluck('directory_id')->all();
        if ($parents) {
            $ids = array_merge($ids, DB::table('dam_directories as parent')
                ->join('dam_directories as child', fn ($join) => $join
                    ->whereColumn('child._lft', '>=', 'parent._lft')->whereColumn('child._rgt', '<=', 'parent._rgt'))
                ->whereIn('parent.id', $parents)->distinct()->pluck('child.id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($ids));
    }

    public function snapshot(Directory $directory): array
    {
        $data = ['directory' => ['id' => $directory->id, 'name' => $directory->name], 'users' => [], 'groups' => [], 'sources' => []];
        foreach (['user' => ['admins', 'admin_id'], 'group' => ['dam_groups', 'group_id']] as $type => [$table, $key]) {
            $rows = DB::table('dam_directory_'.$type.' as grants')
                ->join($table.' as principal', 'principal.id', '=', 'grants.'.$key)
                ->join('dam_directories as folder', 'folder.id', '=', 'grants.directory_id')
                ->where(function ($query) use ($directory) {
                    $query->where('folder.id', $directory->id)->orWhere(fn ($q) => $q
                        ->where('folder._lft', '<', $directory->_lft)->where('folder._rgt', '>', $directory->_rgt)
                        ->where('grants.inherit_children', true));
                })->orderBy('principal.name')
                ->get(['principal.id', 'principal.name', 'grants.inherit_children', 'folder.id as source_directory_id', 'folder.name as source_name']);
            foreach ($rows as $row) {
                $entry = ['type'       => $type, 'id' => (int) $row->id, 'name' => $row->name,
                    'inherit_children' => (bool) $row->inherit_children, 'source_directory_id' => (int) $row->source_directory_id,
                    'source_name'      => $row->source_name, 'inherited' => (int) $row->source_directory_id !== $directory->id];
                $data[$entry['inherited'] ? 'sources' : ($type === 'user' ? 'users' : 'groups')][] = $entry;
            }
        }

        $roleGrants = DB::table('dam_directory_role as grants')
            ->join('roles', 'roles.id', '=', 'grants.role_id')
            ->leftJoin('dam_role_settings as settings', 'settings.role_id', '=', 'roles.id')
            ->join('dam_directories as folder', 'folder.id', '=', 'grants.directory_id')
            ->where(fn ($q) => $q->where('folder.id', $directory->id)->orWhere(fn ($q) => $q
                ->where('folder._lft', '<', $directory->_lft)->where('folder._rgt', '>', $directory->_rgt)
                ->where('settings.inherit_children', true)))
            ->get(['roles.id', 'roles.name', 'folder.id as source_directory_id', 'folder.name as source_name']);
        foreach ($roleGrants as $row) {
            $data['sources'][] = ['type' => 'role', 'id' => (int) $row->id, 'name' => $row->name,
                'source_directory_id'    => (int) $row->source_directory_id, 'source_name' => $row->source_name,
                'inherited'              => (int) $row->source_directory_id !== $directory->id];
        }
        $globalRoles = DB::table('roles')->leftJoin('dam_role_settings as settings', 'settings.role_id', '=', 'roles.id')
            ->where('roles.permission_type', 'all')->orWhere('settings.all_directories', true)->get(['roles.id', 'roles.name']);
        foreach ($globalRoles as $row) {
            $data['sources'] = array_values(array_filter($data['sources'], fn ($source) => $source['type'] !== 'role' || $source['id'] !== (int) $row->id));
            $data['sources'][] = ['type' => 'role', 'id' => (int) $row->id, 'name' => $row->name,
                'source_directory_id'    => null, 'source_name' => 'All folders', 'inherited' => true];
        }

        $data['available_users'] = DB::table('admins')->leftJoin('roles', 'roles.id', '=', 'admins.role_id')
            ->orderBy('admins.name')->get(['admins.id', 'admins.name', 'admins.email', 'roles.name as role_name']);
        // Everyone left out (ADR 0010, sharing slice 4): a legacy grant to it reaches nobody under grants.
        $data['available_groups'] = DB::table('dam_groups')->where('system_key', '!=', 'everyone')
            ->orWhereNull('system_key')->orderBy('name')->get(['id', 'name']);
        // Everyone who can open the folder now and why, from the real check (EffectiveAccessService).
        $data['people'] = app(EffectiveAccessService::class)->people(type: 'directory', id: (int) $directory->id);

        return $data;
    }

    public function save(int $directoryId, array $grants): void
    {
        DB::transaction(function () use ($directoryId, $grants) {
            Directory::query()->lockForUpdate()->findOrFail($directoryId);
            foreach (['users' => ['dam_directory_user', 'admin_id'], 'groups' => ['dam_directory_group', 'group_id']] as $kind => [$table, $key]) {
                DB::table($table)->where('directory_id', $directoryId)->delete();
                if ($grants[$kind]) {
                    DB::table($table)->insert(array_map(fn ($grant) => ['directory_id' => $directoryId, $key => (int) $grant['id'], 'inherit_children' => (bool) $grant['inherit_children']], $grants[$kind]));
                }
            }
        });
        app(DirectoryPermissionService::class)->flush();
        FolderAccessLog::write(event: 'grants_saved', ctx: ['actor_id' => (int) auth('admin')->id(), 'directory_id' => $directoryId, 'users' => count($grants['users']), 'groups' => count($grants['groups'])]);
    }
}
