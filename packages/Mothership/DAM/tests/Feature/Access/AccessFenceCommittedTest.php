<?php

namespace Mothership\DAM\Tests\Feature\Access;

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessFenceLedger;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Services\StylePressCatalogImporter;
use Mothership\DAM\Tests\Concerns\CommittedFixtureCleanup;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\DAM\Tests\Support\LockingClause;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * ADR 0010's fence itself, from a second session and with no DatabaseTransactions wrapper anywhere (sharing
 * slice 2a, Codex review of PR #74, findings 1, 2, 5 and 6).
 *
 * Every other fence case runs inside that wrapper, which supplies an outer transaction the code under test
 * did not open. Four things can only be told apart without it:
 * 1. The production fallback opens a transaction of its own, and takes the hold before the body runs, so an
 *    unfenced writer is still exclusive and still atomic (finding 5).
 * 2. A missing state row is no fence, and the fallback creates it and holds it exclusively rather than
 *    marking an upload fenced while the first switch rebuilds (finding 1).
 * 3. The style-catalog import waits on the state row instead of holding the tree and waiting for it, which
 *    is the cycle it used to close with a rebuild (finding 2).
 * 4. A hold belongs to the transaction that took it: a completed savepoint hands it up, a rolled-back one
 *    gives it back (finding 6).
 *
 * `lockForUpdate()` and `sharedLock()` are no-ops on SQLite, so PostgreSQL is where these are observable.
 * Waits are bounded by PostgreSQL's `lock_timeout` and taken on a second connection; nothing sleeps. These
 * rows are committed, so tearDown removes them and puts the state row back as it found it.
 */
final class AccessFenceCommittedTest extends DamConcurrencyTestCase
{
    use CommittedFixtureCleanup;

    private const HOLDER = 'access_fence_holder';

    private const TIMEOUT_MS = 300;

    private const WAITED_SECONDS = 0.25;

    /** How long to wait for a terminated backend to leave pg_stat_activity before calling the kill a failure. */
    private const TERMINATION_WAIT_MS = 2000;

