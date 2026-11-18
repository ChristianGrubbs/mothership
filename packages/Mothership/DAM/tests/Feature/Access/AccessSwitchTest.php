<?php

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessComparator;
use Mothership\DAM\Services\Access\AccessListRebuilder;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\ShareKeyGrant;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The rollout switch (ADR 0010). Reads stay on today's grants until `dam:access:switch lists`. It runs in
 * maintenance mode, holding the lock it shares with the rebuild:
 * - it drains the requests already admitted
 * - in one transaction it rebuilds the lists, compares them with the grants and fills in missing owners.
 *   Only on a clean comparison does it write pending:<run>, which reads still treat as grants
 * - it settles and checks again: lists only if nothing changed, grants if something did
 * Every change of the state row is a compare-and-set against what the run last read or wrote. One that
 * affects no row, or a lost lock, means another run took over. `dam:access:switch grants` is the rollback.
 * Every sleep is faked; the first is the drain and the second the settle.
 */

beforeEach(function () {
    Sleep::fake();
    $this->damResetAssetTables();
    accessSwitchMaintenance(down: true);

    // A role grant on one folder, and a file the admin owns in a folder no grant reaches. The two models
    // answer that file differently (the owner rule), which shows which one is reading.
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);
    $this->granted = Directory::create(['name' => 'Switch granted', 'parent_id' => null]);
    $this->ungranted = Directory::create(['name' => 'Switch ungranted', 'parent_id' => null]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->granted->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->admin = Admin::factory()->create(['role_id' => $role->id]);
    $this->grantedFile = Asset::factory()->create(['file_name' => 'switch-granted.jpg']);
    $this->grantedFile->directories()->attach($this->granted->id);
    $this->ownedFile = Asset::factory()->create(['file_name' => 'switch-owned.jpg']);
    $this->ownedFile->directories()->attach($this->ungranted->id);
    DB::table('dam_assets')->where('id', $this->ownedFile->id)->update(['created_by' => $this->admin->id]);
});

/** Maintenance mode as `php artisan down` sets it, without writing storage/framework/down. */
function accessSwitchMaintenance(bool $down): void
{
    app()->instance(MaintenanceMode::class, new class($down) implements MaintenanceMode
    {
        public function __construct(private readonly bool $down) {}

        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return $this->down;
        }

        public function data(): array
        {
            return [];
        }
    });
}

/** Runs $then during the switch's nth faked sleep: 1 is the drain, 2 the settle. */
function accessSwitchDuringSleep(int $nth, Closure $then): void
{
    $sleeps = 0;

    Sleep::whenFakingSleep(function () use (&$sleeps, $nth, $then) {
        if (++$sleeps === $nth) {
            $then();
        }
    });
}

/** The state row as stored: null (no row), `grants`, `pending:<run>` or `lists`. */
function accessSwitchRaw(): ?string
{
    return DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value');
}

/**
 * Runs $run with the access log on and returns the events it wrote.
 *
 * @return list<string>
 */
function accessSwitchLogged(Closure $run): array
{
    $path = storage_path('logs/folder-access.jsonl');
    clearstatcache(true, $path);
    $offset = is_file($path) ? (int) filesize($path) : 0;
    config(['dam.access_logging' => true]);

    try {
        $run();
    } finally {
        config(['dam.access_logging' => false]);
    }

    $written = is_file($path) ? (string) file_get_contents($path, offset: $offset) : '';

    return collect(explode(PHP_EOL, trim($written)))
        ->filter()
        ->map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['event'])
        ->values()
        ->all();
}

/** The service's answers for the fixture admin under whichever model is current. */
function accessSwitchAnswers($test): array
{
    $service = app(DirectoryPermissionService::class)->forAdmin($test->admin);

    return [
        'granted folder'   => $service->canAccess($test->granted->id),
        'ungranted folder' => $service->canAccess($test->ungranted->id),
        'granted file'     => $service->canAccessAsset($test->grantedFile->id),
        'owned file'       => $service->canAccessAsset($test->ownedFile->id),
    ];
}

