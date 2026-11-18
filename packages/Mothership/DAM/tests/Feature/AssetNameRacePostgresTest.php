<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\AdminApi\Models\Apikey;
use Mothership\AdminApi\Tests\Traits\ApiHelperTrait;
use Mothership\Core\Models\Locale;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetRename;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\StackMemberRename;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Tests\Support\TestTargetGuard;
use Throwable;

/**
 * Two writes that decide from what is already stored serialize on the asset's row lock (UX eval rev-B 1 and 3):
 *
 *  - Two people add "Caption" and "caption" to one asset at once. The name check reads, then the insert writes; only
 *    the asset lock (UniquePropertyName::serialized) keeps both from passing the check. Exactly one is created; the
 *    other answers 422.
 *    The same holds over REST, and for a custom relationship type ("Lit-By" and "lit-by").
 *  - A rename lands while a re-upload is switching the file (PNG to PDF) under the asset lock. The rename waits, then
 *    reads the row as the re-upload left it, so the new name carries the PDF's extension, not the PNG's.
 *
 *  - A relationship insert that loses on the exact unique index (a row the lock did not cover) fails inside a
 *    savepoint, so the transaction goes on, finds the winner, and answers 422 rather than an aborted-transaction 500.
 *
 * Needs two real PostgreSQL connections: the first writer's transaction is open (holding the lock) while the second,
 * in a forked process with its own connection, is observed blocked on it. SQLite drops FOR UPDATE and serializes the
 * whole database, so it cannot show either race, and neither can an in-process test.
 */
final class AssetNameRacePostgresTest extends DamConcurrencyTestCase
{
    use ApiHelperTrait;
    use AssertsNoCommittedResidue;

    /** Read by Tests\CreatesApplication before the kernel boots: on PostgreSQL this class never boots providers unadmitted. */
    public const DEMANDS_GUARDED_LANE = true;

    private const SECOND = 'revb_second_writer';

    public function test_two_case_variants_of_one_property_name_created_at_once_leave_exactly_one(): void
    {
        $this->admitRace();
        [$folder, $asset] = $this->fixture('revb-props.txt');
        $admin = Admin::factory()->create();
        $store = fn (string $name) => $this->actingAs($admin, 'admin')->postJson(
            route('admin.dam.asset.property.store', $asset->id),
            ['name' => $name, 'type' => 'text', 'language' => 'English', 'value' => $name],
        );

        try {
            $this->race(
                second: function () use ($store): array {
                    $response = $store('caption');

                    return ['status' => $response->status(), 'errors' => array_keys($response->json('errors') ?? [])];
                },
                first: function () use ($store): void {
                    // The first request runs inside a transaction the test keeps open, so its lock is held past the response.
                    $this->assertSame(200, $store('Caption')->status());
                },
                result: function (array $second) use ($asset): void {
                    $this->assertSame(['status' => 422, 'errors' => ['name']], $second, 'The second case variant must be refused once the first commits.');
                    $this->assertSame(['Caption'], AssetProperty::query()->where('dam_asset_id', $asset->id)->whereNull('field_id')->pluck('name')->all());
                },
            );
        } finally {
            DB::table('dam_asset_properties')->where('dam_asset_id', $asset->id)->delete();
            $admin->delete();
            $this->cleanUp($folder, $asset);
        }
    }

    public function test_two_case_variants_of_one_property_name_created_at_once_over_rest_leave_exactly_one(): void
    {
        $this->admitRace();
        [$folder, $asset] = $this->fixture('revb-rest-props.txt');
        $keysBefore = (int) Apikey::query()->max('id');
        $headers = $this->getAuthenticationHeaders();
        $language = Locale::query()->where('status', 1)->value('code');
        $store = fn (string $name) => $this->withHeaders($headers)->postJson(
            route('admin.api.dam.property.add', $asset->id),
            ['name' => $name, 'type' => 'text', 'language' => $language, 'value' => $name],
        );

        try {
            $this->race(
                second: function () use ($store): array {
                    $response = $store('caption');

                    return ['status' => $response->status(), 'errors' => array_keys($response->json('errors') ?? [])];
                },
                first: fn () => $this->assertSame(200, $store('Caption')->status()),
                result: function (array $second) use ($asset): void {
                    $this->assertSame(['status' => 422, 'errors' => ['name']], $second, 'The second case variant must be refused once the first commits.');
                    $this->assertSame(['Caption'], AssetProperty::query()->where('dam_asset_id', $asset->id)->whereNull('field_id')->pluck('name')->all());
                },
            );
        } finally {
            DB::table('dam_asset_properties')->where('dam_asset_id', $asset->id)->delete();
            $this->forgetApiClient(keysBefore: $keysBefore);
            $this->cleanUp($folder, $asset);
        }
    }

