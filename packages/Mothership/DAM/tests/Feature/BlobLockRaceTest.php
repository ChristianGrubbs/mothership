<?php

namespace Mothership\DAM\Tests\Feature;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Jobs\MassCopy;
use Mothership\DAM\Models\ActionRequest;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\StorageIntegrity;
use Mothership\DAM\Support\ReclaimOptions;
use Mothership\DAM\Tests\DamConcurrencyTestCase;
use Mothership\DAM\Vectorization\AssetOutputs;
use Mothership\User\Models\Admin;
use RuntimeException;
use Throwable;

/**
 * Codex review of PR #28, round 2: tombstone cleanup checked references once and unlinked later, so an upload
 * that re-referenced the same blob in between (identical bytes attached right after their last reference was
 * deleted) could commit an attachment that names bytes cleanup then removed. Both sides now take
 * ContentStore::lockBlob(): an ingest writes its FileObject row under it and holds it until that row commits;
 * cleanup takes it, one blob at a time, and unlinks only when no row names the path.
 *
 * Two real OS processes on PostgreSQL, as in SphereSeamConcurrentImportTest (whose docblock explains why this
 * cannot run inside DatabaseTransactions and why the child only writes files): the parent opens the upload's
 * transaction and holds the lock; the forked child starts cleanup while that reference is still uncommitted.
 * Without the lock the child unlinks at once; with it, the child waits, then sees the reference and keeps the blob.
 */
final class BlobLockRaceTest extends DamConcurrencyTestCase
{
    /** How long each process barrier in the retain races waits before it fails; each normally clears in milliseconds. */
    private const BARRIER_SECONDS = 10;