    /** PostgreSQL's lock_not_available: the session waited for the holder and gave up. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    private bool $prepared = false;

    private ?Directory $folder = null;

    private ?Asset $file = null;

    private ?Admin $admin = null;

    private ?Admin $friend = null;

    private ?Role $role = null;

    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The fence is a row lock, and lockForUpdate() is a no-op on SQLite; other drivers skip.');
        }

        config([
            'database.connections.'.self::HOLDER => config('database.connections.'.DB::getDefaultConnection()),
            // The fence's production path, which is what findings 1 and 5 are about. The strict path is
            // proved under the wrapper by AccessFenceTest.
            'dam.access_fence_strict'            => false,
            'dam.access_logging'                 => true,
        ]);

        Storage::fake(Directory::getAssetDisk());

        // Every row from here on is this test's own, fixtures included: the teardown removes them itself and
        // the residue check removes and reports anything it missed. Recording it after the fixtures made their
        // deliberate removal look like residue, in the negative.
        $this->recordResidueBaseline();

        $this->suffix = (string) Str::uuid();

        // Captured before any case writes or deletes the row, and put back from cleanUpCommittedFixtures() on
        // every teardown path.
        $this->recordAccessModelState();

        $this->role = Role::factory()->create(['permission_type' => 'all', 'permissions' => null]);
        $this->admin = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        $this->friend = Admin::factory()->create(['role_id' => $this->role->id, 'status' => 1]);
        $this->actingAs($this->admin, 'admin');

        $this->folder = Directory::create(['name' => 'Fence committed '.$this->suffix]);
        $this->file = Asset::factory()->create(['file_name' => 'fence-committed-'.$this->suffix.'.jpg']);
        $this->file->directories()->attach($this->folder->id);

        DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->friend->id]);
        DB::table('dam_asset_user_access')->where('asset_id', $this->file->id)->delete();

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
            @unlink($this->logPath());
            File::deleteDirectory(storage_path('app/private/fence-catalog-'.$this->suffix));

            foreach (['dam_asset_user_access', 'dam_asset_group_access'] as $table) {
                DB::table($table)->where('asset_id', $this->file->id)->delete();
            }

            DB::table('dam_asset_directory')->where('asset_id', $this->file->id)->delete();
            Asset::query()->whereKey($this->file->id)->forceDelete();

            // The fixture folder, the folders each ordinary create added under it and the whole AI-Generations
            // chain the catalog import builds are removed by id, by the sweep in cleanUpCommittedFixtures():
            // every row above this test's own baseline, children before parents, folders deepest first.
            //
            // Selecting them by name instead, which is what stood here, deleted every folder in the database
            // called Images, AI-Generations or Pre-Built-Styles and the assets attached to them, whoever had
            // created them, and the sweep can report a row it destroyed but cannot bring it back (round 5,
            // finding 3).

            foreach ([$this->friend, $this->admin] as $admin) {
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

    public function test_the_production_fallback_takes_the_hold_before_it_writes_anything(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // A switch or rebuild just after AccessModel::transactionally() locked the state row.
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->first(['value']);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        // The wait is the lock's, not the read's: the same row read without one answers at once.
        $this->assertNotNull(DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'));

        $started = microtime(true);

        try {
            // No transaction open and no hold: exactly the unfenced caller the fallback exists for.
            $this->assertSame(0, DB::transactionLevel());
            app(AccessListWriter::class)->inheritAssets([$this->file->id]);
            $this->fail('the unfenced writer did not wait for the state row');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the unfenced writer did not wait');
        $this->assertSame([], $this->listed(), 'the unfenced writer wrote a list while a transition held the state row');

        $holder->rollBack();

        app(AccessListWriter::class)->inheritAssets([$this->file->id]);

        $this->assertSame([$this->friend->id], $this->listed());
        $this->assertContains('access_fence_missing', $this->loggedEvents());
    }

    public function test_the_production_fallback_rolls_its_whole_body_back(): void
    {
        DB::table('dam_asset_user_access')->insert(['asset_id' => $this->file->id, 'admin_id' => $this->admin->id]);

        // A writer whose body fails after its delete. Without a transaction of its own the delete would have
        // committed on its own and the file would be left with no list at all (finding 5).
        app()->instance(AccessListWriter::class, new class(app(AccessModel::class), app(AccessListService::class), app(DirectoryTreeLock::class)) extends AccessListWriter
        {
            protected function clearFileLists(array $assetIds): void
            {
                parent::clearFileLists($assetIds);

                throw new RuntimeException('halfway');
            }
        });

        $this->assertSame(0, DB::transactionLevel());

        try {
            app(AccessListWriter::class)->recomputeAssets([$this->file->id]);
            $this->fail('the failing body did not propagate');
        } catch (RuntimeException $halfway) {
            $this->assertSame('halfway', $halfway->getMessage());
        }

        $this->assertSame([$this->admin->id], $this->listed(), 'the delete committed without its inserts');
    }

    public function test_a_missing_state_row_is_no_fence_and_an_upload_waits_for_the_first_switch(): void
    {
        // A database that predates migration 2026_09_26_000001 and has never run a switch.
        DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
        app(AccessModel::class)->flush();

        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // The first switch, at AccessModel::lockRow()'s first statements: it creates the row and holds it.
        $holder->table('dam_configuration')->insert(['key' => AccessModel::KEY, 'value' => AccessModel::GRANTS]);
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->first(['value']);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $before = Asset::query()->count();
        $started = microtime(true);

        // A real upload through the real route. Its sharedly() finds no row — the creator has not committed —
        // so it records no hold, and the late fence waits on the row the switch is holding. Before this fix
        // the upload called itself fenced with nothing locked and committed an empty list across the switch.
        $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('fence-race-'.$this->suffix.'.png', 40, 20)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(500);

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the upload did not wait for the state row');
        $this->assertSame($before, Asset::query()->count(), 'the upload committed an asset while the switch held the state row');

        $holder->rollBack();

        // Once the row is there — as the migration now leaves it — the same upload lands with the folder's list.
        DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::GRANTS]);
        app(AccessModel::class)->flush();
        DB::statement('RESET lock_timeout');

        $id = (int) $this->postJson(route('admin.dam.assets.upload'), [
            'files'        => [UploadedFile::fake()->image('fence-landed-'.$this->suffix.'.png', 40, 20)],
            'directory_id' => $this->folder->id,
        ])->assertStatus(201)->json('files.0.id');

        $this->assertSame([$this->friend->id], DB::table('dam_asset_user_access')->where('asset_id', $id)->pluck('admin_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_the_style_catalog_import_waits_on_the_state_row_before_it_touches_the_tree(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        // A rebuild or a switch, holding the state row and about to take the tree lock.
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->first(['value']);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");

        $before = Directory::query()->count();
        $started = microtime(true);

        try {
            // The real importer. Its folder pass used to open a plain DB::transaction and let
            // GenerationDirectory::ensure() lock the tree's root FOR UPDATE before anything reached the fence,
            // which is the other half of a deadlock with the holder above.
            app(StylePressCatalogImporter::class)->importManifest(manifestPath: $this->catalogManifest());
            $this->fail('the catalog import did not wait for the state row');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started, 'the catalog import did not wait');
        $this->assertSame($before, Directory::query()->count(), 'the catalog import created a folder without the fence');

        $holder->rollBack();
        DB::statement('RESET lock_timeout');
        @unlink($this->logPath());

        // Unblocked, and in the ruled order: the state row, then the tree lock, then the tree's root. The
        // advisory lock has to come before that FOR UPDATE, or a transition holding the state row and waiting
        // for the tree closes a cycle with an import holding the root and waiting for the state row.
        $order = [];

        DB::listen(function ($query) use (&$order): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $order[] = 'tree lock';
            } elseif (str_contains($query->sql, '"dam_directories"') && str_contains($query->sql, 'for update')) {
                $order[] = 'root row';
            }
        });

        $this->assertTrue(app(StylePressCatalogImporter::class)->importManifest(manifestPath: $this->catalogManifest())['ok']);
        $this->assertSame(1, Directory::query()->where('name', 'Fence catalog '.$this->suffix)->count());
        $this->assertSame('tree lock', $order[0] ?? null, 'the import locked the tree root before it took the tree lock: '.implode(', ', $order));
        $this->assertContains('root row', $order);

        // And it was fenced from its first statement, not rescued by the late fence: nothing logged.
        $this->assertNotContains('access_fence_missing', $this->loggedEvents());
    }

    /** A one-entry style catalog on disk, routed into a folder named after this case. */
    private function catalogManifest(): string
    {
        $root = storage_path('app/private/fence-catalog-'.$this->suffix);
        File::ensureDirectoryExists($root.'/installed');
        config(['stylepress.import_root' => $root.'/installed']);

        $manifest = $root.'/catalog.json';
        File::put($manifest, json_encode([
            'schema_version' => 1,
            'version'        => 'fence-'.$this->suffix,
            'count'          => 1,
            'categories'     => ['Fence catalog '.$this->suffix],
            'presets'        => [[
                'slug'          => 'fence-style', 'title' => 'Fence style', 'category' => 'Fence catalog '.$this->suffix,
                'categoryLabel' => 'Fence catalog', 'template' => 'A template', 'slots' => 'Subject',
                'generatable'   => true, 'kind' => 'style', 'sourceNote' => 'note.md', 'references' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        return $manifest;
    }

    public function test_a_hold_belongs_to_the_transaction_that_took_it(): void
    {
        $model = app(AccessModel::class);

        // A completed savepoint hands its locks to the enclosing transaction, so the hold survives it.
        DB::transaction(function () use ($model) {
            $this->assertFalse($model->holdsRow());
            $model->sharedly(fn () => $this->assertTrue($model->holdsRow()));
            $this->assertTrue($model->holdsRow(), 'a completed savepoint gave up a lock the database keeps');
        });

        $this->assertFalse($model->holdsRow());

        // A rolled-back savepoint gives them back.
        DB::transaction(function () use ($model) {
            try {
                $model->sharedly(fn () => throw new RuntimeException('rolled back'));
            } catch (RuntimeException) {
                // Swallowed: the caller carries on where the savepoint's lock is gone.
            }

            $this->assertFalse($model->holdsRow(), 'a rolled-back savepoint kept a lock the database released');
        });

        // An inner rollback leaves an enclosing hold alone.
        $model->sharedly(function () use ($model) {
            try {
                DB::transaction(fn () => throw new RuntimeException('inner'));
            } catch (RuntimeException) {
                // Swallowed: the outer hold was taken before this savepoint existed.
            }

            $this->assertTrue($model->holdsRow());
        });

        $this->assertFalse($model->holdsRow());

        $this->assertAnOrdinaryCreateIsStillFenced('after nested savepoints');
    }

    public function test_no_transaction_inherits_a_hold_after_an_ending_that_fired_no_event(): void
    {
        foreach (['commit', 'rollback'] as $ending) {
            $model = app(AccessModel::class);

            DB::beginTransaction();
            $model->holdingRow(fn () => null);

            $this->assertTrue($model->holdsRow(), $ending.': the hold was not recorded');

            // Laravel resets the transaction level without firing TransactionCommitted or
            // TransactionRolledBack when a commit or a rollback loses the connection
            // (ManagesTransactions::handleCommitTransactionException and handleRollBackException). Ending the
            // transaction with the dispatcher unset reproduces exactly that state — the level reset, no event
            // — with no faked exception, and is the trick DatabaseTransactions itself uses.
            $this->withoutConnectionEvents(fn () => $ending === 'commit' ? DB::commit() : DB::rollBack());

            $this->assertSame(0, DB::transactionLevel(), $ending.': the ending did not reset the level');

            DB::beginTransaction();

            // Detection: the new transaction locked nothing, and says so.
            $this->assertFalse($model->holdsRow(), $ending.': a new transaction inherited a stale hold');

            // Safety: the write takes the hold itself, so it is fenced whatever the bookkeeping said.
            $reads = $this->stateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));

            $this->assertGreaterThan(0, $reads, $ending.': the write never read the state row');
            $this->assertSame([$this->friend->id], $this->listed(), $ending.': the list did not land');

            DB::rollBack();

            $this->assertAnOrdinaryCreateIsStillFenced('after a silent '.$ending);
        }
    }

    public function test_an_after_commit_callback_does_not_inherit_the_committed_holds(): void
    {
        // Laravel runs afterCommit callbacks inside commit(), before it fires TransactionCommitted
        // (ManagesTransactions::commit → transactionsManager->commit → fireConnectionEvent('committed')), so a
        // transaction opened in one used to look fenced. These callbacks run only when the level reaches 0, so
        // this case exists here and not under the wrapper.
        $model = app(AccessModel::class);
        $ran = false;

        $model->sharedly(function () use ($model, &$ran): void {
            DB::afterCommit(function () use ($model, &$ran): void {
                $ran = true;

                // The new transaction opens *before* anything consults the record, which is the shape the
                // review reproduced: at this point the committed hold is still written down, and asking
                // holdsRow() first would have cleared it (round 3, finding 4).
                DB::beginTransaction();

                $this->assertFalse($model->holdsRow(), 'an afterCommit callback inherited the committed hold');

                $reads = $this->stateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));

                $this->assertGreaterThan(0, $reads, 'the afterCommit write never took a locking read of the state row');

                DB::commit();
            });
        });

        $this->assertTrue($ran, 'the afterCommit callback never ran');
        $this->assertSame([$this->friend->id], $this->listed());

        $this->assertAnOrdinaryCreateIsStillFenced('after an afterCommit callback');
    }

    public function test_a_commit_that_really_loses_the_connection_leaves_no_hold_behind(): void
    {
        $model = app(AccessModel::class);
        $pid = $this->backendPid();
        $terminated = false;
        $lost = null;

        // A real lost connection, not a simulation: the second session terminates this one's backend while its
        // transaction is open, so COMMIT fails with "server closed the connection unexpectedly" and Laravel's
        // own handleCommitTransactionException() resets the level and fires no event (round 3, finding 4).
        try {
            DB::transaction(function () use ($model, $pid, &$terminated): void {
                $model->holdingRow(fn () => null);

                $this->assertTrue($model->holdsRow());

                $terminated = $this->terminateThisBackend($pid);
            });
        } catch (PDOException $thrown) {
            // Only the exception this case is about. Catching Throwable swallowed both the fail() below and any
            // assertion inside the transaction, so the test could pass having lost nothing (round 4, finding 1).
            $lost = $thrown;
        }

        $this->assertTrue($terminated, 'the backend was never terminated, so this proves nothing about a lost commit');
        $this->assertNotNull($lost, 'the commit did not lose the connection');
        $this->assertLostConnection($lost);

        // Before reconnecting, because reconnecting zeroes the level itself (Connection::setPdo()) and would hide
        // a vendor path that had left it standing.
        $this->assertSame(0, DB::transactionLevel(), 'the lost commit left the transaction level standing');

        DB::reconnect();

        $this->assertNoHoldAndAFencedWrite($model, 'after a lost commit');
    }

    public function test_a_rollback_that_really_loses_the_connection_leaves_no_hold_behind(): void
    {
        $model = app(AccessModel::class);
        $pid = $this->backendPid();
        $terminated = false;
        $lost = null;

        // The same, one step earlier: the body throws onto a dead connection, so ROLLBACK fails and
        // handleRollBackException() resets the level with no event either. What escapes is the failed ROLLBACK,
        // not the body's own exception, which is why catching Throwable here caught the wrong thing.
        try {
            DB::transaction(function () use ($model, $pid, &$terminated): void {
                $model->holdingRow(fn () => null);

                $terminated = $this->terminateThisBackend($pid);

                throw new RuntimeException('rolling back onto a dead connection');
            });
        } catch (PDOException $thrown) {
            $lost = $thrown;
        }

        $this->assertTrue($terminated, 'the backend was never terminated, so this proves nothing about a lost rollback');
        $this->assertNotNull($lost, 'the rollback did not lose the connection');
        $this->assertLostConnection($lost);
        $this->assertSame(0, DB::transactionLevel(), 'the lost rollback left the transaction level standing');

        DB::reconnect();

        $this->assertNoHoldAndAFencedWrite($model, 'after a lost rollback');
    }

    public function test_a_reconnect_drops_the_holds_its_predecessor_took(): void
    {
        $model = app(AccessModel::class);

        DB::beginTransaction();
        $model->holdingRow(fn () => null);

        $this->assertTrue($model->holdsRow());

        // The lock goes with the socket, so no hold may survive. Connection::setPdo() also zeroes the
        // transaction level, which is why holdsRow()'s PDO-identity check is defence in depth here rather than
        // the thing that catches it: the level check gets there first, and no fixture can tell them apart
        // while Laravel keeps doing that.
        DB::reconnect();

        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertFalse($model->holdsRow(), 'a hold survived a reconnect');

        $reads = $this->stateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));

