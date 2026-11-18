<?php

namespace Mothership\User\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\Core\Eloquent\Repository;
use Mothership\User\Contracts\Admin;
use Mothership\User\Models\Admin as AdminModel;
use Mothership\User\Models\Role as RoleModel;
use RuntimeException;

class AdminRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Admin::class;
    }

    /**
     * Count admins with all access.
     */
    public function countAdminsWithAllAccess(): int
    {
        return $this->getModel()
            ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
            ->where('roles.permission_type', 'all')
            ->get()
            ->count();
    }

    /**
     * Count admins with all access and active status.
     */
    public function countAdminsWithAllAccessAndActiveStatus(): int
    {
        return $this->getModel()
            ->leftJoin('roles', 'admins.role_id', '=', 'roles.id')
            ->where('admins.status', 1)
            ->where('roles.permission_type', 'all')
            ->get()
            ->count();
    }

    /**
     * The one rule every path that can take an admin out of full access goes through: at least one active,
     * human admin on a full-access role (`permission_type` all) must remain. A caller names what its write
     * takes away (admins it deactivates, demotes or deletes; roles it turns custom or deletes) and asks before
     * writing. The older checks counted inactive admins, or only fired for a role with exactly one member, so an
     * admin could leave none who can sign in and manage the site (UX eval 2026-10-10, PERM-07).
     *
     * The read is a locking read (activeFullAdminExists()), so inside a REPEATABLE READ transaction whose
     * snapshot is older than another session's committed change (MySQL/MariaDB InnoDB; an import's outer
     * transaction) it still answers from the latest committed rows.
     *
     * @param  array<int, int|string>  $withoutAdminIds
     * @param  array<int, int|string>  $withoutRoleIds
     *
     * @throws ValidationException naming $field when nobody would remain
     */
    public function ensureActiveFullAdminRemains(string $field, array $withoutAdminIds = [], array $withoutRoleIds = []): void
    {
        if (! $this->activeFullAdminExists(withoutAdminIds: $withoutAdminIds, withoutRoleIds: $withoutRoleIds)) {
            throw ValidationException::withMessages([
                $field => trans('admin::app.settings.users.last-active-full-admin'),
            ]);
        }
    }

    /**
     * Runs $write — the caller's own checks and its write — in one transaction that first takes the lock every
     * change able to cost the last active full-access admin shares (lockActiveFullAdmins()). Two such changes
     * therefore run one after the other, and each one's ensureActiveFullAdminRemains() reads what the other
     * committed: two admins demoting each other, or a user change racing a role downgrade, can no longer both
     * pass their check and leave nobody (UX eval 2026-10-10, PERM-07 review). As a backstop the invariant is
     * read again after $write, and the transaction rolls back with a ValidationException naming $field if it
     * held before and no longer does. Nested inside an open transaction (RoleController::fenceRoleWrite()) this
     * is a savepoint and the lock lasts until the outer transaction ends.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     *
     * @throws ValidationException naming $field when $write left no active full-access admin
     */
    public function guardingActiveFullAdmin(string $field, callable $write): mixed
    {
        return DB::transaction(function () use ($field, $write) {
            $this->lockActiveFullAdmins();

            $heldBefore = $this->activeFullAdminExists();

            $result = $write();

            if ($heldBefore && ! $this->activeFullAdminExists()) {
                throw ValidationException::withMessages([
                    $field => trans('admin::app.settings.users.last-active-full-admin'),
                ]);
            }

            return $result;
        });
    }

    /**
     * The shared lock: every all-access role row, then every active human admin on one, FOR UPDATE, each in id
     * order — roles before admins, the one order every holder uses, so two holders cannot deadlock on each other.
     * Any change that can take away the last active full-access admin either changes or deletes such a role, or
     * changes or deletes an admin on one, and takes this lock first through guardingActiveFullAdmin(). Locking
     * reads see the latest committed rows whatever the transaction's snapshot: under PostgreSQL's READ COMMITTED
     * a waiter re-reads the rows once the holder commits, and under InnoDB's REPEATABLE READ a locking read is a
     * current read. A no-op on SQLite, which runs one writer at a time anyway.
     */
    public function lockActiveFullAdmins(): void
    {
        $roleIds = DB::table('roles')
            ->where('permission_type', 'all')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        DB::table('admins')
            ->whereIn('role_id', $roleIds)
            ->where('status', 1)
            ->where('type', 'user')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * $id's admin row and their role's row as committed now, both read with locking reads (taken after
     * lockActiveFullAdmins(), so admins and roles are still locked in the shared order relative to every other
     * holder), or null when the admin is gone. For deciding inside guardingActiveFullAdmin() who is acting and
     * what they act on: a model loaded before the lock, or a plain read in an older REPEATABLE READ snapshot, can
     * be an admin who has since been demoted, deactivated or deleted.
     */
    public function lockedAdmin(int $id): ?AdminModel
    {
        $admin = $this->getModel()->newQuery()->whereKey($id)->lockForUpdate()->first();

        if (! $admin instanceof AdminModel) {
            return null;
        }

        $admin->setRelation('role', $admin->role_id
            ? RoleModel::query()->whereKey($admin->role_id)->lockForUpdate()->first()
            : null);

        return $admin;
    }

    /**
     * The acting admin re-read under the lock (lockedAdmin()), or null when they are gone or inactive: whoever
     * was signed in, or started an import, no longer acts once deleted or deactivated.
     */
    public function lockedActiveActor(int $id): ?AdminModel
    {
        $actor = $this->lockedAdmin($id);

        return $actor && (bool) $actor->status ? $actor : null;
    }

    /**
     * Whether $actor's role (as loaded on the model, e.g. by lockedAdmin()) grants $permission: Bouncer's rule,
     * applied to a given admin rather than to the session's.
     */
    public function actorMay(AdminModel $actor, string $permission): bool
    {
        if ($actor->role?->permission_type === 'all') {
            return true;
        }

        return (bool) $actor->hasPermission($permission);
    }

    /**
     * @param  array<int, int|string>  $withoutAdminIds
     * @param  array<int, int|string>  $withoutRoleIds
     */
    private function activeFullAdminExists(array $withoutAdminIds = [], array $withoutRoleIds = []): bool
    {
        return $this->getModel()->newQuery()
            ->join('roles', 'admins.role_id', '=', 'roles.id')
            ->where('roles.permission_type', 'all')
            ->where('admins.status', 1)
            ->where('admins.type', 'user')
            ->whereNotIn('admins.id', $withoutAdminIds)
            ->whereNotIn('roles.id', $withoutRoleIds)
            ->select('admins.id')
            ->orderBy('roles.id')
            ->orderBy('admins.id')
            ->lockForUpdate()
            ->first() !== null;
    }

    /**
     * Why $actor may not hand out a role of this type and these permissions, as a translation key, or null
     * when they may. A full-access actor may hand out anything. Anyone else may hand out only a role that is
     * explicitly `custom` (an unrecognised type reads as full access downstream) and holds no permission the
     * actor's own role lacks. The one rule for assigning, creating or changing a role, shared by the user
     * screens and the user and role importers (UX eval 2026-10-10, PERM-08).
     *
     * @param  array<int, string>|null  $permissions
     */
    public function roleGrantError(AdminModel $actor, ?string $permissionType, ?array $permissions): ?string
    {
        $actingRole = $actor->role;

        if ($actingRole?->permission_type === 'all') {
            return null;
        }

        if ($permissionType !== 'custom') {
            return 'admin::app.settings.users.cannot-escalate-role';
        }

        if (array_diff($permissions ?? [], $actingRole?->permissions ?? [])) {
            return 'admin::app.settings.users.cannot-assign-unheld-permissions';
        }

        return null;
    }

    /**
     * Whether $target's role outranks $actor, so $actor may not change or delete $target: the role-grant rule
     * applied to the role $target already holds. Nobody outranks themselves, a full-access actor is outranked
     * by nobody, and a target without a role outranks everyone else (PERM-08).
     */
    public function outranks(AdminModel $target, AdminModel $actor): bool
    {
        if ((int) $target->id === (int) $actor->id) {
            return false;
        }

        if ($actor->role?->permission_type === 'all') {
            return false;
        }

        return ! $target->role
            || $this->roleGrantError($actor, $target->role->permission_type, $target->role->permissions) !== null;
    }

    /**
     * Commits or rolls back together with the model's `deleting` listeners (ADR 0010). A listener that
     * vetoes the delete by returning false rolls back what the earlier ones wrote, too; the result is
     * whatever the parent delete returned.
     */
    public function delete($id)
    {
        return $this->deleteVetoable(fn () => parent::delete($id));
    }

    /**
     * delete() for an admin the caller already holds, read with a locking read (Repository::deleteModel()), with
     * the same transaction and veto handling as delete().
     */
    public function deleteModel(Model $model): mixed
    {
        return $this->deleteVetoable(fn () => parent::deleteModel($model));
    }

    private function deleteVetoable(callable $delete): mixed
    {
        $vetoed = new class extends RuntimeException {};
        $result = null;

        try {
            return DB::transaction(function () use ($delete, $vetoed, &$result) {
                $result = $delete();

                if (! $result) {
                    throw $vetoed;
                }

                return $result;
            });
        } catch (RuntimeException $exception) {
            if ($exception !== $vetoed) {
                throw $exception;
            }

            return $result;
        }
    }
}
