<?php

namespace Mothership\DAM\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\SphereSeam\Models\SphereSeamGeneration;
use Mothership\User\Models\Admin;
use Throwable;

/**
 * 2026-09-16 review follow-up (PR #181, "Save to DAM cannot race itself into a 500"): the
 * regression coverage added alongside that fix (SphereSeamTest > "treats a second save posting
 * identical bytes as the same save") calls sphereSeamCompletedImport() first, which completes an
 * entire import — and sets dam_asset_id — before the test's own second request even starts. That
 * exercises the checksum-dedupe branch ($existing && checksum matches), not the actual production
 * race: two requests BOTH observing dam_asset_id = null and BOTH reaching the insert.
 *
 * PHP has no threads, so reproducing that race for real needs a genuinely separate OS process —
 * pcntl_fork() — not just a second variable holding "the same" Eloquent model. A plain PHPUnit
 * class (not a Pest functional file): this needs to extend DamConcurrencyTestCase directly and
 * unambiguously, with no dependency on how Pest's uses() would merge or override the directory's
 * default DamTestCase (which wraps every test in a transaction — see that class's docblock for
 * why this test cannot use it). Skips (rather than fails) anywhere PostgreSQL or pcntl/posix is
 * unavailable: SQLite has no concurrent-connection row locking to race here at all, and MySQL's is
 * intentionally not the target of this fix.
 */
final class SphereSeamConcurrentImportTest extends DamConcurrencyTestCase
{
    public function test_two_identical_concurrent_imports_converge_on_exactly_one_asset(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-lock race requires PostgreSQL; other drivers have no real concurrent-connection row locking to race here.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available in this PHP build.');
        }

        config(['sphereseam.enabled' => true]);
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $id = (string) Str::uuid();
        Storage::disk('private')->put("sphereseam/{$id}/bridge.png", 'fake-bridge-bytes');
        Storage::disk('private')->put("sphereseam/{$id}/normalized.png", 'fake-normalized-bytes');

        SphereSeamGeneration::create([
            'id'                 => $id,
            'admin_id'           => $admin->id,
            'model'              => 'gpt_image_2_5',
            'bridge_ratio'       => '1:1',
            'resolution'         => '2k',
            'quality'            => 'high',
            'normalized_path'    => "sphereseam/{$id}/normalized.png",
            'bridge_path'        => "sphereseam/{$id}/bridge.png",
            'storage_disk'       => 'private',
            'width'              => 400,
            'height'             => 200,
            'strip_width'        => 100,
            'repair_width'       => 80,
            'feather_width'      => 16,
            'normalization_mode' => 'squeeze',
            'prompt'             => 'Foundry interior',
            // Completed but never imported: dam_asset_id genuinely null, unlike
            // sphereSeamCompletedImport()'s fixture, which is already past this point by the time
            // it returns.
            'status'             => 'completed',
            'bridge_result_url'  => 'https://cdn.higgsfield.ai/seam.png',
        ]);