    public function test_cleanup_waits_for_an_upload_that_re_references_its_blob(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The blob lock is a PostgreSQL advisory lock; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $store = app(ContentStore::class);
        $bytes = 'race-'.Str::uuid();
        $sha256 = hash('sha256', $bytes);
        $path = $store->objectPath($sha256);
        Storage::disk($disk)->put($path, $bytes);

        // Committed state after a last-reference delete: the blob is still on disk and its tombstone is queued.
        $tombstoneIds = app(AssetDeletionService::class)->queueFileDeletions([['disk' => $disk, 'path' => $path]]);
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/race-'.Str::uuid().'.bin']));
        $resultDir = sys_get_temp_dir().'/blob-cleanup-race-'.Str::uuid();
        mkdir($resultDir);

        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                usleep(400_000); // the parent's upload is inside its transaction by now
                app(AssetDeletionService::class)->processPendingFileDeletions($tombstoneIds);
                file_put_contents($resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();
        $object = null;

        try {
            DB::transaction(function () use ($store, $sha256, $bytes, $disk, $path, $asset, &$object): void {
                $store->lockBlob($sha256);
                $object = FileObject::query()->create([
                    'sha256' => $sha256, 'size' => strlen($bytes), 'disk' => $disk, 'path' => $path, 'mime_type' => 'text/plain',
                ]);
                AssetFile::query()->create([
                    'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_SIDECAR, 'sort' => 1,
                ]);
                usleep(1_500_000); // cleanup is now waiting on the lock, not unlinking
            });

            pcntl_waitpid($pid, $status);

            $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;
            $this->assertNull($childError, 'the child process threw: '.(string) $childError);
            $this->assertFileExists($resultDir.'/child-done');
            $this->assertTrue(Storage::disk($disk)->exists($path), 'cleanup unlinked a blob an upload had just re-referenced');
            $this->assertSame($bytes, Storage::disk($disk)->get($path));
            $this->assertSame(0, DB::table('dam_file_deletion_tombstones')->whereIn('id', $tombstoneIds)->count());
        } finally {
            if ($object !== null) {
                AssetFile::query()->where('file_object_id', $object->id)->delete();
                FileObject::query()->whereKey($object->id)->delete();
            }
            Asset::query()->whereKey($asset->id)->forceDelete();
            DB::table('dam_file_deletion_tombstones')->whereIn('id', $tombstoneIds)->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
        }
    }

    /**
     * Round 3: cleanup that held every blob lock at once could deadlock with a writer that holds B and then
     * needs A (GenerationPackageImporter attaches several blobs in one transaction, in package order). Cleanup
     * now holds one blob lock at a time, so the writer re-creates A after its unlink and cleanup keeps B.
     */
    public function test_cleanup_never_deadlocks_with_a_writer_that_takes_two_blobs_in_another_order(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The blob lock is a PostgreSQL advisory lock; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $store = app(ContentStore::class);
        $blobs = [];

        foreach (['a', 'b'] as $name) {
            $bytes = "order-{$name}-".Str::uuid();
            $local = tempnam(sys_get_temp_dir(), 'blob-order-');
            file_put_contents($local, $bytes);
            $sha256 = hash('sha256', $bytes);
            $blobs[$name] = ['bytes' => $bytes, 'local' => $local, 'sha256' => $sha256, 'path' => $store->objectPath($sha256)];
            Storage::disk($disk)->put($blobs[$name]['path'], $bytes);
        }

        // The writer takes the larger digest first: the old cleanup locked every blob in sorted order, so it
        // held the smaller one while waiting for the larger, exactly the lock the writer then needed.
        $order = $blobs['a']['sha256'] > $blobs['b']['sha256'] ? ['a', 'b'] : ['b', 'a'];

        // Tombstones for A then B, as a deletion of both would queue them.
        $tombstoneIds = app(AssetDeletionService::class)->queueFileDeletions([
            ['disk' => $disk, 'path' => $blobs['a']['path']],
            ['disk' => $disk, 'path' => $blobs['b']['path']],
        ]);
        $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/order-'.Str::uuid().'.bin']));
        $resultDir = sys_get_temp_dir().'/blob-order-race-'.Str::uuid();
        mkdir($resultDir);

        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                usleep(300_000); // the writer already holds its first blob
                app(AssetDeletionService::class)->processPendingFileDeletions($tombstoneIds);
                file_put_contents($resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();
        $objectIds = [];

        try {
            DB::transaction(function () use ($store, $disk, $blobs, $asset, $order, &$objectIds): void {
                foreach ($order as $index => $name) {
                    if ($index === 1) {
                        usleep(900_000); // cleanup is mid-pass, waiting on the blob this writer holds
                    }

                    $object = $store->putLocal(disk: $disk, localPath: $blobs[$name]['local'], sha256: $blobs[$name]['sha256']);
                    $objectIds[] = $object->id;
                    AssetFile::query()->create([
                        'dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_SOURCE, 'sort' => $index,
                    ]);
                }
            });

            pcntl_waitpid($pid, $status);

            $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;
            $this->assertNull($childError, 'the child process threw: '.(string) $childError);
            $this->assertFileExists($resultDir.'/child-done');

            foreach ($blobs as $name => $blob) {
                $object = FileObject::query()->where('sha256', $blob['sha256'])->firstOrFail();
                $this->assertTrue($store->holdsBlob($object), "blob {$name} is missing after cleanup");
                $this->assertSame($blob['bytes'], Storage::disk($disk)->get($object->path));
            }

            $this->assertSame(0, DB::table('dam_file_deletion_tombstones')->whereIn('id', $tombstoneIds)->count());
        } finally {
            AssetFile::query()->where('dam_asset_id', $asset->id)->delete();
            FileObject::query()->whereIn('sha256', array_column($blobs, 'sha256'))->delete();
            Asset::query()->whereKey($asset->id)->forceDelete();
            DB::table('dam_file_deletion_tombstones')->whereIn('id', $tombstoneIds)->delete();
            foreach ($blobs as $blob) {
                @unlink($blob['local']);
            }
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
        }
    }

    /**
     * Round 4: a graph writer that locked an asset FOR UPDATE and then waited for a blob crossed a writer that
     * held that blob and then inserted a row referencing the asset — the foreign key needs KEY SHARE, which FOR
     * UPDATE blocks. GenerationPackageImporter does exactly that: it attaches a reference file (blob H), then
     * links the generated asset to its source S. Graph writers now lock the asset FOR NO KEY UPDATE
     * (Asset::lockedForGraph), which a foreign-key check passes.
     */
    public function test_a_graph_save_never_deadlocks_with_a_writer_that_links_to_the_asset_after_taking_its_blob(): void
    {
        $this->raceAgainstAnImportThatLinksToTheSource(
            graphWrite: function (int $sourceId, string $reference, string $referenceSha): void {
                $saving = Asset::query()->findOrFail($sourceId);
                $saving->markGraphContentChanged($referenceSha);
                $saving->file_size = strlen($reference);
                $saving->touch();
            },
            role: AssetFile::ROLE_ORIGINAL,
        );
    }

    /** Round 5: vectorization settlement publishes an SVG output onto the source asset under the same row lock. */
    public function test_a_vectorization_output_never_deadlocks_with_a_writer_that_links_to_the_source_after_taking_its_blob(): void
    {
        $rowId = random_int(900_000_000, 999_999_999);

        $this->raceAgainstAnImportThatLinksToTheSource(
            graphWrite: function (int $sourceId) use ($rowId): void {
                DB::transaction(fn () => app(AssetOutputs::class)->publish(row: (object) [
                    'id' => $rowId, 'output_format' => 'svg', 'source_asset_id' => $sourceId,
                ]));
            },
            role: AssetFile::ROLE_OUTPUT,
            prepare: fn (string $reference) => Storage::disk('private')->put("vectorizations/results/{$rowId}/result.svg", $reference),
        );
    }

    /**
     * Codex review of PR #63, round 1: retain() read the live FileObject without a row lock. While a delete of the
     * blob's last other reference held that row FOR UPDATE, retain() returned the committed row the delete was
     * removing, and the attachment's foreign key check then waited for the delete and failed. retain() now reads
     * the row FOR KEY SHARE. Deleter first: retain() waits, finds the row gone, recreates it under the blob lock
     * and attaches, and the reaper (which runs after the delete commits) waits on that lock and keeps the blob.
     *
     * Round 2: neither side commits on a timer. The delete commits only once it sees the upload waiting on the row
     * it deleted, and the upload only once it sees the reaper waiting on the blob lock. Without retain()'s locking
     * read this fails: the upload attaches the row the delete is removing, and its foreign key check then fails.
     */
    public function test_retain_recreates_a_row_a_concurrent_delete_removed_and_the_reaper_keeps_its_blob(): void
    {
        $this->raceRetainAgainstADelete(deleterFirst: true);
    }

    /**
     * Retain first: the delete waits for the attachment, counts it, and neither deletes the row nor tombstones the
     * blob. The upload commits only once it sees the delete waiting on the row. This guards the whole sequence, not
     * retain()'s locking read: the attachment's foreign key check takes the same KEY SHARE lock on the row, so this
     * ordering holds without that read.
     */
    public function test_a_delete_that_waits_on_retain_counts_the_new_attachment_and_keeps_the_blob(): void
    {
        $this->raceRetainAgainstADelete(deleterFirst: false);
    }

    /**
     * Codex review of PR #63, round 4: MassCopy locked its source asset rows one 500-row chunk at a time and attached
     * each chunk's copies to their shared FileObject before it locked the next, so from chunk 1 on it held KEY SHARE
     * on that FileObject while chunk 2's rows were still unlocked. A delete of a chunk-2 source locks that asset row
     * and then the FileObject FOR UPDATE: each side waited for the other and PostgreSQL aborted one of them. A copy
     * now locks every source row, in id order, before it inserts anything, so the delete waits on its asset row.
     */
    public function test_a_mass_copy_across_its_chunk_boundary_never_deadlocks_with_a_delete_of_a_later_source(): void
    {
        $this->raceACopyAgainstADeleteOfALaterSource(
            eventType: EventType::MASS_COPY->value,
            arrange: function (Directory $root, Closure $addAssets): array {
                // 510 selected sources span MassCopy's 500-asset chunks; the delete takes one from the second.
                $ids = $addAssets($root, 510);

                return [$ids[505], fn (int $targetId, int $adminId) => (new MassCopy($ids, [], $targetId, $adminId))->handle()];
            },
            expectedCopies: 510,
        );
    }

    /** Round 4, the same cycle in CopyDirectory: a folder's copies were attached before its child folder's sources were locked. */
    public function test_a_directory_copy_never_deadlocks_with_a_delete_of_a_source_in_a_child_folder(): void
    {
        $this->raceACopyAgainstADeleteOfALaterSource(
            eventType: EventType::COPY_DIRECTORY->value,
            arrange: function (Directory $root, Closure $addAssets): array {
                $addAssets($root, 1);
                [$victim] = $addAssets(Directory::create(['name' => 'Child', 'parent_id' => $root->id]), 1);

                return [$victim, fn (int $targetId, int $adminId) => (new CopyDirectory($root->id, $targetId, $adminId))->handle()];
            },
            expectedCopies: 2,
        );
    }

    /**
     * Codex review of slice 4, round 1. The reclaim clears the path of the editor resource rows that name a legacy
     * working file and unlinks it, in one transaction under the rows' asset locks and then the blob lock. Writer
     * first: a competing editor write locks the asset row and makes another row's details name the file. The reclaim
     * has already planned the file as reclaimable, and waits on that row. Once the write commits, the reclaim's
     * fresh check under its locks sees the new name and keeps the file and every row's path.
     */
    public function test_reclaim_keeps_an_editor_file_another_row_names_while_it_waited_on_the_asset_row(): void
    {
        $this->raceReclaimAgainstAnEditorWrite(reclaimFirst: false);
    }

    /**
     * Reclaim first: it holds the asset row and the blob, and the editor write waits on the asset row. The reclaim
     * finishes (row path cleared, file unlinked), then the write commits after it. Neither side deadlocks.
     */
    public function test_reclaim_holding_its_locks_never_deadlocks_with_an_editor_write_on_the_same_asset(): void
    {
        $this->raceReclaimAgainstAnEditorWrite(reclaimFirst: true);
    }

    /**
     * The parent is the editor write: it locks the asset row the way ImageEditorController::save() does
     * (Asset::lockedForGraph()), then gives another resource row details naming the working file. The forked child
     * runs `dam:storage:reclaim --force` against a real backup pair. Every wait is bounded and fails with what it
     * was waiting for.
     */
    private function raceReclaimAgainstAnEditorWrite(bool $reclaimFirst): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row and advisory lock ordering is a PostgreSQL property; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $store = app(ContentStore::class);
        $bytes = 'reclaim-race-'.Str::uuid();
        $staged = $store->stagingPath('png');
        Storage::disk($disk)->put($staged, $bytes);
        $object = $store->adoptStored(disk: $disk, sourcePath: $staged);
        $asset = Asset::factory()->make(['path' => 'assets/Root/reclaim-race-'.Str::uuid().'.png', 'file_size' => strlen($bytes)])->withGraphContent($object);
        $asset->save();

        // A pre-blob working file, and the row naming it once the migration gave it the asset's original blob.
        $working = "assets/.editor/{$asset->id}/".Str::uuid();
        Storage::disk($disk)->put($working, $bytes);
        $resource = fn (?string $path): ImageEditorResource => ImageEditorResource::create([
            'id'   => (string) Str::uuid(), 'asset_id' => $asset->id, 'admin_id' => 1, 'kind' => 'upload', 'status' => 'ready',
            'path' => $path, 'file_object_id' => $object->id, 'width' => 1, 'height' => 1,
        ]);
        $row = $resource($working);
        $other = $resource(null);

        $backupName = 'reclaim-race-'.bin2hex(random_bytes(4));
        $backupDir = storage_path('dam-backups/'.$backupName);
        mkdir($backupDir, 0755, true);
        file_put_contents($backupDir.'/dam-tables.sql', '-- dump');
        app(DamUpdater::class)->archiveAssetFiles($backupDir);
        $resultDir = sys_get_temp_dir().'/reclaim-editor-race-'.Str::uuid();
        mkdir($resultDir);
        $assets = DB::getTablePrefix().$asset->getTable();

        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                if ($reclaimFirst) {
                    // Hold the reclaim inside its asset row and blob locks until the write waits on that asset row.
                    $hold = function () use ($resultDir, $assets): void {
                        $this->signal($resultDir, 'locked', $this->backendPid());
                        $this->awaitRowLockWait(
                            $this->awaitSignal($resultDir, 'writer-pid', 'the editor write to start'),
                            $assets,
                            'the editor write to wait on the asset row this reclaim holds',
                        );
                    };
                    app()->instance(ContentStore::class, new class($hold) extends ContentStore
                    {
                        public function __construct(private ?Closure $hold) {}

                        public function lockBlob(string $sha256): void
                        {
                            parent::lockBlob($sha256);

                            if ($this->hold !== null) {
                                $hold = $this->hold;
                                $this->hold = null;
                                $hold();
                            }
                        }
                    });
                } else {
                    $this->awaitSignal($resultDir, 'writing', 'the editor write to lock the asset row');
                    $this->signal($resultDir, 'reclaim-pid', $this->backendPid()); // about to plan, then wait on the row
                }

                $result = app(StorageIntegrity::class)->reclaim(new ReclaimOptions(force: true, backup: $backupName));
                file_put_contents($resultDir.'/action', (string) (collect($result['files'])->firstWhere('path', $working)['action'] ?? 'not listed'));
                file_put_contents($resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();
        $parentError = null;

        try {
            try {
                if ($reclaimFirst) {
                    $this->awaitSignal($resultDir, 'locked', 'the reclaim to take the asset row and blob locks');
                    $this->signal($resultDir, 'writer-pid', $this->backendPid()); // about to lock the asset row
                }

                DB::transaction(function () use ($asset, $other, $working, $reclaimFirst, $resultDir, $assets): void {
                    Asset::query()->lockedForGraph()->findOrFail($asset->id);
                    ImageEditorResource::query()->whereKey($other->id)->update(['details' => json_encode(['source' => $working])]);

                    if (! $reclaimFirst) {
                        $this->signal($resultDir, 'writing', $this->backendPid());
                        $this->awaitRowLockWait(
                            $this->awaitSignal($resultDir, 'reclaim-pid', 'the reclaim to start'),
                            $assets,
                            'the reclaim to wait on the asset row this write holds',
                        );
                    }
                });
            } catch (Throwable $e) {
                $parentError = get_class($e).': '.$e->getMessage();
            }

            $this->reapChild($pid);

            $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;
            $this->assertNull($parentError, 'the editor write threw: '.(string) $parentError);
            $this->assertNull($childError, 'the reclaim threw: '.(string) $childError);
            $this->assertFileExists($resultDir.'/child-done');
            $this->assertSame(['source' => $working], ImageEditorResource::query()->findOrFail($other->id)->details, 'the editor write did not commit');

            if ($reclaimFirst) {
                $this->assertSame('deleted', file_get_contents($resultDir.'/action'));
                $this->assertFalse(Storage::disk($disk)->exists($working), 'the reclaim did not unlink the working file');
                $this->assertNull(ImageEditorResource::query()->findOrFail($row->id)->path);
            } else {
                $this->assertSame('kept_now_named', file_get_contents($resultDir.'/action'));
                $this->assertSame($bytes, Storage::disk($disk)->get($working), 'the reclaim unlinked a file another row had just named');
                $this->assertSame($working, ImageEditorResource::query()->findOrFail($row->id)->path);
            }
        } finally {
            ImageEditorResource::query()->where('asset_id', $asset->id)->delete();
            Asset::releaseFileOwnership(assetIds: [$asset->id]);
            AssetFile::query()->where('dam_asset_id', $asset->id)->delete();
            FileObject::query()->whereKey($object->id)->delete();
            Asset::query()->whereKey($asset->id)->forceDelete();
            foreach ([...glob($resultDir.'/*') ?: [], ...glob($backupDir.'/*') ?: []] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
            @rmdir($backupDir);
        }
    }

    /**
     * Every source shares one FileObject F. The parent runs the copy job; as soon as it inserts its first attachment
     * (so it holds KEY SHARE on F) the forked child deletes a source the copy reaches later, and the copy carries on
     * only once pg_locks shows that delete waiting on the copy's transaction. Both must finish: the job completes
     * with a copy of every source (the delete waited, so the copy saw them all), the delete removes its source, and F
     * survives because the copies still name it.
     *
     * `$arrange` lays out the sources under a folder with the adder it is given, and returns the source the delete
     * removes and the copy to run (target folder id, admin id).
     *
     * @param  Closure(Directory, Closure(Directory, int): list<int>): array{0: int, 1: Closure(int, int): void}  $arrange
     */
    private function raceACopyAgainstADeleteOfALaterSource(string $eventType, Closure $arrange, int $expectedCopies): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row lock ordering is a PostgreSQL property; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $store = app(ContentStore::class);
        $bytes = 'copy-race-'.Str::uuid();
        $sha256 = hash('sha256', $bytes);
        $blobPath = $store->objectPath($sha256);
        Storage::disk($disk)->put($blobPath, $bytes);
        $shared = FileObject::query()->create([
            'sha256' => $sha256, 'size' => strlen($bytes), 'disk' => $disk, 'path' => $blobPath, 'mime_type' => 'image/png',
        ]);
        $admin = Admin::factory()->create();
        $root = Directory::create(['name' => 'Copy race source '.Str::uuid()]);
        $target = Directory::create(['name' => 'Copy race target '.Str::uuid()]);
        $targetPrefix = 'assets/'.$target->generatePath().'/';
        $sourceIds = [];

        // Raw rows, as the copy jobs themselves write them: hundreds of sources, each with an original naming F.
        $addAssets = function (Directory $directory, int $count) use ($shared, &$sourceIds): array {
            $prefix = 'assets/'.$directory->generatePath();
            $paths = array_map(fn (int $i) => "{$prefix}/source-{$i}.png", range(1, $count));
            $now = now();

            DB::table('dam_assets')->insert(array_map(fn (string $path) => [
                'file_name' => basename($path), 'file_type' => 'image', 'file_size' => $shared->size, 'mime_type' => 'image/png',
                'extension' => 'png', 'path' => $path, 'created_at' => $now, 'updated_at' => $now,
            ], $paths));
            $ids = Asset::query()->whereIn('path', $paths)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            DB::table('dam_asset_directory')->insert(array_map(fn (int $id) => ['asset_id' => $id, 'directory_id' => $directory->id], $ids));
            DB::table('dam_asset_files')->insert(array_map(fn (int $id) => [
                'dam_asset_id' => $id, 'file_object_id' => $shared->id, 'role' => AssetFile::ROLE_ORIGINAL, 'sort' => 0, 'created_at' => $now,
            ], $ids));
            array_push($sourceIds, ...$ids);

            return $ids;
        };

        [$victimId, $copy] = $arrange($root, $addAssets);
        $victimPath = (string) Asset::query()->findOrFail($victimId)->path;
        $request = ActionRequest::query()->create(['event_type' => $eventType, 'status' => 'pending', 'admin_id' => $admin->id]);
        $attachmentInsert = 'insert into '.DB::getQueryGrammar()->wrapTable((new AssetFile)->getTable());
        $resultDir = sys_get_temp_dir().'/copy-delete-race-'.Str::uuid();
        mkdir($resultDir);

        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                $this->signal($resultDir, 'deleter-pid', $this->backendPid());
                $this->awaitSignal($resultDir, 'attached', 'the copy to attach its first file object');
                app(AssetDeletionService::class)->delete(Asset::query()->findOrFail($victimId), force: true);
                file_put_contents($resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();
        $parentError = null;
        $attached = false;

        try {
            // The copy's first attachment holds KEY SHARE on F; from here the delete starts, and the copy goes on
            // only once the delete is waiting on it (a source row or F, whichever the copy already holds).
            DB::listen(function (QueryExecuted $query) use (&$attached, $attachmentInsert, $resultDir): void {
                if ($attached || ! str_starts_with($query->sql, $attachmentInsert)) {
                    return;
                }

                $attached = true;
                $this->signal($resultDir, 'attached', $this->backendPid());
                $this->awaitTransactionWait(
                    $this->awaitSignal($resultDir, 'deleter-pid', 'the delete to start'),
                    'the delete to wait on a row this copy holds',
                );
            });

            try {
                $copy($target->id, $admin->id);
            } catch (Throwable $e) {
                $parentError = get_class($e).': '.$e->getMessage();
            }

            $this->reapChild($pid);

            $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;
            $request->refresh();
            $this->assertNull($parentError, 'the copy threw: '.(string) $parentError);
            $this->assertTrue($attached, 'the copy never attached a file object');
            $this->assertSame('completed', $request->status, 'the copy failed: '.(string) $request->error_message);
            $this->assertNull($childError, 'the delete threw: '.(string) $childError);
            $this->assertFileExists($resultDir.'/child-done');
            $this->assertNull(Asset::query()->find($victimId));

            $copies = Asset::query()->where('path', 'like', $targetPrefix.'%')->pluck('id');
            $this->assertCount($expectedCopies, $copies);
            $this->assertSame($expectedCopies, AssetFile::query()
                ->whereIn('dam_asset_id', $copies)
                ->where('role', AssetFile::ROLE_ORIGINAL)
                ->where('file_object_id', $shared->id)
                ->count());
            $this->assertNotNull(FileObject::query()->find($shared->id), 'the file object the copies name is gone');
        } finally {
            $assetIds = [...$sourceIds, ...Asset::query()->where('path', 'like', $targetPrefix.'%')->pluck('id')->all()];
            Asset::releaseFileOwnership(assetIds: $assetIds);
            AssetFile::query()->whereIn('dam_asset_id', $assetIds)->delete();
            Asset::query()->whereIn('id', $assetIds)->forceDelete();
            FileObject::query()->whereKey($shared->id)->delete();
            // Children before parents (dam_directories.parent_id does not cascade), each reloaded for its current interval.
            foreach ([$target->id, $root->id] as $treeId) {
                $tree = Directory::query()->find($treeId);
                $ids = $tree === null ? [] : Directory::query()
                    ->where('_lft', '>=', $tree->_lft)->where('_rgt', '<=', $tree->_rgt)->orderByDesc('_lft')->pluck('id');
                foreach ($ids as $id) {
                    Directory::query()->find($id)?->delete();
                }
            }
            $request->delete();
            DB::table('dam_file_deletion_tombstones')->where('disk', $disk)->whereIn('path', [$victimPath, $blobPath])->delete();
            $admin->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
        }
    }

    /**
     * Asset D is the only reference to blob B. The forked child deletes D through AssetDeletionService while the
     * parent, acting as an upload, attaches B to asset U through the graph observer, which calls retain() with the
     * FileObject its writer ingested earlier. Both must commit, U ends with an original naming B, and B survives.
     *
     * The two processes meet at signal files (each also names its backend pid), and the side that holds a lock
     * commits only after pg_locks shows the other side waiting on it, so scheduling can neither run them one after
     * the other nor let one commit early. Every wait is bounded and fails with what it was waiting for.
     */
    private function raceRetainAgainstADelete(bool $deleterFirst): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row and advisory lock ordering is a PostgreSQL property; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $store = app(ContentStore::class);
        $bytes = 'retain-race-'.Str::uuid();
        $sha256 = hash('sha256', $bytes);
        $path = $store->objectPath($sha256);
        Storage::disk($disk)->put($path, $bytes);

        $object = FileObject::query()->create([
            'sha256' => $sha256, 'size' => strlen($bytes), 'disk' => $disk, 'path' => $path, 'mime_type' => 'text/plain',
        ]);
        $deleted = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/retain-deleted-'.Str::uuid().'.bin']));
        $uploaded = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/retain-uploaded-'.Str::uuid().'.bin']));
        AssetFile::query()->create([
            'dam_asset_id' => $deleted->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL, 'sort' => 0,
        ]);
        $resultDir = sys_get_temp_dir().'/retain-delete-race-'.Str::uuid();
        mkdir($resultDir);
        $fileObjects = DB::getTablePrefix().$object->getTable();

        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                if (! $deleterFirst) {
                    $this->awaitSignal($resultDir, 'attached', 'the upload to attach the blob');
                    $this->signal($resultDir, 'deleter-pid', $this->backendPid()); // about to lock the row
                }

                // An outer transaction, as a folder delete runs it: the row stays locked and deleted but
                // uncommitted while the upload's retain() waits on it, and the reaper runs only after the commit.
                DB::transaction(function () use ($deleted, $deleterFirst, $resultDir, $fileObjects): void {
                    app(AssetDeletionService::class)->delete(Asset::query()->findOrFail($deleted->id), force: true);

                    if ($deleterFirst) {
                        $this->signal($resultDir, 'deleted', $this->backendPid());
                        $uploader = $this->awaitSignal($resultDir, 'uploader-pid', 'the upload to start');
                        $this->awaitRowLockWait($uploader, $fileObjects, 'the upload to wait on the file object row this delete removed');
                    }
                });
                file_put_contents($resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();
        $parentError = null;

        try {
            try {
                $deleter = null;

                if ($deleterFirst) {
                    $deleter = $this->awaitSignal($resultDir, 'deleted', 'the delete to remove the file object row');
                    $this->signal($resultDir, 'uploader-pid', $this->backendPid()); // about to retain the row
                }

                DB::transaction(function () use ($uploaded, $object, $bytes, $deleter, $resultDir, $fileObjects): void {
                    $asset = Asset::query()->findOrFail($uploaded->id);
                    $asset->withGraphContent($object);
                    $asset->file_size = strlen($bytes);
                    $asset->save();

                    if ($deleter !== null) {
                        // The delete committed while retain() waited; its reaper now waits on the blob lock retain() holds.
                        $this->awaitBlobLockWait($deleter, 'the reaper to wait on the blob lock this upload holds');
                    } else {
                        $this->signal($resultDir, 'attached', $this->backendPid());
                        $this->awaitRowLockWait(
                            $this->awaitSignal($resultDir, 'deleter-pid', 'the delete to start'),
                            $fileObjects,
                            'the delete to wait on the file object row this upload attached',
                        );
                    }
                });
            } catch (Throwable $e) {
                $parentError = get_class($e).': '.$e->getMessage();
            }

            $this->reapChild($pid);

            $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;
            $this->assertNull($parentError, 'the upload threw: '.(string) $parentError);
            $this->assertNull($childError, 'the delete threw: '.(string) $childError);
            $this->assertFileExists($resultDir.'/child-done');
            $this->assertNull(Asset::query()->find($deleted->id));

            $original = AssetFile::query()->where('dam_asset_id', $uploaded->id)->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
            $live = FileObject::query()->findOrFail($original->file_object_id);
            $this->assertSame($sha256, $live->sha256);
            $this->assertTrue($store->holdsBlob($live), 'the blob the upload attached is gone');
            $this->assertSame($bytes, Storage::disk($disk)->get($path));
            $this->assertSame(0, DB::table('dam_file_deletion_tombstones')->where('disk', $disk)->where('path', $path)->count());
        } finally {
            $assetIds = [$deleted->id, $uploaded->id];
            Asset::releaseFileOwnership(assetIds: $assetIds);
            AssetFile::query()->whereIn('dam_asset_id', $assetIds)->delete();
            FileObject::query()->where('sha256', $sha256)->delete();
            Asset::query()->whereIn('id', $assetIds)->forceDelete();
            DB::table('dam_file_deletion_tombstones')->where('disk', $disk)->whereIn('path', [$path, $deleted->path])->delete();
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
        }
    }

    /** Tell the other process `$name` happened, naming this side's backend pid. Written whole, then renamed into place. */
    private function signal(string $dir, string $name, int $backendPid): void
    {
        file_put_contents($dir.'/'.$name.'.tmp', (string) $backendPid);
        rename($dir.'/'.$name.'.tmp', $dir.'/'.$name);
    }

    /** Wait for the other process's `$name` signal and return the backend pid it names. Fails at once if the child already failed. */
    private function awaitSignal(string $dir, string $name, string $what): int
    {
        return $this->pollUntil(function () use ($dir, $name): ?int {
            if (is_file($dir.'/child-error')) {
                throw new RuntimeException('the forked child failed first: '.file_get_contents($dir.'/child-error'));
            }

            return is_file($dir.'/'.$name) ? (int) file_get_contents($dir.'/'.$name) : null;
        }, $what);
    }

    private function backendPid(): int
    {
        return (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    }

    /**
     * From inside this backend's open transaction, wait until backend `$waiter` waits for it to end on a row of
     * `$table`: the waiter holds that row's tuple lock and an ungranted lock on this transaction's id. pg_locks is
     * read live on every call; pg_stat_activity would be cached for the rest of this transaction.
     */
    private function awaitRowLockWait(int $waiter, string $table, string $what): void
    {
        $this->pollUntil(fn (): ?bool => DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_locks waiting
                JOIN pg_locks mine ON mine.pid = pg_backend_pid() AND mine.granted
                    AND mine.locktype = 'transactionid' AND mine.transactionid = waiting.transactionid
                WHERE waiting.pid = ? AND waiting.locktype = 'transactionid' AND NOT waiting.granted
            ) AND EXISTS (
                SELECT 1 FROM pg_locks WHERE pid = ? AND locktype = 'tuple' AND relation = CAST(? AS regclass)
            ) AS waiting
            SQL, [$waiter, $waiter, $table])->waiting ? true : null, "{$what} (backend {$waiter})");
    }

    /** Like awaitRowLockWait(), on any table: wait until backend `$waiter` waits for this backend's open transaction to end. */
    private function awaitTransactionWait(int $waiter, string $what): void
    {
        $this->pollUntil(fn (): ?bool => DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_locks waiting
                JOIN pg_locks mine ON mine.pid = pg_backend_pid() AND mine.granted
                    AND mine.locktype = 'transactionid' AND mine.transactionid = waiting.transactionid
                WHERE waiting.pid = ? AND waiting.locktype = 'transactionid' AND NOT waiting.granted
            ) AS waiting
            SQL, [$waiter])->waiting ? true : null, "{$what} (backend {$waiter})");
    }

