<?php

declare(strict_types=1);

namespace Mothership\DAM\Traits;

use Mothership\User\Models\Admin;

/**
 * Whether the admin acting on this request holds a role permission, on the web and on the REST API alike. bouncer()
 * reads only the session guard, so an action shared by both surfaces asks here: an API key never carries more than
 * the role of the admin it belongs to.
 */
trait ActingAdminPermission
{
    protected function actingAdminHolds(string $permission): bool
    {
        if (auth()->guard('admin')->check()) {
            return bouncer()->hasPermission($permission);
        }

        $admin = auth()->guard('api')->user();

        return $admin instanceof Admin
            && ($admin->role?->permission_type === 'all' || (bool) $admin->hasPermission($permission));
    }
}
