<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\DataTransfer\Helpers\Error;
use Mothership\DataTransfer\Helpers\Import;
use Mothership\DataTransfer\Helpers\Importers\Role\Importer as RoleImporter;
use Mothership\DataTransfer\Helpers\Importers\User\Importer as UserImporter;
use Mothership\DataTransfer\Models\JobTrack;
use Mothership\DataTransfer\Models\JobTrackBatch;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The user and role importers under REPEATABLE READ (MySQL and MariaDB InnoDB), where a transaction's plain reads
 * answer from the snapshot its first read took. Import::start() runs every batch inside an outer transaction and the
 * importer reads before its guarded write, so every read that decides or targets a write must be a locking read,
 * which InnoDB answers from the latest committed rows whatever the snapshot (UX eval rev-C2 finding 1, rev-C3
 * findings 1 and 3):
 *
 *  - the last-full-admin rule: a second session deactivates admin B and commits; the import's row deactivating A,
 *    the last active full admin, must be refused;
 *  - the job's actor: a second session deactivates the actor, moves them to a weaker role or narrows their role; the
 *    user or role the import then grants must not be written;
 *  - the row's subjects, across both importers and append, update and delete: an email or name reassigned to an
 *    existing row, a row inserted after the snapshot, a role renamed and promoted and replaced, and a status, role
 *    or permission list moved away and written back to its snapshot value. Each row must land with exactly its
 *    values on the admin or role that holds the email or name as committed now, and leave the one the snapshot
 *    mapped it to as it was (rev-C4: the writes go to the locked models, Repository::updateModel()/deleteModel(),
 *    not through update()/delete(), whose plain re-find reads the snapshot).
 *
 * Commits real rows (DamConcurrencyTestCase, no DatabaseTransactions wrapper): the fixture before the outer
 * transaction's snapshot, and the late change from a second session, which takes no lock the outer transaction
 * holds, so one process is enough. Everything above the ids each table started at is removed afterwards. MySQL and
 * MariaDB only: PostgreSQL's READ COMMITTED re-reads every statement (its races are AdminInvariantRacePostgresTest),
 * and SQLite has one writer.
 */
final class AdminInvariantSnapshotMysqlTest extends DamConcurrencyTestCase
{
    private const PROBE = 'revc2_snapshot_probe';

    /** Children before parents. */
    private const TABLES = ['audits', 'job_track_batches', 'job_track', 'job_instances', 'admins', 'roles'];

    /** @var array<string, int> */
    private array $maxIds = [];

