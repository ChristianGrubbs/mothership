<?php

namespace Mothership\DAM\Tests\Feature;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Models\SavedSearch;
use Mothership\DAM\Services\Organization\LabelService;
use Mothership\DAM\Services\Organization\SavedSearchService;
use Mothership\DAM\Services\Organization\SectionService;
use Mothership\DAM\Support\OrganizationNameLock;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;
use Tests\Support\TestTargetGuard;
use Throwable;

/**
 * Every operation that can change a name, or the scope a name is judged in, serializes on the scope's anchor row
 * (OrganizationNameLock) and holds it until its transaction commits (UX eval rev-J2 1):
 *
 *  - a create and a rename of different rows to the same name, in the same scope, at once;
 *  - a create and a move into the scope (to the root, and from one parent to another);
 *  - two boards of one owner, while a different owner's boards, and the shared labels, proceed independently;
 *  - saved searches and sections, and the first use of a scope whose anchor row does not exist yet.
 *
 * Needs two real PostgreSQL connections: the first writer's transaction is open (holding the lock) while the second,
 * in a forked process with its own connection, is observed blocked on it. SQLite drops FOR UPDATE and serializes the
 * whole database, so it cannot show the race, and neither can an in-process test or a cache lease (which expires).
 */
final class OrganizationNameRacePostgresTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    /** Read by Tests\CreatesApplication before the kernel boots: on PostgreSQL this class never boots providers unadmitted. */
    public const DEMANDS_GUARDED_LANE = true;

    private const SECOND = 'revj2_second_writer';

    private int $rolesBefore = 0;

    private int $labelsBefore = 0;

    private int $searchesBefore = 0;

    /** @var list<int> */
    private array $admins = [];

    public function test_a_create_and_a_rename_to_the_same_name_leave_exactly_one(): void
    {
        $this->admitRace();
        $labels = app(LabelService::class);
        $draft = $this->shared('Draft');
        $this->warm('label.shared.shared');

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(LabelService::class)->update(Label::query()->findOrFail($draft->id), ['name' => 'REPORT'], null)),
                first: fn () => $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Report'], viewerId: null),
                result: function (array $second) use ($draft): void {
                    $this->assertSame(['invalid' => ['name']], $second, 'The rename must be refused once the create commits.');
                    $this->assertSame(['Draft', 'Report'], Label::query()->orderBy('id')->pluck('name')->all());
                    $this->assertSame('Draft', Label::query()->findOrFail($draft->id)->name);
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_create_and_a_move_to_the_root_leave_exactly_one(): void
    {
        $this->admitRace();
        $labels = app(LabelService::class);
        $folder = $this->shared('Folder');
        $plan = $this->shared('Plan', $folder);
        $this->warm('label.shared.shared');

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(LabelService::class)->update(Label::query()->findOrFail($plan->id), ['parent_id' => null], null)),
                first: fn () => $labels->create(kind: Label::KIND_SHARED, attributes: ['name' => 'plan'], viewerId: null),
                result: function (array $second) use ($plan, $folder): void {
                    $this->assertSame(['invalid' => ['name']], $second, 'The move must be refused once the create commits.');
                    $this->assertSame($folder->id, (int) Label::query()->findOrFail($plan->id)->parent_id);
                    $this->assertSame(1, Label::query()->whereNull('parent_id')->whereRaw('LOWER(name) = ?', ['plan'])->count());
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_rename_in_one_parent_and_a_move_from_another_parent_leave_exactly_one(): void
    {
        $this->admitRace();
        $labels = app(LabelService::class);
        $first = $this->shared('First');
        $second = $this->shared('Second');
        $item = $this->shared('Item', $first);
        $other = $this->shared('Other', $second);
        $this->warm('label.shared.shared');

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(LabelService::class)->update(Label::query()->findOrFail($item->id), ['parent_id' => $second->id], null)),
                first: fn () => $labels->update(Label::query()->findOrFail($other->id), ['name' => 'item'], null),
                result: function (array $result) use ($item, $first, $second): void {
                    $this->assertSame(['invalid' => ['name']], $result, 'The parent-to-parent move must be refused once the rename commits.');
                    $this->assertSame($first->id, (int) Label::query()->findOrFail($item->id)->parent_id);
                    $this->assertSame(['item'], Label::query()->where('parent_id', $second->id)->pluck('name')->all());
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_two_boards_of_one_owner_with_one_name_leave_exactly_one(): void
    {
        $this->admitRace();
        $owner = $this->admin();
        $this->warm('label.board.'.$owner->id);

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(LabelService::class)->create(kind: Label::KIND_BOARD, attributes: ['name' => 'mood'], viewerId: $owner->id)),
                first: fn () => app(LabelService::class)->create(kind: Label::KIND_BOARD, attributes: ['name' => 'Mood'], viewerId: $owner->id),
                result: function (array $second) use ($owner): void {
                    $this->assertSame(['invalid' => ['name']], $second);
                    $this->assertSame(['Mood'], Label::query()->where('owner_id', $owner->id)->pluck('name')->all());
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_another_owners_board_and_the_shared_labels_proceed_while_a_board_lock_is_held(): void
    {
        $this->admitRace();
        $owner = $this->admin();
        $other = $this->admin();
        $this->warm('label.board.'.$owner->id);
        $this->warm('label.board.'.$other->id);
        $this->warm('label.shared.shared');

        try {
            $this->race(
                second: fn () => $this->outcome(function () use ($other) {
                    app(LabelService::class)->create(kind: Label::KIND_BOARD, attributes: ['name' => 'mood'], viewerId: $other->id);
                    app(LabelService::class)->create(kind: Label::KIND_SHARED, attributes: ['name' => 'Mood'], viewerId: null);
                }),
                first: fn () => app(LabelService::class)->create(kind: Label::KIND_BOARD, attributes: ['name' => 'Mood'], viewerId: $owner->id),
                result: function (array $second) use ($owner, $other): void {
                    $this->assertSame(['ok' => true], $second);
                    $this->assertSame(['Mood'], Label::query()->where('owner_id', $owner->id)->pluck('name')->all());
                    $this->assertSame(['mood'], Label::query()->where('owner_id', $other->id)->pluck('name')->all());
                    $this->assertSame(['Mood'], Label::query()->where('kind', Label::KIND_SHARED)->pluck('name')->all());
                },
                blocks: false,
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_saved_search_create_and_a_rename_to_the_same_name_leave_exactly_one(): void
    {
        $this->admitRace();
        $owner = $this->admin();
        $searches = app(SavedSearchService::class);
        $logos = $searches->create(['name' => 'Logos', 'definition' => ['search' => 'logo']], $owner->id);
        $this->warm('search.'.$owner->id);

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(SavedSearchService::class)->update(SavedSearch::query()->findOrFail($logos->id), ['name' => 'ICONS'], $owner->id)),
                first: fn () => $searches->create(['name' => 'Icons', 'definition' => ['search' => 'icon']], $owner->id),
                result: function (array $second) use ($logos, $owner): void {
                    $this->assertSame(['invalid' => ['name']], $second);
                    $this->assertSame('Logos', SavedSearch::query()->findOrFail($logos->id)->name);
                    $this->assertSame(2, SavedSearch::query()->where('owner_id', $owner->id)->count());
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_section_create_and_a_rename_to_the_same_name_leave_exactly_one(): void
    {
        $this->admitRace();
        $sections = app(SectionService::class);
        $video = $sections->create(['name' => 'Video'], null);
        $this->warm('section');

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(SectionService::class)->update(SavedSearch::query()->findOrFail($video->id), ['name' => 'PRINT'], null)),
                first: fn () => $sections->create(['name' => 'Print'], null),
                result: function (array $second) use ($video): void {
                    $this->assertSame(['invalid' => ['name']], $second);
                    $this->assertSame('Video', SavedSearch::query()->findOrFail($video->id)->name);
                    $this->assertSame(2, SavedSearch::query()->where('kind', SavedSearch::KIND_SECTION)->count());
                },
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_the_first_use_of_a_scope_serializes_on_creating_its_anchor_row(): void
    {
        $this->admitRace();
        $owner = $this->admin();
        $this->assertFalse(DB::table('dam_configuration')->where('key', OrganizationNameLock::anchorKey('label.board.'.$owner->id))->exists());

        try {
            $this->race(
                second: fn () => $this->outcome(fn () => app(LabelService::class)->create(kind: Label::KIND_BOARD, attributes: ['name' => 'fresh'], viewerId: $owner->id)),
                first: fn () => app(LabelService::class)->create(kind: Label::KIND_BOARD, attributes: ['name' => 'Fresh'], viewerId: $owner->id),
                result: function (array $second) use ($owner): void {
                    $this->assertSame(['invalid' => ['name']], $second);
                    $this->assertSame(['Fresh'], Label::query()->where('owner_id', $owner->id)->pluck('name')->all());
                },
                waitsOn: 'insert',
            );
        } finally {
            $this->cleanUp();
        }
    }

    public function test_a_nested_call_puts_the_outer_transactions_lock_timeout_back_whether_it_succeeds_or_fails(): void
    {
        $this->admitRace();
        $this->warm('section');

        try {
            DB::beginTransaction();
            DB::statement("SET LOCAL lock_timeout = '7s'");
            $current = fn () => (string) DB::selectOne("select current_setting('lock_timeout') as value")->value;

            OrganizationNameLock::run(scope: 'section', callback: fn () => $this->assertSame('5s', $current(), 'The call itself runs with its own limit.'));
            $this->assertSame('7s', $current(), 'A successful nested call must not leave its limit behind.');

            try {
                OrganizationNameLock::run(scope: 'section', callback: fn () => throw new \RuntimeException('refused'));
            } catch (\RuntimeException) {
            }
            $this->assertSame('7s', $current(), 'A failed nested call must not leave its limit behind either.');

            // The anchor lock is still held by the outer transaction after the nested call returned: a second session cannot take it.
            config(['database.connections.'.self::SECOND => config('database.connections.'.DB::getDefaultConnection())]);
            $this->assertTrue($this->anchorIsLockedElsewhere('section'));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge(self::SECOND);
            $this->cleanUp();
        }
    }

    private function anchorIsLockedElsewhere(string $scope): bool
    {
        try {
            DB::connection(self::SECOND)->table('dam_configuration')->where('key', OrganizationNameLock::anchorKey($scope))->lock('for update nowait')->first(['key']);
        } catch (QueryException $error) {
            return $error->errorInfo[0] === '55P03';
        } finally {
            DB::connection(self::SECOND)->rollBack();
        }

        return false;
    }

    /** What the second writer reports: it finished, or was refused with these fields. */
    private function outcome(Closure $work): array
    {
        try {
            $work();

            return ['ok' => true];
        } catch (ValidationException $error) {
            return ['invalid' => array_keys($error->errors())];
        }
    }

    private function shared(string $name, ?Label $parent = null): Label
    {
        return app(LabelService::class)->create(kind: Label::KIND_SHARED, attributes: ['name' => $name] + ($parent ? ['parent_id' => $parent->id] : []), viewerId: null);
    }

    private function admin(): Admin
    {
        $admin = Admin::factory()->create();
        $this->admins[] = (int) $admin->id;

        return $admin;
    }

    /** Creates the scope's anchor row (committed), so the second writer waits on the row lock itself, not on its creation. */
    private function warm(string $scope): void
    {
        OrganizationNameLock::run(scope: $scope, callback: fn () => null);
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
        $this->recordResidueBaseline();
        $this->rolesBefore = (int) Role::query()->max('id');
        $this->labelsBefore = (int) DB::table('dam_labels')->max('id');
        $this->searchesBefore = (int) DB::table('dam_saved_searches')->max('id');
    }

    /**
     * $first runs in a transaction this process keeps open; $second runs in a forked process with its own connection.
     * With $blocks it must be seen waiting on a lock $first holds (in a statement containing $waitsOn) before $first
     * commits; without, it must finish while $first is still open. $result receives what $second returned.
     */
    private function race(Closure $second, Closure $first, Closure $result, string $waitsOn = 'for update', bool $blocks = true): void
    {
        $resultDir = sys_get_temp_dir().'/revj2-race-'.bin2hex(random_bytes(8));
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
                    $waiting = $activity?->wait_event_type === 'Lock' && $activity->blocked_by_holder && str_contains(strtolower($activity->query), $waitsOn);
                }
                if ($waiting || is_file($resultDir.'/error') || is_file($resultDir.'/result')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            if ($blocks) {
                $this->assertTrue($waiting, 'The second writer must wait on the scope lock the first holds, before the first commits: '.(is_file($resultDir.'/result') ? 'it finished without waiting: '.file_get_contents($resultDir.'/result') : (is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'it did not wait')));
            } else {
                $this->assertFalse($waiting, 'A different scope must not wait on the lock the first holds.');
                $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The second writer did not finish while the first still held its lock.');
            }
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

    /** Removes what the test committed (children before parents), then proves nothing is left. */
    private function cleanUp(): void
    {
        DB::table('dam_labels')->where('id', '>', $this->labelsBefore)->delete();
        DB::table('dam_saved_searches')->where('id', '>', $this->searchesBefore)->delete();
        DB::table('dam_configuration')->where('key', 'like', 'organize.lock.%')->delete();
        Admin::query()->whereIn('id', $this->admins)->delete();
        Role::query()->where('id', '>', $this->rolesBefore)->delete();
        $this->assertNoCommittedResidue();
    }
}
