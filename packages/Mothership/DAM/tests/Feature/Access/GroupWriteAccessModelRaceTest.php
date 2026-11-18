<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Http\Controllers\GroupController;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Codex review of PR #72, finding 1: GroupController::save() and destroy() used to decide whether a
 * generated role group was read-only from an unlocked read of the access model (AccessModel::lists())
 * taken before AccessModel::sharedly() ever ran, and the callback inside sharedly() then wrote
 * unconditionally, never consulting the value the shared hold had actually locked. A `dam:access:switch
 * grants` that commits while the request is between that read and the hold — it needs no maintenance mode,
 * unlike the lists-ward flip — could land a delete or a rename of a generated role group under grants that
 * the post-switch model would refuse.
 *
 * The fix moves the decision inside sharedly()'s callback, deciding from AccessModel::modelFor($raw) on the
 * value the hold itself locked. Two real OS processes on PostgreSQL prove it, following
 * AccessCascadeLockingCommittedTest's barrier pattern (round-2 re-review: a fixed sleep on each side proves
 * nothing about scheduling, and an elapsed-time floor measures the whole controller call, not specifically
 * that sharedly() waited): the child locks the state row FOR UPDATE and signals once it holds it; the parent
 * then makes its destroy()/save() call, which blocks on that same row; the child polls pg_blocking_pids()
 * for the parent's own backend pid until it names the child's backend as a blocker — proof the parent is
 * actually stuck on this lock, not merely slow — and only then flips the value to `grants` and commits.
 * Every wait is a bounded poll, never a sleep that fabricates the proof. `lockForUpdate()`/`sharedLock()`
 * are no-ops on SQLite, so every other driver skips.
 *
 * Round-3 re-review, finding 1: everything the forked child does — including DB::purge()/reconnect() —
 * now runs inside one try/finally, so an exception anywhere (a failed reconnect included) still terminates
 * the child in the finally rather than letting it fall back out of the fork point and run the rest of this
 * PHPUnit process a second time (test_a_child_that_fails_before_locking_never_runs_teardown_and_is_reaped
 * proves it). Finding 2: cleanup no longer gates on every setup step having finished — each fixture is
 * removed only if it exists, and the state row is restored whenever it was captured, whatever setUp()
 * managed to reach (test_cleanup_removes_only_the_fixtures_that_exist_when_setup_stops_partway proves it).
 */
final class GroupWriteAccessModelRaceTest extends DamConcurrencyTestCase
{
    /** The bound on every poll: the child's barrier wait and the reap after a stuck process. */
    private const BARRIER_SECONDS = 10;

    /** The parent's guard against a lock that never releases, wider than any bounded poll above it. */
    private const LOCK_TIMEOUT_MS = 12_000;

    /** True once the state row has been read and (if present) captured, whatever setUp() does after. */
    private bool $stateCaptured = false;

    private bool $hadStateRow = false;

    private ?string $previousState = null;

    private ?Admin $actor = null;

    private ?Role $role = null;

    private ?Role $memberRole = null;

    private ?int $roleGroupId = null;

    private ?string $resultDir = null;

    /** Tracked so tearDown() can always reap a child, whatever the test body did. */
    private ?int $childPid = null;

    /** Set by raceStateFlipAgainst(), read by assertRefusedAndSurvived(). */
    private ?HttpException $refused = null;

