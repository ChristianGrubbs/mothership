<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\AiIndexAsset;
use Mothership\DAM\Jobs\AiReindexLexical;
use Mothership\DAM\Jobs\ProcessAssetUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\StackMemberState;
use Mothership\DAM\Tests\Concerns\AssertsNoCommittedResidue;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\User\Models\Admin;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Real committed PostgreSQL fixtures: no DatabaseTransactions wrapper across the fork. */
final class StackReorderDeadlockTest extends DamConcurrencyTestCase
{
    use AssertsNoCommittedResidue;

    public const DEMANDS_GUARDED_LANE = true;

    public function test_reverse_first_member_recovers_from_a_real_postgresql_deadlock(): void
    {
        $this->race(stale: false);
    }

    public function test_retry_rejects_an_order_changed_by_the_competing_transaction(): void
    {
        $this->race(stale: true);
    }

    public function test_outer_level_deadlock_exhausts_three_attempts_without_committing_a_non_first_move(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL transaction abort semantics.');
        }
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        config(['ai-index.index_enabled' => false]);
        $asset = $admin = null;
        $lastJob = (int) DB::table('jobs')->max('id');
        try {
            $admin = Admin::factory()->create();
            $this->actingAs($admin, 'admin');
            $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
                'file_name' => 'first.png', 'path' => 'assets/Deadlock/'.bin2hex(random_bytes(8)).'/first.png',
                'file_type' => 'image', 'extension' => 'png', 'mime_type' => 'image/png',
                'meta_data' => ['thumbnail_path' => 'preserved.jpg', 'artist_note' => 'unchanged'],
            ]));
            $first = AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'role' => 'original']);
            $ledger = app(AttachmentLedger::class);
            $second = $ledger->attach(asset: $asset, upload: UploadedFile::fake()->image('second.png', 19, 13), role: 'alternate', label: 'second.png');
            $third = $ledger->attach(asset: $asset, upload: UploadedFile::fake()->image('third.png', 23, 17), role: 'alternate', label: 'third.png');
            $before = AssetFile::where('dam_asset_id', $asset->id)->orderBy('id')->get()->toArray();
            $metadata = $asset->fresh()->meta_data;
            Queue::swap($realQueue);
            config(['queue.default' => 'database', 'ai-index.index_enabled' => true]);
            $attempts = 0;
            DB::connection()->beforeExecuting(function ($sql) use (&$attempts): void {
                if (DB::transactionLevel() === 1 && str_starts_with(strtolower($sql), 'update "dam_attachments"')) {
                    $attempts++;
                    // A server-raised 40P01 aborts the real transaction. The forked cases above
                    // prove real lock contention; this deterministic case exercises the retry cap.
                    DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected (test transaction victim)' USING ERRCODE = '40P01'; END $$");
                }
            });
            $state = null;
            try {
                $ledger->reorder(asset: $asset, attachmentIds: [$first->attachment_id, $third->attachment_id, $second->attachment_id], expectedFiles: [$first->id, $second->id, $third->id]);
            } catch (QueryException $error) {
                $state = $error->getCode();
            }
            $this->assertSame('40P01', $state);
            $this->assertSame(3, $attempts);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($before, AssetFile::where('dam_asset_id', $asset->id)->orderBy('id')->get()->toArray());
            $this->assertSame($metadata, $asset->fresh()->meta_data);
            $this->assertSame($first->attachment_id, Attachment::where('dam_asset_id', $asset->id)->where('is_representative', true)->value('id'));
            $this->assertSame(0, DB::table('jobs')->where('id', '>', $lastJob)->count());
        } finally {
            DB::purge();
            config(['ai-index.index_enabled' => false]);
            try {
                if ($asset !== null) {
                    app(AssetDeletionService::class)->delete($asset->fresh(), force: true);
                }
                $admin?->delete();
            } finally {
                DB::table('jobs')->where('id', '>', $lastJob)->delete();
                $this->assertNoCommittedResidue();
            }
        }
    }

    private function race(bool $stale): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('Requires PostgreSQL and independent forked connections.');
        }
        Storage::fake(Directory::getAssetDisk());
        $this->recordResidueBaseline();
        $realQueue = Queue::getFacadeRoot();
        Queue::fake();
        config(['ai-index.index_enabled' => false]);
        $admin = $asset = $directory = null;
        $pid = $dir = $lastJob = null;
        $reaped = false;
        try {
            $admin = Admin::factory()->create();
            $this->actingAs($admin, 'admin');
            $asset = Asset::withoutEvents(fn () => Asset::factory()->create([
                'file_name' => 'first.png', 'path' => 'assets/Deadlock/'.bin2hex(random_bytes(8)).'/first.png', 'file_type' => 'image',
                'extension' => 'png', 'mime_type' => 'image/png',
            ]));
            $directory = Directory::factory()->create();
            $asset->directories()->attach($directory->id);
            $first = AssetFile::factory()->create(['dam_asset_id' => $asset->id, 'role' => 'original']);
            $originalMeta = ['thumbnail_path' => 'thumbnails/preserved-first.jpg', 'thumbnail_revision' => 'preserved-first', 'artist_note' => 'Keep original member metadata'];
            $asset->update(['meta_data' => $originalMeta]);
            $ledger = app(AttachmentLedger::class);
            $second = $ledger->attach(asset: $asset, upload: UploadedFile::fake()->image('second.png', 19, 13), role: 'alternate', label: 'second.png')->toArray();
            // The live failure was a reverse move after one successful first-member change.
            $ledger->reorder(asset: $asset, attachmentIds: [$second['attachment_id'], $first->attachment_id], expectedFiles: [$first->id, $second['id']]);
            $before = AssetFile::where('dam_asset_id', $asset->id)->orderBy('id')->pluck('file_object_id', 'id')->all();
            $metadata = $asset->fresh()->meta_data;
            DB::table('dam_ai_index')->insertOrIgnore(['asset_id' => $asset->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            config(['ai-index.index_enabled' => true, 'queue.default' => 'database']);
            Queue::swap($realQueue);
            $lastJob = (int) DB::table('jobs')->max('id');
            $dir = sys_get_temp_dir().'/stack-deadlock-'.bin2hex(random_bytes(8));
            mkdir($dir);
            DB::purge();
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge();
                try {
                    DB::statement("SET deadlock_timeout = '10s'");
                    DB::statement("SET statement_timeout = '20s'");
                    DB::transaction(function () use ($asset, $first, $second, $stale, $dir): void {
                        DB::table('dam_ai_index')->where('asset_id', $asset->id)->lockForUpdate()->first();
                        file_put_contents($dir.'/ready', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                        self::awaitFile($dir.'/go');
                        DB::table('dam_assets')->where('id', $asset->id)->lockForUpdate()->first();
                        if ($stale) {
                            // Another writer commits a new ordering while the failed attempt rolls back.
                            foreach ([$first->attachment_id, $second['attachment_id']] as $position => $attachment) {
                                DB::table('dam_attachments')->where('id', $attachment)->update(['position' => $position]);
                                DB::table('dam_asset_files')->where('attachment_id', $attachment)->update(['sort' => $position]);
                            }
                        }
                    });
                    file_put_contents($dir.'/done', 'committed');
                } catch (Throwable $error) {
                    file_put_contents($dir.'/error', $error::class.': '.$error->getMessage());
                }
                posix_kill(posix_getpid(), SIGKILL);
                exit(1);
            }
            $attempts = 0;
            $armed = true;
            $failure = null;
            self::awaitFile($dir.'/ready');
            $backend = (int) file_get_contents($dir.'/ready');
            DB::statement("SET deadlock_timeout = '100ms'");
            DB::statement("SET statement_timeout = '20s'");
            DB::connection()->beforeExecuting(function ($sql) use (&$armed, &$attempts, $backend, $dir): void {
                if (str_contains($sql, 'for no key update') && DB::transactionLevel() === 1) {
                    $attempts++;
                }
                if ($armed && str_starts_with(strtolower($sql), 'update "dam_ai_index"')) {
                    $armed = false;
                    file_put_contents($dir.'/go', 'go');
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
                        if (($waiting->wait_event_type ?? null) === 'Lock') {
                            return;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    throw new \RuntimeException('Competing transaction did not reach the asset lock.');
                }
            });
            try {
                app(AttachmentLedger::class)->reorder(asset: $asset->fresh(), attachmentIds: [$first->attachment_id, $second['attachment_id']], expectedFiles: [$second['id'], $first->id]);
            } catch (HttpException $error) {
                $failure = $error->getStatusCode();
            }
            pcntl_waitpid($pid, $status);
            $reaped = true;
            $this->assertFileDoesNotExist($dir.'/error', is_file($dir.'/error') ? file_get_contents($dir.'/error') : '');
            $this->assertSame('committed', file_get_contents($dir.'/done'));
            $this->assertSame(2, $attempts, 'The real deadlock must roll back and retry the outer transaction once.');
            $this->assertSame($stale ? 409 : null, $failure);
            $this->assertSame($before, AssetFile::where('dam_asset_id', $asset->id)->orderBy('id')->pluck('file_object_id', 'id')->all());
            $this->assertSame([$first->id, $second['id']], AssetFile::where('dam_asset_id', $asset->id)->orderBy('sort')->orderBy('id')->pluck('id')->all());
            $this->assertSame($stale ? $second['attachment_id'] : $first->attachment_id, Attachment::where('dam_asset_id', $asset->id)->where('is_representative', true)->value('id'));
            if ($stale) {
                $this->assertSame($metadata, $asset->fresh()->meta_data);
                $this->assertSame(0, DB::table('jobs')->where('id', '>', $lastJob)->count());
            } else {
                $names = DB::table('jobs')->where('id', '>', $lastJob)->pluck('payload')
                    ->map(fn ($payload) => json_decode($payload, true)['displayName'])->all();
                sort($names);
                $expectedJobs = [AiIndexAsset::class, AiReindexLexical::class, ProcessAssetUpload::class];
                sort($expectedJobs);
                $this->assertSame($expectedJobs, $names);
                $restored = $asset->fresh()->meta_data;
                unset($restored[StackMemberState::KEY]);
                $this->assertSame($originalMeta, $restored);
            }
        } finally {
            if ($pid !== null && $pid > 0 && ! $reaped) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge(); // Drop the attempt observer and session-only timeout settings.
            config(['ai-index.index_enabled' => false]);
            try {
                if ($asset !== null) {
                    app(AssetDeletionService::class)->delete($asset->fresh(), force: true);
                }
                $directory?->delete();
                $admin?->delete();
            } finally {
                if ($lastJob !== null) {
                    DB::table('jobs')->where('id', '>', $lastJob)->delete();
                }
                if ($dir !== null) {
                    foreach (glob($dir.'/*') ?: [] as $file) {
                        unlink($file);
                    }
                    rmdir($dir);
                }
                $this->assertNoCommittedResidue();
            }
        }
    }

    private static function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for concurrency barrier.');
            }
            usleep(10000);
        }
    }
}