/** Everything a rebuild or a switch can write: the model row, the lists, the groups and the owners. */
function accessSwitchState(): array
{
    $rows = fn (string $table, string $item, string $principal) => DB::table($table)->orderBy($item)->orderBy($principal)
        ->get()->map(fn ($row) => (array) $row)->all();

    return [
        'model'          => DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'),
        'folder users'   => $rows('dam_directory_user_access', 'directory_id', 'admin_id'),
        'folder groups'  => $rows('dam_directory_group_access', 'directory_id', 'group_id'),
        'file users'     => $rows('dam_asset_user_access', 'asset_id', 'admin_id'),
        'file groups'    => $rows('dam_asset_group_access', 'asset_id', 'group_id'),
        'groups'         => DB::table('dam_groups')->orderBy('id')->pluck('system_key', 'id')->all(),
        'members'        => $rows('dam_group_members', 'group_id', 'admin_id'),
        'folder owners'  => DB::table('dam_directories')->orderBy('id')->pluck('created_by', 'id')->all(),
        'file owners'    => DB::table('dam_assets')->orderBy('id')->pluck('created_by', 'id')->all(),
    ];
}

/** A custom role's stored permissions, decoded, for asserting the share-key grant and its rollback. */
function shareKeyPermissions(int $roleId): array
{
    return (array) json_decode((string) DB::table('roles')->where('id', $roleId)->value('permissions'), true);
}

/** A rebuild that leaves one list short: the role group's folder rows are gone after it runs. */
function accessSwitchTamperedRebuild(): void
{
    app()->instance(AccessListRebuilder::class, new class extends AccessListRebuilder
    {
        public function run(): array
        {
            $counts = parent::run();
            DB::table('dam_directory_group_access')->delete();

            return $counts;
        }
    });
}

it('reads grants by default and gives the same answers once the lists are built', function () {
    // Migration 2026_09_26_000001 seeds the state row as `grants`, so a database that has never run a switch
    // has that row rather than none. Reads are the same either way (AccessModel::modelFor()); the difference
    // is that a list writer now has a row to hold FOR SHARE (sharing slice 2a).
    expect(DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'))->toBe(AccessModel::GRANTS)
        ->and(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);

    $grants = ['granted folder' => true, 'ungranted folder' => false, 'granted file' => true, 'owned file' => false];

    expect(accessSwitchAnswers($this))->toBe($grants);

    $this->artisan('dam:access:rebuild')->assertExitCode(0);

    // A preparatory rebuild never fills in owners: only the switch does.
    expect(DB::table('dam_asset_group_access')->count())->toBeGreaterThan(0)
        ->and(accessSwitchAnswers($this))->toBe($grants)
        ->and(DB::table('dam_directories')->whereNotNull('created_by')->count())->toBe(0)
        ->and(DB::table('dam_assets')->whereNull('created_by')->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$this->grantedFile->id]);
});

