<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\DamConfiguration;
use Mothership\DAM\Services\Access\ShareKeyGrant;
use Mothership\User\Models\Role;

/*
 * Sharing slice 5: every custom role holds the six share ACL keys under lists (D7), and the rollback to
 * grants restores each role's exact pre-flip set (the plan's "grants parity" rule applied to the keys).
 * These tests exercise the service directly, without going through dam:access:switch, whose own tests
 * (AccessSwitchTest.php) cover only that it is wired in at the right point.
 *
 * CI's PostgreSQL and MySQL databases are built with `mothership:install --with-demo-data`, which seeds its own
 * custom roles — some of which may already hold one or more of the six keys, since these are pre-existing
 * ACL entries the sharing feature only newly forces onto every role, not brand-new permissions no seeder
 * could have assigned. grant()/revert() operate on every custom role in the table, so a global count
 * (roles_granted, keys_added, roles_restored, keys_removed, or the whole snapshot object) is never asserted
 * against a fixed number here — only against this test's own fixture roles by id, or against an expectation
 * derived from the table's state immediately before the call under test (fix round 2, finding 1).
 */

function shareKeyRolePermissions(int $roleId): array
{
    return (array) json_decode((string) DB::table('roles')->where('id', $roleId)->value('permissions'), true);
}

/** Every custom role already in the table, with its current permissions decoded — captured before a fixture acts. */
function shareKeyExistingCustomRoles(): Collection
{
    return DB::table('roles')->where('permission_type', 'custom')->get(['id', 'permissions'])
        ->map(fn ($role) => ['id' => (int) $role->id, 'permissions' => (array) json_decode((string) $role->permissions, true)]);
}

/**
 * What grant() (or, absent any tampering in between, revert() right after it) will do to a known set of
 * roles: how many are missing at least one of the six keys, and how many keys that totals to. Not a
 * reimplementation of grant()'s snapshot or idempotency behaviour — those stay the thing under test — only
 * the set arithmetic needed to turn "some unknown number of demo roles" into an exact expected count.
 *
 * @param  Collection<int, array{id: int, permissions: list<string>}>  $roles
 * @return array{roles_granted: int, keys_added: int}
 */
function shareKeyExpectedGrantCounts(Collection $roles): array
{
    $rolesGranted = 0;
    $keysAdded = 0;

    foreach ($roles as $role) {
        $missing = count(array_diff(ShareKeyGrant::KEYS, $role['permissions']));

        if ($missing > 0) {
            $rolesGranted++;
            $keysAdded += $missing;
        }
    }

    return ['roles_granted' => $rolesGranted, 'keys_added' => $keysAdded];
}

it('grants the six keys to every custom role and skips full-access roles', function () {
    $existing = shareKeyExistingCustomRoles();

    $bare = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);
    $partial = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.share']]);
    $full = Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);

    $expected = shareKeyExpectedGrantCounts($existing->push(
        ['id' => $bare->id, 'permissions' => ['dam', 'dam.asset.view']],
        ['id' => $partial->id, 'permissions' => ['dam.asset.share']],
    ));

    $result = app(ShareKeyGrant::class)->grant();

    expect(shareKeyRolePermissions($bare->id))->toEqualCanonicalizing(array_merge(['dam', 'dam.asset.view'], ShareKeyGrant::KEYS))
        ->and(shareKeyRolePermissions($partial->id))->toEqualCanonicalizing(ShareKeyGrant::KEYS)
        ->and(DB::table('roles')->where('id', $full->id)->value('permissions'))->toBeNull()
        ->and($result['roles_granted'])->toBe($expected['roles_granted'])
        ->and($result['keys_added'])->toBe($expected['keys_added'])
        ->and($result['snapshot_created'])->toBeTrue();
});

it('records each role pre-flip share-key set exactly once, so a repeated grant never overwrites it', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.share']]);

    $first = app(ShareKeyGrant::class)->grant();
    expect($first['snapshot_created'])->toBeTrue();

    // Simulate an admin editing the role mid-lists-period: drop the key it had before the flip.
    DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(ShareKeyGrant::KEYS)]);

    $second = app(ShareKeyGrant::class)->grant();

    $snapshot = json_decode((string) DamConfiguration::find(ShareKeyGrant::SNAPSHOT_KEY)->value, true);

    expect($second['snapshot_created'])->toBeFalse()
        // The first grant already gave every custom role — demo-seeded or this test's own — all six keys,
        // so a second run, before anything else changes, has nothing left to add anywhere.
        ->and($second['roles_granted'])->toBe(0)
        ->and($second['keys_added'])->toBe(0)
        ->and($snapshot[(string) $role->id])->toBe(['dam.asset.share']);
});

