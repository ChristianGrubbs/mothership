<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Support\FolderAccessLog;

class GroupController extends Controller
{
    public function __construct(
        protected AccessModel $accessModel,
        protected AccessListService $accessListService,
    ) {}

    public function index()
    {
        return view('dam::groups.index');
    }

    /**
     * Every group, with the fields the rebuilt page needs (ADR 0010, sharing slice 4): member_count and
     * is_system in both models, read_only per "Which groups are read-only" (Everyone in both, a generated
     * role group under grants only), and access_roots only while the access model is lists.
     */
    public function list()
    {
        $lists = $this->accessModel->lists();

        $groups = DB::table('dam_groups')->orderBy('name')->get();
        $groupIds = $groups->pluck('id')->map(fn ($id) => (int) $id)->all();

        // The canonical grouped-count query (AccessListService), never a separate derivation or a full
        // read of dam_group_members (Codex review, finding 3).
        $counts = $this->accessListService->memberCounts(groupIds: $groupIds, excludeIntegrations: true);
        // Integration/API users are hidden on this page (display only, membership rows are untouched).
        $memberIds = DB::table('dam_group_members')
            ->whereIn('group_id', $groupIds)
            ->whereNotIn('admin_id', $this->accessListService->integrationAdminIds())
            ->get(['group_id', 'admin_id'])
            ->groupBy('group_id');

        $groups = $groups->map(function ($group) use ($counts, $memberIds, $lists) {
            $isEveryone = $group->system_key === 'everyone';
            $isGeneratedRole = str_starts_with((string) $group->system_key, 'role:');

            $group->member_count = $counts[(int) $group->id] ?? 0;
            $group->is_system = $isEveryone;
            $group->read_only = $isEveryone || ($isGeneratedRole && ! $lists);
            $group->member_ids = ($memberIds[$group->id] ?? collect())->pluck('admin_id')->map(fn ($id) => (int) $id)->all();

            return $group;
        });

        if ($lists) {
            $roots = $this->accessRoots(groupIds: $groups->pluck('id')->map(fn ($id) => (int) $id)->all());
            $groups = $groups->map(function ($group) use ($roots) {
                $group->access_roots = $roots[(int) $group->id] ?? [];

                return $group;
            });
        }

        return response()->json(['groups' => $groups->values(), 'users' => DB::table('admins')
            ->leftJoin('roles', 'roles.id', '=', 'admins.role_id')->where('admins.type', '!=', 'api')->orderBy('admins.name')
            ->get(['admins.id', 'admins.name', 'admins.email', 'admins.status', 'roles.name as role_name'])]);
    }