it('drains, switches to lists after a clean comparison, fills in owners, verifies, and switches back without maintenance mode', function () {
    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('Verified: the lists still match the grants.')
        ->expectsOutputToContain('Run php artisan up')
        ->assertExitCode(0);

    // The drain, then the settle, at their default lengths.
    Sleep::assertSequence([Sleep::for(30)->seconds(), Sleep::for(5)->seconds()]);

    $firstFullAdmin = (int) DB::table('admins')->join('roles', 'roles.id', '=', 'admins.role_id')
        ->where('admins.status', true)->where('roles.permission_type', 'all')->min('admins.id');

    expect(app(AccessModel::class)->current())->toBe(AccessModel::LISTS)
        ->and(DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'))->toBe(AccessModel::LISTS)
        // The owner rule answers now: the admin opens the file they own.
        ->and(accessSwitchAnswers($this))->toBe(['granted folder' => true, 'ungranted folder' => false, 'granted file' => true, 'owned file' => true])
        // Items with no owner went to the first active full admin; the owned file kept its owner.
        ->and(DB::table('dam_directories')->distinct()->pluck('created_by')->map(fn ($id) => (int) $id)->all())->toBe([$firstFullAdmin])
        ->and((int) DB::table('dam_assets')->where('id', $this->grantedFile->id)->value('created_by'))->toBe($firstFullAdmin)
        ->and((int) DB::table('dam_assets')->where('id', $this->ownedFile->id)->value('created_by'))->toBe($this->admin->id);

    // The rollback needs no maintenance mode, and neither drains nor settles.
    accessSwitchMaintenance(down: false);

    $this->artisan('dam:access:switch', ['model' => 'grants'])->assertExitCode(0);

    Sleep::assertSleptTimes(2);

    expect(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS)
        ->and(accessSwitchAnswers($this)['owned file'])->toBeFalse();
});

it('grants the six share keys to every custom role once the switch to lists verifies, and restores them on the rollback', function () {
    $roleId = $this->admin->role_id;

    $grantEvents = accessSwitchLogged(fn () => $this->artisan('dam:access:switch', ['model' => 'lists'])->assertExitCode(0));

    expect(shareKeyPermissions($roleId))->toEqualCanonicalizing(array_merge(['dam', 'dam.asset.view'], ShareKeyGrant::KEYS))
        ->and($grantEvents)->toContain('share_keys_granted');

    // Every custom role now holds them (D7), whatever role a fresh admin gets.
    $secondRole = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam']]);
    expect(shareKeyPermissions($secondRole->id))->toEqualCanonicalizing(['dam']);

    accessSwitchMaintenance(down: false);

    $revertEvents = accessSwitchLogged(fn () => $this->artisan('dam:access:switch', ['model' => 'grants'])->assertExitCode(0));

    expect(shareKeyPermissions($roleId))->toEqualCanonicalizing(['dam', 'dam.asset.view'])
        ->and($revertEvents)->toContain('share_keys_restored')
        // A role created after the grant ran never had a pre-flip baseline, so it never got the keys.
        ->and(shareKeyPermissions($secondRole->id))->toEqualCanonicalizing(['dam']);
});

it('keeps reads on the grants while the switch settles, and on the lists only once it verifies', function () {
    $during = [];

    // What a request arriving during the settle would read.
    accessSwitchDuringSleep(nth: 2, then: function () use (&$during) {
        $fresh = new AccessModel;
        $during = ['state' => $fresh->raw(), 'model' => $fresh->current(), 'owned file' => accessSwitchAnswers($this)['owned file']];
    });

    $this->artisan('dam:access:switch', ['model' => 'lists'])->assertExitCode(0);

    expect($during['state'])->toStartWith(AccessModel::PENDING)
        ->and($during['model'])->toBe(AccessModel::GRANTS)
        ->and($during['owned file'])->toBeFalse()
        ->and(accessSwitchRaw())->toBe(AccessModel::LISTS);
});

it('stays pending, which reads as grants, when the verifying comparison throws', function () {
    app()->instance(AccessComparator::class, new class extends AccessComparator
    {
        private int $calls = 0;

        public function compare(bool $withPairs = false): array
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('The verifying comparison failed.');
            }

            return parent::compare($withPairs);
        }
    });

    $events = accessSwitchLogged(fn () => $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('Unverified')
        ->expectsOutputToContain('run php artisan dam:access:switch grants')
        ->assertExitCode(1)
        ->run());

    expect(accessSwitchRaw())->toStartWith(AccessModel::PENDING)
        ->and((new AccessModel)->current())->toBe(AccessModel::GRANTS)
        ->and(accessSwitchAnswers($this)['owned file'])->toBeFalse()
        ->and($events)->toContain('access_switch_unverified')
        ->not->toContain('access_model_switched');
});

