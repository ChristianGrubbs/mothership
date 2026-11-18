<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessFenceMissing;
use Mothership\DAM\Services\Access\AccessListWriter;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryTreeLock;
use Mothership\DAM\Tests\Support\LockingClause;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * ADR 0010's fence (sharing slice 2a): every transaction that writes access-list rows holds the
 * DAM_ACCESS_MODEL row FOR SHARE, so no list write can commit across a switch's activation.
 *
 * What the check has to get right is that it reads the same under Laravel's DatabaseTransactions wrapper,
 * which every DAM feature test runs inside, as it does in production, where there is no wrapper. It does
 * that by asking only whether a hold exists — never how deep a transaction is — because the wrapper changes
 * depths and Eloquent's own createOrFirst() nests a savepoint only when a transaction is already open.
 *
 * The committed proof that the hold is a real row lock, and that a switch blocks a writer, is
 * AccessInheritLockingCommittedTest on PostgreSQL; sharedLock() is a no-op on SQLite.
 */

beforeEach(function () {
    $this->parent = Directory::create(['name' => 'Fence parent', 'parent_id' => null]);
    $this->admin = fenceAdmin();

    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->parent->id, 'admin_id' => $this->admin->id]);
});

it('runs a list write when the tree lock has taken the hold', function () {
    // The path every folder create takes: Directory::save() through DirectoryTreeLock::run().
    $child = Directory::create(['name' => 'Fence child', 'parent_id' => $this->parent->id]);

    expect(fenceListed($child->id))->toBe([$this->admin->id]);
});

it('throws in tests when a list write has no hold at all', function () {
    expect(fn () => app(AccessListWriter::class)->inheritFolder($this->parent->id))
        ->toThrow(AccessFenceMissing::class);
});

it('names the writer that ran unfenced, so the defect is the message', function () {
    $file = Asset::factory()->create(['file_name' => 'fence-unheld.jpg']);

    try {
        app(AccessListWriter::class)->inheritAssets([$file->id]);
        $this->fail('an unfenced inheritAssets() did not throw');
    } catch (AccessFenceMissing $missing) {
        expect($missing->writer)->toBe('inheritAssets')
            ->and($missing->getMessage())->toContain('AccessModel::sharedly()');
    }
});

it('writes the list anyway on the production path, taking the hold late', function () {
    config(['dam.access_fence_strict' => false]);

    $file = Asset::factory()->create(['file_name' => 'fence-late.jpg']);
    $file->directories()->attach($this->parent->id);

    app(AccessListWriter::class)->inheritAssets([$file->id]);

    // A lost list write is worse than a late lock: under `lists` the file would otherwise be Only me to
    // everyone the folder is shared with.
    expect(fenceListed(assetId: $file->id))->toBe([$this->admin->id]);
});

it('counts a rebuild\'s exclusive hold as holding, so the rebuild can use the same writers', function () {
    $held = app(AccessModel::class)->transactionally(fn () => app(AccessModel::class)->holdsRow());

    expect($held)->toBeTrue();
});

it('leaves no hold behind once the taker\'s transaction has ended', function () {
    $model = app(AccessModel::class);

    $model->sharedly(fn () => expect($model->holdsRow())->toBeTrue());

    expect($model->holdsRow())->toBeFalse();
});

it('leaves no hold behind when the taker\'s transaction rolls back', function () {
    $model = app(AccessModel::class);

    expect(fn () => $model->sharedly(fn () => throw new RuntimeException('rolled back')))
        ->toThrow(RuntimeException::class);

    expect($model->holdsRow())->toBeFalse();
});

it('does not take a second hold when one is already held', function () {
    $model = app(AccessModel::class);

    // The nesting every fenced writer produces: sharedly() outside, a folder create — and so
    // DirectoryTreeLock::run() — inside it. The inner call joins the hold rather than taking another.
    $model->sharedly(function () use ($model) {
        expect($model->holdsRow())->toBeTrue();

        $child = Directory::create(['name' => 'Fence nested child', 'parent_id' => $this->parent->id]);

        expect(fenceListed($child->id))->toBe([$this->admin->id]);
    });

    expect($model->holdsRow())->toBeFalse();
});

it('reports a folder create nested in a caller\'s own unfenced transaction', function () {
    // The detection the review asked for back (finding 2). A caller that opened its own transaction and then
    // creates a folder has locked whatever it locked before the state row, which is the reverse of the order
    // dam:access:switch takes them in. run() takes nothing at that depth, so the writer reports it rather
    // than quietly taking a late hold that could deadlock.
    expect(fn () => DB::transaction(fn () => Directory::create(['name' => 'Fence inner child', 'parent_id' => $this->parent->id])))
        ->toThrow(AccessFenceMissing::class);

    expect(Directory::query()->where('name', 'Fence inner child')->exists())->toBeFalse();
});

