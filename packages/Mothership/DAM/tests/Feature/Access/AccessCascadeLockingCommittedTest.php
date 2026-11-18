<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\DAM\Tests\Support\InjectedFailure;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * The locking a sharing cascade takes (ADR 0010 and ADR 0004, sharing slice 3), proved from a second
 * session. `lockForUpdate()` is a no-op on SQLite, so PostgreSQL is where this contract is observable and
 * every other driver skips.
 *
 * Three waits, in the lock order the ADR gives — the DAM_ACCESS_MODEL row, then the directory tree lock,
 * then the subtree's folder rows:
 * 1. A holder on a subtree folder row (FOR SHARE) blocks the cascade, which then rewrites that folder once
 *    the holder commits. Sharing slice 2's inherit-on-create relies on exactly this.
 * 2. A switch's transition, holding the state row FOR UPDATE, blocks a list writer's shared hold.
 * 3. A list writer's shared hold blocks a transition.
 *
 * No sleeps decide anything: each case takes its barrier explicitly on a second connection, bounds the wait
 * with PostgreSQL's `lock_timeout`, and asserts both that the blocked session really waited and that it
 * wrote nothing. Each wait is also shown to come from the lock and not from the statement: the same rows
 * read without a lock come back at once while the holder still holds it, which is what the cascade would do
 * if its reads were not locking reads.
 *
 * These rows are committed (DamConcurrencyTestCase, no DatabaseTransactions wrapper), so tearDown removes
 * them and puts the state row back as it found it.
 */
final class AccessCascadeLockingCommittedTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'access_cascade_holder';

    /** The bound on every wait, and the floor the blocked session must have spent waiting. */
    private const TIMEOUT_MS = 300;

    private const WAITED_SECONDS = 0.25;

    /** PostgreSQL's lock_not_available: the session waited for the holder and gave up. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    private bool $prepared = false;

    private ?Directory $root = null;

    private ?Directory $child = null;

    private ?Directory $leaf = null;

    private ?Asset $file = null;

    private ?Admin $owner = null;

    private ?Admin $first = null;

    private ?Admin $second = null;

    private ?Role $role = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('lockForUpdate() is a no-op on SQLite and the waits are bounded with PostgreSQL lock_timeout; other drivers skip.');
        }

        config(['database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection())]);

        // Every row from here on is this test's own, fixtures included: the teardown removes them itself and
        // the residue check removes and reports anything it missed. Recording it after the fixtures made their
        // deliberate removal look like residue, in the negative.
        $this->recordResidueBaseline();

        // Captured before the line below changes it, and put back from cleanUpCommittedFixtures() on every
        // teardown path — including the one a setUp that throws from here on takes.
        $this->recordAccessModelState();
        $this->setAccessModelState(AccessModel::LISTS);

        InjectedFailure::at('after the access-model state changed');

        $suffix = Str::uuid();
        $this->root = Directory::create(['name' => 'Cascade lock root '.$suffix]);
        $this->child = Directory::create(['name' => 'Cascade lock child '.$suffix, 'parent_id' => $this->root->id]);
        $this->leaf = Directory::create(['name' => 'Cascade lock leaf '.$suffix, 'parent_id' => $this->child->id]);
        $this->file = Asset::factory()->create(['file_name' => 'cascade-lock-'.$suffix.'.jpg']);
        $this->file->directories()->attach($this->leaf->id);

        // Creating a child shifts its ancestors' _rgt in the database, not on a model already in memory, so
        // the root still carries its creation-time bounds ([1,2] for an empty root) until it is read again.
        // Every subtree query here derives from these bounds, so a stale root silently shrinks the subtree to
        // the root alone and the locking proofs pass without ever reaching a descendant (Codex review of
        // PR #69, finding 4).
        $this->root->refresh();
        $this->child->refresh();
        $this->leaf->refresh();

        // The race cases go through set(), which needs an owner and two real principals to choose between.
        $this->role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);
        $this->owner = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        $this->first = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        $this->second = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        DB::table('dam_assets')->where('id', $this->file->id)->update(['created_by' => $this->owner->id]);

        foreach ([$this->root, $this->child, $this->leaf] as $directory) {
            DB::table('dam_directories')->where('id', $directory->id)->update(['created_by' => $this->owner->id]);
        }

        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        // A skipped case configured nothing to unwind. A setUp that threw part way through did, though, and
        // its rows are committed, so the sweep still runs.
        if (! $this->prepared) {
            try {
                $this->cleanUpCommittedFixtures();
            } finally {
                parent::tearDown();
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

            foreach (['dam_asset_user_access', 'dam_asset_group_access'] as $table) {
                DB::table($table)->where('asset_id', $this->file->id)->delete();
            }

            $this->file->directories()->detach();
            Asset::query()->whereKey($this->file->id)->forceDelete();

            foreach ([$this->leaf, $this->child, $this->root] as $directory) {
                foreach (['dam_directory_user_access', 'dam_directory_group_access'] as $table) {
                    DB::table($table)->where('directory_id', $directory->id)->delete();
                }

                Directory::find($directory->id)?->delete();
            }

            foreach ([$this->second, $this->first, $this->owner] as $admin) {
                Admin::query()->whereKey($admin->id)->delete();
            }

            Role::query()->whereKey($this->role->id)->delete();

            DB::purge(self::HOLDER);

        } finally {
            // Restores the shared state and sweeps whatever is left, failing if anything is: a row, a blob or an
            // access model this test changed is another CI step's failure on the same database.
            $this->cleanUpCommittedFixtures();
            parent::tearDown();
        }
    }

    public function test_a_holder_on_a_subtree_folder_blocks_the_cascade_until_it_commits(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // The barrier: a reader of one folder inside the subtree, exactly what slice 2 takes while it gives a
        // new item its parent's list.
        $this->assertSame(
            $this->leaf->id,
            (int) $holder->table('dam_directories')->where('id', $this->leaf->id)->sharedLock()->value('id')
        );

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait comes from the cascade's locking read, not from reading these rows: the same subtree read
        // without a lock returns at once while the holder still holds it.
        $this->assertCount(3, DB::table('dam_directories')
            ->whereBetween('_lft', [$this->root->_lft, $this->root->_rgt])
            ->orderBy('_lft')
            ->pluck('id'));

        $admin = (int) DB::table('admins')->orderBy('id')->value('id');
        $started = microtime(true);

        try {
            app(AccessListService::class)->applyToSubtree(directoryId: $this->root->id, userIds: [$admin], groupIds: []);
            $this->fail('the cascade did not wait for the folder row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the cascade did not wait for the lock');
        $this->assertSame(0, DB::table('dam_directory_user_access')->where('directory_id', $this->leaf->id)->count(), 'the cascade wrote rows while another session held the folder');

        $holder->rollBack();

        // Once the holder is gone the same cascade rewrites that folder and the file inside it.
        $counts = app(AccessListService::class)->applyToSubtree(directoryId: $this->root->id, userIds: [$admin], groupIds: []);

        $this->assertSame(['folders' => 3, 'files' => 1], $counts);
        $this->assertSame(1, DB::table('dam_directory_user_access')->where('directory_id', $this->leaf->id)->where('admin_id', $admin)->count());
        $this->assertSame(1, DB::table('dam_asset_user_access')->where('asset_id', $this->file->id)->where('admin_id', $admin)->count());
    }

    public function test_a_transition_holding_the_state_row_blocks_a_list_writer(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // A switch or rebuild just after AccessModel::transactionally() locked the state row.
        $this->assertSame(AccessModel::LISTS, $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value'));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // Again, the wait is the lock's: a plain read of the same row answers immediately.
        $this->assertSame(AccessModel::LISTS, DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'));

        $model = new AccessModel;
        $ran = false;
        $started = microtime(true);

        try {
            $model->sharedly(function () use (&$ran) {
                $ran = true;
            });
            $this->fail('the list writer did not wait for the state row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertFalse($ran, 'the writer ran its body without the shared hold');
        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the writer did not wait for the lock');

        $holder->rollBack();

        $this->assertSame(AccessModel::LISTS, $model->sharedly(fn (?string $raw) => $raw));
    }

    public function test_a_list_writers_hold_blocks_a_transition(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // The hold every list write takes as its first statement (AccessModel::sharedly()).
        $this->assertSame(AccessModel::LISTS, $holder->table('dam_configuration')->where('key', AccessModel::KEY)->sharedLock()->value('value'));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $model = new AccessModel;
        $started = microtime(true);

        try {
            $model->transactionally(fn (?string $raw) => $model->compareAndSet(expected: $raw, value: AccessModel::GRANTS));
            $this->fail('the transition did not wait for the writer\'s shared hold');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the transition did not wait for the hold');

        $holder->rollBack();

        $this->assertSame(AccessModel::LISTS, DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'));
    }

    public function test_two_saves_of_one_empty_file_list_cannot_both_win(): void
    {
        // The writer that got there first: it holds the file at stage 4 and has replaced its empty list.
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('dam_assets')->where('id', $this->file->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->where('asset_id', $this->file->id)->delete();
        $holder->table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->first->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait below is the row lock's: the same row read without one answers at once.
        $this->assertSame($this->file->id, (int) DB::table('dam_assets')->where('id', $this->file->id)->value('id'));

        $started = microtime(true);

        try {
            $this->save(principal: $this->second->id);
            $this->fail('the second save did not wait for the first writer\'s row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the second save did not wait');
        // It wrote nothing: without the lock it would have deleted an invisible row and inserted its own.
        $this->assertSame([], $this->listed());

        $holder->commit();

        // One complete replacement, the first writer's.
        $this->assertSame([$this->first->id], $this->listed());

        // The second save, retried, is the other complete replacement — never the union of the two.
        $this->save(principal: $this->second->id);
        $this->assertSame([$this->second->id], $this->listed());
    }

    public function test_a_cascade_cannot_interleave_with_a_save_of_a_file_it_covers(): void
    {
        // A file save that has taken stage 4 on the one file and replaced its empty list.
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('dam_assets')->where('id', $this->file->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->where('asset_id', $this->file->id)->delete();
        $holder->table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->first->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The folders are free; only the file is held, so a cascade that skipped stage 4 would sail through.
        $this->assertCount(3, DB::table('dam_directories')
            ->whereBetween('_lft', [$this->root->_lft, $this->root->_rgt])
            ->pluck('id'));

        $started = microtime(true);

        try {
            app(AccessListService::class)->applyToSubtree(directoryId: $this->root->id, userIds: [$this->second->id], groupIds: []);
            $this->fail('the cascade did not wait for the file row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the cascade did not wait');
        $this->assertSame([], $this->listed());
        $this->assertSame(0, DB::table('dam_directory_user_access')->where('directory_id', $this->leaf->id)->count(), 'the cascade wrote folders while the file was held');

        $holder->commit();

        $this->assertSame([$this->first->id], $this->listed());

        // The cascade, retried, replaces that file's list completely rather than adding to it.
        $counts = app(AccessListService::class)->applyToSubtree(directoryId: $this->root->id, userIds: [$this->second->id], groupIds: []);

        $this->assertSame(['folders' => 3, 'files' => 1], $counts);
        $this->assertSame([$this->second->id], $this->listed());
    }

    /** The Share dialog's own path: the owner replaces the file's list with exactly one person. */
    private function save(int $principal): void
    {
        app(AccessListService::class)->set(
            actor: $this->owner,
            type: 'asset',
            id: $this->file->id,
            mode: AccessListService::PEOPLE,
            userIds: [$principal],
            groupIds: [],
        );
    }

    /** @return list<int> the admin ids on the fixture file's list, from this session */
    private function listed(): array
    {
        return DB::table('dam_asset_user_access')
            ->where('asset_id', $this->file->id)
            ->orderBy('admin_id')
            ->pluck('admin_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