it('stays pending, which reads as grants, when the revert itself throws', function () {
    DB::table('dam_directory_user')->insert(['directory_id' => $this->ungranted->id, 'admin_id' => $this->admin->id, 'inherit_children' => false]);
    accessSwitchDuringSleep(nth: 2, then: fn () => DB::table('dam_directory_user')
        ->where('directory_id', $this->ungranted->id)->where('admin_id', $this->admin->id)->delete());

    app()->instance(AccessModel::class, new class extends AccessModel
    {
        public function compareAndSet(?string $expected, string $value): bool
        {
            if ($value === AccessModel::GRANTS) {
                throw new RuntimeException('The revert failed.');
            }

            return parent::compareAndSet(expected: $expected, value: $value);
        }
    });

    $events = accessSwitchLogged(fn () => $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('Unverified')
        ->assertExitCode(1)
        ->run());

    expect(accessSwitchRaw())->toStartWith(AccessModel::PENDING)
        ->and((new AccessModel)->current())->toBe(AccessModel::GRANTS)
        ->and(accessSwitchAnswers($this)['ungranted folder'])->toBeFalse()
        ->and($events)->toContain('access_switch_unverified');
});

it('redoes a switch a dead run left pending, which reads as grants until then', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'pending:dead-run']);

    expect((new AccessModel)->current())->toBe(AccessModel::GRANTS)
        ->and(accessSwitchAnswers($this)['owned file'])->toBeFalse();

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('Found pending:dead-run')
        ->expectsOutputToContain('Verified')
        ->assertExitCode(0);

    // The whole switch again: the drain, then the settle.
    Sleep::assertSleptTimes(2);

    expect(accessSwitchRaw())->toBe(AccessModel::LISTS)
        ->and(accessSwitchAnswers($this)['owned file'])->toBeTrue();
});

it('rolls a pending switch back to grants', function () {
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => 'pending:dead-run']);
    accessSwitchMaintenance(down: false);

    $this->artisan('dam:access:switch', ['model' => 'grants'])
        ->expectsOutputToContain('the state was pending:dead-run')
        ->assertExitCode(0);

    Sleep::assertNeverSlept();

    expect(accessSwitchRaw())->toBe(AccessModel::GRANTS);
});

it('reports superseded and leaves the state alone when another run takes over while it settles', function (bool $lockLost, string $message) {
    accessSwitchDuringSleep(nth: 2, then: function () use ($lockLost) {
        DB::table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => 'pending:other-run']);

        if ($lockLost) {
            Cache::lock(AccessModel::LOCK)->forceRelease();
            Cache::lock(AccessModel::LOCK, 60)->get();
        }
    });

    // One expectation per line: the message and the state it reports share one.
    $events = accessSwitchLogged(fn () => $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain($message.' The state is now pending:other-run.')
        ->assertExitCode(1)
        ->run());

    expect(accessSwitchRaw())->toBe('pending:other-run')
        ->and((new AccessModel)->current())->toBe(AccessModel::GRANTS)
        ->and($events)->toBe(['access_migrated', 'access_switch_superseded']);
})->with([
    // The lock still held: only the compare-and-set notices.
    'with the lock still held' => [false, 'Superseded: another run changed the access model during this switch, so this run wrote nothing more.'],
    'with the lock lost too'   => [true, 'Superseded: this run no longer holds the switch lock (it expired, or another run took it), so it wrote nothing more.'],
]);

it('reports superseded when switch grants rolled the pending switch back while it settled', function () {
    accessSwitchDuringSleep(nth: 2, then: function () {
        // Another operator's rollback, once the first run's lock has expired: it takes the lock, moves the
        // pending state to grants, and releases it.
        $owner = Cache::getStore()->locks[AccessModel::LOCK]['owner'];
        Cache::lock(AccessModel::LOCK)->forceRelease();

        expect(Artisan::call('dam:access:switch', ['model' => 'grants']))->toBe(0);

        // The first run gets its lock back, so only the compare-and-set can stop it.
        Cache::restoreLock(AccessModel::LOCK, $owner)->get();
    });

    $events = accessSwitchLogged(fn () => $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('Superseded: another run changed the access model')
        ->assertExitCode(1)
        ->run());

    // The rollback's switch to grants, then this run's superseded report; never a switch to lists.
    expect(accessSwitchRaw())->toBe(AccessModel::GRANTS)
        ->and($events)->toBe(['access_migrated', 'access_model_switched', 'access_switch_superseded']);
});