    /**
     * The read-only decision runs inside sharedly(), on the model value locked there, so a switch
     * committing between an earlier unlocked read and this write can never be missed (Codex review,
     * finding 1): the state row, then the group row, in that order.
     */
    public function save(Request $request, ?int $id = null)
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100', Rule::unique('dam_groups', 'name')->ignore($id)],
            'member_ids' => 'present|array|max:1000', 'member_ids.*' => 'required|integer|distinct|exists:admins,id',
        ]);

        [$groupId, $memberIds] = $this->accessModel->sharedly(function (?string $raw) use ($id, $data) {
            if ($id !== null) {
                $group = DB::table('dam_groups')->where('id', $id)->lockForUpdate()->first(['system_key']);
                abort_if($group === null, 404);
                $this->abortIfReadOnly(group: $group, raw: $raw);

                DB::table('dam_groups')->where('id', $id)->update(['name' => $data['name'], 'updated_at' => now()]);
                $groupId = $id;
            } else {
                $groupId = DB::table('dam_groups')->insertGetId(['name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
            }

            // Integration/API users are not shown on the page, so a save never drops their membership.
            DB::table('dam_group_members')->where('group_id', $groupId)
                ->whereNotIn('admin_id', $this->accessListService->integrationAdminIds())->delete();
            $memberIds = DB::table('admins')->whereIn('id', $data['member_ids'])->where('type', '!=', 'api')->pluck('id')->all();
            if ($memberIds) {
                DB::table('dam_group_members')->insert(array_map(fn ($memberId) => ['group_id' => $groupId, 'admin_id' => (int) $memberId], $memberIds));
            }

            return [(int) $groupId, array_map('intval', $memberIds)];
        });

        app(DirectoryPermissionService::class)->flush();
        FolderAccessLog::write(event: 'group_saved', ctx: ['actor_id' => (int) auth('admin')->id(), 'group_id' => $groupId, 'members' => count($memberIds)]);

        return response()->json(['data' => ['id' => $groupId, 'name' => $data['name'], 'member_ids' => $memberIds]], $id === null ? 201 : 200);
    }

    /**
     * Deleting a group is a list write: its foreign keys cascade into dam_directory_group_access and
     * dam_asset_group_access. The state row is held FOR SHARE first, so it never commits across a switch's
     * activation (ADR 0010), model-independent because both models maintain the lists. The read-only
     * decision is made from that same locked value (Codex review, finding 1) — the state row, then the
     * group row.
     */
    public function destroy(int $id)
    {
        $this->accessModel->sharedly(function (?string $raw) use ($id) {
            $group = DB::table('dam_groups')->where('id', $id)->lockForUpdate()->first(['system_key']);
            abort_if($group === null, 404);
            $this->abortIfReadOnly(group: $group, raw: $raw);

            DB::table('dam_groups')->where('id', $id)->delete();
        });

        app(DirectoryPermissionService::class)->flush();
        FolderAccessLog::write(event: 'group_deleted', ctx: ['actor_id' => (int) auth('admin')->id(), 'group_id' => $id]);

        return response()->json(['deleted' => true]);
    }

    /**
     * Everyone is read-only in both models; a generated role group (system_key = role:<id>) is read-only
     * only under grants, where a legacy folder grant may still use it (ADR 0010, "Which groups are
     * read-only"). $raw is the model value AccessModel::sharedly() locked, never a separate unlocked read,
     * so a switch that commits after this decision starts cannot land a write the new model would refuse.
     */
    protected function abortIfReadOnly(object $group, ?string $raw): void
    {
        $lists = AccessModel::modelFor($raw) === AccessModel::LISTS;

        abort_if($group->system_key === 'everyone', 422, trans('dam::app.admin.dam.groups.everyone-read-only'));
        abort_if(
            str_starts_with((string) $group->system_key, 'role:') && ! $lists,
            422,
            trans('dam::app.admin.dam.groups.role-group-read-only')
        );
    }

    /**
     * The top folder of each contiguous subtree whose group list names the group: listed while its parent
     * folder's list does not (or it has no parent), across every group at once. A self-join rather than a
     * query per group, so the page costs the same regardless of how many groups exist.
     *
     * @param  list<int>  $groupIds
     * @return array<int, list<array{id: int, name: string, path: string}>>
     */
    protected function accessRoots(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows = DB::table('dam_directory_group_access as listed')
            ->join('dam_directories as folder', 'folder.id', '=', 'listed.directory_id')
            ->leftJoin('dam_directory_group_access as parent_listed', function ($join) {
                $join->on('parent_listed.directory_id', '=', 'folder.parent_id')
                    ->whereColumn('parent_listed.group_id', 'listed.group_id');
            })
            ->whereIn('listed.group_id', $groupIds)
            ->whereNull('parent_listed.directory_id')
            ->orderBy('folder.name')
            ->get(['listed.group_id', 'folder.id', 'folder.name']);

        if ($rows->isEmpty()) {
            return [];
        }

        $paths = $this->pathsFor(folderIds: $rows->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $byGroup = [];

        foreach ($rows as $row) {
            $folderId = (int) $row->id;
            $byGroup[(int) $row->group_id][] = [
                'id'   => $folderId,
                'name' => (string) $row->name,
                'path' => $paths[$folderId] ?? (string) $row->name,
            ];
        }

        return $byGroup;
    }

    /**
     * Every id's full path from the tree root, "/"-joined, in one query regardless of how many ids are
     * asked for: each ancestor-or-self of each id, over the nested-set bounds, ordered so the names
     * concatenate root-first (Codex review, finding 2 — Directory::generatePath() queries once per folder,
     * which grows with the number of disjoint roots).
     *
     * @param  list<int>  $folderIds
     * @return array<int, string>
     */
    protected function pathsFor(array $folderIds): array
    {
        if ($folderIds === []) {
            return [];
        }

        $rows = DB::table('dam_directories as target')
            ->join('dam_directories as ancestor', function ($join) {
                $join->on('ancestor._lft', '<=', 'target._lft')
                    ->on('ancestor._rgt', '>=', 'target._rgt');
            })
            ->whereIn('target.id', $folderIds)
            ->orderBy('target.id')
            ->orderBy('ancestor._lft')
            ->get(['target.id as target_id', 'ancestor.name']);

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row->target_id][] = (string) $row->name;
        }

        return array_map(fn ($segments) => implode('/', $segments), $names);
    }
}