        // Identical bytes for both requests, matching production: two clicks of the SAME export.
        $image = imagecreatetruecolor(400, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 60));
        $panoramaPath = tempnam(sys_get_temp_dir(), 'sphereseam-race').'.png';
        imagepng($image, $panoramaPath);
        imagedestroy($image);
        $expectedSha256 = hash_file('sha256', $panoramaPath);

        $resultDir = sys_get_temp_dir().'/sphereseam-race-'.Str::uuid();
        mkdir($resultDir);

        // The import creates the root-level exports folder when it is missing, and that row commits
        // for real here. Remember which ones already existed so cleanup removes only the one this
        // test created: left behind, it made SphereSeamTest's "reuses an existing root-level exports
        // folder" count two when the whole directory ran on PostgreSQL in filename order.
        $exportsFolderName = trim((string) config('sphereseam.exports_directory_name', ''));
        $exportsFolders = fn () => Directory::where('name', $exportsFolderName)
            ->where('parent_id', Directory::whereNull('parent_id')->orderBy('id')->value('id'));
        $preexistingExportsFolderIds = $exportsFolders()->pluck('id')->all();
        // The stable, logical Library path a save lands on now (ADR 0009 slice 3, B16 gone): no
        // physical assets/SphereSeam/ tree, just the exports folder's full tree path. That path
        // starts with the root folder's own name (assets/Root/SphereSeam-Exports/... once the
        // Library root is seeded, as CI does), so match any folder rather than assume one.
        $pathPattern = 'assets/%/sphere-seam-'.substr($id, 0, 8).'%';

        $runImport = function () use ($id, $panoramaPath) {
            return $this->post("/admin/sphere-seam/generations/{$id}/import", [
                'panorama' => new UploadedFile($panoramaPath, 'panorama.png', 'image/png', null, true),
            ], ['Accept' => 'application/json']);
        };

        // Every process — parent and child alike — reconnects before touching the database again:
        // the pre-fork PDO socket is not safe for two processes to share, even momentarily.
        DB::purge();

        $pid = pcntl_fork();
        if ($pid === -1) {
            @unlink($panoramaPath);
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            // Child process. Nothing here is a PHPUnit assertion: assertions made in a forked
            // child are never seen by the parent's test runner, so the child only ever writes its
            // raw result to disk for the parent to read and assert on itself.
            DB::purge();
            DB::reconnect();
            try {
                $response = $runImport();
                file_put_contents($resultDir.'/child-status', (string) $response->getStatusCode());
                file_put_contents($resultDir.'/child-asset-id', (string) ($response->json('asset.id') ?? ''));
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }
            // SIGKILL, not exit(): this is still a fork of a PHPUnit process, and running its
            // normal shutdown path here would re-trigger whatever the parent's own shutdown
            // already does (coverage/output flushing, event listeners) a second time, from a
            // process the parent's test runner knows nothing about.
            if (function_exists('posix_kill')) {
                posix_kill(posix_getpid(), SIGKILL);
            }
            exit(0);
        }

        // Parent process: reconnects the same way, then races the SAME import itself.
        DB::reconnect();
        $parentResponse = $runImport();

        pcntl_waitpid($pid, $status);

        $childStatus = is_file($resultDir.'/child-status') ? (int) file_get_contents($resultDir.'/child-status') : null;
        $childAssetId = is_file($resultDir.'/child-asset-id') ? (int) file_get_contents($resultDir.'/child-asset-id') : null;
        $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;

        try {
            $this->assertNull($childError, 'the child process threw: '.(string) $childError);
            $this->assertSame(200, $parentResponse->getStatusCode());
            $this->assertSame(200, $childStatus);
            $this->assertNotNull($childAssetId);
            $this->assertSame($parentResponse->json('asset.id'), $childAssetId);

            $assetId = $parentResponse->json('asset.id');
            $asset = Asset::findOrFail($assetId);
            // Exactly one asset for this generation's export prefix, not two racing inserts.
            $this->assertSame(1, Asset::where('path', 'like', $pathPattern)->count());
            $store = app(ContentStore::class);
            $stream = $store->open($store->current($asset));
            $this->assertSame($expectedSha256, hash('sha256', stream_get_contents($stream)));
            fclose($stream);
            $this->assertSame($assetId, SphereSeamGeneration::find($id)->dam_asset_id);
        } finally {
            // This TestCase carries no automatic rollback: undo everything by hand regardless of
            // whether the assertions above passed. The asset goes through AssetDeletionService, not
            // a bare delete(): that left its committed FileObject row behind with no file, which
            // CompoundAssetObserverTest's whole-database checks then tripped over (PR #191's first run).
            foreach (Asset::where('path', 'like', $pathPattern)->get() as $imported) {
                app(AssetDeletionService::class)->delete($imported, force: true);
            }
            @unlink($panoramaPath);
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
            SphereSeamGeneration::whereKey($id)->delete();
            Storage::disk('private')->deleteDirectory("sphereseam/{$id}");
            // Through the model, not a query-builder delete: Directory::delete() takes the tree lock
            // (ADR 0004) and lets the nested set close the gap. Before the admin, whom created_by names.
            foreach ($exportsFolders()->whereNotIn('id', $preexistingExportsFolderIds)->get() as $folder) {
                $folder->delete();
            }
            $admin->delete();
        }
    }
}