it('reverts to grants when a legacy revocation lands while the switch settles', function () {
    DB::table('dam_directory_user')->insert(['directory_id' => $this->ungranted->id, 'admin_id' => $this->admin->id, 'inherit_children' => false]);

    // A request admitted before `down`, still running, revokes that grant after the comparison.
    accessSwitchDuringSleep(nth: 2, then: fn () => DB::table('dam_directory_user')
        ->where('directory_id', $this->ungranted->id)->where('admin_id', $this->admin->id)->delete());

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('folder_gained')
        ->expectsOutputToContain('Reverted to grants')
        ->expectsOutputToContain('The owners filled in stay')
        ->assertExitCode(1);

    // Back on grants, where the revocation holds.
    expect(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS)
        ->and(DB::table('dam_configuration')->where('key', AccessModel::KEY)->value('value'))->toBe(AccessModel::GRANTS)
        ->and(accessSwitchAnswers($this)['ungranted folder'])->toBeFalse()
        // A switch that reverts on drift grants nothing: the role never gained the share keys, and no
        // pre-flip snapshot was left behind for a later switch to misread.
        ->and(shareKeyPermissions($this->admin->role_id))->toBe(['dam', 'dam.asset.view'])
        ->and(DB::table('dam_configuration')->where('key', ShareKeyGrant::SNAPSHOT_KEY)->exists())->toBeFalse();
});

it('refuses and writes nothing when maintenance mode is lifted during the drain', function () {
    $before = accessSwitchState();
    accessSwitchDuringSleep(nth: 1, then: fn () => accessSwitchMaintenance(down: false));

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('lifted during the drain')
        ->assertExitCode(1);

    Sleep::assertSleptTimes(1);

    expect(accessSwitchState())->toBe($before)
        ->and(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);
});

it('rejects a drain or settle that is not a whole number of seconds', function (array $option) {
    $before = accessSwitchState();

    $this->artisan('dam:access:switch', ['model' => 'lists', ...$option])->assertExitCode(1);

    Sleep::assertNeverSlept();

    expect(accessSwitchState())->toBe($before);
})->with([
    'a negative drain'   => [['--drain' => '-1']],
    'a worded settle'    => [['--settle' => 'soon']],
]);

it('refuses to switch to lists outside maintenance mode and writes nothing', function () {
    accessSwitchMaintenance(down: false);
    $before = accessSwitchState();

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('php artisan down')
        ->assertExitCode(1);

    Sleep::assertNeverSlept();

    expect(accessSwitchState())->toBe($before)
        ->and(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);
});

it('refuses a rebuild or a switch at once while another run holds the lock, and writes nothing', function () {
    $competitor = Cache::lock(AccessModel::LOCK, 60);
    expect($competitor->get())->toBeTrue();
    $before = accessSwitchState();

    try {
        $this->artisan('dam:access:rebuild')->expectsOutputToContain('is running')->assertExitCode(1);
        $this->artisan('dam:access:switch', ['model' => 'lists'])->expectsOutputToContain('is running')->assertExitCode(1);
        $this->artisan('dam:access:switch', ['model' => 'grants'])->expectsOutputToContain('is running')->assertExitCode(1);
    } finally {
        $competitor->release();
    }

    expect(accessSwitchState())->toBe($before);
});

it('re-reads the model under the lock, so a switch another process made is a no-op', function () {
    expect(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);

    // Another process flips the row after this one read it.
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    $before = accessSwitchState();

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('already use the lists model')
        ->assertExitCode(0);

    // Seen before the drain, so there is nothing to wait for.
    Sleep::assertNeverSlept();

    expect(accessSwitchState())->toBe($before);
});

