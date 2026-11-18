<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\ShareKeyGrant;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * Sharing slice 5, fix rounds 1–3: a role save through the real admin.settings.roles routes is fenced
 * against dam:access:switch by AccessModel::holding() — the same state-row FOR SHARE hold every list writer
 * takes, which a switch's own FOR UPDATE hold (AccessModel::transactionally(), inside ShareKeyGrant::grant()/
 * revert()) blocks and is blocked by. Proved from a second session, following
 * AccessCascadeLockingCommittedTest.php's barrier pattern: a lock-acquired barrier, a bounded PostgreSQL
 * lock_timeout, and the 55P03 lock_not_available code as the proof the blocked session actually waited — no
 * proof is carried by a sleep.
 *
 * Round 3 (Codex re-review of PR #76, findings 1–2) drove two changes here:
 * - Requests now go through the real RoleController routes (admin.settings.roles.store/update), not a
 *   fixture that replicates its closure body — removing RoleController::fenceRoleWrite() now fails these
 *   tests (proved by the control at the end of this file), which the closure-based fixtures never could.
 * - A second direction is proved: a switch waits for a role save's hold too, with the barrier placed after
 *   RoleBuilder has already prepared the forced keys (not before), and ShareKeyGrant::grant()/revert() are
 *   proved against a role locked by an unrelated, uncommitted permission edit — the edit survives once the
 *   lock releases, and no manual flush() anywhere here stands in for AccessModel's own freshness guarantee.
 *
 * `lockForUpdate()`/`sharedLock()` are no-ops on SQLite, so PostgreSQL is where this contract is observable
 * and every other driver skips. These rows are committed (DamConcurrencyTestCase, no DatabaseTransactions
 * wrapper), so tearDown removes them and puts the state row back as it found it.
 */
final class RoleShareKeyFenceCommittedTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'role_share_key_fence_holder';

    private const TIMEOUT_MS = 300;

    private const WAITED_SECONDS = 0.25;

    /** Longer than TIMEOUT_MS, short enough not to slow the suite: the barrier a blocking session waits out. */
    private const HOLD_MS = 450;

    private const LOCK_NOT_AVAILABLE = '55P03';

    private bool $prepared = false;

    private ?Role $role = null;

    private ?Admin $superAdmin = null;

    /** @var array<int|string, string|null> every role's permissions column, keyed by id, before this test acted. */
    private array $roleBaselinePermissions = [];

    private bool $snapshotRowExistedBeforeTest = false;

    private ?string $snapshotBaselineValue = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('lockForUpdate()/sharedLock() are no-ops on SQLite and the waits are bounded with PostgreSQL lock_timeout; other drivers skip.');
        }

        config(['database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection())]);

        // The lock-timeout QueryException these tests catch would otherwise never reach them: the test HTTP
        // client renders an uncaught exception as a 500 response instead of re-throwing it.
        $this->withoutExceptionHandling();

        $this->recordResidueBaseline();
        $this->recordAccessModelState();
        $this->recordRolePermissionsBaseline();

        $suffix = (string) Str::uuid();

        // A role already forced-granted, as ShareKeyGrant::grant() would leave it mid-lists: it kept its own
        // pre-flip key (dam.asset.view is not one of the six) and picked up the six it never chose.
        $this->role = Role::factory()->create([
            'name'            => 'Fence race role '.$suffix,
            'permission_type' => 'custom',
            'permissions'     => array_merge(['dam.asset.view'], ShareKeyGrant::KEYS),
        ]);
        // AdminFactory's own default role_id is Role::first()?->id, an unordered query: CI's demo-data
        // install seeds several custom roles alongside the full-access one, and without an explicit order a
        // Postgres query plan is free to return any of them — including, once in a while, $this->role
        // itself, whose only permissions are dam.asset.view and the six share keys. Picking the full-access
        // role explicitly makes every HTTP request below reliably authorized.
        $this->superAdmin = Admin::factory()->create([
            'name'    => 'Fence race admin '.$suffix,
            'status'  => 1,
            'role_id' => Role::where('permission_type', 'all')->orderBy('id')->value('id'),
        ]);
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
        app(AccessModel::class)->flush();

        $this->actingAs($this->superAdmin, 'admin');

        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        if (! $this->prepared) {
            try {
                $this->restoreRolePermissionsBaseline();
            } finally {
                try {
                    $this->cleanUpCommittedFixtures();
                } finally {
                    parent::tearDown();
                }
            }

            return;
        }

        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $holder = DB::connection(self::HOLDER);

            while ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }

            DB::statement('RESET lock_timeout');

            Role::query()->whereKey($this->role->id)->delete();
            Admin::query()->whereKey($this->superAdmin->id)->delete();
            Role::query()->where('name', 'Fence race created role')->delete();

            // grant()/revert() run against every custom role in the table — CI's demo-data install seeds
            // several — so restoring only this test's own fixture role would leave demo roles carrying six
            // extra permissions (or missing whatever revert() stripped) into every later test in the same
            // run. Runs unconditionally, in this same finally-guarded path, whether the test above passed or
            // failed.
            $this->restoreRolePermissionsBaseline();

            DB::purge(self::HOLDER);
        } finally {
            // Nested so a failure in cleanUpCommittedFixtures() (or in the block above) can never skip
            // parent::tearDown(): that call tears down this test's whole application instance, and skipping
            // it would leave the next test running against a stale container — the Event::listen()
            // registrations and the app()->instance(AccessModel::class, ...) double some of these tests
            // install would otherwise leak straight into it.
            try {
                $this->cleanUpCommittedFixtures();
            } finally {
                parent::tearDown();
            }
        }
    }

    /** Every role's permissions, and ShareKeyGrant's snapshot row, exactly as the database had them. */
    private function recordRolePermissionsBaseline(): void
    {
        $this->roleBaselinePermissions = DB::table('roles')->orderBy('id')->pluck('permissions', 'id')->all();

        $snapshotRow = DB::table('dam_configuration')->where('key', ShareKeyGrant::SNAPSHOT_KEY)->first();
        $this->snapshotRowExistedBeforeTest = $snapshotRow !== null;
        $this->snapshotBaselineValue = $snapshotRow->value ?? null;
    }

    /**
     * Puts every role's permissions, and the snapshot row, back exactly as recordRolePermissionsBaseline()
     * found them — including every pre-existing custom role grant()/revert() touched, not only this test's
     * own fixture — and then proves it by value, not merely by row count: AssertsNoCommittedResidue's own
     * sweep compares counts and new ids, so it cannot see a role whose id already existed gain or lose keys.
     */
    private function restoreRolePermissionsBaseline(): void
    {
        foreach ($this->roleBaselinePermissions as $roleId => $permissions) {
            DB::table('roles')->where('id', $roleId)->update(['permissions' => $permissions]);
        }

        $this->snapshotRowExistedBeforeTest
            ? DB::table('dam_configuration')->updateOrInsert(['key' => ShareKeyGrant::SNAPSHOT_KEY], ['value' => $this->snapshotBaselineValue])
            : DB::table('dam_configuration')->where('key', ShareKeyGrant::SNAPSHOT_KEY)->delete();

        $this->assertSame(
            $this->roleBaselinePermissions,
            DB::table('roles')->whereIn('id', array_keys($this->roleBaselinePermissions))->orderBy('id')->pluck('permissions', 'id')->all(),
            'a pre-existing role\'s permissions were not restored to their exact pre-test value'
        );

        $this->assertSame(
            $this->snapshotBaselineValue,
            DB::table('dam_configuration')->where('key', ShareKeyGrant::SNAPSHOT_KEY)->value('value'),
            'the DAM_SHARE_KEY_ROLE_SNAPSHOT row was not restored to its exact pre-test value'
        );
    }

    public function test_a_real_role_update_request_waits_for_a_switchs_state_row_hold_and_then_reads_the_post_switch_model(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // The barrier: exactly the hold AccessSwitch's transactionally() takes before ShareKeyGrant::revert()
        // runs, mid-way through `dam:access:switch grants`.
        $this->assertSame(AccessModel::LISTS, $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value'));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait comes from the fence's locking read, not from reading the row at all: a plain read
        // answers at once while the holder still holds it.
        $this->assertSame(AccessModel::LISTS, DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'));

        $started = microtime(true);

        try {
            $this->putRoleUpdate(['dam.asset.view']);
            $this->fail('the role update did not wait for the switch\'s state row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the role update did not wait for the lock');

        // Nothing committed while blocked: the role still holds every forced key from before the attempt.
        $this->assertSame(array_merge(['dam.asset.view'], ShareKeyGrant::KEYS), $this->rolePermissions());

        // The holder now plays out the rest of dam:access:switch grants for real.
        $holder->table('roles')->where('id', $this->role->id)->update(['permissions' => json_encode(['dam.asset.view'])]);
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => AccessModel::GRANTS]);
        $holder->commit();

        DB::statement('RESET lock_timeout');

        // Retried, as a real client would: the fence now finds the row free, reads grants — the model as it
        // actually is once the switch landed — and adds none of the six keys back.
        $this->putRoleUpdate(['dam.asset.view', 'dam.asset.property.view'])->assertRedirect();

        $this->assertSame(['dam.asset.view', 'dam.asset.property.view'], $this->rolePermissions());
    }

    public function test_a_real_role_creation_request_waits_for_a_switchs_state_row_hold_and_then_reads_the_post_switch_model(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        $this->assertSame(AccessModel::LISTS, $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value'));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $started = microtime(true);

        try {
            $this->postRoleCreate('Fence race created role', ['dam.asset.view']);
            $this->fail('the role create did not wait for the switch\'s state row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the role create did not wait for the lock');
        $this->assertSame(0, DB::table('roles')->where('name', 'Fence race created role')->count(), 'the create committed while another session held the state row');

        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => AccessModel::GRANTS]);
        $holder->commit();

        DB::statement('RESET lock_timeout');

        $this->postRoleCreate('Fence race created role', ['dam.asset.view'])->assertRedirect();

        // Grants by the time the create actually ran: RoleBuilder::keepShareKeysUnderLists() added nothing.
        $created = Role::query()->where('name', 'Fence race created role')->firstOrFail();
        $this->assertSame(['dam.asset.view'], (array) $created->permissions);
    }

    /**
     * The other direction: a switch attempting the state row FOR UPDATE waits for a role save's own FOR
     * SHARE hold. The barrier sits after RoleBuilder::keepShareKeysUnderLists() has already prepared the
     * forced keys (a listener on user.role.update.before, registered after DAM's own, so it runs second),
     * not before — proving the hold is still in force through preparation and into persistence, not just at
     * the moment the fence is first acquired.
     */
    public function test_a_switch_waits_for_a_role_updates_hold_placed_after_permission_preparation(): void
    {
        $holder = DB::connection(self::HOLDER);
        // The bound belongs to the holder's own session: it is the side attempting the lock here, the
        // opposite of every other test in this file.
        $holder->statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $barrierMs = self::HOLD_MS;
        Event::listen('user.role.update.before', function () use ($barrierMs) {
            // RoleBuilder::prepareRequest() (registered ahead of this listener, by DAMServiceProvider at
            // boot) has already merged the forced keys into request('permissions') by the time this runs.
            // Sleeping here, still inside AccessModel::holding()'s transaction, holds the FOR SHARE lock a
            // second connection's switch must wait out.
            DB::select('select pg_sleep(?)', [$barrierMs / 1000]);
        });

        $switchBlocked = false;
        $switchWaited = 0.0;

        Event::listen('user.role.update.after', function () use ($holder, &$switchBlocked, &$switchWaited) {
            // Fired from inside the role save's own transaction, after persistence but before its holding()
            // call releases the lock: exactly when dam:access:switch's transactionally() would try to move
            // the model on, and must wait for this transaction to end first.
            $started = microtime(true);

            try {
                $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value');
            } catch (QueryException $timedOut) {
                $switchBlocked = $timedOut->getCode() === self::LOCK_NOT_AVAILABLE;
                $switchWaited = microtime(true) - $started;
            }
        });

        $this->putRoleUpdate(['dam.asset.view', 'dam.asset.property.view'])->assertRedirect();

        $this->assertTrue($switchBlocked, 'a switch attempting the state row FOR UPDATE did not block on the role save\'s own hold');
        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, $switchWaited);

        $holder->statement('RESET lock_timeout');
        // The model never actually moved to grants in this test — only a switch's own attempt was proved to
        // wait — so the six keys are still forced on, as they are for every custom role under lists.
        $this->assertSame(
            array_merge(['dam.asset.view', 'dam.asset.property.view'], ShareKeyGrant::KEYS),
            $this->rolePermissions(),
        );
    }

    /**
     * The control for the previous test: with RoleController::fenceRoleWrite()'s hold removed, the same
     * barrier and the same attempted switch no longer block at all — the switch's FOR UPDATE lands
     * immediately, because nothing holds the state row while the role save sleeps. This is what proves the
     * blocking test above actually depends on the controller's fence, not on the sleep alone.
     */
    public function test_without_the_controller_fence_a_switch_does_not_wait_for_the_role_updates_sleep(): void
    {
        $unfenced = new class extends AccessModel
        {
            public function holding(callable $callback): mixed
            {
                return $callback();
            }
        };
        app()->instance(AccessModel::class, $unfenced);

        $barrierMs = self::HOLD_MS;
        Event::listen('user.role.update.before', function () use ($barrierMs) {
            DB::select('select pg_sleep(?)', [$barrierMs / 1000]);
        });

        $holder = DB::connection(self::HOLDER);
        // Defensive only: this attempt is expected to succeed at once, but a bound keeps a surprise wait
        // from hanging the test instead of failing it.
        $holder->statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
        $switchBlocked = null;

        Event::listen('user.role.update.after', function () use ($holder, &$switchBlocked) {
            try {
                $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value');
                $switchBlocked = false;
            } catch (QueryException) {
                $switchBlocked = true;
            } finally {
                $holder->rollBack();
            }
        });

        $holder->beginTransaction();

        $this->putRoleUpdate(['dam.asset.view'])->assertRedirect();

        $this->assertFalse($switchBlocked, 'the switch blocked even though the controller\'s fence was removed');
    }

    public function test_share_key_grant_waits_for_an_unrelated_concurrent_permission_edit_and_preserves_it_once_it_commits(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // An admin's own, unrelated edit to this exact role, mid-save and not yet committed — nothing to do
        // with sharing at all.
        $this->assertSame($this->role->id, (int) $holder->table('roles')->where('id', $this->role->id)->lockForUpdate()->value('id'));
        $holder->table('roles')->where('id', $this->role->id)->update([
            'permissions' => json_encode(['dam.asset.view', 'dam.asset.property.view']),
        ]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $started = microtime(true);

        try {
            app(ShareKeyGrant::class)->grant();
            $this->fail('grant() did not wait for the concurrently locked role row');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'grant() did not wait for the lock');
        // The uncommitted edit is invisible from here; this connection still sees the row as it was before
        // the holder's transaction began.
        $this->assertSame(array_merge(['dam.asset.view'], ShareKeyGrant::KEYS), $this->rolePermissions());

        $holder->commit();
        DB::statement('RESET lock_timeout');

        app(ShareKeyGrant::class)->grant();

        // The concurrent edit survived: grant() read the post-edit permissions — ascending role locking
        // (ShareKeyGrant.php's lockForUpdate()) waited for it rather than racing it — and added only what
        // was still missing.
        $this->assertSame(
            array_merge(['dam.asset.view', 'dam.asset.property.view'], ShareKeyGrant::KEYS),
            $this->rolePermissions(),
        );
    }

    public function test_share_key_revert_waits_for_an_unrelated_concurrent_permission_edit_and_preserves_it_once_it_commits(): void
    {
        // A real pre-flip baseline: the fixture role starts already holding all six (as ShareKeyGrant::grant()
        // would leave a role mid-lists), which would make grant() here record that baseline as itself, so
        // revert() would remove nothing. Strip them first, so grant() snapshots "none of the six" for this
        // role, and revert() has something real to undo.
        DB::table('roles')->where('id', $this->role->id)->update(['permissions' => json_encode(['dam.asset.view'])]);
        app(ShareKeyGrant::class)->grant();

        // Puts the role's own COMMITTED state back to exactly its snapshot (no share keys) before the
        // concurrent edit below. Without this, the committed row still carries the six keys grant() just
        // added; an unlocked revert() read would then see them, decide there is real work to do, and issue
        // its own UPDATE — which blocks on the holder's row lock regardless of whether revert()'s SELECT was
        // ever locked, making the wait this test proves next indistinguishable from a false positive (Codex
        // re-review of PR #76 round 3, finding 1). With the row already matching its snapshot, an unlocked
        // read finds nothing to update and returns without waiting at all — the fail() below would fire.
        DB::table('roles')->where('id', $this->role->id)->update(['permissions' => json_encode(['dam.asset.view'])]);

        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // An admin's own, unrelated edit to this exact role, mid-save and not yet committed: it reintroduces
        // the six keys — nothing to do with sharing — alongside a real permission of its own.
        $this->assertSame($this->role->id, (int) $holder->table('roles')->where('id', $this->role->id)->lockForUpdate()->value('id'));
        $holder->table('roles')->where('id', $this->role->id)->update([
            'permissions' => json_encode(array_merge(['dam.asset.view', 'dam.asset.property.view'], ShareKeyGrant::KEYS)),
        ]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $started = microtime(true);

        try {
            app(ShareKeyGrant::class)->revert();
            $this->fail('revert() did not wait for the concurrently locked role row');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'revert() did not wait for the lock');
        // The uncommitted edit is invisible from here: this connection still sees the pre-edit row, already
        // matching its snapshot.
        $this->assertSame(['dam.asset.view'], $this->rolePermissions());

        $holder->commit();
        DB::statement('RESET lock_timeout');

        app(ShareKeyGrant::class)->revert();

        // The concurrent edit survived: only the six forced keys came off, dam.asset.property.view stayed.
        $this->assertSame(['dam.asset.view', 'dam.asset.property.view'], $this->rolePermissions());
    }

    /**
     * Sharing slice 5, fix round 3, finding 1: AccessModel::holdingInside() must refresh current() from its
     * own locking read even when a transaction is already open — the path holding() never calls flush() on
     * — so a role save nested inside an enclosing transaction cannot decide from a value this session
     * already believed before the switch below committed. Fails before that fix (the role keeps every
     * forced key despite grants having landed) and passes after.
     */
    public function test_a_role_save_inside_an_already_open_transaction_refreshes_the_model_instead_of_trusting_a_pre_transaction_cache(): void
    {
        // This session already believes lists, from a read made before anything below happens — exactly
        // what a request that checked the model earlier in its own lifecycle would carry into a later,
        // nested transaction.
        $this->assertSame(AccessModel::LISTS, app(AccessModel::class)->current());

        // The switch completes for real, on a second connection, before this session's own outer
        // transaction even opens.
        $holder = DB::connection(self::HOLDER);
        $holder->table('roles')->where('id', $this->role->id)->update(['permissions' => json_encode(['dam.asset.view'])]);
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => AccessModel::GRANTS]);

        // An outer transaction already open when the role save runs: RoleController::fenceRoleWrite()'s
        // holding() then takes the "already open" branch, which never calls flush() itself — no manual
        // flush() is called anywhere in this test either.
        DB::beginTransaction();

        try {
            $this->putRoleUpdate(['dam.asset.view', 'dam.asset.property.view'])->assertRedirect();
        } finally {
            DB::commit();
        }

        // Reads use grants now, so RoleBuilder::keepShareKeysUnderLists() must have added nothing.
        $this->assertSame(['dam.asset.view', 'dam.asset.property.view'], $this->rolePermissions());
    }

    private function putRoleUpdate(array $permissions)
    {
        return $this->put(route('admin.settings.roles.update', $this->role->id), [
            'name'            => $this->role->name,
            'permission_type' => 'custom',
            'permissions'     => $permissions,
        ]);
    }

    private function postRoleCreate(string $name, array $permissions)
    {
        return $this->post(route('admin.settings.roles.store'), [
            'name'            => $name,
            'permission_type' => 'custom',
            'permissions'     => $permissions,
        ]);
    }

    /** @return list<string> */
    private function rolePermissions(): array
    {
        return (array) json_decode((string) DB::table('roles')->where('id', $this->role->id)->value('permissions'), true);
    }
}
