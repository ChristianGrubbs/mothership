<?php

namespace Mothership\DAM\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Removes a deleted admin's rows from the user access lists before the admin row goes (ADR 0010), and their boards
 * and saved searches (P4): the same second-cascade-path rule makes those owner keys NO ACTION there too.
 *
 * Registered on SQL Server only: there admin_id on those lists is NO ACTION (a second cascade path from
 * admins, error 1785), and every other driver cascades the rows with the delete itself. It listens to
 * the Admin model's `deleting` event, which every delete path fires (the user importer never fires
 * `user.admin.delete.before`), and AdminRepository::delete() runs that event and the row delete in one
 * transaction, so a vetoed or failed delete keeps the rows.
 */
class DeleteAdminAccessRows
{
    public function handle(Model $admin): void
    {
        $adminId = (int) $admin->getKey();

        DB::table('dam_directory_user_access')->where('admin_id', $adminId)->delete();
        DB::table('dam_asset_user_access')->where('admin_id', $adminId)->delete();
        // A board's members and a saved search's rows cascade from these on every driver.
        DB::table('dam_labels')->where('owner_id', $adminId)->delete();
        DB::table('dam_saved_searches')->where('owner_id', $adminId)->delete();

        // P4 D2: dam_collections.owner_id is NO ACTION here for the same reason; elsewhere it is SET NULL, and a
        // collection without an owner is managed by full admins.
        DB::table('dam_collections')->where('owner_id', $adminId)->update(['owner_id' => null]);
    }
}
