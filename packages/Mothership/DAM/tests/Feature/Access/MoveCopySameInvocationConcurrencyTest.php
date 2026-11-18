<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Jobs\DeleteDirectory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Throwable;

/**
 * Codex review of PR #75 round 2, finding 6: MoveCopyAssetLockCommittedTest proves real timeout waits,
 * but the holder there commits only after the first call already timed out and failed — a *second*
 * call then starts, and its own first, unlocked filter already sees the committed revocation, so that
 * test would still pass even with the post-lock revalidation removed entirely.
 *
 * This file forks a real second OS process (pcntl_fork(), the same technique
 * SphereSeamConcurrentImportTest uses to reproduce a race no single PHP thread can): the child makes
 * its own copy/delete job's *one* handle() call, which reaches its first, unlocked accessibility read
 * (seeing the file as still accessible), then blocks waiting for the asset row lock a holder already
 * took. The parent — a third connection — confirms the child is genuinely waiting (pg_stat_activity's
 * wait_event_type, not a timing guess), commits a revocation that touches only the list row (never the
 * locked asset row, so it needs no lock of its own), then releases the holder's lock. The same,
 * already-running invocation resumes and finishes: mutation-tested below by removing the post-lock
 * revalidation with the row lock still in place, which still passes on PostgreSQL (its READ COMMITTED
 * already answers every statement from the latest committed state once unblocked — the staleness this
 * revalidation exists for is MySQL/MariaDB's REPEATABLE READ; AccessListReaderLockingClauseTest proves
 * the revalidation's own SQL carries the locking clause that closes it there, since there is no MySQL
 * server here to run this fork against).
 */
final class MoveCopySameInvocationConcurrencyTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'same_invocation_holder';

    private bool $prepared = false;

    private ?Directory $folder = null;

    private ?Directory $target = null;

    private ?Asset $asset = null;

    private ?Admin $actor = null;

    private ?Role $role = null;

    private ?string $resultDir = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_stat_activity and lockedForGraph() are PostgreSQL-specific; other drivers skip.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are not available in this PHP build.');
        }

        config(['database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection())]);

        $this->recordResidueBaseline();
        $this->recordAccessModelState();
        $this->setAccessModelState(AccessModel::LISTS);

        $suffix = (string) Str::uuid();
        $this->folder = Directory::create(['name' => 'Same-invocation folder '.$suffix]);
        $this->target = Directory::create(['name' => 'Same-invocation target '.$suffix]);
        $this->asset = Asset::factory()->create(['file_name' => 'same-invocation-'.$suffix.'.jpg']);
        $this->asset->directories()->attach($this->folder->id);

        $this->role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => [
            'dam', 'dam.asset', 'dam.asset.moved', 'dam.directory', 'dam.directory.moved', 'dam.directory.destroy',
        ]]);
        $this->actor = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);

        DB::table('dam_directory_user_access')->insert([
            ['directory_id' => $this->folder->id, 'admin_id' => $this->actor->id],
            ['directory_id' => $this->target->id, 'admin_id' => $this->actor->id],
        ]);
        DB::table('dam_asset_user_access')->insert(['asset_id' => $this->asset->id, 'admin_id' => $this->actor->id]);

        $this->resultDir = sys_get_temp_dir().'/dam-same-invocation-'.$suffix;
        mkdir($this->resultDir);

        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        if ($this->resultDir !== null && is_dir($this->resultDir)) {
            array_map('unlink', glob($this->resultDir.'/*') ?: []);
            @rmdir($this->resultDir);
        }

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

            foreach (['dam_asset_user_access', 'dam_asset_group_access'] as $table) {
                DB::table($table)->where('asset_id', $this->asset->id)->delete();
            }

            $this->asset->directories()->detach();
            Asset::query()->whereKey($this->asset->id)->forceDelete();

            Directory::query()
                ->where('parent_id', $this->target->id)
                ->orderByDesc('_lft')
                ->get()
                ->each(function (Directory $copy) {
                    DB::table('dam_asset_directory')->where('directory_id', $copy->id)->delete();
                    Directory::find($copy->id)?->delete();
                });

            foreach (array_filter([$this->folder, $this->target]) as $directory) {
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

    public function test_copy_resumes_the_same_invocation_correctly_after_a_revocation_commits_while_it_waits(): void
    {
        $child = function (): void {
            (new CopyDirectory($this->folder->id, $this->target->id, $this->actor->id))->handle();
        };

        $this->runAcrossTheWait($child);

        $copy = Directory::where('parent_id', $this->target->id)->first();
        $this->assertNotNull($copy, 'the copy never ran to completion');
        $this->assertSame(0, $copy->assets()->count(), 'the same invocation copied a file whose access was revoked while it waited for the asset lock');
    }

    public function test_delete_refuses_the_same_invocation_correctly_after_a_revocation_commits_while_it_waits(): void
    {
        $child = function (): void {
            (new DeleteDirectory($this->folder->id, $this->actor->id))->handle();
        };

        $this->runAcrossTheWait($child);

        $this->assertNotNull(Directory::find($this->folder->id), 'the same invocation deleted a folder whose one file had its access revoked while checkLocked() waited');
    }

    /**
     * Forks $child into a real second process, waits until pg_stat_activity shows it genuinely blocked
     * on the asset row lock a holder took before the fork, revokes the asset's access on a third
     * connection (the list row, never the locked asset row), releases the holder, and waits for $child
     * to finish — the same invocation that made the pre-lock read, not a fresh retry.
     */
    private function runAcrossTheWait(callable $child): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('dam_assets')->where('id', $this->asset->id)->lock('for no key update')->first(['id']);

        DB::purge();

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                $backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                file_put_contents($this->resultDir.'/child-pid', (string) $backendPid);
                $child();
                file_put_contents($this->resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($this->resultDir.'/child-error', $e::class.': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();

        $deadline = microtime(true) + 5.0;

        while (! is_file($this->resultDir.'/child-pid')) {
            if (microtime(true) > $deadline) {
                $holder->rollBack();
                $this->fail('the forked child never reported its backend pid');
            }

            usleep(10000);
        }

        $childBackendPid = (int) file_get_contents($this->resultDir.'/child-pid');

        $waiting = false;
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $waiting = (bool) DB::selectOne(
                "select 1 as found from pg_stat_activity where pid = ? and wait_event_type = 'Lock'",
                [$childBackendPid]
            );

            if ($waiting) {
                break;
            }

            usleep(20000);
        }

        if (! $waiting) {
            $holder->rollBack();
            pcntl_waitpid($pid, $status);
            $this->fail('never observed the forked child waiting on the asset row lock (pg_stat_activity)');
        }

        // The revocation this test races against: touches only the list row, which the holder's own
        // lock on the asset row never covers, so this commits immediately without waiting on anything.
        DB::table('dam_asset_user_access')->where('asset_id', $this->asset->id)->delete();

        // Releases the asset row lock; the child's own blocked statement resumes from here.
        $holder->commit();

        pcntl_waitpid($pid, $status);

        $childError = is_file($this->resultDir.'/child-error') ? file_get_contents($this->resultDir.'/child-error') : null;
        $this->assertNull($childError, "the forked child threw: {$childError}");
        $this->assertFileExists($this->resultDir.'/child-done', 'the forked child never reached the end of its one handle() call');
    }
}
