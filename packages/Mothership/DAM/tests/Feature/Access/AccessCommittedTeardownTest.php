<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\DAM\Tests\Support\InjectedFailure;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * What the committed Access tests owe the database they ran against, proved from outside them.
 *
 * Both properties are about what survives a teardown, so neither can be asserted from inside the test whose
 * teardown it is. These cases run the real classes in a child process against this same database and then look
 * at the rows themselves (Codex review of PR #74 round 5, findings 2 and 3):
 *
 * 1. A setUp that fails after changing DAM_ACCESS_MODEL still puts it back — the value the database had, or no
 *    row where it had none. That failure path was live: the change happens before `$prepared` becomes true, and
 *    the teardown's early return skipped the restore, so a `grants` database was left on `lists` with every row
 *    count matching, because an updated row changes no count and `key` is not an id the sweep can compare.
 * 2. A completed run leaves rows it did not create alone. The teardowns used to select folders by name —
 *    Images, AI-Generations, Misc. — and delete them and their assets, whoever had created them.
 *
 * PostgreSQL only, because the classes under test skip on every other driver, which would make the child run
 * prove nothing.
 */
final class AccessCommittedTeardownTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    /** The two classes whose setUp changes DAM_ACCESS_MODEL before it is safe to fail. */
    private const FLIPS_STATE = [
        'packages/Mothership/DAM/tests/Feature/Access/AccessInheritLockingCommittedTest.php',
        'packages/Mothership/DAM/tests/Feature/Access/AccessCascadeLockingCommittedTest.php',
    ];

    /** Every committed class whose teardown removes fixtures, for the run that has to leave this test's rows. */
    private const CLEANS_UP = [
        ...self::FLIPS_STATE,
        'packages/Mothership/DAM/tests/Feature/Access/AccessFenceCommittedTest.php',
    ];

    /** One cheap case from each class, for the run that has to succeed. */
    private const ONE_EACH = 'test_folder_and_file_rows_are_locked_in_one_order_across_batch_boundaries'
        .'|test_a_holder_on_a_subtree_folder_blocks_the_cascade_until_it_commits'
        .'|test_a_hold_belongs_to_the_transaction_that_took_it';

    private bool $prepared = false;

    private string $suffix = '';

    /** @var list<array{folder: int, asset: int, name: string}> */
    private array $reused = [];

    private ?Admin $admin = null;

    private ?Role $role = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The classes under test are PostgreSQL-only, so a child run on another driver would skip.');
        }

        $this->recordResidueBaseline();
        $this->recordAccessModelState();

        $this->prepared = true;
        $this->suffix = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        if (! $this->prepared) {
            parent::tearDown();

            return;
        }

        try {
            foreach ($this->reused as $row) {
                DB::table('dam_asset_directory')->where('asset_id', $row['asset'])->delete();
                Asset::query()->whereKey($row['asset'])->forceDelete();
                DB::table('dam_directory_user_access')->where('directory_id', $row['folder'])->delete();
                Directory::find($row['folder'])?->delete();
            }

            if ($this->admin !== null) {
                Admin::query()->whereKey($this->admin->id)->delete();
            }

            if ($this->role !== null) {
                Role::query()->whereKey($this->role->id)->delete();
            }
        } finally {
            $this->cleanUpCommittedFixtures();
            parent::tearDown();
        }
    }

    public function test_a_setup_that_fails_after_the_state_change_puts_the_value_back(): void
    {
        $this->setAccessModelState(AccessModel::GRANTS);

        $child = $this->runCommittedTests(
            files: self::FLIPS_STATE,
            filtering: self::ONE_EACH,
            injecting: 'after the access-model state changed'
        );

        $this->assertTrue($child->failed(), 'the injected failure did not fail the child run');
        $this->assertStringContainsString('injected test failure', $child->output().$child->errorOutput());

        $this->assertSame(
            AccessModel::GRANTS,
            $this->currentAccessModelState(),
            'a setUp that failed after switching the access model left the database on the other one'
        );
    }

    public function test_a_setup_that_fails_after_the_state_change_leaves_an_absent_row_absent(): void
    {
        // A database with no state row at all: the first switch has not run. The child's setUp inserts one, so
        // restoring means removing it again rather than writing a value back.
        DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();

        $child = $this->runCommittedTests(
            files: self::FLIPS_STATE,
            filtering: self::ONE_EACH,
            injecting: 'after the access-model state changed'
        );

        $this->assertTrue($child->failed(), 'the injected failure did not fail the child run');
        $this->assertNull(
            $this->currentAccessModelState(),
            'a setUp that failed after inserting the state row left it behind'
        );
    }

    public function test_a_committed_run_leaves_pre_existing_folders_and_their_assets_alone(): void
    {
        $this->role = Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);
        $this->admin = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);

        // The names the teardowns used to select on, each with an asset and a list entry of its own.
        foreach (['Images', 'AI-Generations', 'Misc.'] as $name) {
            $folder = Directory::create(['name' => $name]);
            $asset = Asset::factory()->create(['file_name' => 'reused-'.Str::slug($name).'-'.$this->suffix.'.jpg']);
            $asset->directories()->attach($folder->id);

            DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $this->admin->id]);

            $this->reused[] = ['folder' => $folder->id, 'asset' => $asset->id, 'name' => $name];
        }

        $child = $this->runCommittedTests(files: self::CLEANS_UP, filtering: self::ONE_EACH);

        $this->assertTrue($child->successful(), 'the child run failed, so it proves nothing about its teardown: '
            .$child->output().$child->errorOutput());

        foreach ($this->reused as $row) {
            $folder = Directory::find($row['folder']);

            $this->assertNotNull($folder, $row['name'].': the teardown deleted a folder it did not create');
            $this->assertSame($row['name'], $folder->name, $row['name'].': the folder is not the one this test created');

            $this->assertNotNull(
                Asset::query()->whereKey($row['asset'])->first(),
                $row['name'].': the teardown deleted an asset attached to a folder it did not create'
            );

            $this->assertSame(
                1,
                DB::table('dam_asset_directory')->where('directory_id', $row['folder'])->where('asset_id', $row['asset'])->count(),
                $row['name'].': the asset is no longer in the folder'
            );

            $this->assertSame(
                1,
                DB::table('dam_directory_user_access')->where('directory_id', $row['folder'])->where('admin_id', $this->admin->id)->count(),
                $row['name'].': the folder lost the list entry this test gave it'
            );
        }
    }

    /**
     * Runs the classes under test in a child process against this same database.
     *
     * The child inherits .env, so it is the same connection this test is inspecting; `injecting` names the point
     * its setUp should throw at, and `filtering` selects which cases run.
     *
     * @param  list<string>  $files
     */
    private function runCommittedTests(array $files, ?string $filtering = null, ?string $injecting = null): ProcessResult
    {
        $command = array_merge(['vendor/bin/pest'], $files);

        if ($filtering !== null) {
            $command[] = '--filter='.$filtering;
        }

        return Process::path(base_path())
            ->timeout(900)
            ->env($injecting === null ? [] : [InjectedFailure::ENV => $injecting])
            ->run($command);
    }
}
