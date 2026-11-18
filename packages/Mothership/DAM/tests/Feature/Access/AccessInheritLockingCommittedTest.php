<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AccessPlacementsMoved;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Services\GenerationDirectory;
use Mothership\DAM\Services\GenerationPackageImporter;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\DAM\Tests\Support\InjectedFailure;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/**
 * The locking inherit-on-create takes (ADR 0010, sharing slice 2a), proved from a second session.
 * `sharedLock()` and `lockForUpdate()` are no-ops on SQLite, so PostgreSQL is where these contracts are
 * observable and every other driver skips.
 *
 * Two waits, in the lock order the ADR gives:
 * 1. Stage 3 — a cascade holding a folder row (FOR UPDATE, as AccessListService::rewriteSubtree does) blocks
 *    a new item inheriting that folder's list, which then copies the list the cascade left behind, not the
 *    one it replaced. This is the spec's "a concurrent cascade either finishes first or waits for the new
 *    item and then rewrites it", from the new item's side.
 * 2. Stage 1 — a switch's transition holding the DAM_ACCESS_MODEL row FOR UPDATE blocks a real writer, a
 *    folder create, before it reaches the tree lock, and the create succeeds once the transition commits.
 *    No deadlock: the writer only ever waits, and it waits on the first row in the order.
 *
 * No sleeps decide anything: each case takes its barrier explicitly on a second connection, bounds the wait
 * with PostgreSQL's `lock_timeout`, and asserts both that the blocked session really waited and that it
 * wrote nothing. Each wait is also shown to be the lock's and not the statement's — the same rows read
 * without a lock come back at once while the holder still holds them.
 *
 * These rows are committed (DamConcurrencyTestCase, no DatabaseTransactions wrapper), so tearDown removes
 * them and puts the state row back as it found it.
 */
final class AccessInheritLockingCommittedTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'access_inherit_holder';

    /** The bound on every wait, and the floor the blocked session must have spent waiting. */
    private const TIMEOUT_MS = 300;

    private const WAITED_SECONDS = 0.25;

    /** PostgreSQL's lock_not_available: the session waited for the holder and gave up. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    private bool $prepared = false;

    private ?Directory $folder = null;

    private ?Asset $file = null;

    private ?Admin $first = null;

    private ?Admin $second = null;

    private ?Role $role = null;

    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('sharedLock() and lockForUpdate() are no-ops on SQLite and the waits are bounded with PostgreSQL lock_timeout; other drivers skip.');
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
        // A full admin, so the cases that go through real routes are not refused by the read side.
        $this->role = Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);
        $this->first = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        $this->second = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);

        $this->suffix = $suffix;
        Storage::fake(Directory::getAssetDisk());
        $this->actingAs($this->first, 'admin');

        $this->folder = Directory::create(['name' => 'Inherit lock folder '.$suffix]);
        $this->file = Asset::factory()->create(['file_name' => 'inherit-lock-'.$suffix.'.jpg']);
        $this->file->directories()->attach($this->folder->id);

        // The folder's list before any cascade, and the file's own list cleared: every case below is about
        // what the file inherits from here.
        DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->first->id]);
        DB::table('dam_asset_user_access')->where('asset_id', $this->file->id)->delete();

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

            DB::table('dam_asset_user_access')->where('asset_id', $this->file->id)->delete();
            DB::table('dam_asset_group_access')->where('asset_id', $this->file->id)->delete();
            $this->file->directories()->detach();
            Asset::query()->whereKey($this->file->id)->forceDelete();

            // The generation re-import case commits a whole AI-Generations chain and its asset; nothing else in
            // the suite may inherit them (they polluted InheritOnCreateTest's generation rows on PostgreSQL).
            DB::table('dam_generation_assets')->where('generation_id', 'like', 'inherit-lock:%')->delete();

            // The folders and assets the cases created — the fixture folder, the generation chain a re-import
            // builds, whatever a route committed — are removed by id, by the sweep in cleanUpCommittedFixtures():
            // every row above this test's own baseline, children before parents, folders deepest first.
            //
            // Selecting them by name instead, which is what stood here, deleted every folder in the database
            // called Images, AI-Generations or Misc. and the assets attached to them, whoever had created them,
            // and the sweep can report a row it destroyed but cannot bring it back (round 5, finding 3).

            foreach ([$this->second, $this->first] as $admin) {
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

    public function test_a_cascade_holding_the_folder_blocks_a_new_item_and_then_decides_its_list(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // A cascade at stage 3 and 4: it holds the folder row and has replaced its list with its own
        // principal, but has not committed.
        $holder->table('dam_directories')->where('id', $this->folder->id)->lockForUpdate()->first(['id']);
        $holder->table('dam_directory_user_access')->where('directory_id', $this->folder->id)->delete();
        $holder->table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->second->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait below is the folder lock's, not the read's: the same row read without one answers at
        // once, and still shows the pre-cascade list this session would otherwise have copied.
        $this->assertSame($this->folder->id, (int) DB::table('dam_directories')->where('id', $this->folder->id)->value('id'));
        $this->assertSame([$this->first->id], $this->listed());

        $started = microtime(true);

        try {
            $this->inherit();
            $this->fail('the new item did not wait for the cascade\'s folder row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the new item did not wait for the lock');
        $this->assertSame([], $this->listedFile(), 'the new item took a list while the cascade held the folder');

        $holder->commit();

        // Retried, it copies the list the cascade left — never the one the cascade replaced.
        $this->inherit();

        $this->assertSame([$this->second->id], $this->listedFile());
    }

    public function test_a_transition_holding_the_state_row_blocks_a_real_writer(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // A switch or rebuild just after AccessModel::transactionally() locked the state row.
        $this->assertSame(AccessModel::LISTS, $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->value('value'));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // Again the wait is the lock's: a plain read of the same row answers immediately.
        $this->assertSame(AccessModel::LISTS, DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'));

        $name = 'Inherit lock child '.Str::uuid();
        $started = microtime(true);

        try {
            // A real writer, not sharedly() on its own: every folder create runs through Directory::save(),
            // whose tree-lock transaction takes the fence's hold as its first statement.
            Directory::create(['name' => $name, 'parent_id' => $this->folder->id]);
            $this->fail('the folder create did not wait for the state row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the writer did not wait for the lock');
        $this->assertSame(0, Directory::query()->where('name', $name)->count(), 'the writer created a folder without the hold');

        $holder->commit();

        // Once the transition commits the same create goes through, and the new folder has its parent's
        // list. It only ever waited: the state row is the first lock in the order, so there is no cycle.
        $child = Directory::create(['name' => $name, 'parent_id' => $this->folder->id]);

        $this->assertSame([$this->first->id], DB::table('dam_directory_user_access')
            ->where('directory_id', $child->id)
            ->pluck('admin_id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    public function test_a_real_upload_into_a_cascading_folder_ends_with_the_cascades_list(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // A cascade mid-flight: it holds the folder row and has replaced its list with its own principal.
        $holder->table('dam_directories')->where('id', $this->folder->id)->lockForUpdate()->first(['id']);
        $holder->table('dam_directory_user_access')->where('directory_id', $this->folder->id)->delete();
        $holder->table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->second->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $before = Asset::query()->count();
        $started = microtime(true);

        // A real upload, through the real route, not a hand-made list write: it waits at stage 3 and its whole
        // transaction rolls back, asset included (Codex review of PR #74, finding 7).
        $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('inherit-lock-race-'.$this->suffix.'.png', 40, 20)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(500);

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the upload did not wait for the cascade');
        $this->assertSame($before, Asset::query()->count(), 'the upload committed an asset while the cascade held its folder');

        $holder->commit();
        DB::statement('RESET lock_timeout');

        // Retried, it takes the list the cascade left, never the one the cascade replaced.
        $id = (int) $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('inherit-lock-landed-'.$this->suffix.'.png', 40, 20)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(201)->json('files.0.id');

        $this->assertSame([$this->second->id], $this->listedOf($id));
    }

    public function test_an_inherit_waits_for_another_inherit_on_the_same_file(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // Another inherit at stage 4 on this file, mid-write.
        $holder->table('dam_assets')->where('id', $this->file->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->second->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait is the file row lock's: the folders are free and the same row reads without one at once.
        $this->assertSame($this->file->id, (int) DB::table('dam_assets')->where('id', $this->file->id)->value('id'));

        $started = microtime(true);

        try {
            $this->inherit();
            $this->fail('the second inherit did not wait for the first writer\'s row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the second inherit did not wait');
        $this->assertSame([], $this->listedFile(), 'the second inherit wrote while the first held the file');

        $holder->commit();

        // Both principals, and no duplicate-key failure: NOT EXISTS reads the first writer's committed row.
        $this->inherit();

        $this->assertSame([$this->first->id, $this->second->id], $this->listedFile());
    }

    public function test_an_inherit_cannot_interleave_with_a_replacement_of_the_same_file(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // A file save replacing this file's empty list: stage 4, then delete, then its own principals.
        $holder->table('dam_assets')->where('id', $this->file->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->where('asset_id', $this->file->id)->delete();
        $holder->table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->second->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $started = microtime(true);

        try {
            $this->inherit();
            $this->fail('the inherit did not wait for the replacement\'s row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the inherit did not wait');
        // Without stage 4 this is where the union appeared: the replacement's delete, then this insert, then
        // the replacement's own insert (Codex review of PR #74, finding 4).
        $this->assertSame([], $this->listedFile());

        $holder->commit();

        $this->inherit();

        $this->assertSame([$this->first->id, $this->second->id], $this->listedFile());
    }

    public function test_an_editor_save_as_new_waits_for_a_cascade_and_takes_its_folders_before_its_asset(): void
    {
        $asset = Asset::findOrFail((int) $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('inherit-lock-editable-'.$this->suffix.'.png', 200, 100)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(201)->json('files.0.id'));

        $opened = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();

        // The ruled order, statement by statement: the placement folder FOR SHARE before the asset row. A
        // folder row asked for after an asset row is the cycle a cascade closes (finding 3).
        $this->assertFoldersBeforeFiles($this->recordLocks(fn () => $this->saveAsNew($asset, $opened)->assertOk()));

        // And it really waits for a cascade that holds that folder.
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('dam_directories')->where('id', $this->folder->id)->lockForUpdate()->first(['id']);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
        $before = Asset::query()->count();
        $started = microtime(true);

        Exceptions::fake();
        $this->saveAsNew($asset, $opened)->assertStatus(500);

        Exceptions::assertReported(fn (QueryException $failed): bool => $failed->getCode() === self::LOCK_NOT_AVAILABLE);
        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the editor save did not wait for the cascade');
        $this->assertSame($before, Asset::query()->count(), 'the editor save committed a copy while the cascade held its folder');

        $holder->rollBack();
    }

    public function test_a_reimport_into_existing_folders_takes_its_folders_before_it_writes_the_asset(): void
    {
        [$manifest, $style, $root] = $this->generationPackage();
        $importer = app(GenerationPackageImporter::class);
        $first = $importer->importManifest(manifestPath: $manifest(null), ownerId: $this->first->id);

        // The re-import routes the asset into a folder that already exists, so firstOrCreate() creates nothing
        // and the pre-fix path took no tree lock and no folder lock before updating the asset (finding 3). The
        // wait itself is proved separately, against a real cascade.
        app(GenerationDirectory::class)->ensure(segments: ['AI-Generations', 'Images', 'Pre-Built-Styles', $style['category'], $style['title']]);

        $this->assertFoldersBeforeFiles($this->recordLocks(fn () => $importer->importManifest(manifestPath: $manifest($style), ownerId: $this->first->id)));

        $this->sweepGeneration((int) $first['asset_id']);
        File::deleteDirectory($root);
    }

    public function test_folder_and_file_rows_are_locked_in_one_order_across_batch_boundaries(): void
    {
        // Five folders whose `_lft` order is the reverse of their id order, because each new one is prepended,
        // and five files handed over deliberately shuffled. A writer that ordered only inside a batch, or that
        // skipped the global sort, would lock them in the wrong groups and the wrong sequence.
        $folders = [];

        for ($i = 0; $i < 5; $i++) {
            $folder = new Directory(['name' => 'Inherit lock order '.$i.' '.$this->suffix]);
            $folder->prependToNode($this->folder)->save();
            $folders[] = $folder;
        }

        $folderIds = array_map(fn (Directory $folder): int => (int) $folder->id, $folders);
        $byLft = DB::table('dam_directories')->whereIn('id', $folderIds)
            ->orderBy('_lft')->pluck('id')->map(fn ($id) => (int) $id)->all();

        // The proof only means anything while the two orders disagree.
        $this->assertNotSame($folderIds, $byLft, 'the fixture folders came out in id order, so this proves nothing');

        $files = [];

        for ($i = 0; $i < 5; $i++) {
            $file = Asset::factory()->create(['file_name' => 'inherit-lock-order-'.$i.'-'.$this->suffix.'.jpg']);
            // Every file in every folder, so one inherit has five folders and five files to order.
            $file->directories()->attach($folderIds);
            $files[] = (int) $file->id;
        }

        $ascending = $files;
        sort($ascending);
        $shuffled = [$files[3], $files[0], $files[4], $files[1], $files[2]];

        $this->assertNotSame($ascending, $shuffled, 'the shuffle did not shuffle anything');

        // static::BATCH, so this really is two rows per statement: three folder statements and three file
        // statements for five ids each.
        $writer = new class(app(AccessModel::class), app(AccessListService::class), app(DirectoryTreeLock::class)) extends AccessListWriter
        {
            protected const BATCH = 2;
        };

        $folderStatements = [];
        $fileStatements = [];
        $unordered = [];

        DB::listen(function ($query) use (&$folderStatements, &$fileStatements, &$unordered): void {
            $ids = array_values(array_map('intval', array_filter($query->bindings, 'is_int')));

            if ($ids === [] || preg_match('/for (share|no key update|update)/', $query->sql) !== 1) {
                return;
            }

            if (str_contains($query->sql, '"dam_directories"')) {
                $folderStatements[] = $ids;

                // Acquisition order, as opposed to the binding order below: within one statement it is the
                // ORDER BY that decides which row PostgreSQL locks first, so the clause has to be there.
                if (! str_contains($query->sql, 'order by "_lft" asc')) {
                    $unordered[] = $query->sql;
                }
            } elseif (str_contains($query->sql, '"dam_assets"')) {
                $fileStatements[] = $ids;

                if (! str_contains($query->sql, 'order by "dam_assets"."id" asc')) {
                    $unordered[] = $query->sql;
                }
            }
        });

        app(AccessModel::class)->sharedly(fn () => $writer->inheritAssets($shuffled));

        // Several statements really ran, and no statement carried more than a batch.
        $this->assertCount(3, $folderStatements, 'the folders were not locked in batches of two');
        $this->assertCount(3, $fileStatements, 'the files were not locked in batches of two');
        $this->assertSame([2, 2, 1], array_map('count', $folderStatements));
        $this->assertSame([2, 2, 1], array_map('count', $fileStatements));

        // Every locking statement orders its own rows...
        $this->assertSame([], $unordered, 'a locking statement carried no ORDER BY: '.implode(' | ', $unordered));

        // ...and the batches themselves are cut in that same order, which is what the bindings show: `_lft` for
        // folders, ascending id for files even though they arrived shuffled.
        $this->assertSame($byLft, array_merge(...$folderStatements), 'the folder batches were not cut in `_lft` order');
        $this->assertSame($ascending, array_merge(...$fileStatements), 'the file batches were not cut in ascending id order');

        foreach ($files as $fileId) {
            foreach (['dam_asset_user_access', 'dam_asset_group_access', 'dam_asset_directory'] as $table) {
                DB::table($table)->where('asset_id', $fileId)->delete();
            }

            Asset::query()->whereKey($fileId)->forceDelete();
        }
    }

    public function test_a_real_upload_waits_for_a_real_cascade_and_ends_with_its_list(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // A real cascade, mid-flight on the second session: AccessListService::applyToSubtree() itself, not a
        // hand-made list write (Codex review of PR #74 round 2, finding 3).
        $this->onHolder(fn () => app(AccessListService::class)
            ->applyToSubtree(directoryId: $this->folder->id, userIds: [$this->second->id], groupIds: []));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $before = Asset::query()->count();
        $started = microtime(true);

        // The controller catches its own failures, so the reason is read from what it reported. Without this
        // the fixture would pass on any 500 at all.
        //
        // What serialises the two here is the attach: `dam_asset_directory`'s foreign key needs KEY SHARE on
        // the folder row, and the cascade holds that row FOR UPDATE. So this proves the end-to-end property —
        // an upload racing a real cascade ends with the cascade's list, never a half of each — rather than
        // inheritAssets()'s own stage-3 lock, which is isolated by the two fixtures above.
        Exceptions::fake();

        $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('inherit-lock-cascade-'.$this->suffix.'.png', 40, 20)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(500);

        Exceptions::assertReported(fn (QueryException $failed): bool => $failed->getCode() === self::LOCK_NOT_AVAILABLE);
        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the upload did not wait for the cascade');
        $this->assertSame($before, Asset::query()->count(), 'the upload committed an asset while the cascade held its folder');

        $holder->commit();
        DB::statement('RESET lock_timeout');

        $id = (int) $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('inherit-lock-after-'.$this->suffix.'.png', 40, 20)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(201)->json('files.0.id');

        $this->assertSame([$this->second->id], $this->listedOf($id), 'the upload did not take the cascade\'s list');
    }

    public function test_a_reimport_into_existing_folders_waits_for_a_real_cascade(): void
    {
        [$manifest, $style, $root] = $this->generationPackage();
        $importer = app(GenerationPackageImporter::class);
        $importer->importManifest(manifestPath: $manifest(null), ownerId: $this->first->id);

        // The destination the styled manifest routes into, created ahead of the re-import so firstOrCreate()
        // finds it — the shape that used to take no tree lock and no folder lock at all.
        $destination = app(GenerationDirectory::class)
            ->ensure(segments: ['AI-Generations', 'Images', 'Pre-Built-Styles', $style['category'], $style['title']]);

        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $this->onHolder(fn () => app(AccessListService::class)
            ->applyToSubtree(directoryId: $destination->id, userIds: [$this->second->id], groupIds: []));

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
        $started = microtime(true);

        try {
            $importer->importManifest(manifestPath: $manifest($style), ownerId: $this->first->id);
            $this->fail('the re-import did not wait for the cascade');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the re-import did not wait');

        $holder->commit();
        DB::statement('RESET lock_timeout');

        $result = $importer->importManifest(manifestPath: $manifest($style), ownerId: $this->first->id);

        $this->assertSame([$this->second->id], $this->listedOf((int) $result['asset_id']), 'the re-import did not take the cascade\'s list');

        File::deleteDirectory($root);
        $this->sweepGeneration((int) $result['asset_id']);
    }

    public function test_two_inherits_of_the_same_principal_do_not_collide(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        // The duplicate-key shape: the other writer inserts the very principal this folder lists, from an
        // empty list, while holding the file at stage 4.
        $holder->table('dam_assets')->where('id', $this->file->id)->lock('for no key update')->first(['id']);
        $holder->table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->first->id]);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
        $started = microtime(true);

        try {
            $this->inherit();
            $this->fail('the second inherit did not wait for the first writer\'s row lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the second inherit did not wait');
        $this->assertSame([], $this->listedFile());

        $holder->commit();

        // Without stage 4 both writers would have missed the same absent principal and one would have lost to
        // the primary key. With it, the second reads the committed row and writes nothing.
        $this->inherit();

        $this->assertSame([$this->first->id], $this->listedFile());
    }

    public function test_a_placement_that_moves_between_discovery_and_the_asset_lock_is_retried(): void
    {
        $elsewhere = Directory::create(['name' => 'Inherit lock elsewhere '.$this->suffix]);
        DB::table('dam_directory_user_access')->insert(['directory_id' => $elsewhere->id, 'admin_id' => $this->second->id]);

        // The one placement writer that runs without the tree lock is the shared attach, so that is what the
        // second session does — committed, at the one moment that matters: after this writer read the
        // placements and before it locked the file (finding 3).
        $writer = $this->writerAttachingOnce($elsewhere->id, attempts: 1);
        $seen = 0;

        $writer->fencedAndTreeLocked(function () use ($writer, &$seen): void {
            $seen++;
            $folders = $writer->holdPlacementFolders(assetIds: [$this->file->id]);
            Asset::query()->whereKey($this->file->id)->lockedForGraph()->first(['id']);
            $writer->assertPlacementsUnchanged(assetIds: [$this->file->id], locked: $folders);
            $writer->inheritAssets([$this->file->id]);
        });

        // Once for the attempt the attach beat, once for the one that succeeded.
        $this->assertSame(2, $seen, 'the writer did not retry after the placements moved');
        $this->assertSame([$this->first->id, $this->second->id], $this->listedFile(), 'the retry did not inherit both folders');
    }

    public function test_a_placement_that_keeps_moving_gives_up_after_the_second_attempt(): void
    {
        $elsewhere = Directory::create(['name' => 'Inherit lock elsewhere '.$this->suffix]);
        $further = Directory::create(['name' => 'Inherit lock further '.$this->suffix]);

        $writer = $this->writerAttachingOnce([$elsewhere->id, $further->id], attempts: 2);
        $seen = 0;

        try {
            $writer->fencedAndTreeLocked(function () use ($writer, &$seen): void {
                $seen++;
                $folders = $writer->holdPlacementFolders(assetIds: [$this->file->id]);
                Asset::query()->whereKey($this->file->id)->lockedForGraph()->first(['id']);
                $writer->assertPlacementsUnchanged(assetIds: [$this->file->id], locked: $folders);
                $writer->inheritAssets([$this->file->id]);
            });
            $this->fail('a second interleaving did not escape');
        } catch (AccessPlacementsMoved $moved) {
            // The second attempt held the folders it found, and the second attach added one more.
            $this->assertCount(count($moved->locked) + 1, $moved->found);
        }

        $this->assertSame(2, $seen, 'the writer retried more than once');
        // Nothing written: both attempts rolled back rather than inheriting from folders nobody held.
        $this->assertSame([], $this->listedFile());
    }

    /**
     * A writer that attaches $directoryIds to the fixture file from the second session, committed, the first
     * $attempts times it discovers that file's placements.
     *
     * @param  int|list<int>  $directoryIds
     */
    private function writerAttachingOnce(int|array $directoryIds, int $attempts): AccessListWriter
    {
        $queue = array_values((array) $directoryIds);
        $assetId = (int) $this->file->id;
        $holder = self::HOLDER;

        return new class(app(AccessModel::class), app(AccessListService::class), app(DirectoryTreeLock::class), $queue, $attempts, $assetId, $holder) extends AccessListWriter
        {
            private int $fired = 0;

            public function __construct(
                AccessModel $model,
                AccessListService $lists,
                DirectoryTreeLock $treeLock,
                private array $queue,
                private int $attempts,
                private int $assetId,
                private string $holder,
            ) {
                parent::__construct($model, $lists, $treeLock);
            }

            public function holdPlacementFolders(array $assetIds, array $directoryIds = []): array
            {
                $placements = parent::holdPlacementFolders($assetIds, $directoryIds);

                if ($this->fired < $this->attempts && $this->queue !== []) {
                    $this->fired++;
                    DB::connection($this->holder)->table('dam_asset_directory')
                        ->insert(['asset_id' => $this->assetId, 'directory_id' => array_shift($this->queue)]);
                }

                return $placements;
            }
        };
    }

    /** Runs $work with $connection as the default one, so a service resolving DB::connection() uses it. */
    private function onHolder(callable $work): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection(self::HOLDER);

        try {
            return $work();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /**
     * A generation package on disk: a manifest writer taking an optional style, the style the re-import uses,
     * and the package root.
     *
     * @return array{0: callable(?array): string, 1: array, 2: string}
     */
    private function generationPackage(): array
    {
        $root = storage_path('app/private/inherit-lock-generation-'.$this->suffix);
        File::ensureDirectoryExists($root);
        $output = $root.'/output.png';
        $image = imagecreatetruecolor(3, 2);
        imagepng($image, $output);
        imagedestroy($image);

        $manifest = fn (?array $style): string => tap($root.'/generation.json', fn (string $path) => File::put($path, json_encode([
            'schema_version' => 1, 'generation_id' => 'inherit-lock:'.$this->suffix, 'title' => 'Locked generation',
            'kind'           => 'image', 'provider' => 'higgsfield-generate', 'model' => 'gpt_image_2', 'prompt' => 'Prompt',
            'style'          => $style, 'settings' => ['quality' => 'low'], 'references' => [],
            'output'         => ['path' => 'output.png', 'sha256' => hash_file('sha256', $output), 'mime' => 'image/png'],
        ], JSON_THROW_ON_ERROR)));

        return [$manifest, ['slug' => 'locked', 'title' => 'Locked style', 'category' => '04 Style', 'template' => 'T', 'version' => '1'], $root];
    }

    /** Removes an imported generation's committed rows. */
    private function sweepGeneration(int $assetId): void
    {
        foreach (['dam_asset_user_access', 'dam_asset_group_access', 'dam_asset_directory'] as $table) {
            DB::table($table)->where('asset_id', $assetId)->delete();
        }

        DB::table('dam_generation_assets')->where('asset_id', $assetId)->delete();
        Asset::query()->whereKey($assetId)->forceDelete();
    }

    /** The editor's save-as-new, through the real route. */
    private function saveAsNew(Asset $asset, array $opened): TestResponse
    {
        $render = imagecreatetruecolor(200, 100);
        imagefill($render, 0, 0, imagecolorallocate($render, 9, 180, 60));
        $path = tempnam(sys_get_temp_dir(), 'inherit-lock-render-').'.png';
        imagepng($render, $path);
        imagedestroy($render);

        return $this->post(route('admin.dam.assets.editor.save', $asset->id), [
            'revision' => $opened['revision'],
            'mode'     => 'new',
            'document' => json_encode($opened['document']),
            'render'   => new UploadedFile($path, 'render.png', 'image/png', null, true),
        ], ['Accept' => 'application/json']);
    }

    /**
     * The row locks $work took, as `<table>:<mode>`, in the order it took them. Lock order is not observable
     * from outside a session, so this reads the statements the connection ran.
     *
     * Two details decide whether this proves anything:
     * - the mode, because GenerationDirectory::ensure() legitimately locks the tree's root FOR UPDATE before
     *   anything else, so only the FOR SHARE on the placement folders answers "folders before files";
     * - plain UPDATEs, because an update takes the row's write lock just as surely as a locking read, and the
     *   generation importer's own asset write is an UPDATE rather than a lockedForGraph() read.
     *
     * @return list<string>
     */
    private function recordLocks(callable $work): array
    {
        $locks = [];

        DB::listen(function ($query) use (&$locks): void {
            foreach (['dam_directories', 'dam_assets'] as $table) {
                if (! str_contains($query->sql, '"'.$table.'"')) {
                    continue;
                }

                if (preg_match('/for (share|no key update|update)/', $query->sql, $mode) === 1) {
                    $locks[] = $table.':'.$mode[1];
                } elseif (str_starts_with($query->sql, 'update "'.$table.'"')) {
                    $locks[] = $table.':update';
                }
            }
        });

        $work();

        return $locks;
    }

    /** Asserts that $locks took the placement folders FOR SHARE before it took any asset row. */
    private function assertFoldersBeforeFiles(array $locks): void
    {
        $folders = array_search('dam_directories:share', $locks, true);
        $files = null;

        foreach ($locks as $at => $lock) {
            if (str_starts_with($lock, 'dam_assets:')) {
                $files = $at;

                break;
            }
        }

        $this->assertNotFalse($folders, 'no placement folder was locked FOR SHARE: '.implode(', ', $locks));
        $this->assertNotNull($files, 'no asset row was locked: '.implode(', ', $locks));
        $this->assertLessThan($files, $folders, 'an asset row was locked before the placement folders: '.implode(', ', $locks));
    }

    /** @return list<int> the admin ids on one asset's list, from this session */
    private function listedOf(int $assetId): array
    {
        return DB::table('dam_asset_user_access')->where('asset_id', $assetId)->orderBy('admin_id')
            ->pluck('admin_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The list write every attach point makes: the fence's hold, then the folder's list onto the file. */
    private function inherit(): void
    {
        app(AccessModel::class)->sharedly(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));
    }

    /** @return list<int> the admin ids on the fixture folder's list, from this session */
    private function listed(): array
    {
        return DB::table('dam_directory_user_access')
            ->where('directory_id', $this->folder->id)
            ->orderBy('admin_id')
            ->pluck('admin_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<int> the admin ids on the fixture file's list, from this session */
    private function listedFile(): array
    {
        return DB::table('dam_asset_user_access')
            ->where('asset_id', $this->file->id)
            ->orderBy('admin_id')
            ->pluck('admin_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
