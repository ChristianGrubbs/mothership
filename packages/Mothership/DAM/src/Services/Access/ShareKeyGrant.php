<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\DamConfiguration;

/**
 * Grants the six share ACL keys to every custom role once DAM reads switch to lists (sharing slice 5, D7:
 * every user keeps the share-link feature), and restores each role's exact pre-flip set on the rollback to
 * grants. Runs inside AccessSwitch's own transactionally() blocks, so the grant or the restore commits with
 * the model transition it belongs to, never separately.
 *
 * The six keys already exist in Config/acl.php; only their presence on a role's stored permissions changes.
 */
class ShareKeyGrant
{
    public const KEYS = [
        'dam.asset.share',
        'dam.directory.share',
        'dam.shares',
        'dam.shares.index',
        'dam.shares.revoke',
        'dam.shares.delete',
    ];

    /** dam_configuration row: {role_id: [share keys that role held before the flip]}, written once per lists period. */
    public const SNAPSHOT_KEY = 'DAM_SHARE_KEY_ROLE_SNAPSHOT';

    /**
     * Appends the six keys, deduplicated, to every custom role's permissions. Idempotent: a second run (a
     * repeated switch lists) adds only what is still missing and never overwrites a snapshot an earlier run
     * already wrote, so a role that was granted once and then edited keeps its true pre-flip baseline.
     *
     * @return array{roles_granted: int, keys_added: int, snapshot_created: bool}
     */
    public function grant(): array
    {
        // Locked FOR UPDATE, ascending by id: the same order dam:access:switch's own row lock and every
        // other multi-row DAM lock take, so a writer that locks more than one row here can never deadlock
        // against one that does the same in revert() below (Codex review of PR #76, finding 1). This is on
        // top of AccessSwitch's own state-row FOR UPDATE hold, which already keeps a concurrent role save
        // (RoleController::fenceRoleWrite(), holding the state row FOR SHARE) from running at all while this
        // is in progress — the row locks here are for a writer that reaches `roles` without going through
        // that fence, such as a direct import.
        $roles = DB::table('roles')->where('permission_type', 'custom')->orderBy('id')->lockForUpdate()->get(['id', 'permissions']);

        $snapshotCreated = false;

        if (! DamConfiguration::query()->whereKey(self::SNAPSHOT_KEY)->exists()) {
            $snapshot = [];

            foreach ($roles as $role) {
                $current = $this->permissionsOf($role);
                $snapshot[(string) $role->id] = array_values(array_intersect(self::KEYS, $current));
            }

            DamConfiguration::query()->create([
                'key'   => self::SNAPSHOT_KEY,
                'value' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ]);
            $snapshotCreated = true;
        }

        $rolesGranted = 0;
        $keysAdded = 0;

        foreach ($roles as $role) {
            $current = $this->permissionsOf($role);
            $missing = array_values(array_diff(self::KEYS, $current));

            if ($missing === []) {
                continue;
            }

            $this->store(roleId: (int) $role->id, permissions: array_merge($current, $missing));

            $rolesGranted++;
            $keysAdded += count($missing);
        }

        return [
            'roles_granted'    => $rolesGranted,
            'keys_added'       => $keysAdded,
            'snapshot_created' => $snapshotCreated,
        ];
    }

    /**
     * Restores every custom role's share keys to its recorded pre-flip set, removing any of the six this
     * grant added and never touching a key the role held before the flip or any other permission. A role
     * with no snapshot entry — created after the flip, so it never had a pre-flip baseline — loses all six.
     * Clears the snapshot so the next switch to lists captures a fresh baseline from the state this restore
     * leaves behind.
     *
     * @return array{roles_restored: int, keys_removed: int, snapshot_found: bool}
     */
    public function revert(): array
    {
        $snapshotRow = DamConfiguration::query()->whereKey(self::SNAPSHOT_KEY)->first();

        if (! $snapshotRow) {
            return ['roles_restored' => 0, 'keys_removed' => 0, 'snapshot_found' => false];
        }

        $snapshot = (array) json_decode((string) $snapshotRow->value, true);

        // Same lock, same order as grant() above.
        $roles = DB::table('roles')->where('permission_type', 'custom')->orderBy('id')->lockForUpdate()->get(['id', 'permissions']);

        $rolesRestored = 0;
        $keysRemoved = 0;

        foreach ($roles as $role) {
            $current = $this->permissionsOf($role);
            $keep = array_values((array) ($snapshot[(string) $role->id] ?? []));
            $currentShareKeys = array_values(array_intersect(self::KEYS, $current));

            $sortedKeep = $keep;
            $sortedCurrentShareKeys = $currentShareKeys;
            sort($sortedKeep);
            sort($sortedCurrentShareKeys);

            if ($sortedKeep === $sortedCurrentShareKeys) {
                continue;
            }

            $restored = array_values(array_unique(array_merge(array_diff($current, self::KEYS), $keep)));

            $this->store(roleId: (int) $role->id, permissions: $restored);

            $rolesRestored++;
            $keysRemoved += max(count($currentShareKeys) - count($keep), 0);
        }

        DamConfiguration::query()->whereKey(self::SNAPSHOT_KEY)->delete();

        return ['roles_restored' => $rolesRestored, 'keys_removed' => $keysRemoved, 'snapshot_found' => true];
    }

    /** @return list<string> */
    private function permissionsOf(object $role): array
    {
        $permissions = json_decode((string) $role->permissions, true);

        return is_array($permissions) ? array_values($permissions) : [];
    }

    private function store(int $roleId, array $permissions): void
    {
        DB::table('roles')->where('id', $roleId)->update([
            'permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR),
        ]);
    }
}