it('fences a folder create through firstOrCreate, wrapper or no wrapper', function () {
    // Eloquent's createOrFirst() wraps its insert in a transaction only when one is already open
    // (Builder::withSavepointIfNeeded), which the test wrapper makes always true and production almost never.
    // DAM's own builder takes the tree lock before that savepoint can open, so run() is the outermost
    // transaction either way and this create is fenced in a test exactly as it is in production.
    $child = Directory::firstOrCreate(['parent_id' => $this->parent->id, 'name' => 'Fence first-or-create']);

    expect(fenceListed($child->id))->toBe([$this->admin->id]);

    // And again, idempotently: the second call finds the folder and writes no second list.
    expect(Directory::firstOrCreate(['parent_id' => $this->parent->id, 'name' => 'Fence first-or-create'])->id)->toBe($child->id)
        ->and(fenceListed($child->id))->toBe([$this->admin->id]);
});

it('keeps a hold a completed savepoint took, because the database does', function () {
    $model = app(AccessModel::class);

    // A nested sharedly() is a savepoint. PostgreSQL hands the locks a released savepoint took to its parent,
    // so the hold belongs to the enclosing transaction from then on (Codex review of PR #74, finding 6).
    DB::transaction(function () use ($model) {
        expect($model->holdsRow())->toBeFalse();

        $model->sharedly(fn () => expect($model->holdsRow())->toBeTrue());

        expect($model->holdsRow())->toBeTrue();
    });

    expect($model->holdsRow())->toBeFalse();
});

it('drops a hold the savepoint that took it rolled back', function () {
    $model = app(AccessModel::class);

    DB::transaction(function () use ($model) {
        try {
            $model->sharedly(fn () => throw new RuntimeException('rolled back'));
        } catch (RuntimeException) {
            // Swallowed on purpose: the caller carries on in its own transaction, where the row lock the
            // rolled-back savepoint took is gone.
        }

        expect($model->holdsRow())->toBeFalse();
    });
});

it('keeps an enclosing hold through an inner rollback', function () {
    $model = app(AccessModel::class);

    $model->sharedly(function () use ($model) {
        expect($model->holdsRow())->toBeTrue();

        try {
            DB::transaction(fn () => throw new RuntimeException('inner'));
        } catch (RuntimeException) {
            // The inner savepoint took no lock of its own, so the outer hold is untouched.
        }

        expect($model->holdsRow())->toBeTrue();
    });

    expect($model->holdsRow())->toBeFalse();
});

it('logs access_fence_missing on the production path', function () {
    config(['dam.access_fence_strict' => false, 'dam.access_logging' => true]);

    $log = storage_path('logs/folder-access.jsonl');
    @unlink($log);

    $file = Asset::factory()->create(['file_name' => 'fence-logged.jpg']);
    $file->directories()->attach($this->parent->id);

    app(AccessListWriter::class)->inheritAssets([$file->id]);

    $events = collect(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->map(fn (string $line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR)['event'])
        ->all();

    expect($events)->toContain('access_fence_missing')
        ->and(fenceListed(assetId: $file->id))->toBe([$this->admin->id]);

    @unlink($log);
});

it('creates the state row on the production path when there is none to share', function () {
    config(['dam.access_fence_strict' => false]);

    // A database that predates migration 2026_09_26_000001 and has never run a switch: nothing to hold FOR
    // SHARE, so the late fence creates the row and holds it FOR UPDATE instead (finding 1).
    DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
    app(AccessModel::class)->flush();

    $file = Asset::factory()->create(['file_name' => 'fence-no-row.jpg']);
    $file->directories()->attach($this->parent->id);

    app(AccessListWriter::class)->inheritAssets([$file->id]);

    expect(DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'))->toBe(AccessModel::GRANTS)
        ->and(fenceListed(assetId: $file->id))->toBe([$this->admin->id]);
});

it('never calls a missing state row a fence', function () {
    DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
    app(AccessModel::class)->flush();

    $model = app(AccessModel::class);

    // sharedly() locked nothing, so it records nothing: an upload cannot be marked fenced while the first
    // switch creates the row and rebuilds concurrently (finding 1).
    $model->sharedly(fn (?string $raw) => expect($raw)->toBeNull()->and($model->holdsRow())->toBeFalse());

    // A folder create through the same absent state throws rather than writing an unfenced list.
    expect(fn () => Directory::create(['name' => 'Fence no row child', 'parent_id' => $this->parent->id]))
        ->toThrow(AccessFenceMissing::class);
});

it('does not inherit a hold into a transaction after an ending that fired no event', function (string $ending) {
    config(['dam.access_fence_strict' => false]);

    $model = app(AccessModel::class);
    $file = Asset::factory()->create(['file_name' => 'fence-eventless-'.$ending.'.jpg']);
    $file->directories()->attach($this->parent->id);

    DB::beginTransaction();
    $model->holdingRow(fn () => null);

    expect($model->holdsRow())->toBeTrue();

    // Laravel resets the transaction level without firing TransactionCommitted or TransactionRolledBack when
    // a commit or a rollback loses the connection (ManagesTransactions::handleCommitTransactionException and
    // handleRollBackException). Ending the transaction with the dispatcher unset reproduces exactly that
    // state — the level reset, no event — with no faked exception; it is the trick DatabaseTransactions
    // itself uses (Codex review of PR #74 round 2, finding 1).
    fenceWithoutEvents(fn () => $ending === 'commit' ? DB::commit() : DB::rollBack());

    DB::beginTransaction();

    // Detection: the new transaction locked nothing, and says so.
    expect($model->holdsRow())->toBeFalse();

    // Safety: the write takes the hold itself, so it is fenced whatever the bookkeeping said.
    $locks = fenceStateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$file->id]));

    expect($locks)->toBeGreaterThan(0)
        ->and(fenceListed(assetId: $file->id))->toBe([$this->admin->id]);

    DB::rollBack();
})->with(['commit', 'rollback']);