it('rolls the whole switch back when it fails after the rebuild rewrote the lists', function () {
    $this->artisan('dam:access:rebuild')->assertExitCode(0);

    // Granted after that rebuild, so the switch's own rebuild writes different lists.
    DB::table('dam_directory_user')->insert(['directory_id' => $this->ungranted->id, 'admin_id' => $this->admin->id, 'inherit_children' => false]);
    $before = accessSwitchState();

    app()->instance(AccessComparator::class, new class extends AccessComparator
    {
        public function compare(bool $withPairs = false): array
        {
            throw new RuntimeException('The comparison failed mid-switch.');
        }
    });

    expect(fn () => $this->artisan('dam:access:switch', ['model' => 'lists'])->run())->toThrow(RuntimeException::class, 'mid-switch');

    expect(accessSwitchState())->toBe($before)
        ->and(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);
});

it('refuses to switch to lists when a list no longer matches the grants, and writes nothing', function () {
    accessSwitchTamperedRebuild();
    $before = accessSwitchState();

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('Refused')
        ->assertExitCode(1);

    expect(accessSwitchState())->toBe($before)
        ->and(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);
});

it('switches anyway with --force, prints the failing pairs, and keeps lists while they stay the same', function () {
    accessSwitchTamperedRebuild();

    $this->artisan('dam:access:switch', ['model' => 'lists', '--force' => true])
        ->expectsOutputToContain('folder_lost')
        ->expectsOutputToContain('differences --force let through')
        ->assertExitCode(0);

    expect(app(AccessModel::class)->current())->toBe(AccessModel::LISTS);
});

it('reverts a --force switch when the failing pairs change while it settles', function () {
    accessSwitchTamperedRebuild();

    // A grant lands after the comparison, so the check finds a failing pair --force never saw.
    accessSwitchDuringSleep(nth: 2, then: fn () => DB::table('dam_directory_user')
        ->insert(['directory_id' => $this->ungranted->id, 'admin_id' => $this->admin->id, 'inherit_children' => false]));

    $this->artisan('dam:access:switch', ['model' => 'lists', '--force' => true])
        ->expectsOutputToContain('Reverted to grants')
        ->assertExitCode(1);

    expect(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS)
        ->and(accessSwitchAnswers($this)['ungranted folder'])->toBeTrue();
});

it('rejects an unknown model and leaves the switch alone', function () {
    $this->artisan('dam:access:switch', ['model' => 'everyone'])->assertExitCode(1);

    expect(app(AccessModel::class)->current())->toBe(AccessModel::GRANTS);
});

it('never rebuilds over the lists once reads use them', function () {
    $this->artisan('dam:access:switch', ['model' => 'lists'])->assertExitCode(0);

    // A sharing change made under lists, which only the lists record.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->ungranted->id, 'admin_id' => $this->admin->id]);

    $this->artisan('dam:access:switch', ['model' => 'lists'])->assertExitCode(0);
    $this->artisan('dam:access:rebuild')->assertExitCode(1);

    expect(DB::table('dam_directory_user_access')->where('directory_id', $this->ungranted->id)->where('admin_id', $this->admin->id)->exists())->toBeTrue();
});

it('refuses a rebuild unless the state is exactly grants, and writes nothing', function (string $state, string $message) {
    // Lists built under grants first, so an overwrite would show.
    $this->artisan('dam:access:rebuild')->assertExitCode(0);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->ungranted->id, 'admin_id' => $this->admin->id]);
    DB::table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => $state]);
    $before = accessSwitchState();

    $events = accessSwitchLogged(fn () => $this->artisan('dam:access:rebuild')
        ->expectsOutputToContain($message)
        ->assertExitCode(1)
        ->run());

    expect(accessSwitchState())->toBe($before)
        ->and($events)->toBe(['access_rebuild_refused']);
})->with([
    'a pending switch'         => ['pending:x', 'A switch is pending or was interrupted (the state is pending:x)'],
    'the lists, authoritative' => ['lists', 'Reads use the access lists, which a rebuild would overwrite.'],
    'an unknown value'         => ['Lists', 'The access model state is Lists, not grants.'],
]);