        $this->assertGreaterThan(0, $reads);
        $this->assertSame([$this->friend->id], $this->listed());

        $this->assertAnOrdinaryCreateIsStillFenced('after a reconnect');
    }

    public function test_a_rollback_to_an_earlier_level_drops_every_hold_it_released(): void
    {
        $model = app(AccessModel::class);

        DB::beginTransaction();
        $outer = DB::transactionLevel();
        DB::beginTransaction();
        $model->holdingRow(fn () => null);
        DB::beginTransaction();
        // Two holds, at two levels above $outer, so a prune that stepped down one level at a time would leave
        // the shallower one recorded.
        $model->holdingRow(fn () => null);

        $this->assertTrue($model->holdsRow());

        // One event for several levels: the level on it is what decides, not a count of endings.
        DB::rollBack($outer);

        $this->assertSame($outer, DB::transactionLevel());
        $this->assertFalse($model->holdsRow(), 'a hold survived the rollback that released it');

        $reads = $this->stateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));

        $this->assertGreaterThan(0, $reads);
        $this->assertSame([$this->friend->id], $this->listed());

        DB::rollBack();

        $this->assertAnOrdinaryCreateIsStillFenced('after a rollback to an earlier level');
    }

    /**
     * The ledger is a singleton because a connection's transactions outlive every scoped binding: a queue worker
     * rebuilds those between jobs and a request between requests, while the hold stays taken (round 4, finding 2).
     */
    public function test_the_ledger_survives_the_scoped_bindings_being_rebuilt(): void
    {
        $model = app(AccessModel::class);

        DB::beginTransaction();
        $model->holdingRow(fn () => null);

        $this->assertTrue($model->holdsRow());

        app()->forgetScopedInstances();

        $rebuilt = app(AccessModel::class);

        $this->assertNotSame($model, $rebuilt, 'the scoped binding was not rebuilt, so this proves nothing');
        $this->assertTrue($rebuilt->holdsRow(), 'the rebuilt AccessModel lost the hold its predecessor took');
        $this->assertSame(app(AccessFenceLedger::class), app(AccessFenceLedger::class), 'the ledger is not a singleton');

        // And the baseline came with it: the write through the rebuilt bindings is recognised as fenced rather
        // than reported, and still takes its own lock.
        $reads = $this->stateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));

        $this->assertGreaterThan(0, $reads);
        $this->assertNotContains('access_fence_missing', $this->loggedEvents(), 'the rebuilt bindings reported a fenced write as unfenced');
        $this->assertSame([$this->friend->id], $this->listed());

        DB::rollBack();

        $this->assertAnOrdinaryCreateIsStillFenced('after the scoped bindings were rebuilt');
    }

    /**
     * Two sessions, interleaved, at the same physical transaction level. Levels alone cannot tell them apart,
     * which is why every entry in the ledger is keyed by connection: one session's ending must leave the other's
     * hold exactly as it was (round 4, finding 2).
     */
    public function test_holds_on_one_connection_are_not_holds_on_another(): void
    {
        $model = app(AccessModel::class);
        $ledger = app(AccessFenceLedger::class);
        $holder = DB::connection(self::HOLDER);

        DB::beginTransaction();
        $model->holdingRow(fn () => null);

        // The second session takes the same row shared — compatible, so nothing blocks — and its beginning and
        // ending reach the ledger through the same connection events the DAM provider listens to.
        $holder->beginTransaction();
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->sharedLock()->first(['value']);
        $ledger->record($holder);

        $this->assertSame(DB::transactionLevel(), $holder->transactionLevel(), 'the two sessions are not at the same level');
        $this->assertTrue($ledger->holds($holder));
        $this->assertTrue($model->holdsRow());

        $holder->commit();

        $this->assertFalse($ledger->holds($holder), 'the second session kept a hold it committed away');
        $this->assertTrue($model->holdsRow(), 'this session lost its hold when the other session committed');

        DB::commit();

        $this->assertFalse($model->holdsRow());
        $this->assertFalse($ledger->holds($holder));

        $this->assertAnOrdinaryCreateIsStillFenced('after two interleaved sessions');
    }

    public function test_a_stale_held_record_cannot_skip_the_lock(): void
    {
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('dam_configuration')->where('key', AccessModel::KEY)->lockForUpdate()->first(['value']);

        // The bookkeeping lies the way every eventless ending used to. The write must still wait for the
        // transition above, which is only possible if it takes the lock rather than trusting the record.
        app()->instance(AccessModel::class, new class extends AccessModel
        {
            public function holdsRow(): bool
            {
                return true;
            }
        });
        app()->forgetInstance(AccessListWriter::class);

        DB::statement("SET lock_timeout = '".self::TIMEOUT_MS."ms'");
        $started = microtime(true);

        try {
            app(AccessListWriter::class)->inheritAssets([$this->file->id]);
            $this->fail('a write with a stale "held" record skipped the lock');
        } catch (QueryException $timedOut) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $timedOut->getCode());
        }

        $this->assertGreaterThanOrEqual(self::WAITED_SECONDS, microtime(true) - $started);
        $this->assertSame([], $this->listed());

        $holder->rollBack();
    }

    /** Runs $work with the connection's event dispatcher unset, as an ending that fires no event leaves it. */
    private function withoutConnectionEvents(callable $work): void
    {
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $connection->unsetEventDispatcher();

        try {
            $work();
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }
    }

    /**
     * How many times $work took a *locking* read of the DAM_ACCESS_MODEL row. That read is the fence, and this
     * class only ever runs on PostgreSQL, so the clause is required rather than assumed: counting any SELECT
     * would have passed on a read that locked nothing (round 3, finding 4). The pattern itself comes from
     * LockingClause, which takes each driver's from Laravel's grammars (round 4, finding 4).
     */
    private function stateRowReads(callable $work): int
    {
        $reads = 0;

        DB::listen(function ($query) use (&$reads): void {
            if (str_starts_with($query->sql, 'select')
                && str_contains($query->sql, 'dam_configuration')
                && LockingClause::matches($query->sql)) {
                $reads++;
            }
        });

        $work();

        return $reads;
    }

    /** This session's PostgreSQL backend pid, for the second session to terminate. */
    private function backendPid(): int
    {
        return (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    }

    /**
     * Terminates this session's backend from the second session, and returns whether PostgreSQL both signalled it
     * and stopped reporting it.
     *
     * pg_terminate_backend's own return value says only that SIGTERM was sent, and the backend dies
     * asynchronously, so waiting for it to leave pg_stat_activity is what makes the loss certain rather than
     * likely — the commit that follows cannot win a race with a signal that has already landed.
     */
    private function terminateThisBackend(int $pid): bool
    {
        $holder = DB::connection(self::HOLDER);

        if (! (bool) $holder->selectOne('select pg_terminate_backend(?) as signalled', [$pid])->signalled) {
            return false;
        }

        for ($waited = 0; $waited < self::TERMINATION_WAIT_MS; $waited += 10) {
            if ((int) $holder->selectOne('select count(*) as alive from pg_stat_activity where pid = ?', [$pid])->alive === 0) {
                return true;
            }

            usleep(10_000);
        }

        return false;
    }

    /** That $thrown is the lost connection, by Laravel's own detector rather than by a message this test guesses. */
    private function assertLostConnection(PDOException $thrown): void
    {
        $this->assertTrue(
            (new LostConnectionDetector)->causedByLostConnection($thrown),
            'the statement failed for some other reason: '.$thrown->getMessage()
        );
    }

    /**
     * After a lifecycle that ended without an event: nothing is recorded as held, and the next write still takes
     * a locking read of the state row, so nothing goes unfenced.
     */
    private function assertNoHoldAndAFencedWrite(AccessModel $model, string $what): void
    {
        DB::beginTransaction();

        $this->assertFalse($model->holdsRow(), $what.': a new transaction inherited a stale hold');

        $reads = $this->stateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$this->file->id]));

        $this->assertGreaterThan(0, $reads, $what.': the write took no locking read of the state row');
        $this->assertSame([$this->friend->id], $this->listed(), $what.': the list did not land');

        DB::rollBack();

        $this->assertAnOrdinaryCreateIsStillFenced($what);
    }

    /**
     * Strict mode on, and then a folder create with the shape every DAM writer has in production: no
     * transaction of its own, DirectoryTreeLock::run() opening the outermost one.
     *
     * The lifecycle cases end with a stale record cleared and a writer that fences itself, which is the safety
     * property. That an ordinary write still *works* afterwards is a different property, and they are not the
     * same evidence (round 4, finding 2): with `access_fence_strict` on, a create whose fence had gone wrong
     * throws AccessFenceMissing instead of writing, so a ledger that lost its baseline during the lifecycle is
     * caught here. The statements also say the state row was locked before the tree, which is ADR 0010's order.
     */
    private function assertAnOrdinaryCreateIsStillFenced(string $what): void
    {
        $strict = config('dam.access_fence_strict');
        config(['dam.access_fence_strict' => true]);

        try {
            $parent = $this->listedOn($this->folder->id);
            $child = null;

            // Or the inheritance assertion below would hold for an empty list, which is no assertion at all.
            $this->assertNotSame([], $parent, $what.': the fixture folder has no list to inherit');

            $order = $this->lockOrder(function () use ($what, &$child): void {
                $child = Directory::create([
                    'name'      => 'Fence committed '.$this->suffix.' '.$what,
                    'parent_id' => $this->folder->id,
                ]);
            });

            $this->assertSame(
                [],
                array_values(array_diff($parent, $this->listedOn($child->id))),
                $what.': the child did not inherit its parent\'s list'
            );

            $this->assertSame(['state row', 'tree lock'], $order, $what.': the create did not take the state row before the tree');
        } finally {
            config(['dam.access_fence_strict' => $strict]);
        }
    }

    /**
     * The order in which $work took ADR 0010's two locks, from the statements themselves: the locking read of
     * the state row, and ADR 0004's pg_advisory_xact_lock. Only the first of each, since run() is re-entrant.
     *
     * @return list<string>
     */
    private function lockOrder(callable $work): array
    {
        $order = [];

        DB::listen(function ($query) use (&$order): void {
            if (str_contains($query->sql, 'dam_configuration')
                && str_starts_with($query->sql, 'select')
                && LockingClause::matches($query->sql)) {
                $order[] = 'state row';
            } elseif (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $order[] = 'tree lock';
            }
        });

        $work();

        return array_values(array_unique($order));
    }

    /** @return list<int> the admin ids on one folder's list */
    private function listedOn(int $directoryId): array
    {
        return DB::table('dam_directory_user_access')
            ->where('directory_id', $directoryId)
            ->orderBy('admin_id')
            ->pluck('admin_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function logPath(): string
    {
        return storage_path('logs/folder-access.jsonl');
    }

    /** @return list<string> the events written to the folder-access log during this case */
    private function loggedEvents(): array
    {
        if (! is_file($this->logPath())) {
            return [];
        }

        return array_map(
            fn (string $line): string => json_decode($line, true, 8, JSON_THROW_ON_ERROR)['event'],
            file($this->logPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
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
