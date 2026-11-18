<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListRebuilder;
use Mothership\DAM\Services\Access\AccessRebuildRefused;
use Mothership\DAM\Services\Access\LegacyGrantReader;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * Codex review of PR #62, round 2: the rebuild's drift check read the folder grants with a plain SELECT. Under
 * REPEATABLE READ (MySQL and MariaDB) that answers from the transaction's snapshot, so a legacy grant to an
 * edited generated role group, committed after the snapshot, went unseen. The rebuild then reset the group's
 * members and changed a grants answer. The drift check now uses locking reads (ADR 0010).
 *
 * These cases commit real rows (DamConcurrencyTestCase, no DatabaseTransactions wrapper): the fixture before the
 * outer transaction's snapshot, and the late grant from a second session. PostgreSQL (READ COMMITTED) must pass
 * as well; SQLite and SQL Server skip.
 */
final class AccessRebuildSnapshotTest extends DamConcurrencyTestCase
{
    private const PROBE = 'access_rebuild_probe';

    private ?Role $role = null;

    /** @var list<Admin> */
    private array $admins = [];

    /** @var list<Directory> */
    private array $folders = [];

    private ?int $group = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::PROBE => config('database.connections.'.DB::getDefaultConnection())]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        // Committed rows, children first; the role's settings and folder grant go with the role.
        if ($this->group !== null) {
            DB::table('dam_directory_group')->where('group_id', $this->group)->delete();
            DB::table('dam_group_members')->where('group_id', $this->group)->delete();
            DB::table('dam_groups')->where('id', $this->group)->delete();
        }

        foreach ($this->admins as $admin) {
            $admin->delete();
        }

        $this->role?->delete();

        foreach (array_reverse($this->folders) as $folder) {
            Directory::find($folder->id)?->delete();
        }

        DB::purge(self::PROBE);

        parent::tearDown();
    }

    public function test_on_mysql_repeatable_read_the_rebuild_refuses_a_grant_committed_after_its_snapshot(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The REPEATABLE READ snapshot case runs on MySQL and MariaDB.');
        }

        $this->assertTheRebuildSeesTheLateGrant();
    }

    public function test_on_postgresql_the_rebuild_refuses_a_grant_committed_after_its_snapshot(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The same case under READ COMMITTED runs on PostgreSQL.');
        }

        $this->assertTheRebuildSeesTheLateGrant();
    }

    private function assertTheRebuildSeesTheLateGrant(): void
    {
        // Committed before the outer transaction: a role with a folder grant, two holders, and its generated
        // group edited to drop one of them while no folder grant used the group.
        $name = 'Rebuild snapshot '.Str::uuid();
        $this->role = Role::factory()->create(['name' => $name, 'permission_type' => 'custom', 'permissions' => ['dam']]);
        $this->folders = [
            $roleFolder = Directory::create(['name' => $name.' role', 'parent_id' => null]),
            $groupFolder = Directory::create(['name' => $name.' group', 'parent_id' => null]),
        ];
        DB::table('dam_directory_role')->insert(['directory_id' => $roleFolder->id, 'role_id' => $this->role->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->admins = [
            $kept = Admin::factory()->create(['role_id' => $this->role->id]),
            $removed = Admin::factory()->create(['role_id' => $this->role->id]),
        ];
        $this->group = (int) DB::table('dam_groups')->insertGetId(['name' => $name, 'system_key' => 'role:'.$this->role->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_group_members')->insert(['group_id' => $this->group, 'admin_id' => $kept->id]);

        DB::beginTransaction();

        // The outer transaction's first plain read fixes its snapshot under REPEATABLE READ.
        $this->assertSame(0, DB::table('dam_directory_group')->where('group_id', $this->group)->count());

        // A second session grants the edited group a folder, and commits.
        DB::connection(self::PROBE)->table('dam_directory_group')->insert(['directory_id' => $groupFolder->id, 'group_id' => $this->group, 'inherit_children' => false]);

        $grants = $this->grantsAnswers(admins: [$kept, $removed]);

        try {
            app(AccessListRebuilder::class)->run();
            $this->fail('The rebuild reset a generated group that a folder grant, committed after its snapshot, uses.');
        } catch (AccessRebuildRefused $refused) {
            $this->assertSame([$this->group], array_column($refused->groups, 'group_id'));
        }

        $members = DB::table('dam_group_members')->where('group_id', $this->group)->orderBy('admin_id')->pluck('admin_id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$kept->id], $members, 'the rebuild changed the members of a group a folder grant uses');
        $this->assertSame($grants, $this->grantsAnswers(admins: [$kept, $removed]));
    }

    /**
     * @param  list<Admin>  $admins
     */
    private function grantsAnswers(array $admins): array
    {
        $reader = new LegacyGrantReader;
        $sorted = fn (array $ids) => collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();
        $answers = [];

        foreach ($admins as $admin) {
            $admin = Admin::query()->findOrFail($admin->id);

            $answers[$admin->id] = [
                'bypass'  => $reader->bypass(admin: $admin),
                'folders' => $sorted($reader->directlyGrantedIds(admin: $admin)),
                'paths'   => $sorted($reader->viewableIds(admin: $admin)),
            ];
        }

        return $answers;
    }
}