    /** Anything the controller call threw that was not an HttpException. */
    private ?Throwable $unexpected = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('lockForUpdate()/sharedLock() are no-ops on SQLite; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $row = DB::table('dam_configuration')->where('key', AccessModel::KEY)->first();
        $this->hadStateRow = $row !== null;
        $this->previousState = $row?->value;
        // Captured before anything is mutated or created: everything from here on is optional as far as
        // cleanup is concerned, but this capture, once made, is always honoured (round-3 finding 2).
        $this->stateCaptured = true;

        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
        app(AccessModel::class)->flush();

        $this->role = Role::factory()->create(['permission_type' => 'all']);
        $this->actor = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        $this->actingAs($this->actor, 'admin');

        $this->memberRole = Role::factory()->create(['permission_type' => 'custom']);
        $this->roleGroupId = (int) DB::table('dam_groups')->insertGetId([
            'name'       => 'Race role group '.$this->memberRole->id,
            'system_key' => 'role:'.$this->memberRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->resultDir = sys_get_temp_dir().'/group-write-race-'.Str::uuid();
        mkdir($this->resultDir);
    }

    protected function tearDown(): void
    {
        // Whatever markTestSkipped's driver/pcntl checks stopped, nothing below ran and there is nothing
        // to restore or remove.
        if (! $this->stateCaptured) {
            parent::tearDown();

            return;
        }

        // Before anything else: a child must never be left running or holding a lock while cleanup
        // restores shared, committed database state (round-2 re-review, finding 2).
        $this->reapChildIfAny();

        $this->cleanupFixtures();

        parent::tearDown();
    }

    public function test_a_switch_committing_lists_to_grants_while_destroy_waits_never_deletes_a_role_group(): void
    {
        try {
            $this->raceStateFlipAgainst(fn () => app(GroupController::class)->destroy($this->roleGroupId));
        } finally {
            $this->reapChildIfAny();
        }

        $this->assertRefusedAndSurvived();
        $this->assertDatabaseHas('dam_groups', ['id' => $this->roleGroupId]);
    }

    public function test_a_switch_committing_lists_to_grants_while_save_waits_never_renames_a_role_group(): void
    {
        $request = Request::create('/admin/settings/groups/'.$this->roleGroupId, 'PUT', [
            'name'       => 'Renamed mid-switch',
            'member_ids' => [],
        ]);

        try {
            $this->raceStateFlipAgainst(fn () => app(GroupController::class)->save($request, $this->roleGroupId));
        } finally {
            $this->reapChildIfAny();
        }

        $this->assertRefusedAndSurvived();
        $this->assertDatabaseMissing('dam_groups', ['id' => $this->roleGroupId, 'name' => 'Renamed mid-switch']);
    }

    /**
     * Round-4 re-review, finding 1: the round-3 version of this test forked its own child with a separate
     * try/finally instead of going through forkChild() — the actual boundary raceStateFlipAgainst() uses —
     * so it proved the termination of a duplicated branch, not the one the production race tests exercise.
     * It now calls forkChild() itself, injecting the failure via $beforeReconnect, which runs inside the
     * same try as forkChild()'s own DB::purge()/reconnect(): moving that reconnect back outside the try
     * (the round-3 finding 1 shape) makes this fixture fail again — see the fix's commit message for that
     * control run. The child must record the failure and die, and — the actual hazard finding 1 named —
     * never reach this test's own tearDown() and delete the fixtures the real process is still using.
     */
    public function test_a_child_that_fails_before_locking_never_runs_teardown_and_is_reaped(): void
    {
        $pid = $this->forkChild(
            beforeReconnect: function () {
                // Thrown from exactly where round-3 finding 1 was about: before this shared boundary's own
                // DB::purge()/reconnect() run at all. A bad connection config was tried first, but
                // DB::reconnect() only resolves a PDO connection lazily on the next query, so the actual
                // throw always landed inside $work() regardless of where purge/reconnect sat — a control
                // that moved them outside the try still passed. A direct throw here is unambiguous.
                throw new RuntimeException('injected failure before reconnect');
            },
            work: fn () => null,
        );

        DB::reconnect();
        $this->reapChildIfAny();

        $childError = is_file($this->resultDir.'/child-error') ? file_get_contents($this->resultDir.'/child-error') : null;
        $this->assertNotNull($childError, 'the injected failure should have made the child fail');
        $this->assertStringContainsString('injected failure before reconnect', (string) $childError);
        $this->assertFileDoesNotExist($this->resultDir.'/child-done');

        // The proof finding 1 is about: had the child escaped its exception boundary and fallen back into
        // PHPUnit, it would have run this test's own tearDown() in its own process and deleted the
        // fixtures setUp() created here — while this, the real process, is still using them.
        $this->assertDatabaseHas('dam_groups', ['id' => $this->roleGroupId]);
        $this->assertDatabaseHas('admins', ['id' => $this->actor->id]);
        $this->assertDatabaseHas('roles', ['id' => $this->role->id]);
    }

    /**
     * Round-3 re-review, finding 2: cleanup must not assume every setup step ran. Stands in for setUp()
     * throwing right after the state row was captured and mutated and one fixture had already committed,
     * before anything else — no actor, no member role, no group, no result dir. A second, independent
     * instance of this same test class (never run by PHPUnit, so its own setUp()/tearDown() never fire)
     * holds exactly that partial state and runs the private cleanup this test's real tearDown() also uses;
     * this test's own fixtures, built by its own setUp(), are untouched and are cleaned normally afterwards.
     */
    public function test_cleanup_removes_only_the_fixtures_that_exist_when_setup_stops_partway(): void
    {
        try {
            // Two captures a real setUp() could have made, neither of them the value cleanup will find
            // sitting there: a previously-GRANTS row (restore must change it back, not find it already
            // right), and no row at all (deletion must remove a row that genuinely exists). Round-4
            // re-review, finding 2: the original version captured LISTS and left the row at LISTS, so it
            // passed even with state restoration removed from cleanupFixtures() entirely, and never
            // exercised the absent-row delete.
            foreach ([[true, AccessModel::GRANTS], [false, null]] as [$hadStateRowBefore, $previousStateBefore]) {
                $hadStateRowBefore
                    ? DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $previousStateBefore])
                    : DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
                app(AccessModel::class)->flush();

                $partialRole = Role::factory()->create(['permission_type' => 'all']);

                $partial = new self('test_cleanup_removes_only_the_fixtures_that_exist_when_setup_stops_partway');
                $partial->hadStateRow = $hadStateRowBefore;
                $partial->previousState = $previousStateBefore;
                $partial->stateCaptured = true;
                $partial->role = $partialRole;
                // Left null on $partial, matching setUp() having stopped here: actor, memberRole,
                // roleGroupId, resultDir. cleanupFixtures() must skip all of those rather than assume they
                // exist.

                // What a partially-run setUp() actually leaves behind: it always writes LISTS immediately
                // after capturing, whatever it captured, before doing anything else that might fail.
                DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
                app(AccessModel::class)->flush();

                $partial->cleanupFixtures();

                $this->assertDatabaseMissing('roles', ['id' => $partialRole->id]);
                $state = DB::table('dam_configuration')->where('key', AccessModel::KEY)->first();
                $hadStateRowBefore ? $this->assertSame($previousStateBefore, $state?->value) : $this->assertNull($state);
            }
        } finally {
            // Round-5 re-review, finding 1: the matrix's absent-row case deletes the real, shared state
            // row — the one this test's own setUp() captured, not the matrix's simulated capture. Restored
            // here with the same updateOrInsert() the fix applies in cleanupFixtures(), so this test leaves
            // the row exactly as its own setUp() found it, whatever the matrix did to it in between.
            $this->hadStateRow
                ? DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $this->previousState])
                : DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
            app(AccessModel::class)->flush();
        }

        // Proof, not an assumption: the outer fixture's original row and value are still there after both
        // matrix cases ran and after the finally above — the actual thing round-5 finding 1 is about.
        $state = DB::table('dam_configuration')->where('key', AccessModel::KEY)->first();
        $this->hadStateRow
            ? $this->assertSame($this->previousState, $state?->value, "the outer fixture's captured state must survive the matrix")
            : $this->assertNull($state, "the outer fixture's captured absence must survive the matrix");
    }

    /**
     * Runs $call — a destroy() or a save() on the fixture role group — while a forked child holds the
     * DAM_ACCESS_MODEL row FOR UPDATE, confirms $call's backend is genuinely blocked on that hold via
     * pg_blocking_pids(), and only then flips the value to `grants` and commits. Sets $this->refused /
     * $this->unexpected for the caller to assert on.
     *
     * @param  Closure(): void  $call
     */
    private function raceStateFlipAgainst(Closure $call): void
    {
        $this->refused = null;
        $this->unexpected = null;

        $this->forkChild(work: function () {
            $childPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            DB::transaction(function () use ($childPid) {
                DB::table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->first();
                $this->signal('locked', $childPid);

                $parentPid = $this->awaitSignal('parent-pid', "the parent's real backend pid");

                // Proof, not a guess: the parent's own backend must show this child as a blocker before
                // the child moves on. A stale or unrelated wait on the parent's side would never satisfy
                // this, where a fixed sleep on each side could pass by luck of scheduling.
                $this->pollUntil(
                    fn (): ?bool => in_array($childPid, $this->blockingPids($parentPid), true) ? true : null,
                    "the parent backend {$parentPid} to be blocked by this transaction"
                );

                DB::table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => AccessModel::GRANTS]);
            });
        });

        // From here on a child exists and must be reaped, however $call behaves.
        DB::reconnect();
        $this->signal('parent-pid', (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
        $this->awaitSignal('locked', 'the child to lock the state row');

        DB::statement("SET lock_timeout = '".self::LOCK_TIMEOUT_MS."ms'");

        try {
            $call();
        } catch (HttpException $e) {
            $this->refused = $e;
        } catch (Throwable $e) {
            $this->unexpected = $e;
        }
    }

    /**
     * Forks a child that runs $beforeReconnect() (default: nothing), then this shared boundary's own
     * DB::purge()/reconnect(), then $work() — all inside one try/finally, so any exception anywhere in
     * that sequence still terminates the child rather than letting it fall back out of this method and run
     * the rest of this PHPUnit process a second time (round-3 re-review, finding 1). Both
     * raceStateFlipAgainst() and test_a_child_that_fails_before_locking_never_runs_teardown_and_is_reaped()
     * go through this one boundary — round-4 re-review, finding 1 — so a regression that moves the
     * purge/reconnect back outside the try affects both callers identically, and the reconnect-failure test
     * actually exercises the code path the race tests depend on. Sets $this->childPid; the caller
     * reconnects its own connection before touching the database again.
     */
    private function forkChild(Closure $work, ?Closure $beforeReconnect = null): int
    {
        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            try {
                $beforeReconnect?->__invoke();
                DB::purge();
                DB::reconnect();

                $work();

                file_put_contents($this->resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($this->resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
                exit(0);
            }
        }

        $this->childPid = $pid;

        return $pid;
    }

    /** Common assertions for both race tests: the child ran clean, and the call was refused, not thrown some other way. */
    private function assertRefusedAndSurvived(): void
    {
        $childError = is_file($this->resultDir.'/child-error') ? file_get_contents($this->resultDir.'/child-error') : null;
        $this->assertNull($childError, 'the forked state flipper threw: '.(string) $childError);
        $this->assertFileExists($this->resultDir.'/child-done', 'the forked state flipper never completed');

        $this->assertNull($this->unexpected, 'the controller call threw something other than a refusal: '.($this->unexpected !== null ? get_class($this->unexpected).': '.$this->unexpected->getMessage() : ''));

        // The decision is the fixed code's: made from the value the hold itself locked, which by the time
        // the call got in was already `grants`. Reverting the fix to decide before the hold — or to write
        // unconditionally once inside sharedly() — makes this assertion fail: the group is deleted or
        // renamed instead of refused.
        $this->assertNotNull($this->refused, 'a role group was written under grants a switch had just committed');
        $this->assertSame(422, $this->refused->getStatusCode());
    }

    /** The pids currently blocking backend $pid, from pg_blocking_pids(), parsed out of Postgres's array literal. */
    private function blockingPids(int $pid): array
    {
        $blockers = DB::selectOne('SELECT pg_blocking_pids(?) AS blockers', [$pid])->blockers;

        $trimmed = trim((string) $blockers, '{}');

        return $trimmed === '' ? [] : array_map('intval', explode(',', $trimmed));
    }

    /** Tell the other process `$name` happened, naming this side's value. Written whole, then renamed into place. */
    private function signal(string $name, int $value): void
    {
        file_put_contents($this->resultDir.'/'.$name.'.tmp', (string) $value);
        rename($this->resultDir.'/'.$name.'.tmp', $this->resultDir.'/'.$name);
    }

    /** Wait for the other process's `$name` signal, bounded. Fails at once if the child already errored. */
    private function awaitSignal(string $name, string $what): int
    {
        return $this->pollUntil(function () use ($name): ?int {
            if (is_file($this->resultDir.'/child-error')) {
                throw new RuntimeException('the forked child failed first: '.file_get_contents($this->resultDir.'/child-error'));
            }

            return is_file($this->resultDir.'/'.$name) ? (int) file_get_contents($this->resultDir.'/'.$name) : null;
        }, $what);
    }

    /**
     * Poll `$probe` until it returns non-null, for at most BARRIER_SECONDS. Every wait in this test goes
     * through here or through PostgreSQL's own lock_timeout — never a sleep standing in for a real signal.
     *
     * @template T
     *
     * @param  Closure(): (T|null)  $probe
     * @return T
     */
    private function pollUntil(Closure $probe, string $what): mixed
    {
        $deadline = microtime(true) + self::BARRIER_SECONDS;

        do {
            $result = $probe();

            if ($result !== null) {
                return $result;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf('Timed out after %ds waiting for %s.', self::BARRIER_SECONDS, $what));
    }

    /** Reap a tracked forked child if one exists: bounded wait, then kill if it is still running. Idempotent. */
    private function reapChildIfAny(): void
    {
        if ($this->childPid === null) {
            return;
        }

        $pid = $this->childPid;
        $this->childPid = null;

        $deadline = microtime(true) + self::BARRIER_SECONDS;

        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);

                $this->fail('The forked state flipper was still running after '.self::BARRIER_SECONDS.'s; killed it.');
            }

            usleep(10_000);
        }
    }

    /**
     * Removes exactly the fixtures that exist and restores the state row if it was captured — never
     * assuming every step of setUp() ran (round-3 re-review, finding 2). Shared by tearDown() and by
     * test_cleanup_removes_only_the_fixtures_that_exist_when_setup_stops_partway(), which calls it on a
     * second, otherwise-unused instance holding a deliberately partial state.
     */
    private function cleanupFixtures(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('RESET lock_timeout');

        if ($this->roleGroupId !== null) {
            DB::table('dam_group_members')->where('group_id', $this->roleGroupId)->delete();
            DB::table('dam_groups')->where('id', $this->roleGroupId)->delete();
        }

        if ($this->actor !== null) {
            Admin::query()->whereKey($this->actor->id)->delete();
        }

        if ($this->role !== null) {
            Role::query()->whereKey($this->role->id)->delete();
        }

        if ($this->memberRole !== null) {
            Role::query()->whereKey($this->memberRole->id)->delete();
        }

        // updateOrInsert(), not update(): the row this captured may have been deleted since (the
        // absent-row iteration of test_cleanup_removes_only_the_fixtures_that_exist_when_setup_stops_partway
        // deletes the real, shared row), and update() against a row that no longer exists silently affects
        // nothing — permanently losing whatever value this test's setUp() found there (round-5 re-review,
        // finding 1).
        $this->hadStateRow
            ? DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $this->previousState])
            : DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();

        app(AccessModel::class)->flush();

        if ($this->resultDir !== null) {
            foreach (glob($this->resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->resultDir);
        }
    }
}
