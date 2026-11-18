<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Jobs\DeleteDirectory;
use Mothership\DAM\Models\ActionRequest;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\ContentStore;

beforeEach(function () {
    $this->admin = $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

// P2 S4: the folders and their pivots go; the files go to the trash (restorable for 30 days, Library root once their
// folders are gone), and their permanent delete is AssetTrashService::purge().
it('removes a directory subtree and its pivots atomically, moves its assets to the trash, and leaves no orphans', function () {
    $parent = Directory::create(['name' => 'parent']);
    $target = Directory::create(['name' => 'target', 'parent_id' => $parent->id]);
    $child = Directory::create(['name' => 'child', 'parent_id' => $target->id]);

    $assetA = Asset::factory()->create();
    $assetB = Asset::factory()->create();
    $assetA->directories()->attach($target->id);
    $assetB->directories()->attach($child->id);

    (new DeleteDirectory($target->id, $this->admin->id))->handle();

    $this->assertDatabaseMissing('dam_directories', ['id' => $target->id]);
    $this->assertDatabaseMissing('dam_directories', ['id' => $child->id]);

    $this->assertSoftDeleted('dam_assets', ['id' => $assetA->id]);
    $this->assertSoftDeleted('dam_assets', ['id' => $assetB->id]);
    expect(Asset::onlyTrashed()->whereIn('id', [$assetA->id, $assetB->id])->pluck('deleted_by')->unique()->all())->toBe([$this->admin->id]);

    $this->assertDatabaseMissing('dam_asset_directory', ['directory_id' => $target->id]);
    $this->assertDatabaseMissing('dam_asset_directory', ['directory_id' => $child->id]);

    $this->assertDatabaseHas('dam_directories', ['id' => $parent->id]);
});

it('deletes assets that carry versions and renditions of their own files', function () {
    $disk = Directory::getAssetDisk();

    $parent = Directory::create(['name' => 'versioned-parent']);
    $target = Directory::create(['name' => 'versioned-target', 'parent_id' => $parent->id]);

    $path = 'assets/Root/versioned-in-directory.bin';
    Storage::disk($disk)->put($path, 'versioned-content');
    $asset = Asset::factory()->create(['path' => $path]);
    $asset->directories()->attach($target->id);
    $assetFile = $asset->files()->firstOrFail();
    $version = AssetVersion::factory()->create(['dam_asset_id' => $asset->id, 'primary_asset_file_id' => $assetFile->id]);
    $rendition = Rendition::factory()->create(['dam_asset_id' => $asset->id, 'source_asset_file_id' => $assetFile->id]);

    (new DeleteDirectory($target->id, $this->admin->id))->handle();

    $this->assertDatabaseMissing('dam_directories', ['id' => $target->id]);
    $this->assertSoftDeleted('dam_assets', ['id' => $asset->id]);
    expect(AssetVersion::find($version->id))->not->toBeNull();

    app(AssetTrashService::class)->purge($asset);

    $this->assertDatabaseMissing('dam_assets', ['id' => $asset->id]);
    expect(AssetVersion::find($version->id))->toBeNull();
    expect(Rendition::find($rendition->id))->toBeNull();
});

// ADR 0009 slice 3: folder delete queues blob tombstones through AssetDeletionService by reference
// count, instead of a raw bulk delete that never touched dam_file_objects at all.
it('tombstones a deleted asset\'s blob only once nothing else references it', function () {
    $disk = Directory::getAssetDisk();

    $target = Directory::create(['name' => 'tombstone-target']);
    $path = 'assets/Root/tombstone-target/solo.bin';
    Storage::disk($disk)->put($path, 'solo-bytes');
    $asset = Asset::factory()->create(['path' => $path]);
    $asset->directories()->attach($target->id);
    $fileObjectId = $asset->files()->firstOrFail()->file_object_id;
    $blobPath = app(ContentStore::class)->objectPath(FileObject::findOrFail($fileObjectId)->sha256);

    (new DeleteDirectory($target->id, $this->admin->id))->handle();
    expect(FileObject::find($fileObjectId))->not->toBeNull();
    app(AssetTrashService::class)->purge($asset);

    expect(FileObject::find($fileObjectId))->toBeNull();
    Storage::disk($disk)->assertMissing($blobPath);
});

// A copy elsewhere shares the same FileObject (ADR 0009 slice 3), so deleting one folder must not
// unlink bytes the surviving copy still needs.
it('keeps a blob a copy outside the deleted folder still references', function () {
    $disk = Directory::getAssetDisk();

    $target = Directory::create(['name' => 'shared-blob-target']);
    $elsewhere = Directory::create(['name' => 'shared-blob-elsewhere']);

    $path = 'assets/Root/shared-blob-target/shared.bin';
    Storage::disk($disk)->put($path, 'shared-bytes');
    $asset = Asset::factory()->create(['path' => $path]);
    $asset->directories()->attach($target->id);
    $fileObjectId = $asset->files()->firstOrFail()->file_object_id;

    $copyPath = 'assets/Root/shared-blob-elsewhere/copy.bin';
    Storage::disk($disk)->put($copyPath, 'copy-placeholder-bytes');
    $copy = Asset::factory()->create(['path' => $copyPath]);
    $copy->directories()->attach($elsewhere->id);
    $copyOriginal = $copy->files()->firstOrFail();
    $staleFileObjectId = $copyOriginal->file_object_id;

    // Point the copy's original AssetFile at the SAME blob $asset uses (simulating a slice-3
    // FileObject-sharing copy), then drop its own now-unreferenced placeholder FileObject.
    $copyOriginal->update(['file_object_id' => $fileObjectId]);
    FileObject::whereKey($staleFileObjectId)->delete();

    $blobPath = app(ContentStore::class)->objectPath(FileObject::findOrFail($fileObjectId)->sha256);

    (new DeleteDirectory($target->id, $this->admin->id))->handle();
    app(AssetTrashService::class)->purge($asset);

    expect(FileObject::find($fileObjectId))->not->toBeNull();
    Storage::disk($disk)->assertExists($blobPath);
});

// The controllers only refuse the Library root (Directory::NON_DELETABLE_DRECTORIES), so any other
// parentless directory reaches the job with a null parent.
it('deletes a parentless directory and completes the action request', function () {
    $directory = Directory::create(['name' => 'rootless']);

    $request = ActionRequest::create([
        'event_type' => EventType::DELETE_DIRECTORY->value,
        'status'     => 'pending',
        'admin_id'   => $this->admin->id,
    ]);

    (new DeleteDirectory($directory->id, $this->admin->id))->handle();

    $this->assertDatabaseMissing('dam_directories', ['id' => $directory->id]);
    expect($request->fresh()->status)->toBe('completed');
});

// A TypeError is an \Error, not an \Exception; it must still reach markFailed().
it('marks the action request failed when the delete throws an \Error', function () {
    $directory = Directory::create(['name' => 'erroring']);

    $request = ActionRequest::create([
        'event_type' => EventType::DELETE_DIRECTORY->value,
        'status'     => 'pending',
        'admin_id'   => $this->admin->id,
    ]);

    $this->mock(DirectoryRepository::class, function ($mock) {
        $mock->shouldReceive('find')->andThrow(new TypeError('boom'));
    });

    (new DeleteDirectory($directory->id, $this->admin->id))->handle();

    expect($request->fresh()->status)->toBe('failed')
        ->and($request->fresh()->error_message)->toBe('boom');
    $this->assertDatabaseHas('dam_directories', ['id' => $directory->id]);
});

// Codex review of PR #63: each asset's physical cleanup ran as soon as its own delete returned, inside the tree
// lock's transaction, so a later failure rolled back rows whose only bytes were already gone. The failure here
// comes after the first asset's delete has fully returned; the rollback must leave every row and blob in place.
it('keeps every asset, FileObject and blob when the folder delete fails after an asset was deleted', function () {
    $disk = Directory::getAssetDisk();
    $target = Directory::create(['name' => 'rollback-target']);
    $assets = collect(['first', 'second'])->map(function (string $name) use ($disk, $target) {
        $path = "assets/Root/rollback-target/{$name}.bin";
        Storage::disk($disk)->put($path, "{$name}-rollback-bytes");
        $asset = Asset::factory()->create(['path' => $path]);
        $asset->directories()->attach($target->id);

        return $asset;
    });
    $objects = $assets->map(fn (Asset $asset) => $asset->files()->firstOrFail()->fileObject);
    $tombstones = DB::table('dam_file_deletion_tombstones')->count();

    $request = ActionRequest::create([
        'event_type' => EventType::DELETE_DIRECTORY->value,
        'status'     => 'pending',
        'admin_id'   => $this->admin->id,
    ]);

    $real = new AssetTrashService(new AssetDeletionService);
    $calls = 0;
    $this->mock(AssetTrashService::class, function ($mock) use ($real, &$calls) {
        $mock->shouldReceive('trash')->andReturnUsing(function (Asset $asset, bool $force = false, ?int $deletedBy = null) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('second asset failed');
            }

            return $real->trash(asset: $asset, force: $force, deletedBy: $deletedBy);
        });
    });

    (new DeleteDirectory($target->id, $this->admin->id))->handle();

    expect($calls)->toBe(2)
        ->and($request->fresh()->status)->toBe('failed');
    $this->assertDatabaseHas('dam_directories', ['id' => $target->id]);

    foreach ($assets as $asset) {
        $this->assertDatabaseHas('dam_assets', ['id' => $asset->id, 'deleted_at' => null, 'path' => $asset->path]);
    }

    foreach ($objects as $object) {
        $this->assertDatabaseHas('dam_file_objects', ['id' => $object->id]);
        Storage::disk($disk)->assertExists($object->path);
    }

    // The tombstones were written inside the rolled-back transaction, so none is left to reap the bytes later.
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe($tombstones);
});

