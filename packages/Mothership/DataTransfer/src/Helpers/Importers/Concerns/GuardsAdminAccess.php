<?php

namespace Mothership\DataTransfer\Helpers\Importers\Concerns;

use Illuminate\Validation\ValidationException;
use Mothership\User\Models\Admin;
use Mothership\User\Repositories\AdminRepository;

/**
 * The user and role importers write admins and roles directly, so they answer to the same two rules as the
 * user and role screens (UX eval 2026-10-10, PERM-07/PERM-08): the import's actor may not change, delete or
 * hand out access that outranks their own (AdminRepository::outranks()/roleGrantError()), and no row may take
 * away the last active full-access admin (AdminRepository::ensureActiveFullAdminRemains()).
 *
 * The actor is the admin who started the job (`job_track.user_id`), looked up afresh at every check, since an
 * earlier row in the same job may have demoted, deactivated or deleted them. Only a job recorded as console
 * authority (`job_track.console_authority`: `data-transfer` run without a user email) is a shell operator and
 * is not rank-checked. Any other job whose user is missing, inactive or gone — `user_id` is ON DELETE SET
 * NULL, so a deleted admin's queued job reads null there — is refused everything that needs authority.
 */
trait GuardsAdminAccess
{
    public const ERROR_OUTRANKED = 'outranked';

    public const ERROR_LAST_FULL_ADMIN = 'last_full_admin';

    abstract protected function adminAccessRepository(): AdminRepository;

    /**
     * The acting admin for a job track, read now (locking read) with their current role: an Admin; null for an explicit
     * console job with no user; false when the job has no usable actor (missing, deleted or inactive), which is
     * refused. Never cached: every write boundary asks again.
     */
    protected function importActor(mixed $jobTrack): Admin|false|null
    {
        $userId = $jobTrack->user_id ?? null;

        if (! $userId) {
            return ($jobTrack->console_authority ?? false) === true ? null : false;
        }

        // A locking read: inside the import's outer transaction a plain read may answer from an older
        // REPEATABLE READ snapshot (MySQL/MariaDB), i.e. from before the actor was demoted or deactivated.
        return $this->adminAccessRepository()->lockedActiveActor((int) $userId) ?? false;
    }

    /**
     * Translation key naming why the actor may not create, change or assign a role of this type with these
     * permissions; null when they may.
     *
     * @param  array<int, string>|null  $permissions
     */
    protected function roleGrantErrorFor(mixed $jobTrack, ?string $permissionType, ?array $permissions): ?string
    {
        $actor = $this->importActor($jobTrack);

        if ($actor === null) {
            return null;
        }

        if ($actor === false) {
            return 'admin::app.settings.users.cannot-escalate-role';
        }

        return $this->adminAccessRepository()->roleGrantError($actor, $permissionType, $permissions);
    }

    protected function actorOutrankedBy(mixed $jobTrack, ?Admin $target): bool
    {
        $actor = $this->importActor($jobTrack);

        if ($actor === null || ! $target) {
            return false;
        }

        if ($actor === false) {
            return true;
        }

        return $this->adminAccessRepository()->outranks(target: $target, actor: $actor);
    }

    /**
     * Whether a full-access admin who is active would still remain without these admins and roles.
     *
     * @param  array<int, int|string>  $withoutAdminIds
     * @param  array<int, int|string>  $withoutRoleIds
     */
    protected function activeFullAdminRemains(array $withoutAdminIds = [], array $withoutRoleIds = []): bool
    {
        if ($withoutAdminIds === [] && $withoutRoleIds === []) {
            return true;
        }

        try {
            $this->adminAccessRepository()->ensureActiveFullAdminRemains(
                field: 'import',
                withoutAdminIds: $withoutAdminIds,
                withoutRoleIds: $withoutRoleIds,
            );
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * One row's write step: decides $refusal and runs $write in the same transaction, under the lock every
     * user and role change that can cost the last active full-access admin takes
     * (AdminRepository::guardingActiveFullAdmin()), so the answer reflects what is committed now and no
     * concurrent change lands between the check and the write. Returns [refusal code, null] when the row is
     * refused and nothing was written, or [null, what $write returned].
     *
     * @template T
     *
     * @param  callable(): ?string  $refusal
     * @param  callable(): T  $write
     * @return array{0: ?string, 1: T|null}
     */
    protected function writeUnderAdminLock(callable $refusal, callable $write): array
    {
        try {
            return $this->adminAccessRepository()->guardingActiveFullAdmin(
                field: 'import',
                write: function () use ($refusal, $write): array {
                    if ($code = $refusal()) {
                        return [$code, null];
                    }

                    return [null, $write()];
                },
            );
        } catch (ValidationException) {
            return [self::ERROR_LAST_FULL_ADMIN, null];
        }
    }

    /**
     * Record a row the write step refused (validation passed but an earlier row in the job changed the picture).
     */
    protected function logRefusedRow(string $reason, string $key): void
    {
        $this->jobLogger?->warning("Row for {$key} skipped: {$reason}");
    }
}
