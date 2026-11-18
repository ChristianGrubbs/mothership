<?php

namespace Mothership\DAM\Http\Controllers;

use Illuminate\Http\Request;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\FolderAccessService;

class FolderAccessController extends Controller
{
    public function show(int $id, FolderAccessService $access)
    {
        $this->abortUnderLists();

        return response()->json($access->snapshot($this->visibleDirectory($id)));
    }

    public function update(Request $request, int $id, FolderAccessService $access)
    {
        $this->abortUnderLists();
        $this->visibleDirectory($id);

        $grants = $request->validate([
            'users'                     => 'present|array|max:1000', 'users.*.id' => 'required|integer|distinct|exists:admins,id',
            'users.*.inherit_children'  => 'required|boolean',
            'groups'                    => 'present|array|max:1000', 'groups.*.id' => 'required|integer|distinct|exists:dam_groups,id',
            'groups.*.inherit_children' => 'required|boolean',
        ]);
        $access->save(directoryId: $id, grants: $grants);

        return response()->json(['saved' => true]);
    }

    /**
     * ManageFolderAccess already admits only full admins, who see every folder, so this never refuses
     * today. It keeps the panel answering a hidden folder like a missing one if that ACL is ever widened.
     * No canAccess: managing a folder's grants is not opening it.
     */
    protected function visibleDirectory(int $id): Directory
    {
        return VisibleDirectory::find($id) ?? abort(404);
    }

    /**
     * Under `lists` the folder grants are no longer read, and the Share dialog replaces this panel
     * (ADR 0010, sharing slice 3). Under `grants` — the default — both actions behave exactly as before.
     */
    protected function abortUnderLists(): void
    {
        abort_if(app(AccessModel::class)->lists(), 404);
    }
}