    public function test_two_case_variants_of_one_custom_relationship_type_created_at_once_leave_exactly_one(): void
    {
        $this->admitRace();
        [$folder, $asset] = $this->fixture('revb-rel-from.txt');
        $target = Asset::factory()->create(['path' => 'assets/'.$folder->generatePath().'/revb-rel-to.txt', 'file_name' => 'revb-rel-to.txt']);
        $target->directories()->attach($folder->id);
        $admin = Admin::factory()->create();
        $store = fn (string $type) => $this->actingAs($admin, 'admin')->postJson(route('admin.dam.assets.relationships.store', $asset->id), ['to_asset_id' => $target->id, 'type' => $type]);

        try {
            $this->race(
                second: function () use ($store): array {
                    $response = $store('lit-by');

                    return ['status' => $response->status(), 'errors' => array_keys($response->json('errors') ?? [])];
                },
                first: fn () => $this->assertSame(201, $store('Lit-By')->status()),
                result: function (array $second) use ($asset): void {
                    $this->assertSame(['status' => 422, 'errors' => ['to_asset_id']], $second);
                    $this->assertSame(['Lit-By'], AssetRelationship::query()->where('from_asset_id', $asset->id)->pluck('type')->all());
                },
            );
        } finally {
            DB::table('dam_asset_relationships')->where('from_asset_id', $asset->id)->delete();
            $admin->delete();
            $this->cleanUp($folder, $target);
            $this->cleanUp($folder, $asset);
        }
    }

    public function test_a_relationship_insert_that_loses_on_the_unique_index_recovers_in_its_savepoint_and_answers_422(): void
    {
        $this->admitRace();
        [$folder, $asset] = $this->fixture('revb-sp-from.txt');
        $target = Asset::factory()->create(['path' => 'assets/'.$folder->generatePath().'/revb-sp-to.txt', 'file_name' => 'revb-sp-to.txt']);
        $target->directories()->attach($folder->id);
        $admin = Admin::factory()->create();

        try {
            $this->race(
                second: function () use ($admin, $asset, $target): array {
                    $response = $this->actingAs($admin, 'admin')->postJson(route('admin.dam.assets.relationships.store', $asset->id), ['to_asset_id' => $target->id, 'type' => 'Lit-By']);

                    return ['status' => $response->status(), 'errors' => array_keys($response->json('errors') ?? [])];
                },
                // A writer that does not take the asset lock: the second request's check cannot see this row, and its
                // insert waits on the unique index, then fails when this commits.
                first: fn () => DB::table('dam_asset_relationships')->insert([
                    'from_asset_id' => $asset->id, 'to_asset_id' => $target->id, 'type' => 'Lit-By', 'created_at' => now(), 'updated_at' => now(),
                ]),
                result: function (array $second) use ($asset): void {
                    $this->assertSame(['status' => 422, 'errors' => ['to_asset_id']], $second, 'A failed insert must leave the transaction usable for the duplicate check.');
                    $this->assertSame(1, AssetRelationship::query()->where('from_asset_id', $asset->id)->count());
                },
                waitsOn: 'insert',
            );
        } finally {
            DB::table('dam_asset_relationships')->where('from_asset_id', $asset->id)->delete();
            $admin->delete();
            $this->cleanUp($folder, $target);
            $this->cleanUp($folder, $asset);
        }
    }

