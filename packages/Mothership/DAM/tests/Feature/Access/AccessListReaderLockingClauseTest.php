<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Tests\Support\LockingClause;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Codex review of PR #75 round 2, finding 2: the copy jobs' post-lock re-validation
 * (ClonesOriginalFileObjects::accessibleAssetIds(locked: true)) was an ordinary SELECT — on
 * MySQL/MariaDB's REPEATABLE READ, a plain read still answers from the transaction's snapshot even
 * after the row it names has been locked by an earlier statement, so a revocation that committed
 * while lockSourceAssets() waited stayed invisible. Fixed by giving the re-check's own outer query and
 * every list subquery it depends on (AccessListReader::whereListed()) a shared lock, so InnoDB takes a
 * current read of them regardless of the transaction's snapshot. These cases compile the real query
 * against each grammar (no MySQL server here), so the fragments are held to what Laravel emits, the
 * same way AccessFenceLockingClauseTest does for the DAM_ACCESS_MODEL fence.
 *
 * Finding 5, same round: inaccessibleSubtreeCounts() read the subtree's root bounds in a statement of
 * its own before the counting aggregate; folded into one statement via crossJoinSub(), proved below by
 * counting the actual queries a real call makes.
 */

/*
 * whereListed()'s subqueries go through the DB facade — the real, current connection's grammar — so
 * they cannot be compiled against a driver this environment isn't actually connected as (there is no
 * MySQL or MariaDB server here). AccessFenceLockingClauseTest already holds Laravel's own grammars to
 * what LockingClause recognises, generically, for pgsql/mysql/mariadb/sqlsrv; that proof is not
 * repeated here. What is new to this finding is that *this* code — the real
 * DirectoryPermissionService::applyAssetDirectoryGrantFilter($query, locked: true) call
 * accessibleAssetIds(locked: true) makes — calls sharedLock() in the right four places. toSql() only
 * compiles (nothing executes), so this runs on whichever driver is actually connected: PostgreSQL in
 * this environment, and any of pgsql/mysql/mariadb in CI's own PostgreSQL/MySQL jobs.
 */
it('carries a locking clause on the outer query and every list subquery it depends on', function () {
    lockingClauseModel(AccessModel::LISTS);
    $admin = lockingClauseAdmin();
    $service = (new DirectoryPermissionService)->forAdmin($admin);

    $locked = DB::table('dam_assets')->whereIn('dam_assets.id', [1, 2, 3]);
    $service->applyAssetDirectoryGrantFilter($locked, locked: true);
    $locked->sharedLock();
    $sql = $locked->toSql();

    $pattern = LockingClause::pattern();
    $matches = preg_match_all($pattern ?? '/./', $sql, $ignored);

    // The outer SELECT and each of the three whereListed() subqueries (ownership, user list, group
    // list): four occurrences of the clause, not just one — skipped on SQLite, which compiles none
    // at all (LockingClause's own documented exception), so there is nothing for this count to prove.
    if ($pattern !== null) {
        expect($matches)->toBeGreaterThanOrEqual(4, $sql);
    }

    // And it is $locked doing the work: the unlocked sibling carries none of it.
    $unlocked = DB::table('dam_assets')->whereIn('dam_assets.id', [1, 2, 3]);
    $service->applyAssetDirectoryGrantFilter($unlocked, locked: false);
    $unlockedSql = $unlocked->toSql();

    expect(LockingClause::matches($unlockedSql))->toBe($pattern === null, 'unlocked call carried a lock: '.$unlockedSql);
});

/*
 * Codex review of PR #75 round 2, finding 3: inaccessibleSubtreeCounts() used to selectRaw() a bare
 * `dam_directories.id`/`dam_assets.id` alongside a query builder FROM that DOES respect DB_PREFIX — so
 * under the workflow's wk_ prefix, the compiled SELECT named a table alias ("dam_directories") the FROM
 * clause never had (it had "wk_dam_directories"), a SQL error on every non-bypass guard check. Fixed by
 * dropping that projection outright (nothing selected it). AssetDataGridPrefixTest sets the same
 * stand-in prefix for the same reason: no wk_-prefixed tables exist on this test database, so only the
 * compiled SQL is asserted, never executed.
 */
