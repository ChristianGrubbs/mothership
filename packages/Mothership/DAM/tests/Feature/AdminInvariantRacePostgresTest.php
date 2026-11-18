<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\Core\Models\Locale;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestTargetGuard;
use Throwable;

/**
 * Two changes that could each cost the last active full-access admin serialize on one lock (UX eval rev-C 3,
 * AdminRepository::guardingActiveFullAdmin()): each takes every all-access role row FOR UPDATE before its check, and
 * the check and the write share that transaction.
 *
 *  - Of the only two active full admins, A deactivates B while, in a second session, A moves themselves to a custom
 *    role. Each check alone passes (the other is still there), so without the lock both commit and nobody can
 *    manage the site. The step-down waits on the lock, then sees B gone and is refused with 422.
 *  - A turns B's all-access role custom while, in a second session, A steps down. The role downgrade holds the lock
 *    (taken inside RoleController::fenceRoleWrite()), the step-down waits on it, then finds nobody else left and is
 *    refused.
 *  - A full admin is moved to a custom role while their own request waits on the lock: a user update (password
 *    reset), delete or create, or a role promotion or full-access role create. The demotion either drops the
 *    route's permission or keeps it and only takes full access away. Once the request gets the lock it re-reads
 *    its actor and repeats the permission, rank and role-grant checks (RoleForm's rule included), and is refused
 *    with 403, leaving everything as it was (rev-C2 finding 2, rev-C3 findings 2 and 3).
 *
 * Every other active admin is deactivated for the race and restored afterwards, so the two fixtures really are the
 * last two. Needs two real PostgreSQL connections: the first writer's transaction is open (holding the lock) while
 * the second, in a forked process with its own connection, is observed blocked on it. SQLite drops FOR UPDATE and
 * serializes the whole database, so it cannot show either race.
 */
final class AdminInvariantRacePostgresTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    /** Read by Tests\CreatesApplication before the kernel boots: on PostgreSQL this class never boots providers unadmitted. */
    public const DEMANDS_GUARDED_LANE = true;

    private const SECOND = 'revc_second_writer';

    /** The fixture admins' password, generated per run, so one can confirm a change to themselves. */
    private string $password = '';

    /** @var list<int> */
    private array $deactivated = [];

    private int $auditsBefore = 0;

    public function test_a_full_admin_stepping_down_while_their_only_peer_is_deactivated_is_refused(): void
    {
        $this->admitRace();

        try {
            $role = Role::create(['name' => 'RevC All '.uniqid(), 'permission_type' => 'all']);
            $custom = Role::create(['name' => 'RevC Custom '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['dashboard']]);
            $actor = $this->fullAdmin('revc-a', $role);
            $peer = $this->fullAdmin('revc-b', $role);
            $this->leaveOnly([$actor->id, $peer->id]);

            $this->race(
                second: function () use ($actor, $custom): array {
                    $response = $this->stepDown(actor: $actor, to: $custom);

                    return ['status' => $response->status(), 'errors' => array_keys($response->json('errors') ?? [])];
                },
                first: fn () => $this->assertSame(200, $this->deactivate(actor: $actor, target: $peer)->status()),
                result: function (array $result) use ($actor, $peer, $role): void {
                    $this->assertSame(['status' => 422, 'errors' => ['role_id']], $result, 'Stepping down must be refused once the peer\'s deactivation commits.');
                    $this->assertSame($role->id, (int) Admin::query()->whereKey($actor->id)->value('role_id'));
                    $this->assertSame(0, (int) Admin::query()->whereKey($peer->id)->value('status'));
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_user_change_racing_a_role_downgrade_is_refused_once_the_downgrade_commits(): void
    {
        $this->admitRace();

        try {
            $keptRole = Role::create(['name' => 'RevC Kept '.uniqid(), 'permission_type' => 'all']);
            $downgradedRole = Role::create(['name' => 'RevC Downgraded '.uniqid(), 'permission_type' => 'all']);
            $custom = Role::create(['name' => 'RevC Custom '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['dashboard']]);
            $actor = $this->fullAdmin('revc-c', $keptRole);
            $peer = $this->fullAdmin('revc-d', $downgradedRole);
            $this->leaveOnly([$actor->id, $peer->id]);

            $this->race(
                second: function () use ($actor, $custom): array {
                    $response = $this->stepDown(actor: $actor, to: $custom);

                    return ['status' => $response->status(), 'errors' => array_keys($response->json('errors') ?? [])];
                },
                first: function () use ($actor, $downgradedRole): void {
                    $response = $this->actingAs($actor, 'admin')->putJson(route('admin.settings.roles.update', $downgradedRole->id), [
                        'name'            => $downgradedRole->name,
                        'permission_type' => 'custom',
                        'permissions'     => ['dashboard'],
                    ]);

                    $this->assertContains($response->status(), [200, 302], 'The role downgrade must succeed: '.$response->getContent());
                },
                result: function (array $result) use ($actor, $keptRole, $downgradedRole): void {
                    $this->assertSame(['status' => 422, 'errors' => ['role_id']], $result, 'The user change must be refused once the downgrade commits.');
                    $this->assertSame($keptRole->id, (int) Admin::query()->whereKey($actor->id)->value('role_id'));
                    $this->assertSame('custom', Role::query()->whereKey($downgradedRole->id)->value('permission_type'));
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    /**
     * Each user and role write, from an actor who loses either the route's permission or (keeping it) their full
     * access while the request waits on the lock.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function waiters(): array
    {
        $cases = [];

        foreach ([
            'user update' => 'settings.users.users.edit',
            'user delete' => 'settings.users.users.delete',
            'user create' => 'settings.users.users.create',
            'role update' => 'settings.roles.edit',
            'role create' => 'settings.roles.create',
        ] as $action => $permission) {
            $cases[$action.', permission revoked'] = [$action, []];
            $cases[$action.', permission kept, full access lost'] = [$action, [$permission]];
        }

        return $cases;
    }

    #[DataProvider('waiters')]
    public function test_a_request_that_waited_out_its_actors_loss_of_authority_is_refused(string $action, array $keptPermissions): void
    {
        $this->admitRace();

        try {
            $all = Role::create(['name' => 'RevC3 All '.uniqid(), 'permission_type' => 'all']);
            $demotedTo = Role::create(['name' => 'RevC3 Demoted '.uniqid(), 'permission_type' => 'custom', 'permissions' => array_merge(['dashboard'], $keptPermissions)]);
            $custom = Role::create(['name' => 'RevC3 Custom '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['dashboard']]);
            $demoter = $this->fullAdmin('revc3-a', $all);
            $actor = $this->fullAdmin('revc3-x', $all);
            $other = $this->fullAdmin('revc3-b', $all);
            $this->leaveOnly([$demoter->id, $actor->id, $other->id]);
            $newName = 'RevC3 New '.uniqid();
            $newEmail = 'revc3-new-'.bin2hex(random_bytes(4)).'@example.com';

            // Everything the refused write could have changed.
            $state = fn (): array => [
                'other'       => (array) DB::table('admins')->where('id', $other->id)->first(['name', 'password', 'status', 'role_id']),
                'custom role' => Role::query()->whereKey($custom->id)->value('permission_type'),
                'new user'    => Admin::query()->where('email', $newEmail)->exists(),
                'new role'    => Role::query()->where('name', $newName)->exists(),
            ];
            $before = $state();

            $this->race(
                // Signed in as a full admin when the request starts, so Bouncer and the form request let it through.
                second: function () use ($action, $actor, $other, $all, $custom, $newName, $newEmail): array {
                    $this->actingAs($actor, 'admin');
                    $password = Str::random(24).'-Aa1!';

                    $response = match ($action) {
                        'user update' => $this->putJson(route('admin.settings.users.update'), [
                            'id'           => $other->id, 'name' => 'RevC3 Renamed', 'email' => $other->email, 'role_id' => $other->role_id,
                            'status'       => true, 'password' => $password, 'password_confirmation' => $password,
                            'ui_locale_id' => $other->ui_locale_id, 'timezone' => 'UTC',
                        ]),
                        'user delete' => $this->deleteJson(route('admin.settings.users.delete', $other->id)),
                        'user create' => $this->postJson(route('admin.settings.users.store'), [
                            'name'         => 'RevC3 New', 'email' => $newEmail, 'role_id' => $all->id, 'status' => true,
                            'password'     => $password, 'password_confirmation' => $password,
                            'ui_locale_id' => $other->ui_locale_id, 'timezone' => 'UTC',
                        ]),
                        'role update' => $this->putJson(route('admin.settings.roles.update', $custom->id), ['name' => $custom->name, 'permission_type' => 'all']),
                        'role create' => $this->postJson(route('admin.settings.roles.store'), ['name' => $newName, 'permission_type' => 'all']),
                    };

                    return ['status' => $response->status()];
                },
                // Meanwhile another full admin moves the actor to a custom role and holds the lock until commit.
                first: fn () => $this->assertSame(200, $this->assign(actor: $demoter, target: $actor, role: $demotedTo)->status()),
                result: function (array $result) use ($state, $before): void {
                    $this->assertSame(['status' => 403], $result, 'A request that waited out its actor\'s loss of authority must be refused.');
                    $this->assertSame($before, $state(), 'The refused request must leave everything as it was.');
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    /** $actor moves $target to $role, keeping them active. */
    private function assign(Admin $actor, Admin $target, Role $role): TestResponse
    {
        return $this->actingAs($actor, 'admin')->putJson(route('admin.settings.users.update'), [
            'id'           => $target->id,
            'name'         => $target->name,
            'email'        => $target->email,
            'role_id'      => $role->id,
            'status'       => true,
            'password'     => '',
            'ui_locale_id' => $target->ui_locale_id,
            'timezone'     => 'UTC',
        ]);
    }

    /** $actor moves themselves to $to (a custom role), from a second session of their own. */
    private function stepDown(Admin $actor, Role $to): TestResponse
    {
        return $this->actingAs($actor, 'admin')->putJson(route('admin.settings.users.update'), [
            'id'               => $actor->id,
            'name'             => $actor->name,
            'email'            => $actor->email,
            'role_id'          => $to->id,
            'status'           => true,
            'password'         => '',
            'current_password' => $this->password,
            'ui_locale_id'     => $actor->ui_locale_id,
            'timezone'         => 'UTC',
        ]);
    }

    private function deactivate(Admin $actor, Admin $target): TestResponse
    {
        return $this->actingAs($actor, 'admin')->putJson(route('admin.settings.users.update'), [
            'id'           => $target->id,
            'name'         => $target->name,
            'email'        => $target->email,
            'role_id'      => $target->role_id,
            'status'       => false,
            'password'     => '',
            'ui_locale_id' => $target->ui_locale_id,
            'timezone'     => 'UTC',
        ]);
    }

    private function fullAdmin(string $prefix, Role $role): Admin
    {
        return Admin::create([
            'name'         => 'RevC Admin',
            'email'        => $prefix.'-'.bin2hex(random_bytes(4)).'@example.com',
            'password'     => Hash::make($this->password),
            'role_id'      => $role->id,
            'status'       => 1,
            'ui_locale_id' => Locale::query()->where('status', 1)->value('id'),
            'timezone'     => 'UTC',
        ]);
    }

    /**
     * Deactivates every other active admin, remembering who, so $keep are the last active full admins.
     *
     * @param  list<int>  $keep
     */
    private function leaveOnly(array $keep): void
    {
        $this->deactivated = Admin::query()->where('status', 1)->whereNotIn('id', $keep)->pluck('id')->map(fn ($id) => (int) $id)->all();
        Admin::query()->whereIn('id', $this->deactivated)->update(['status' => 0]);
    }

    private function admitRace(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two real PostgreSQL connections and row locks; SQLite cannot exercise this race.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'PostgreSQL acceptance requires pcntl and posix; missing extensions must fail the gate.');
        $this->assertTrue(defined(TestTargetGuard::LANE_CONSTANT), 'Committed race fixtures run only in a guarded lane: the local allowlist (phpunit.mothership.xml) or the CI service proof (tests/Support/TestTargetGuard.php); both admit before providers boot.');
        $this->assertContains(config('database.connections.'.DB::getDefaultConnection().'.host'), ['127.0.0.1', 'localhost'], 'Committed race fixtures never run against a remote database.');
        config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
        $this->password = Str::random(24).'-Aa1!';
        Storage::fake(Directory::getAssetDisk());
        $this->auditsBefore = Schema::hasTable('audits') ? (int) DB::table('audits')->max('id') : 0;
        $this->recordResidueBaseline();
    }

    /**
     * $first runs in a transaction this process keeps open; $second runs in a forked process with its own connection
     * and must be seen waiting on a lock $first holds before $first commits. $result receives what $second returned.
     */
    private function race(\Closure $second, \Closure $first, \Closure $result): void
    {
        $resultDir = sys_get_temp_dir().'/revc-race-'.bin2hex(random_bytes(8));
        mkdir($resultDir, 0700);
        $pid = null;
        // Neither process inherits an open database socket. Each opens its own after the fork.
        DB::purge();
        DB::purge(self::SECOND);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                try {
                    DB::setDefaultConnection(self::SECOND);
                    DB::statement("SET statement_timeout = '15000ms'");
                    file_put_contents($resultDir.'/pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                    $deadline = microtime(true) + 10;
                    while (! is_file($resultDir.'/go')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('The first writer never released the start barrier.');
                        }
                        usleep(10000);
                    }
                    file_put_contents($resultDir.'/result', json_encode($second(), JSON_THROW_ON_ERROR));
                } catch (Throwable $error) {
                    file_put_contents($resultDir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }

            DB::beginTransaction();
            $first();
            file_put_contents($resultDir.'/go', '1');

            $deadline = microtime(true) + 10;
            $waiting = false;
            do {
                if (is_file($resultDir.'/pid')) {
                    $backend = (int) file_get_contents($resultDir.'/pid');
                    $holder = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                    $this->assertNotSame($holder, $backend);
                    // pg_stat_activity is snapshotted for the rest of this transaction: drop the snapshot so every poll sees the current wait.
                    DB::select('select pg_stat_clear_snapshot()');
                    $activity = DB::selectOne('select wait_event_type, query, ? = any(pg_blocking_pids(pid)) as blocked_by_holder from pg_stat_activity where pid = ?', [$holder, $backend]);
                    $waiting = $activity?->wait_event_type === 'Lock' && $activity->blocked_by_holder && str_contains(strtolower($activity->query), 'for update');
                }
                if ($waiting || is_file($resultDir.'/error') || is_file($resultDir.'/result')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The second writer must wait on the full-admin lock the first holds, before the first commits: '.(is_file($resultDir.'/result') ? 'it finished without waiting: '.file_get_contents($resultDir.'/result') : (is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'it did not wait')));
            DB::commit();

            $deadline = microtime(true) + 15;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The second writer did not finish.');
            $result(json_decode(file_get_contents($resultDir.'/result'), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(self::SECOND);
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
        }
    }

    /** Restores the admins leaveOnly() deactivated, removes the fixtures and their audit rows, and proves nothing is left. */
    private function cleanUp(): void
    {
        Admin::query()->whereIn('id', $this->deactivated)->update(['status' => 1]);
        $this->deactivated = [];

        if (Schema::hasTable('audits')) {
            DB::table('audits')->where('id', '>', $this->auditsBefore)->delete();
        }

        $this->assertNoCommittedResidue();
    }
}