it('drops the holds a rollback to an earlier level released', function () {
    config(['dam.access_fence_strict' => false]);

    $model = app(AccessModel::class);
    $file = Asset::factory()->create(['file_name' => 'fence-to-level.jpg']);
    $file->directories()->attach($this->parent->id);

    DB::beginTransaction();
    $outer = DB::transactionLevel();
    DB::beginTransaction();
    DB::beginTransaction();
    $model->holdingRow(fn () => null);

    expect($model->holdsRow())->toBeTrue();

    // rollBack($toLevel) drops several levels and still fires one event, so counting events would leave the
    // deeper holds recorded. The level on the event is what decides instead.
    DB::rollBack($outer);

    expect(DB::transactionLevel())->toBe($outer)
        ->and($model->holdsRow())->toBeFalse();

    $locks = fenceStateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$file->id]));

    expect($locks)->toBeGreaterThan(0)
        ->and(fenceListed(assetId: $file->id))->toBe([$this->admin->id]);

    DB::rollBack();
});

it('takes the hold even when the bookkeeping wrongly says it is held', function () {
    // The whole point of the round-2 design: a stale "held" costs an unreported caller, never an unfenced
    // write. This double lies the way every eventless ending used to.
    app()->instance(AccessModel::class, new class extends AccessModel
    {
        public function holdsRow(): bool
        {
            return true;
        }
    });
    // The writer is scoped and already holds the real model, so it has to be resolved again.
    app()->forgetInstance(AccessListWriter::class);

    $file = Asset::factory()->create(['file_name' => 'fence-lying.jpg']);
    $file->directories()->attach($this->parent->id);

    $locks = fenceStateRowReads(fn () => app(AccessListWriter::class)->inheritAssets([$file->id]));

    expect($locks)->toBeGreaterThan(0)
        ->and(fenceListed(assetId: $file->id))->toBe([$this->admin->id]);
});

it('fences an ordinary folder create after a rollback to an earlier level', function () {
    // The reviewer's exact shape (round 3, finding 1): begin at 2, 3 and 4, roll back to 2, then to the
    // wrapper's level, then write. A depth kept by one decrement per event is left claiming 1 here, so the next
    // ordinary transaction looks nested, run() takes no hold, and this create used to throw. Strict mode is on,
    // which is what makes that a failure rather than a log line.
    DB::beginTransaction();
    $wrapper = DB::transactionLevel() - 1;
    DB::beginTransaction();
    DB::beginTransaction();
    DB::rollBack($wrapper + 1);
    DB::rollBack($wrapper);

    expect(DB::transactionLevel())->toBe($wrapper);

    [$child, $order] = fenceCreateAndWatchLockOrder('Fence after rollback', $this->parent->id);

    expect(fenceListed($child->id))->toBe([$this->admin->id])
        ->and($order[0] ?? null)->toBe('state row', 'the create did not lock the state row first: '.implode(', ', $order));
});

it('fences an ordinary folder create after an ending that fired no event', function (string $ending) {
    $model = app(AccessModel::class);

    DB::beginTransaction();
    $model->holdingRow(fn () => null);
    fenceWithoutEvents(fn () => $ending === 'commit' ? DB::commit() : DB::rollBack());

    // Strict mode again: a depth left stale by the silent ending would report the create as unfenced.
    [$child, $order] = fenceCreateAndWatchLockOrder('Fence after silent '.$ending, $this->parent->id);

    expect(fenceListed($child->id))->toBe([$this->admin->id])
        ->and($order[0] ?? null)->toBe('state row', 'the create did not lock the state row first: '.implode(', ', $order));
})->with(['commit', 'rollback']);