    /** Wait until backend `$waiter` waits for a blob lock (ContentStore::lockBlob) that this backend holds. */
    private function awaitBlobLockWait(int $waiter, string $what): void
    {
        $this->pollUntil(fn (): ?bool => DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_locks waiting
                JOIN pg_locks mine ON mine.pid = pg_backend_pid() AND mine.granted AND mine.locktype = 'advisory'
                    AND mine.database = waiting.database AND mine.classid = waiting.classid
                    AND mine.objid = waiting.objid AND mine.objsubid = waiting.objsubid
                WHERE waiting.pid = ? AND waiting.locktype = 'advisory' AND NOT waiting.granted
                    AND waiting.classid = CAST(? AS oid) AND waiting.objsubid = 2
            ) AS waiting
            SQL, [$waiter, ContentStore::LOCK_NAMESPACE])->waiting ? true : null, "{$what} (backend {$waiter})");
    }

    /**
     * Poll `$probe` until it returns non-null, for at most BARRIER_SECONDS.
     *
     * @template T
     *
     * @param  Closure(): (T|null)  $probe
     * @return T
     */
    private function pollUntil(Closure $probe, string $what): mixed
    {
        $deadline = microtime(true) + self::BARRIER_SECONDS;

        do {
            $result = $probe();

            if ($result !== null) {
                return $result;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(sprintf('Timed out after %ds waiting for %s.', self::BARRIER_SECONDS, $what));
    }

    /** Wait for the forked child to exit; kill it and fail if it outlives every barrier it could still be waiting on. */
    private function reapChild(int $pid): void
    {
        $deadline = microtime(true) + 3 * self::BARRIER_SECONDS;

        while (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (microtime(true) >= $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->fail(sprintf('The forked child was still running after %ds; killed it.', 3 * self::BARRIER_SECONDS));
            }

            usleep(10_000);
        }
    }

    /**
     * The forked child runs `$graphWrite` on source S while the parent, acting as the importer, holds the reference
     * blob H and then links a generated asset to S. Both must commit, and S ends with a `$role` file naming H.
     *
     * @param  Closure(int, string, string): void  $graphWrite
     * @param  (Closure(string): mixed)|null  $prepare
     */
    private function raceAgainstAnImportThatLinksToTheSource(Closure $graphWrite, string $role, ?Closure $prepare = null): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row and advisory lock ordering is a PostgreSQL property; other drivers have nothing to race here.');
        }
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix is not available in this PHP build.');
        }

        $disk = Directory::getAssetDisk();
        Storage::fake($disk);
        $store = app(ContentStore::class);
        $sourcePath = 'assets/Root/source-'.Str::uuid().'.png';
        Storage::disk($disk)->put($sourcePath, 'source-bytes-'.Str::uuid());
        $source = Asset::factory()->create(['path' => $sourcePath]);
        $generated = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/generated-'.Str::uuid().'.png']));

        // The source's new content is the same bytes the import attaches as its reference.
        $reference = 'reference-bytes-'.Str::uuid();
        $referenceSha = hash('sha256', $reference);
        $local = tempnam(sys_get_temp_dir(), 'blob-fk-');
        file_put_contents($local, $reference);
        Storage::disk($disk)->put($sourcePath, $reference);
        $prepare?->__invoke($reference);
        $resultDir = sys_get_temp_dir().'/blob-fk-race-'.Str::uuid();
        mkdir($resultDir);

        DB::purge();
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            DB::purge();
            DB::reconnect();

            try {
                usleep(300_000); // the import already holds the reference blob
                $graphWrite($source->id, $reference, $referenceSha);
                file_put_contents($resultDir.'/child-done', '1');
            } catch (Throwable $e) {
                file_put_contents($resultDir.'/child-error', get_class($e).': '.$e->getMessage());
            }

            posix_kill(posix_getpid(), SIGKILL);
            exit(0);
        }

        DB::reconnect();
        $parentError = null;

        try {
            try {
                DB::transaction(function () use ($store, $disk, $local, $referenceSha, $generated, $source): void {
                    $object = $store->putLocal(disk: $disk, localPath: $local, sha256: $referenceSha);
                    AssetFile::query()->create([
                        'dam_asset_id' => $generated->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_SOURCE, 'sort' => 10,
                    ]);
                    usleep(900_000); // the source's graph writer is now waiting on the reference blob
                    DB::table('dam_asset_relationships')->insert([
                        'from_asset_id' => $generated->id, 'to_asset_id' => $source->id, 'type' => 'derived-from',
                        'metadata'      => json_encode(['owner' => 'blob-lock-race']), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            } catch (Throwable $e) {
                $parentError = get_class($e).': '.$e->getMessage();
            }

            pcntl_waitpid($pid, $status);

            $childError = is_file($resultDir.'/child-error') ? file_get_contents($resultDir.'/child-error') : null;
            $this->assertNull($parentError, 'the import side threw: '.(string) $parentError);
            $this->assertNull($childError, 'the graph writer threw: '.(string) $childError);
            $this->assertFileExists($resultDir.'/child-done');
            $this->assertSame(1, DB::table('dam_asset_relationships')->where('from_asset_id', $generated->id)->where('to_asset_id', $source->id)->count());
            $written = AssetFile::query()->where('dam_asset_id', $source->id)->where('role', $role)->firstOrFail();
            $this->assertSame($referenceSha, FileObject::query()->findOrFail($written->file_object_id)->sha256);
        } finally {
            $assetIds = [$source->id, $generated->id];
            DB::table('dam_asset_relationships')->whereIn('from_asset_id', $assetIds)->delete();
            Asset::releaseFileOwnership(assetIds: $assetIds);
            $objectIds = AssetFile::query()->whereIn('dam_asset_id', $assetIds)->pluck('file_object_id')->all();
            AssetFile::query()->whereIn('dam_asset_id', $assetIds)->delete();
            FileObject::query()->whereIn('id', $objectIds)->delete();
            Asset::query()->whereIn('id', $assetIds)->forceDelete();
            @unlink($local);
            foreach (glob($resultDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);
        }
    }
}