    public function test_a_rename_blocked_behind_a_re_upload_names_the_file_the_re_upload_left(): void
    {
        $this->admitRace();
        [$folder, $asset] = $this->fixture('revb-rename.png', ['extension' => 'png', 'mime_type' => 'image/png', 'file_type' => 'image']);
        // The page loaded the asset while it was still the PNG.
        $stale = Asset::query()->findOrFail($asset->id);
        $directory = dirname((string) $asset->path);

        try {
            $this->race(
                second: function () use ($stale): array {
                    app(AssetRename::class)->rename($stale, 'final.png');

                    return ['file_name' => $stale->file_name];
                },
                first: function () use ($asset, $directory): void {
                    // What the re-upload publisher does under the same lock: the row now describes a PDF.
                    Asset::query()->whereKey($asset->id)->lockedForGraph()->firstOrFail();
                    DB::table('dam_assets')->where('id', $asset->id)->update([
                        'file_name' => 'revb-rename.pdf', 'path' => $directory.'/revb-rename.pdf',
                        'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_type' => 'document',
                    ]);
                },
                result: function (array $second) use ($asset, $directory): void {
                    $this->assertSame(['file_name' => 'final.pdf'], $second);
                    $this->assertSame(
                        ['file_name' => 'final.pdf', 'path' => $directory.'/final.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'file_type' => 'document'],
                        Asset::query()->findOrFail($asset->id)->only(['file_name', 'path', 'extension', 'mime_type', 'file_type']),
                        'Name, extension and content must agree after the rename.',
                    );
                },
            );
        } finally {
            $this->cleanUp($folder, $asset);
        }
    }

    public function test_member_apply_waits_for_promotion_and_renames_only_the_reviewed_slot(): void
    {
        $this->admitRace();
        [$folder, $asset] = $this->fixture('reviewed.jpg', ['extension' => 'jpg', 'mime_type' => 'image/jpeg', 'file_type' => 'image']);
        $cover = AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'label' => 'reviewed.jpg']);
        $incoming = AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'role' => 'alternate', 'label' => 'incoming.png']);
        Queue::fake();
        try {
            $this->race(
                second: fn (): array => app(StackMemberRename::class)->rename(
                    asset: $asset, attachmentId: (int) $cover->attachment_id, expectedFileId: (int) $cover->id,
                    fileName: 'reviewed-final.jpg', title: 'Must not become stack title',
                ),
                first: fn () => app(AttachmentLedger::class)->promote(asset: $asset, attachmentId: (int) $incoming->attachment_id),
                result: function (array $second) use ($asset, $cover, $incoming): void {
                    $this->assertSame('renamed', $second['status']);
                    $this->assertSame('incoming.png', $asset->fresh()->file_name);
                    $this->assertSame('reviewed-final.jpg', $cover->fresh()->label);
                    $this->assertSame('incoming.png', $incoming->fresh()->label);
                    $this->assertNotSame('Must not become stack title', $asset->fresh()->title);
                },
            );
        } finally {
            $this->cleanUp($folder, $asset);
        }
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
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();
    }

    /** @return array{0: Directory, 1: Asset} */
    private function fixture(string $fileName, array $attributes = []): array
    {
        $folder = Directory::factory()->create();
        $asset = Asset::factory()->create(['path' => 'assets/'.$folder->generatePath().'/'.$fileName, 'file_name' => $fileName] + $attributes);
        $asset->directories()->attach($folder->id);

        return [$folder, $asset];
    }

    /**
     * $first runs in a transaction this process keeps open; $second runs in a forked process with its own connection
     * and must be seen waiting on a lock $first holds (in a statement containing $waitsOn) before $first commits. $result receives what $second returned.
     */
    private function race(\Closure $second, \Closure $first, \Closure $result, string $waitsOn = 'for '): void
    {
        $resultDir = sys_get_temp_dir().'/revb-race-'.bin2hex(random_bytes(8));
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
            $this->assertTrue($waiting, 'The second writer must wait on the asset row lock the first holds, before the first commits: '.(is_file($resultDir.'/result') ? 'it finished without waiting: '.file_get_contents($resultDir.'/result') : (is_file($resultDir.'/error') ? file_get_contents($resultDir.'/error') : 'it did not wait')));
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

    /** Deletes the asset, then (once its folder holds nothing of this test's) the folder, and proves nothing is left. */
    private function cleanUp(Directory $folder, Asset $asset): void
    {
        if ($remaining = Asset::withTrashed()->find($asset->id)) {
            app(AssetDeletionService::class)->delete(asset: $remaining, force: true);
        }

        if (DB::table('dam_asset_directory')->where('directory_id', $folder->id)->doesntExist()) {
            $folder->delete();
            $this->assertNoCommittedResidue();
        }
    }

    /** Removes what getAuthenticationHeaders() committed: the admin, its API key, OAuth client and tokens. */
    private function forgetApiClient(int $keysBefore): void
    {
        $key = Apikey::query()->where('id', '>', $keysBefore)->first();
        $clientId = $key?->oauth_client_id;
        $adminId = $key?->admin_id;
        $tokens = DB::table('oauth_access_tokens')->where('client_id', $clientId)->pluck('id');
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokens)->delete();
        DB::table('oauth_access_tokens')->whereIn('id', $tokens)->delete();
        $key?->delete();
        DB::table('oauth_clients')->where('id', $clientId)->delete();
        Admin::query()->whereKey($adminId)->delete();
    }
}