it('compiles every table reference prefixed under DB_PREFIX=wk_, with no bare unprefixed alias left over', function () {
    $reader = new AccessListReader;
    // Enabled, so this compiles the whereNotListed() branch too (ownership, user-access,
    // group-access subqueries), not just the folder/file join skeleton.
    $admin = new Admin(['id' => 1, 'status' => 1]);

    // groupIds() would otherwise run a real query against a wk_-prefixed table this test database
    // does not have (the same reason AssetDataGridPrefixTest mocks its own directory-access read
    // rather than let it execute under the stand-in prefix). Pre-warming the cache this reads first
    // skips that query without changing anything this test compiles.
    $cache = new ReflectionProperty(AccessListReader::class, 'groupIdsCache');
    $cache->setAccessible(true);
    $cache->setValue($reader, []);
    $cachedFor = new ReflectionProperty(AccessListReader::class, 'groupIdsForAdminId');
    $cachedFor->setAccessible(true);
    $cachedFor->setValue($reader, $admin->id);

    $originalPrefix = DB::getTablePrefix();
    $testPrefix = $originalPrefix !== '' ? $originalPrefix : 'wk_';

    if ($originalPrefix === '') {
        DB::connection()->setTablePrefix($testPrefix);
    }

    try {
        $method = new ReflectionMethod(AccessListReader::class, 'inaccessibleSubtreeQuery');
        $method->setAccessible(true);
        $sql = $method->invoke($reader, $admin, 1)->toSql();

        expect($sql)->toContain($testPrefix.'dam_directories')
            ->and($sql)->toContain($testPrefix.'dam_assets')
            ->and($sql)->toContain($testPrefix.'dam_asset_directory')
            ->and($sql)->toContain($testPrefix.'dam_directory_user_access')
            ->and($sql)->toContain($testPrefix.'dam_asset_user_access')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_directories\b/i')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_assets\b/i')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_asset_directory\b/i')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_directory_user_access\b/i')
            ->and($sql)->not->toMatch('/(?<![a-z_0-9])dam_asset_user_access\b/i');
    } finally {
        if ($originalPrefix === '') {
            DB::connection()->setTablePrefix($originalPrefix);
        }
    }
});

it('answers the subtree question in one statement, apart from the principal-loading it excludes', function () {
    lockingClauseModel(AccessModel::LISTS);
    $admin = lockingClauseAdmin();
    $folder = Directory::create(['name' => 'Guard shape folder']);
    DB::table('dam_directories')->where('id', $folder->id)->update(['created_by' => null]);
    // The subtree this method reads is inclusive of the root itself, so it needs its own grant to
    // isolate this test's claim to the one hidden file rather than also counting the root.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $admin->id]);
    $hidden = Asset::factory()->create(['file_name' => 'guard-shape-hidden.jpg']);
    $hidden->directories()->attach($folder->id);
    DB::table('dam_assets')->where('id', $hidden->id)->update(['created_by' => null]);

    $reader = new AccessListReader;

    // Warms exactly what this claim excludes and documents why (Codex review round 2, finding 5):
    // $admin->role (Eloquent's own lazy relation, cached on this model instance) and groupIds()
    // (cached on this reader instance after its first call) — principal membership, not subtree
    // state, so neither is part of the "one statement" the aggregate itself makes.
    $reader->inaccessibleSubtreeCounts(admin: $admin, directoryId: $folder->id);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $counts = $reader->inaccessibleSubtreeCounts(admin: $admin, directoryId: $folder->id);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($counts)->toBe(['folders' => 0, 'files' => 1])
        ->and($queries)->toHaveCount(1, 'expected one statement, got: '.$queries->pluck('query')->implode(' | '));
});

function lockingClauseModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function lockingClauseAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}