// A folder delete moves each asset to the trash, and its permanent delete runs it through AssetDeletionService, so
// every asset in the subtree loses the same per-asset files and rows a single delete removes; the folders' own share
// links go with the folders.
it('removes every per-asset artifact of every asset in the subtree, and the folders\' share links', function () {
    $disk = Directory::getAssetDisk();
    $target = Directory::create(['name' => 'artifact-target']);
    $child = Directory::create(['name' => 'artifact-child', 'parent_id' => $target->id]);

    $assets = collect([$target, $child])->map(function (Directory $directory) use ($disk) {
        $path = "assets/Root/artifact-{$directory->id}.glb";
        Storage::disk($disk)->put($path, "model-{$directory->id}");
        $asset = Asset::factory()->create(['path' => $path, 'file_name' => basename($path), 'extension' => 'glb', 'file_type' => 'document']);
        $asset->directories()->attach($directory->id);

        return $asset;
    });
    $files = $assets->mapWithKeys(fn (Asset $asset) => [$asset->id => giveDeletionArtifacts($asset)]);
    $shares = collect([$target, $child])->map(fn (Directory $directory) => Share::factory()->forDirectory($directory->id)->create());

    (new DeleteDirectory($target->id, $this->admin->id))->handle();

    foreach ($assets as $asset) {
        app(AssetTrashService::class)->purge($asset);
        $this->assertDatabaseMissing('dam_assets', ['id' => $asset->id]);
        assertDeletionArtifactsGone($asset->id, $files[$asset->id]);
    }

    expect(Share::query()->whereIn('id', $shares->pluck('id'))->count())->toBe(0)
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});
