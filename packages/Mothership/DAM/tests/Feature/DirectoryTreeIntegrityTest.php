<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryTreeLock;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->root = Directory::create(['name' => 'Integrity '.uniqid()]);
    $this->parent = Directory::create(['name' => 'parent', 'parent_id' => $this->root->id]);
    $this->child = Directory::create(['name' => 'child', 'parent_id' => $this->parent->id]);
});

// Every row claims the same interval: duplicate intervals and wrong parents, the shape production had on 2026-09-13.
function corruptDirectoryTree(): void
{
    DB::table('dam_directories')->update(['_lft' => 1, '_rgt' => 2]);
}

$pgsqlOnly = fn () => DB::connection()->getDriverName() !== 'pgsql';

it('reports a clean tree, detects corruption, and never repairs on its own', function () {
    $this->artisan('dam:directories:check')->assertExitCode(0);

    corruptDirectoryTree();
    expect(Directory::isBroken())->toBeTrue();

    $this->artisan('dam:directories:check')->assertExitCode(1);
    expect(Directory::isBroken())->toBeTrue();
});

it('repairs the nested set from parent_id only with --force and restores every derived path', function () {
    corruptDirectoryTree();
    expect(Directory::find($this->child->id)->generatePath())->not->toBe($this->root->name.'/parent/child');

    $this->artisan('dam:directories:repair')->assertExitCode(1);
    expect(Directory::isBroken())->toBeTrue();

    $before = glob(storage_path('backups/dam_directories-before-*.json')) ?: [];
    $this->artisan('dam:directories:repair', ['--force' => true])->assertExitCode(0);

    // --force writes a real pre-repair snapshot; remove the one this test made so runs leave no files behind.
    $snapshots = array_diff(glob(storage_path('backups/dam_directories-before-*.json')) ?: [], $before);
    array_map('unlink', $snapshots);
    expect($snapshots)->not->toBeEmpty();

    expect(Directory::isBroken())->toBeFalse()
        ->and(array_sum(Directory::countErrors()))->toBe(0)
        ->and(Directory::find($this->child->id)->generatePath())->toBe($this->root->name.'/parent/child')
        ->and(Directory::find($this->child->id)->parent_id)->toBe($this->parent->id);
});

it('takes the directory tree lock around create, move and delete, re-entrantly', function () {
    $lock = new class(app(AccessModel::class)) extends DirectoryTreeLock
    {
        public array $events = [];

        public function run(callable $callback): mixed
        {
            $this->events[] = $this->held() ? 'reenter' : 'enter';

            return parent::run($callback);
        }
    };
    app()->instance(DirectoryTreeLock::class, $lock);

    $sibling = Directory::create(['name' => 'sibling', 'parent_id' => $this->root->id]);
    Directory::withTreeLock(fn () => $this->child->appendToNode($sibling)->save());
    // Leaves only: the parent_id foreign key has no cascade, so subtree removal is DeleteDirectory's job.
    $this->child->delete();
    $sibling->delete();

    $counts = array_count_values($lock->events);
    expect($counts['enter'] ?? 0)->toBeGreaterThanOrEqual(4, 'create, move and both deletes each take the lock')
        ->and($counts['reenter'] ?? 0)->toBeGreaterThanOrEqual(1, 'a save inside an explicit lock re-enters instead of deadlocking')
        ->and(Directory::isBroken())->toBeFalse()
        ->and(Directory::find($this->child->id))->toBeNull()
        ->and(Directory::find($sibling->id))->toBeNull();
});

it('acquires the PostgreSQL advisory lock before shifting the tree, and re-reads the parent under it', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    // Filling parent_id makes nestedset look the parent up before save(); that read is harmless because
    // save() re-reads the parent's interval under the lock before computing the insert position.
    Directory::create(['name' => 'locked', 'parent_id' => $this->parent->id]);

    $indexOf = fn (callable $match) => array_search(true, array_map($match, $queries), true);
    $lockAt = $indexOf(fn ($sql) => str_contains($sql, 'pg_advisory_xact_lock'));
    $writeAt = $indexOf(fn ($sql) => str_contains($sql, 'dam_directories') && preg_match('/^\s*(insert|update|delete)/', $sql) === 1);
    $parentReadAfterLock = false;
    foreach ($queries as $index => $sql) {
        if ($lockAt !== false && $index > $lockAt && str_contains($sql, 'dam_directories') && str_starts_with(trim($sql), 'select')) {
            $parentReadAfterLock = true;
            break;
        }
    }

    expect($lockAt)->not->toBeFalse('the advisory lock statement ran')
        ->and($writeAt)->not->toBeFalse('the tree was written')
        ->and($lockAt)->toBeLessThan($writeAt, 'the lock precedes the first tree write')
        ->and($parentReadAfterLock)->toBeTrue('the parent interval is read again under the lock');
})->skip($pgsqlOnly, 'PostgreSQL advisory locks only');

it('excludes a second connection while the advisory lock is held', function () {
    config(['database.connections.pgsql_probe' => config('database.connections.'.DB::getDefaultConnection())]);
    $other = DB::connection('pgsql_probe');
    $tryOther = fn () => filter_var(
        $other->selectOne('SELECT pg_try_advisory_xact_lock(?) AS ok', [DirectoryTreeLock::KEY])->ok,
        FILTER_VALIDATE_BOOLEAN
    );

    try {
        $other->beginTransaction();

        if (DB::transactionLevel() > 0) {
            // The test harness keeps its own transaction open, so beforeEach's inserts already hold the lock here.
            expect($tryOther())->toBeFalse('the lock taken by the test connection blocks the probe');
        } else {
            DB::beginTransaction();
            DB::statement('SELECT pg_advisory_xact_lock(?)', [DirectoryTreeLock::KEY]);
            expect($tryOther())->toBeFalse('the lock taken by the test connection blocks the probe');
            DB::rollBack();

            $other->rollBack();
            $other->beginTransaction();
            expect($tryOther())->toBeTrue('the probe acquires the lock once the holder commits or rolls back');
        }
    } finally {
        $other->rollBack();
        $other->disconnect();
    }
})->skip($pgsqlOnly, 'PostgreSQL advisory locks only');