it('restores each role to exactly its pre-flip share-key set, never touching another permission', function () {
    $existing = shareKeyExistingCustomRoles();

    $keptOne = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.share', 'dam.asset.view']]);
    $keptNone = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.view']]);

    // Grant and revert with nothing else touching any role in between: revert() undoes exactly the roles and
    // keys grant() just added, so the same expected counts apply to both.
    $expected = shareKeyExpectedGrantCounts($existing->push(
        ['id' => $keptOne->id, 'permissions' => ['dam.asset.share', 'dam.asset.view']],
        ['id' => $keptNone->id, 'permissions' => ['dam.asset.view']],
    ));

    app(ShareKeyGrant::class)->grant();

    $result = app(ShareKeyGrant::class)->revert();

    expect(shareKeyRolePermissions($keptOne->id))->toEqualCanonicalizing(['dam.asset.share', 'dam.asset.view'])
        ->and(shareKeyRolePermissions($keptNone->id))->toEqualCanonicalizing(['dam.asset.view'])
        ->and($result['roles_restored'])->toBe($expected['roles_granted'])
        ->and($result['keys_removed'])->toBe($expected['keys_added'])
        ->and(DamConfiguration::find(ShareKeyGrant::SNAPSHOT_KEY))->toBeNull();
});

it('drops all six keys from a role created after the grant, since it has no pre-flip baseline', function () {
    app(ShareKeyGrant::class)->grant();

    // A role RoleBuilder gave every key to under lists, without anyone choosing them (keepShareKeysUnderLists()).
    $postFlip = Role::factory()->create(['permission_type' => 'custom', 'permissions' => array_merge(['dam.asset.view'], ShareKeyGrant::KEYS)]);

    app(ShareKeyGrant::class)->revert();

    expect(shareKeyRolePermissions($postFlip->id))->toEqualCanonicalizing(['dam.asset.view']);
});

it('does nothing on revert when no snapshot exists, such as a switch that never granted', function () {
    Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.view']]);

    $result = app(ShareKeyGrant::class)->revert();

    expect($result)->toBe(['roles_restored' => 0, 'keys_removed' => 0, 'snapshot_found' => false]);
});

it('treats a custom role with a null permissions column as holding none of the six keys', function () {
    $existing = shareKeyExistingCustomRoles();

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => null]);
    expect(DB::table('roles')->where('id', $role->id)->value('permissions'))->toBeNull();

    $expected = shareKeyExpectedGrantCounts($existing->push(['id' => $role->id, 'permissions' => []]));

    $result = app(ShareKeyGrant::class)->grant();

    expect(shareKeyRolePermissions($role->id))->toEqualCanonicalizing(ShareKeyGrant::KEYS)
        ->and($result['roles_granted'])->toBe($expected['roles_granted'])
        ->and($result['keys_added'])->toBe($expected['keys_added']);

    app(ShareKeyGrant::class)->revert();

    expect(DB::table('roles')->where('id', $role->id)->value('permissions'))->toBe('[]');
});

it('dedupes a role whose stored permissions already contain duplicate entries', function () {
    $existing = shareKeyExistingCustomRoles();

    $role = Role::factory()->create(['permission_type' => 'custom']);
    DB::table('roles')->where('id', $role->id)->update([
        'permissions' => json_encode(['dam', 'dam', 'dam.asset.share', 'dam.asset.share']),
    ]);

    $expected = shareKeyExpectedGrantCounts($existing->push(
        ['id' => $role->id, 'permissions' => ['dam', 'dam.asset.share']],
    ));

    $result = app(ShareKeyGrant::class)->grant();

    $stored = shareKeyRolePermissions($role->id);
    expect($stored)->toEqualCanonicalizing(array_values(array_unique(array_merge(['dam', 'dam.asset.share'], ShareKeyGrant::KEYS))))
        ->and(array_unique($stored))->toHaveCount(count($stored))
        ->and($result['keys_added'])->toBe($expected['keys_added']);

    app(ShareKeyGrant::class)->revert();

    $restored = shareKeyRolePermissions($role->id);
    expect($restored)->toEqualCanonicalizing(['dam', 'dam.asset.share'])
        ->and(array_unique($restored))->toHaveCount(count($restored));
});
