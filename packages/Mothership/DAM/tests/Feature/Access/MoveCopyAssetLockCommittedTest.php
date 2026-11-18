<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessMoveGuard;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * Codex review of PR #75, findings 1 and 2: the directory tree lock (ADR 0004) keeps a folder's own
 * list from changing underneath a move, delete or copy, but AccessListService::rewriteAsset() locks
 * only the one file row it rewrites, never the tree lock — so a revocation of a single file's access
 * can commit mid-operation unless that file's row is *also* locked before the operation trusts what it
 * read about whether the actor can still see it.
 *
 * Proved from a second session, on PostgreSQL only: lockForUpdate() and lockedForGraph()'s FOR NO KEY
 * UPDATE are no-ops on SQLite, and the waits below are bounded with PostgreSQL's lock_timeout so a
 * broken lock order fails fast instead of hanging CI.
 *
 * Each case takes the same row lock AccessListService::rewriteAsset() takes on a second connection,
 * before deleting the actor's grant — the revocation in flight, not yet committed — then shows the
 * fixed code (AccessMoveGuard::checkLocked() for move/delete, CopyDirectory's lock-then-revalidate for
 * copy) waits for that lock rather than reading around it, and answers from the committed state once
 * unblocked. Reverting either fix to a plain unlocked read (isBlocked() alone, or trusting the first,
 * unlocked accessibleAssetIds() filter) makes the "waited" assertion fail: an unlocked read never
 * blocks on the holder at all.
 */
final class MoveCopyAssetLockCommittedTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'move_copy_asset_lock_holder';

    private const TIMEOUT_MS = 300;

    private const WAITED_SECONDS = 0.25;

    /** PostgreSQL's lock_not_available: the session waited for the holder and gave up. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    private bool $prepared = false;

    private ?Directory $folder = null;

    private ?Directory $target = null;

    private ?Asset $asset = null;

    private ?Admin $actor = null;

    private ?Role $role = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('lockForUpdate() and lockedForGraph() are no-ops on SQLite and the waits are bounded with PostgreSQL lock_timeout; other drivers skip.');
        }

        config(['database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection())]);

        $this->recordResidueBaseline();
        $this->recordAccessModelState();
        $this->setAccessModelState(AccessModel::LISTS);

        $suffix = (string) Str::uuid();
        $this->folder = Directory::create(['name' => 'Lock race folder '.$suffix]);
        $this->target = Directory::create(['name' => 'Lock race target '.$suffix]);
        $this->asset = Asset::factory()->create(['file_name' => 'lock-race-'.$suffix.'.jpg']);
        $this->asset->directories()->attach($this->folder->id);

        $this->role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => [
            'dam', 'dam.asset', 'dam.asset.moved', 'dam.directory', 'dam.directory.moved',
        ]]);
        $this->actor = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);

        DB::table('dam_directory_user_access')->insert([
            ['directory_id' => $this->folder->id, 'admin_id' => $this->actor->id],
            ['directory_id' => $this->target->id, 'admin_id' => $this->actor->id],
        ]);
        DB::table('dam_asset_user_access')->insert(['asset_id' => $this->asset->id, 'admin_id' => $this->actor->id]);

        $this->prepared = true;
    }

    protected function tearDown(): void
    {
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
                DB::table($table)->where('asset_id', $this->asset->id)->delete();
            }

            $this->asset->directories()->detach();
            Asset::query()->whereKey($this->asset->id)->forceDelete();

            // Whatever the copy case created under $target, deepest-first (children before the copy root).
            Directory::query()
                ->where('parent_id', $this->target->id)
                ->orderByDesc('_lft')
                ->get()
                ->each(function (Directory $copy) {
                    DB::table('dam_asset_directory')->where('directory_id', $copy->id)->delete();
                    Directory::find($copy->id)?->delete();
                });

            foreach ([$this->folder, $this->target] as $directory) {
                foreach (['dam_directory_user_access', 'dam_directory_group_access'] as $table) {
                    DB::table($table)->where('directory_id', $directory->id)->delete();
                }

                Directory::find($directory->id)?->delete();
            }

            Admin::query()->whereKey($this->actor->id)->delete();
            Role::query()->whereKey($this->role->id)->delete();

            DB::purge(self::HOLDER);
        } finally {
            $this->cleanUpCommittedFixtures();
            parent::tearDown();
        }
    }

    public function test_move_delete_guard_waits_for_a_revocation_in_flight_and_sees_it_once_committed(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // The row lock AccessListService::rewriteAsset() takes before it deletes the actor's grant.
        $holder->table('dam_assets')->where('id', $this->asset->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->where('asset_id', $this->asset->id)->delete();

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait comes from the row lock, not from reading it: a plain read of the same row answers now.
        $this->assertSame($this->asset->id, (int) DB::table('dam_assets')->where('id', $this->asset->id)->value('id'));

        $started = microtime(true);

        try {
            app(AccessMoveGuard::class)->checkLocked(admin: $this->actor, directoryId: $this->folder->id);
            $this->fail("checkLocked() did not wait for the revocation holder's row lock");
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'checkLocked() did not wait for the lock');

        $holder->commit();

        // The revocation is now committed; checkLocked() reads it fresh and refuses the move.
        $result = app(AccessMoveGuard::class)->checkLocked(admin: $this->actor, directoryId: $this->folder->id);

        $this->assertTrue($result['blocked'], 'checkLocked() missed a revocation that committed while it waited');
    }

    public function test_copy_waits_for_a_revocation_in_flight_and_skips_the_file_once_committed(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('dam_assets')->where('id', $this->asset->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->where('asset_id', $this->asset->id)->delete();

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $this->assertSame($this->asset->id, (int) DB::table('dam_assets')->where('id', $this->asset->id)->value('id'));

        $started = microtime(true);

        // handle() catches every \Throwable itself (markFailed()), so the timeout never escapes here — the
        // proof is in the timing and in what got left behind, not a caught exception.
        (new CopyDirectory($this->folder->id, $this->target->id, $this->actor->id))->handle();

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'CopyDirectory did not wait for the asset lock');
        $this->assertSame(0, Directory::where('parent_id', $this->target->id)->count(), 'CopyDirectory left a copy behind despite failing on the lock timeout');

        $holder->commit();

        (new CopyDirectory($this->folder->id, $this->target->id, $this->actor->id))->handle();

        $copy = Directory::where('parent_id', $this->target->id)->first();
        $this->assertNotNull($copy, 'the retried copy did not run at all');
        $this->assertSame(0, $copy->assets()->count(), 'the copy included a file whose access was revoked while the copy waited for its lock');
    }
}