    /** @var list<int> */
    private array $deactivated = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The REPEATABLE READ snapshot cases run on MySQL and MariaDB; PostgreSQL has AdminInvariantRacePostgresTest.');
        }

        $this->assertSame('REPEATABLE-READ', DB::selectOne('select @@transaction_isolation as level')->level);
        config(['database.connections.'.self::PROBE => config('database.connections.'.DB::getDefaultConnection())]);

        foreach (self::TABLES as $table) {
            $this->maxIds[$table] = (int) DB::table($table)->max('id');
        }
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach (self::TABLES as $table) {
            if (isset($this->maxIds[$table])) {
                DB::table($table)->where('id', '>', $this->maxIds[$table])->delete();
            }
        }

        DB::table('admins')->whereIn('id', $this->deactivated)->update(['status' => 1]);
        DB::purge(self::PROBE);

        parent::tearDown();
    }

    public function test_an_import_cannot_deactivate_the_last_full_admin_from_a_stale_repeatable_read_snapshot(): void
    {
        $role = $this->role('all');
        [$a, $b] = [$this->admin($role), $this->admin($role)];
        $this->deactivated = Admin::query()->where('status', 1)->whereNotIn('id', [$a->id, $b->id])->pluck('id')->map(fn ($id) => (int) $id)->all();
        Admin::query()->whereIn('id', $this->deactivated)->update(['status' => 0]);

        $this->importAfterSnapshot(
            importer: UserImporter::class,
            jobTrack: JobTrack::factory()->fromConsole()->create(['action' => Import::ACTION_APPEND]),
            rows: [['name' => $a->name, 'email' => $a->email, 'role_name' => $role->name, 'status' => 'inactive']],
            afterSnapshot: fn (Connection $probe) => $probe->table('admins')->where('id', $b->id)->update(['status' => 0]),
        );

        $this->assertSame(1, (int) Admin::query()->whereKey($a->id)->value('status'), 'The import deactivated the last active full admin.');
        $this->assertSame(0, (int) Admin::query()->whereKey($b->id)->value('status'));
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function actorChanges(): array
    {
        $cases = [];

        foreach (['user import' => UserImporter::class, 'role import' => RoleImporter::class] as $import => $importer) {
            foreach (['actor deactivated', 'actor moved to a weaker role', 'actor\'s role narrowed'] as $change) {
                $cases[$import.', '.$change] = [$importer, $change];
            }
        }

        return $cases;
    }

    /**
     * A custom-role actor may grant what their own role holds. Once another session takes that away after the
     * import's snapshot, the grant must not be written.
     */
    #[DataProvider('actorChanges')]
    public function test_an_authenticated_import_acts_on_its_actors_committed_authority(string $importer, string $change): void
    {
        $actorRole = $this->role('custom', ['dashboard', 'settings']);
        $weaker = $this->role('custom', ['dashboard']);
        $actor = $this->admin($actorRole);
        $granted = $this->role('custom', ['dashboard', 'settings']);
        $newEmail = 'revc3-grant-'.bin2hex(random_bytes(4)).'@example.com';
        $newRole = 'RevC3 Grant '.uniqid();

        $this->importAfterSnapshot(
            importer: $importer,
            jobTrack: JobTrack::factory()->create(['action' => Import::ACTION_APPEND, 'user_id' => $actor->id]),
            rows: $importer === UserImporter::class
                ? [['name' => 'RevC3 Granted', 'email' => $newEmail, 'role_name' => $granted->name, 'status' => 'active']]
                : [['name' => $newRole, 'permission_type' => 'custom', 'permissions' => 'dashboard,settings']],
            afterSnapshot: fn (Connection $probe) => match ($change) {
                'actor deactivated'            => $probe->table('admins')->where('id', $actor->id)->update(['status' => 0]),
                'actor moved to a weaker role' => $probe->table('admins')->where('id', $actor->id)->update(['role_id' => $weaker->id]),
                'actor\'s role narrowed'       => $probe->table('roles')->where('id', $actorRole->id)->update(['permissions' => json_encode(['dashboard'])]),
            },
        );

        $this->assertFalse(Admin::query()->where('email', $newEmail)->exists(), 'The import granted a role with authority its actor no longer had.');
        $this->assertFalse(Role::query()->where('name', $newRole)->exists(), 'The import created a role with authority its actor no longer had.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function subjectChanges(): array
    {
        return array_combine($names = [
            'user append, role inserted after the snapshot',
            'user append, email moved away from a full admin',
            'user append, role renamed, promoted and replaced',
            'user update, admin inserted after the snapshot',
            'user update, email reassigned to an existing admin',
            'user update, status back to the snapshot value',
            'user update, role back to the snapshot value',
            'user update, role permissions reduced to the snapshot value',
            'user delete, admin inserted after the snapshot',
            'user delete, email reassigned to an existing admin',
            'role append, name freed after the snapshot',
            'role update, role inserted after the snapshot',
            'role update, name reassigned to an existing role',
            'role update, permissions reduced to the snapshot value',
            'role delete, role inserted after the snapshot',
            'role delete, name reassigned to an existing role',
        ], array_map(fn (string $name): array => [$name], $names));
    }

    /**
     * Every row an authorized custom-role actor imports must land on exactly the admin or role it names as committed
     * now — a row committed after the import's snapshot included — with exactly the row's values, and leave the
     * admin or role that held that email or name in the snapshot as it is.
     */
    #[DataProvider('subjectChanges')]
    public function test_an_import_writes_exactly_the_subject_it_checked(string $scenario): void
    {
        $actor = $this->admin($this->role('custom', ['dashboard', 'settings']));
        ['importer' => $importer, 'action' => $action, 'rows' => $rows, 'after' => $after, 'expect' => $expect] = $this->scenario($scenario);

        $this->importAfterSnapshot(
            importer: $importer,
            jobTrack: JobTrack::factory()->create(['action' => $action, 'user_id' => $actor->id]),
            rows: $rows,
            afterSnapshot: $after,
        );

        $expect();
    }

    /**
     * @return array{importer: class-string, action: string, rows: list<array<string, mixed>>, after: \Closure(Connection): void, expect: \Closure(): void}
     */
    private function scenario(string $scenario): array
    {
        $weak = $this->role('custom', ['dashboard']);
        $weak2 = $this->role('custom', ['dashboard']);
        $email = 'revc4-'.bin2hex(random_bytes(4)).'@example.com';
        $name = 'RevC4 Role '.uniqid();
        $late = null;

        return match ($scenario) {
            'user append, role inserted after the snapshot' => [
                'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                'rows'     => [['name' => 'RevC4 Created', 'email' => $email, 'role_name' => $name, 'status' => 'active']],
                'after'    => function (Connection $probe) use ($name, &$late): void {
                    $late = $this->insertRole($probe, $name, ['dashboard']);
                },
                'expect' => function () use ($email, &$late): void {
                    $this->assertSame(['name' => 'RevC4 Created', 'email' => $email, 'role_id' => $late, 'status' => 1], $this->adminState($email));
                },
            ],
            'user append, email moved away from a full admin' => (function () use ($weak): array {
                $full = $this->admin($this->role('all'));
                $before = array_merge($this->adminState($full->email), ['email' => 'moved-'.$full->email]);

                return [
                    'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                    'rows'     => [['name' => 'RevC4 New', 'email' => $full->email, 'role_name' => $weak->name, 'status' => 'active']],
                    'after'    => fn (Connection $probe) => $probe->table('admins')->where('id', $full->id)->update(['email' => 'moved-'.$full->email]),
                    'expect'   => function () use ($full, $weak, $before): void {
                        $this->assertSame(['name' => 'RevC4 New', 'email' => $full->email, 'role_id' => $weak->id, 'status' => 1], $this->adminState($full->email));
                        $this->assertNotSame($full->id, (int) Admin::query()->where('email', $full->email)->value('id'));
                        $this->assertSame($before, $this->adminState('moved-'.$full->email), 'The full admin the snapshot mapped the email to changed.');
                    },
                ];
            })(),
            'user append, role renamed, promoted and replaced' => [
                'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                'rows'     => [['name' => 'RevC4 Assigned', 'email' => $email, 'role_name' => $weak->name, 'status' => 'active']],
                'after'    => function (Connection $probe) use ($weak, &$late): void {
                    $probe->table('roles')->where('id', $weak->id)->update(['name' => $weak->name.' (moved)', 'permission_type' => 'all', 'permissions' => null]);
                    $late = $this->insertRole($probe, $weak->name, ['dashboard']);
                },
                'expect' => function () use ($email, $weak, &$late): void {
                    $this->assertSame(['name' => 'RevC4 Assigned', 'email' => $email, 'role_id' => $late, 'status' => 1], $this->adminState($email));
                    $this->assertSame(['name' => $weak->name.' (moved)', 'permission_type' => 'all', 'permissions' => []], $this->roleState($weak->id));
                },
            ],
            'user update, admin inserted after the snapshot' => [
                'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                'rows'     => [['name' => 'RevC4 Updated', 'email' => $email, 'role_name' => $weak->name, 'status' => 'inactive']],
                'after'    => function (Connection $probe) use ($email, $weak2, &$late): void {
                    $late = $this->insertAdmin($probe, $email, $weak2->id);
                },
                'expect' => function () use ($email, $weak, &$late): void {
                    $this->assertSame(['name' => 'RevC4 Updated', 'email' => $email, 'role_id' => $weak->id, 'status' => 0], $this->adminState($email));
                    $this->assertSame([$late], Admin::query()->where('email', $email)->pluck('id')->map(fn ($id) => (int) $id)->all());
                },
            ],
            'user update, email reassigned to an existing admin' => (function () use ($weak, $weak2): array {
                [$x, $y] = [$this->admin($weak), $this->admin($weak2)];
                $xBefore = array_merge($this->adminState($x->email), ['email' => 'moved-'.$x->email]);

                return [
                    'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                    'rows'     => [['name' => 'RevC4 Intended', 'email' => $x->email, 'role_name' => $weak->name, 'status' => 'active']],
                    'after'    => function (Connection $probe) use ($x, $y): void {
                        $probe->table('admins')->where('id', $x->id)->update(['email' => 'moved-'.$x->email]);
                        $probe->table('admins')->where('id', $y->id)->update(['email' => $x->email]);
                    },
                    'expect' => function () use ($x, $y, $weak, $xBefore): void {
                        $this->assertSame(['name' => 'RevC4 Intended', 'email' => $x->email, 'role_id' => $weak->id, 'status' => 1], $this->adminState($x->email));
                        $this->assertSame($y->id, (int) Admin::query()->where('email', $x->email)->value('id'));
                        $this->assertSame($xBefore, $this->adminState('moved-'.$x->email), 'The admin the snapshot mapped the email to changed.');
                    },
                ];
            })(),
            'user update, status back to the snapshot value',
            'user update, role back to the snapshot value' => (function () use ($scenario, $weak, $weak2): array {
                $t = $this->admin($weak);

                return [
                    'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                    'rows'     => [['name' => $t->name, 'email' => $t->email, 'role_name' => $weak->name, 'status' => 'active']],
                    // The committed value moves away; the row writes the snapshot's value back.
                    'after' => fn (Connection $probe) => $probe->table('admins')->where('id', $t->id)->update(
                        str_contains($scenario, 'status') ? ['status' => 0] : ['role_id' => $weak2->id],
                    ),
                    'expect' => fn () => $this->assertSame(['name' => $t->name, 'email' => $t->email, 'role_id' => $weak->id, 'status' => 1], $this->adminState($t->email)),
                ];
            })(),
            'user update, role permissions reduced to the snapshot value' => (function () use ($weak, $weak2): array {
                $t = $this->admin($weak2);

                return [
                    'importer' => UserImporter::class, 'action' => Import::ACTION_APPEND,
                    'rows'     => [['name' => $t->name, 'email' => $t->email, 'role_name' => $weak->name, 'permission_type' => 'custom', 'permissions' => 'dashboard', 'status' => 'active']],
                    'after'    => fn (Connection $probe) => $probe->table('roles')->where('id', $weak->id)->update(['permissions' => json_encode(['dashboard', 'settings'])]),
                    'expect'   => function () use ($t, $weak): void {
                        $this->assertSame(['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => ['dashboard']], $this->roleState($weak->id));
                        $this->assertSame(['name' => $t->name, 'email' => $t->email, 'role_id' => $weak->id, 'status' => 1], $this->adminState($t->email));
                    },
                ];
            })(),
            'user delete, admin inserted after the snapshot' => [
                'importer' => UserImporter::class, 'action' => Import::ACTION_DELETE,
                'rows'     => [['email' => $email]],
                'after'    => function (Connection $probe) use ($email, $weak): void {
                    $this->insertAdmin($probe, $email, $weak->id);
                },
                'expect' => fn () => $this->assertNull($this->adminState($email), 'The admin committed after the snapshot was not deleted.'),
            ],
            'user delete, email reassigned to an existing admin' => (function () use ($weak, $weak2): array {
                [$x, $y] = [$this->admin($weak), $this->admin($weak2)];
                $xBefore = array_merge($this->adminState($x->email), ['email' => 'moved-'.$x->email]);

                return [
                    'importer' => UserImporter::class, 'action' => Import::ACTION_DELETE,
                    'rows'     => [['email' => $x->email]],
                    'after'    => function (Connection $probe) use ($x, $y): void {
                        $probe->table('admins')->where('id', $x->id)->update(['email' => 'moved-'.$x->email]);
                        $probe->table('admins')->where('id', $y->id)->update(['email' => $x->email]);
                    },
                    'expect' => function () use ($x, $y, $xBefore): void {
                        $this->assertFalse(Admin::query()->whereKey($y->id)->exists(), 'The admin holding the email now was not deleted.');
                        $this->assertSame($xBefore, $this->adminState('moved-'.$x->email), 'The admin the snapshot mapped the email to changed.');
                    },
                ];
            })(),
            'role append, name freed after the snapshot' => [
                'importer' => RoleImporter::class, 'action' => Import::ACTION_APPEND,
                'rows'     => [['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => 'dashboard,settings']],
                'after'    => fn (Connection $probe) => $probe->table('roles')->where('id', $weak->id)->update(['name' => $weak->name.' (moved)']),
                'expect'   => function () use ($weak): void {
                    $created = (int) Role::query()->where('name', $weak->name)->value('id');
                    $this->assertNotSame($weak->id, $created);
                    $this->assertSame(['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => ['dashboard', 'settings']], $this->roleState($created));
                    $this->assertSame(['name' => $weak->name.' (moved)', 'permission_type' => 'custom', 'permissions' => ['dashboard']], $this->roleState($weak->id));
                },
            ],
            'role update, role inserted after the snapshot' => [
                'importer' => RoleImporter::class, 'action' => Import::ACTION_APPEND,
                'rows'     => [['name' => $name, 'permission_type' => 'custom', 'permissions' => 'dashboard,settings']],
                'after'    => function (Connection $probe) use ($name, &$late): void {
                    $late = $this->insertRole($probe, $name, ['dashboard']);
                },
                'expect' => function () use ($name, &$late): void {
                    $this->assertSame([$late], Role::query()->where('name', $name)->pluck('id')->map(fn ($id) => (int) $id)->all());
                    $this->assertSame(['name' => $name, 'permission_type' => 'custom', 'permissions' => ['dashboard', 'settings']], $this->roleState($late));
                },
            ],
            'role update, name reassigned to an existing role',
            'role delete, name reassigned to an existing role' => (function () use ($scenario, $weak): array {
                $other = $this->role('custom', ['settings']);
                $delete = str_starts_with($scenario, 'role delete');

                return [
                    'importer' => RoleImporter::class, 'action' => $delete ? Import::ACTION_DELETE : Import::ACTION_APPEND,
                    'rows'     => [['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => 'dashboard']],
                    'after'    => function (Connection $probe) use ($weak, $other): void {
                        $probe->table('roles')->where('id', $weak->id)->update(['name' => $weak->name.' (moved)']);
                        $probe->table('roles')->where('id', $other->id)->update(['name' => $weak->name]);
                    },
                    'expect' => function () use ($weak, $other, $delete): void {
                        $this->assertSame(
                            $delete ? null : ['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => ['dashboard']],
                            $this->roleState($other->id),
                            'The role holding the name now was not the one written.',
                        );
                        $this->assertSame(['name' => $weak->name.' (moved)', 'permission_type' => 'custom', 'permissions' => ['dashboard']], $this->roleState($weak->id), 'The role the snapshot mapped the name to changed.');
                    },
                ];
            })(),
            'role update, permissions reduced to the snapshot value' => [
                'importer' => RoleImporter::class, 'action' => Import::ACTION_APPEND,
                'rows'     => [['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => 'dashboard']],
                'after'    => fn (Connection $probe) => $probe->table('roles')->where('id', $weak->id)->update(['permissions' => json_encode(['dashboard', 'settings'])]),
                'expect'   => fn () => $this->assertSame(['name' => $weak->name, 'permission_type' => 'custom', 'permissions' => ['dashboard']], $this->roleState($weak->id)),
            ],
            'role delete, role inserted after the snapshot' => [
                'importer' => RoleImporter::class, 'action' => Import::ACTION_DELETE,
                'rows'     => [['name' => $name]],
                'after'    => function (Connection $probe) use ($name, &$late): void {
                    $late = $this->insertRole($probe, $name, ['dashboard']);
                },
                'expect' => function () use (&$late): void {
                    $this->assertNull($this->roleState($late), 'The role committed after the snapshot was not deleted.');
                },
            ],
        };
    }

    /** @return array{name: string, email: string, role_id: int|null, status: int}|null */
    private function adminState(string $email): ?array
    {
        $row = DB::table('admins')->where('email', $email)->first(['name', 'email', 'role_id', 'status']);

        return $row ? ['name' => $row->name, 'email' => $row->email, 'role_id' => $row->role_id === null ? null : (int) $row->role_id, 'status' => (int) $row->status] : null;
    }

    /** @return array{name: string, permission_type: string, permissions: list<string>}|null */
    private function roleState(?int $id): ?array
    {
        $role = $id ? Role::query()->find($id) : null;

        return $role ? ['name' => $role->name, 'permission_type' => $role->permission_type, 'permissions' => array_values($role->permissions ?? [])] : null;
    }

    private function insertRole(Connection $probe, string $name, array $permissions): int
    {
        return (int) $probe->table('roles')->insertGetId([
            'name' => $name, 'permission_type' => 'custom', 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertAdmin(Connection $probe, string $email, int $roleId): int
    {
        return (int) $probe->table('admins')->insertGetId([
            'name'     => 'RevC4 Late', 'email' => $email, 'password' => Hash::make(Str::random(32)), 'role_id' => $roleId,
            'status'   => 1, 'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Runs one batch the way Import::start() does: inside an outer transaction whose first plain read fixes the
     * snapshot, with $afterSnapshot committed from a second session in between.
     *
     * @param  class-string  $importer
     * @param  list<array<string, mixed>>  $rows
     */
    private function importAfterSnapshot(string $importer, JobTrack $jobTrack, array $rows, \Closure $afterSnapshot): void
    {
        $batch = JobTrackBatch::factory()->create(['job_track_id' => $jobTrack->id, 'data' => $rows]);

        $helper = app($importer);
        $helper->setImport($jobTrack);
        $helper->setErrorHelper(app(Error::class));

        DB::beginTransaction();
        DB::table('admins')->count();

        $afterSnapshot(DB::connection(self::PROBE));

        $helper->importBatch($batch);
        DB::commit();
    }

    private function role(string $type, ?array $permissions = null): Role
    {
        return Role::create(['name' => 'RevC3 Role '.uniqid(), 'permission_type' => $type, 'permissions' => $permissions]);
    }

    private function admin(Role $role): Admin
    {
        return Admin::create([
            'name'     => 'RevC3 Admin',
            'email'    => 'revc3-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => Hash::make(Str::random(32)),
            'role_id'  => $role->id,
            'status'   => 1,
            'timezone' => 'UTC',
        ]);
    }
}