it('creates the state row as grants when a rebuild finds none, and reads stay on the grants', function () {
    // A database that predates migration 2026_09_26_000001 and has never run a switch: no row at all.
    DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete();
    app(AccessModel::class)->flush();

    expect(accessSwitchRaw())->toBeNull();

    $this->artisan('dam:access:rebuild')->assertExitCode(0);

    expect(accessSwitchRaw())->toBe(AccessModel::GRANTS)
        ->and((new AccessModel)->current())->toBe(AccessModel::GRANTS)
        ->and(DB::table('dam_asset_group_access')->count())->toBeGreaterThan(0)
        ->and(accessSwitchAnswers($this)['owned file'])->toBeFalse();
});

it('reports lists, not grants, when another run activated the lists before the verification failed', function () {
    // Another run finished the switch while this one settled; then this run's verification throws.
    accessSwitchDuringSleep(nth: 2, then: fn () => DB::table('dam_configuration')->where('key', AccessModel::KEY)->update(['value' => AccessModel::LISTS]));
    app()->instance(AccessModel::class, new class extends AccessModel
    {
        protected function lockRow(): ?string
        {
            $locked = parent::lockRow();

            if ($locked === AccessModel::LISTS) {
                throw new RuntimeException('The verification failed.');
            }

            return $locked;
        }
    });

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('The state is lists, so reads use the lists model.')
        ->doesntExpectOutputToContain('reads use the grants model')
        ->assertExitCode(1);
});

it('reports the state as unknown when it cannot be read after the verification failed', function () {
    $model = new class extends AccessModel
    {
        public bool $unreadable = false;

        public function raw(): ?string
        {
            if ($this->unreadable) {
                throw new RuntimeException('The state row could not be read.');
            }

            return parent::raw();
        }
    };
    app()->instance(AccessModel::class, $model);
    app()->instance(AccessComparator::class, new class($model) extends AccessComparator
    {
        private int $calls = 0;

        public function __construct(private readonly AccessModel $model) {}

        public function compare(bool $withPairs = false): array
        {
            if (++$this->calls === 2) {
                $this->model->unreadable = true;

                throw new RuntimeException('The verifying comparison failed.');
            }

            return parent::compare($withPairs);
        }
    });

    $this->artisan('dam:access:switch', ['model' => 'lists'])
        ->expectsOutputToContain('The state could not be read, so which model reads use is unknown.')
        ->doesntExpectOutputToContain('reads use the grants model')
        ->assertExitCode(1);
});

it('reports the stored state beside the model reads use', function (?string $state, string $model) {
    // The state row is seeded as grants (migration 2026_09_26_000001), so the absent case removes it.
    $state === null
        ? DB::table('dam_configuration')->where('key', AccessModel::KEY)->delete()
        : DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $state]);

    // The JSON line opens with the two, before the list counts.
    $this->artisan('dam:access:report', ['--json' => true])
        ->expectsOutputToContain(rtrim(json_encode(['model' => $model, 'state' => $state], JSON_THROW_ON_ERROR), '}'))
        ->assertExitCode(0);

    // The report is read-only: it never creates the row.
    expect(accessSwitchRaw())->toBe($state);
})->with([
    'no row'           => [null, AccessModel::GRANTS],
    'grants'           => [AccessModel::GRANTS, AccessModel::GRANTS],
    'a pending switch' => ['pending:report-run', AccessModel::GRANTS],
    'lists'            => [AccessModel::LISTS, AccessModel::LISTS],
    'an unknown value' => ['Lists', AccessModel::GRANTS],
]);
