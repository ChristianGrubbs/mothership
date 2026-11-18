<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\Automation\ValueDecisions;
use Mothership\DAM\Services\Automation\ValueSource;
use Mothership\DAM\Services\Automation\ValueWriter;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestTargetGuard;
use Throwable;

/**
 * A mapping's or a rule's field write decides twice: once up front, and again under the asset's row lock, in the same
 * transaction as the write. A person's write takes the same lock. So when a person replaces or clears the value, or
 * sets a field that had none, between the machine's first look and its write, the machine finds out and writes nothing.
 *
 * Needs two real PostgreSQL connections: the person's transaction is open (uncommitted, holding the asset lock) while
 * the machine writer, in a forked process with its own connection, has already passed its first check and is blocked
 * on that lock. SQLite cannot show this, and neither can an in-process test.
 */
final class AutomationManualWriteRacePostgresTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    /** Read by Tests\CreatesApplication before the kernel boots: on PostgreSQL this class never boots providers unadmitted. */
    public const DEMANDS_GUARDED_LANE = true;

    private const MACHINE = 's8_machine_writer';

    /**
     * case, which side takes the asset lock first, the machine's outcome, its reason, the value left behind (null = none) and whether the removal is recorded.
     * The person-first datasets are genuine two-connection races (the machine writer is observed blocked on the lock the
     * person holds). The machine-first datasets are NOT races: they are sequential precedence checks of the other order.
     * "blank-empty" is a person submitting a blank form for a field that never had a value: that is not a clear, records
     * nothing, and leaves the machine free to fill the field.
     */
    public static function races(): array
    {
        return [
            'person first: replaces a machine value'                                       => ['replace', 'person-first', 'refused', 'manual', 'person value', 'manual', false],
            'person first: clears a machine value'                                         => ['clear', 'person-first', 'refused', 'removed', null, null, true],
            'person first: sets a field with no value yet'                                 => ['set-empty', 'person-first', 'refused', 'manual', 'person value', 'manual', false],
            'person first: deletes the value row'                                          => ['delete-row', 'person-first', 'refused', 'removed', null, null, true],
            'person first: blank form on an empty field'                                   => ['blank-empty', 'person-first', 'written', null, 'machine value', 'rule', false],
            'sequential precedence (machine commits first): person then replaces'          => ['replace', 'machine-first', 'written', null, 'person value', 'manual', false],
            'sequential precedence (machine commits first): person then clears'            => ['clear', 'machine-first', 'written', null, null, null, true],
            'sequential precedence (machine commits first): person then sets'              => ['set-empty', 'machine-first', 'written', null, 'person value', 'manual', false],
        ];
    }

    #[DataProvider('races')]
    public function test_a_machine_write_and_a_person_write_serialize_on_the_asset_lock_in_either_order(string $case, string $order, string $expectedOutcome, ?string $expectedReason, ?string $expectedValue, ?string $expectedSource, bool $expectedRemoved): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two real PostgreSQL connections and row locks; SQLite cannot exercise this race.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'PostgreSQL acceptance requires pcntl and posix; missing extensions must fail the gate.');
        $this->assertTrue(defined(TestTargetGuard::LANE_CONSTANT), 'Committed race fixtures run only in a guarded lane: the local allowlist (phpunit.mothership.xml) or the CI service proof (tests/Support/TestTargetGuard.php); both admit before providers boot.');
        $this->assertContains(config('database.connections.'.DB::getDefaultConnection().'.host'), ['127.0.0.1', 'localhost'], 'Committed race fixtures never run against a remote database.');
        config(['database.connections.'.self::MACHINE => config('database.connections.'.DB::getDefaultConnection())]);
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();

        $folder = Directory::factory()->create();
        $asset = Asset::factory()->create(['path' => 'assets/'.$folder->generatePath().'/s8-race.txt', 'file_name' => 's8-race.txt']);
        $asset->directories()->attach($folder->id);
        $field = MetadataField::query()->create(['code' => 's8_'.bin2hex(random_bytes(4)), 'label' => 'S8 race', 'type' => 'text']);
        $fields = app(FieldValues::class);

        if (! in_array($case, ['set-empty', 'blank-empty'], true)) {
            $this->assertTrue($fields->writeMachine(asset: $asset, field: $field, value: 'ai value', provenance: 'ai:s8-test'));
        }

        $resultDir = sys_get_temp_dir().'/s8-write-'.bin2hex(random_bytes(8));
        mkdir($resultDir, 0700);
        $pid = null;
        // Neither process inherits an open database socket. Each opens its own after the fork.
        DB::purge();
        DB::purge(self::MACHINE);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                try {
                    DB::setDefaultConnection(self::MACHINE);
                    DB::statement("SET statement_timeout = '15000ms'");
                    file_put_contents($resultDir.'/pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                    $deadline = microtime(true) + 10;
                    while (! is_file($resultDir.'/go')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('The person never released the start barrier.');
                        }
                        usleep(10000);
                    }
                    // A rule outranks the AI value it replaces, so without the person's change this write would go through.
                    $outcome = app(ValueWriter::class)->setField(
                        asset: Asset::query()->findOrFail($asset->id),
                        field: MetadataField::query()->findOrFail($field->id),
                        raw: 'machine value',
                        source: ValueSource::rule(9001, 1),
                        mode: ValueWriter::MODE_REPLACE_MACHINE,
                        dryRun: false,
                    );
                    file_put_contents($resultDir.'/result', json_encode($outcome->toArray(), JSON_THROW_ON_ERROR));
                } catch (Throwable $error) {
                    file_put_contents($resultDir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }

            $personWrites = function () use ($case, $fields, $asset, $field) {
                DB::beginTransaction();
                if ($case === 'delete-row') {
                    $row = AssetProperty::query()->where('dam_asset_id', $asset->id)->where('field_id', $field->id)->firstOrFail();
                    $fields->deleteByPerson(row: $row, actorId: null, delete: fn () => $row->delete());
                } else {
                    $fields->write(asset: $asset, values: [$field->code => ['field' => $field, 'value' => in_array($case, ['clear', 'blank-empty'], true) ? null : 'person value']], source: 'web');
                }
            };

            if ($order === 'person-first') {
                // The person's transaction takes the asset lock and stays open until the machine is waiting on it.
                $personWrites();
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
                $this->assertTrue($waiting, 'The machine writer must wait on the asset row lock the person holds, before the person commits: '.(is_file($resultDir.'/result') ? file_get_contents($resultDir.'/result') : 'it did not wait'));
                DB::commit();
            } else {
                // The machine takes the lock and commits first; only then does the person write.
                file_put_contents($resultDir.'/go', '1');
                $deadline = microtime(true) + 15;
                while (! is_file($resultDir.'/result') && ! is_file($resultDir.'/error') && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The machine writer did not finish before the person wrote.');
                $personWrites();
                DB::commit();
            }

            $deadline = microtime(true) + 15;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The machine writer did not finish.');
            $outcome = json_decode(file_get_contents($resultDir.'/result'), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($expectedOutcome, $outcome['outcome'], 'Unexpected machine outcome: '.json_encode($outcome));
            if ($expectedReason !== null) {
                $this->assertSame($expectedReason, $outcome['reason']);
            }

            $stored = $fields->current(assetId: (int) $asset->id, field: $field);
            $removed = app(ValueDecisions::class)->isRemoved(assetId: (int) $asset->id, kind: ValueDecisions::KIND_FIELD, targetId: (int) $field->id);

            $this->assertSame($expectedRemoved, $removed, 'The removal record must follow an actual clear, never a blank form.');

            if ($expectedValue === null) {
                $this->assertNull($stored, 'A value the person cleared must not come back.');
            } else {
                $this->assertSame($expectedValue, $stored['text']);
                $this->assertSame([], $stored['options']);
                $this->assertStringStartsWith($expectedSource, $stored['source']);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(self::MACHINE);
            DB::table('dam_value_decisions')->where('asset_id', $asset->id)->delete();
            DB::table('dam_asset_properties')->where('dam_asset_id', $asset->id)->delete();
            app(AssetDeletionService::class)->delete(asset: Asset::withTrashed()->findOrFail($asset->id), force: true);
            $field->delete();
            $folder->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
            $this->assertNoCommittedResidue();
        }
    }

    /**
     * The reverse of the person-first races: the machine's transaction holds the asset row lock and the person's write,
     * in the forked process, is observed blocked on it. When the machine commits, the person's write goes through on
     * top of it, so the manual value or the removal wins.
     */
    public static function personBlockedCases(): array
    {
        return [
            'machine holds the lock: a blocked person replaces the value' => ['replace', 'person value', 'manual', false],
            'machine holds the lock: a blocked person clears the value'   => ['clear', null, null, true],
        ];
    }

    #[DataProvider('personBlockedCases')]
    public function test_a_person_write_blocked_behind_a_machine_write_wins_once_the_machine_commits(string $case, ?string $expectedValue, ?string $expectedSource, bool $expectedRemoved): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires two real PostgreSQL connections and row locks; SQLite cannot exercise this race.');
        }
        $this->assertTrue(function_exists('pcntl_fork') && function_exists('posix_kill'), 'PostgreSQL acceptance requires pcntl and posix; missing extensions must fail the gate.');
        $this->assertTrue(defined(TestTargetGuard::LANE_CONSTANT), 'Committed race fixtures run only in a guarded lane: the local allowlist (phpunit.mothership.xml) or the CI service proof (tests/Support/TestTargetGuard.php); both admit before providers boot.');
        $this->assertContains(config('database.connections.'.DB::getDefaultConnection().'.host'), ['127.0.0.1', 'localhost'], 'Committed race fixtures never run against a remote database.');
        config(['database.connections.'.self::MACHINE => config('database.connections.'.DB::getDefaultConnection())]);
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();

        $folder = Directory::factory()->create();
        $asset = Asset::factory()->create(['path' => 'assets/'.$folder->generatePath().'/s8-race.txt', 'file_name' => 's8-race.txt']);
        $asset->directories()->attach($folder->id);
        $field = MetadataField::query()->create(['code' => 's8_'.bin2hex(random_bytes(4)), 'label' => 'S8 race', 'type' => 'text']);
        $fields = app(FieldValues::class);
        $this->assertTrue($fields->writeMachine(asset: $asset, field: $field, value: 'ai value', provenance: 'ai:s8-test'));

        $resultDir = sys_get_temp_dir().'/s8-write-'.bin2hex(random_bytes(8));
        mkdir($resultDir, 0700);
        $pid = null;
        DB::purge();
        DB::purge(self::MACHINE);

        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork failed');
            if ($pid === 0) {
                try {
                    DB::setDefaultConnection(self::MACHINE);
                    DB::statement("SET statement_timeout = '15000ms'");
                    file_put_contents($resultDir.'/pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                    $deadline = microtime(true) + 10;
                    while (! is_file($resultDir.'/go')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('The machine never released the start barrier.');
                        }
                        usleep(10000);
                    }
                    DB::transaction(fn () => app(FieldValues::class)->write(
                        asset: Asset::query()->findOrFail($asset->id),
                        values: [$field->code => ['field' => MetadataField::query()->findOrFail($field->id), 'value' => $case === 'clear' ? null : 'person value']],
                        source: 'web',
                    ));
                    file_put_contents($resultDir.'/result', 'done');
                } catch (Throwable $error) {
                    file_put_contents($resultDir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }

            // The machine's transaction takes the asset lock and stays open until the person is waiting on it.
            DB::beginTransaction();
            $outcome = app(ValueWriter::class)->setField(
                asset: Asset::query()->findOrFail($asset->id),
                field: MetadataField::query()->findOrFail($field->id),
                raw: 'machine value',
                source: ValueSource::rule(9001, 1),
                mode: ValueWriter::MODE_REPLACE_MACHINE,
                dryRun: false,
            );
            $this->assertSame('written', $outcome->toArray()['outcome'], 'The machine holds the lock only if its own write went through.');
            file_put_contents($resultDir.'/go', '1');

            $deadline = microtime(true) + 10;
            $waiting = false;
            do {
                if (is_file($resultDir.'/pid')) {
                    $backend = (int) file_get_contents($resultDir.'/pid');
                    $holder = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                    $this->assertNotSame($holder, $backend);
                    DB::select('select pg_stat_clear_snapshot()');
                    $activity = DB::selectOne('select wait_event_type, query, ? = any(pg_blocking_pids(pid)) as blocked_by_holder from pg_stat_activity where pid = ?', [$holder, $backend]);
                    $waiting = $activity?->wait_event_type === 'Lock' && $activity->blocked_by_holder && str_contains(strtolower($activity->query), 'for update');
                }
                if ($waiting || is_file($resultDir.'/error') || is_file($resultDir.'/result')) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'The person\'s write must wait on the asset row lock the machine holds, before the machine commits: '.(is_file($resultDir.'/result') ? 'it finished without waiting' : (is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'it did not wait')));
            DB::commit();

            $deadline = microtime(true) + 15;
            while (pcntl_waitpid($pid, $status, WNOHANG) === 0 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($resultDir.'/result', is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'The person\'s write did not finish.');

            $stored = $fields->current(assetId: (int) $asset->id, field: $field);
            $removed = app(ValueDecisions::class)->isRemoved(assetId: (int) $asset->id, kind: ValueDecisions::KIND_FIELD, targetId: (int) $field->id);
            $this->assertSame($expectedRemoved, $removed);

            if ($expectedValue === null) {
                $this->assertNull($stored, 'A value the person cleared after the machine wrote must not come back.');
            } else {
                $this->assertSame($expectedValue, $stored['text']);
                $this->assertStringStartsWith($expectedSource, $stored['source']);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(self::MACHINE);
            DB::table('dam_value_decisions')->where('asset_id', $asset->id)->delete();
            DB::table('dam_asset_properties')->where('dam_asset_id', $asset->id)->delete();
            app(AssetDeletionService::class)->delete(asset: Asset::withTrashed()->findOrFail($asset->id), force: true);
            $field->delete();
            $folder->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($resultDir);
            $this->assertNoCommittedResidue();
        }
    }
}