it('takes the hold before the tree lock, which is ADR 0010\'s order', function () {
    // From the statements themselves, so a tree lock taken first and fenced immediately afterwards would fail.
    [, $order] = fenceCreateAndWatchLockOrder('Fence order child', $this->parent->id);

    expect($order[0] ?? null)->toBe('state row', 'the create did not lock the state row first: '.implode(', ', $order));

    // Where the driver compiles a tree lock, the order is a pair rather than a single observation.
    if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        expect($order)->toBe(['state row', 'tree lock']);
    }
});

/**
 * The order in which $work's transaction took the two locks ADR 0010 orders, read from the statements the
 * connection actually executed: `state row` for the locking read of DAM_ACCESS_MODEL, `tree lock` for ADR 0004's
 * lock — `pg_advisory_xact_lock` on PostgreSQL, the `dam_directory_tree_lock` row on MySQL and MariaDB.
 *
 * Observing holdsRow() from inside the callback run() passes to its transaction could not tell the two apart: it
 * would have said "hold first" even if run() had taken the tree lock and fenced immediately afterwards (Codex
 * review of PR #74 round 4, finding 3). SQLite compiles neither a lock clause nor a tree lock, so there this
 * returns the state-row read alone and the order is proved on the drivers that have both.
 *
 * @return list<string>
 */
function fenceLockOrder(callable $work): array
{
    $order = [];
    $clause = LockingClause::pattern();

    DB::listen(function ($query) use (&$order, $clause): void {
        if (str_contains($query->sql, 'dam_configuration')
            && str_starts_with($query->sql, 'select')
            && ($clause === null || preg_match($clause, $query->sql) === 1)) {
            $order[] = 'state row';

            return;
        }

        if (str_contains($query->sql, 'pg_advisory_xact_lock')
            || (str_contains($query->sql, DirectoryTreeLock::TABLE) && $clause !== null && preg_match($clause, $query->sql) === 1)) {
            $order[] = 'tree lock';
        }
    });

    $work();

    // Only the first of each: run() is re-entrant and a nested save re-reads neither.
    return array_values(array_unique($order));
}

/** Creates a folder the ordinary way and returns the lock order its transaction took. */
function fenceCreateAndWatchLockOrder(string $name, int $parentId): array
{
    $child = null;

    $order = fenceLockOrder(function () use ($name, $parentId, &$child): void {
        $child = Directory::create(['name' => $name, 'parent_id' => $parentId]);
    });

    return [$child, $order];
}

/** Runs $work with the connection's event dispatcher unset, as an ending that fires no event leaves it. */
function fenceWithoutEvents(callable $work): void
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
 * How many times $work took a locking read of the DAM_ACCESS_MODEL row. That read *is* the fence.
 *
 * The clause every driver really emits, taken from Laravel's own grammars rather than guessed
 * (Query\Grammars\*::compileLock, and SqlServerGrammar::compileFrom for the table hint):
 * - PostgreSQL: `for share`, `for update`, and `for no key update` where a caller asks for it;
 * - MySQL and MariaDB (MariaDbGrammar inherits MySqlGrammar's): `lock in share mode` and `for update`;
 * - SQL Server: no clause at all, a `with(rowlock,...,holdlock)` table hint instead;
 * - SQLite: nothing, because it serialises writers at the file level — the documented exception, where the
 *   statement itself is all there is to see. The clause is required on PostgreSQL by AccessFenceCommittedTest.
 *
 * Getting this wrong failed valid fenced writes on MySQL and SQL Server while passing on PostgreSQL (Codex
 * review of PR #74 round 4, finding 4). The fragments live in LockingClause, which AccessFenceLockingClauseTest
 * holds to the grammars.
 */
function fenceStateRowReads(callable $work): int
{
    $reads = 0;
    $clause = LockingClause::pattern();

    DB::listen(function ($query) use (&$reads, $clause): void {
        if (! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'dam_configuration')) {
            return;
        }

        if ($clause !== null && preg_match($clause, $query->sql) !== 1) {
            return;
        }

        $reads++;
    });

    $work();

    return $reads;
}

function fenceAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** @return list<int> the admin ids listed on a folder, or on a file when $assetId is given */
function fenceListed(?int $directoryId = null, ?int $assetId = null): array
{
    return $assetId === null
        ? DB::table('dam_directory_user_access')->where('directory_id', $directoryId)->orderBy('admin_id')->pluck('admin_id')->map(fn ($id) => (int) $id)->all()
        : DB::table('dam_asset_user_access')->where('asset_id', $assetId)->orderBy('admin_id')->pluck('admin_id')->map(fn ($id) => (int) $id)->all();
}
