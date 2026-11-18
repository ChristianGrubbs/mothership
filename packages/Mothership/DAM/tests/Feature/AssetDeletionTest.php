<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\DeleteDirectory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetRelationship;
use Mothership\DAM\Models\AssetVersion;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\DerivativeImages;

beforeEach(function () {
    $this->loginAsAdmin();

    Storage::fake(Directory::getAssetDisk());
});

/**
 * P2 S4: a delete moves the asset to the trash and keeps everything; the Trash page's Delete permanently (or
 * dam:trash:purge) is the delete these tests are about, and reclaims exactly what a delete reclaimed before.
 */
function purgeFromTrash($test, int ...$ids): void
{
    foreach ($ids as $id) {
        expect(Asset::onlyTrashed()->whereKey($id)->exists())->toBeTrue("asset {$id} is in the trash");
        $test->deleteJson(route('admin.dam.trash.destroy', $id))->assertOk();
    }
}

it('refuses deletion with a blocking incoming dependency and returns its analysis', function () {
    $dependent = Asset::factory()->create();
    $required = Asset::factory()->create();

    $edge = AssetRelationship::factory()->create([
        'from_asset_id' => $dependent->id,
        'to_asset_id'   => $required->id,
        'type'          => AssetRelationship::TYPE_USES_TEXTURE,
    ]);

    $this->deleteJson(route('admin.dam.assets.destroy', $required->id))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('analysis.blocking.0.id', $edge->id)
        ->assertJsonPath('analysis.blocking.0.type', AssetRelationship::TYPE_USES_TEXTURE)
        ->assertJsonCount(0, 'analysis.warnings');

    expect(Asset::find($required->id))->not->toBeNull();
    expect(AssetRelationship::find($edge->id))->not->toBeNull();
});

it('force deletes an asset and all of its relationship edges', function () {
    $asset = Asset::factory()->create();
    $dependent = Asset::factory()->create();
    $related = Asset::factory()->create();

    $incoming = AssetRelationship::factory()->create([
        'from_asset_id' => $dependent->id,
        'to_asset_id'   => $asset->id,
        'type'          => AssetRelationship::TYPE_CONTAINS,
    ]);

    $outgoing = AssetRelationship::factory()->create([
        'from_asset_id' => $asset->id,
        'to_asset_id'   => $related->id,
        'type'          => AssetRelationship::TYPE_RELATED_TO,
    ]);

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id), ['force' => true])
        ->assertOk()
        ->assertJsonPath('success', true);

    // In the trash the edges stay, for Restore; deleting it for good removes them.
    expect(Asset::find($asset->id))->toBeNull()
        ->and(AssetRelationship::find($incoming->id))->not->toBeNull();
    purgeFromTrash($this, $asset->id);

    expect(Asset::withTrashed()->find($asset->id))->toBeNull();
    expect(AssetRelationship::find($incoming->id))->toBeNull();
    expect(AssetRelationship::find($outgoing->id))->toBeNull();
});

it('deletes an orphaned file object and its underlying file', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/orphaned.bin';
    Storage::disk($disk)->put($path, 'orphaned-content');

    $asset = Asset::factory()->create(['path' => $path]);
    $fileObject = $asset->files()->firstOrFail()->fileObject;

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))
        ->assertOk();
    expect(FileObject::find($fileObject->id))->not->toBeNull();
    purgeFromTrash($this, $asset->id);

    expect(FileObject::find($fileObject->id))->toBeNull();
    Storage::disk($disk)->assertMissing($path);
});

it('deletes same-asset versions and renditions with their owning asset', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/versioned.bin';
    Storage::disk($disk)->put($path, 'versioned-content');

    $asset = Asset::factory()->create(['path' => $path]);
    $assetFile = $asset->files()->firstOrFail();
    $version = AssetVersion::factory()->create([
        'dam_asset_id'          => $asset->id,
        'primary_asset_file_id' => $assetFile->id,
    ]);
    $rendition = Rendition::factory()->create([
        'dam_asset_id'         => $asset->id,
        'source_asset_file_id' => $assetFile->id,
    ]);

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))
        ->assertOk();
    expect(AssetVersion::find($version->id))->not->toBeNull()
        ->and(Rendition::find($rendition->id))->not->toBeNull();
    purgeFromTrash($this, $asset->id);

    expect(AssetVersion::find($version->id))->toBeNull();
    expect(Rendition::find($rendition->id))->toBeNull();
});

it('deletes an exclusive rendition-only output row and physical blob', function () {
    $disk = Directory::getAssetDisk();
    $outputPath = app(ContentStore::class)->objectPath(hash('sha256', 'exclusive-rendition'));
    Storage::disk($disk)->put($outputPath, 'exclusive-rendition');
    $asset = Asset::factory()->create();
    $output = FileObject::factory()->create([
        'sha256' => hash('sha256', 'exclusive-rendition'),
        'disk'   => $disk,
        'path'   => $outputPath,
    ]);

    Rendition::factory()->create([
        'dam_asset_id'          => $asset->id,
        'output_file_object_id' => $output->id,
    ]);

    app(AssetDeletionService::class)->delete($asset);

    expect(FileObject::find($output->id))->toBeNull();
    Storage::disk($disk)->assertMissing($outputPath);
});

it('keeps a rendition output shared by another asset', function () {
    $disk = Directory::getAssetDisk();
    $outputPath = app(ContentStore::class)->objectPath(hash('sha256', 'shared-rendition'));
    Storage::disk($disk)->put($outputPath, 'shared-rendition');
    $assetA = Asset::factory()->create();
    $assetB = Asset::factory()->create();
    $output = FileObject::factory()->create([
        'sha256' => hash('sha256', 'shared-rendition'),
        'disk'   => $disk,
        'path'   => $outputPath,
    ]);

    Rendition::factory()->create([
        'dam_asset_id'          => $assetA->id,
        'output_file_object_id' => $output->id,
    ]);
    $sharedRendition = Rendition::factory()->create([
        'dam_asset_id'          => $assetB->id,
        'output_file_object_id' => $output->id,
    ]);

    app(AssetDeletionService::class)->delete($assetA);

    expect(FileObject::find($output->id))->not->toBeNull();
    expect(Rendition::find($sharedRendition->id))->not->toBeNull();
    Storage::disk($disk)->assertExists($outputPath);
});

it('keeps a shared file object and its underlying file', function () {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/shared.bin';
    Storage::disk($disk)->put($path, 'shared-content');

    $assetA = Asset::factory()->create(['path' => $path]);
    $assetB = Asset::factory()->create(['path' => 'assets/Root/second-reference.bin']);
    $shared = $assetA->files()->firstOrFail()->fileObject;

    $fileB = AssetFile::factory()->create([
        'dam_asset_id'   => $assetB->id,
        'file_object_id' => $shared->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);

    $this->deleteJson(route('admin.dam.assets.destroy', $assetA->id))
        ->assertOk();
    purgeFromTrash($this, $assetA->id);

    expect(FileObject::find($shared->id))->not->toBeNull();
    expect(AssetFile::find($fileB->id))->not->toBeNull();
    // The shared bytes live in the blob; the deleted asset's Library file goes with it (ADR 0009).
    Storage::disk($disk)->assertExists($shared->path);
    Storage::disk($disk)->assertMissing($path);
});

it('deletes with warning-only relationships without force', function () {
    $asset = Asset::factory()->create();
    $related = Asset::factory()->create();

    $edge = AssetRelationship::factory()->create([
        'from_asset_id' => $related->id,
        'to_asset_id'   => $asset->id,
        'type'          => AssetRelationship::TYPE_DERIVED_FROM,
    ]);

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))
        ->assertOk()
        ->assertJsonPath('analysis.warnings.0.id', $edge->id)
        ->assertJsonPath('analysis.warnings.0.type', AssetRelationship::TYPE_DERIVED_FROM);

    expect(Asset::find($asset->id))->toBeNull();
    purgeFromTrash($this, $asset->id);
    expect(AssetRelationship::find($edge->id))->toBeNull();
});

it('refuses a mass deletion atomically when one asset has a blocking dependency', function () {
    $unblocked = Asset::factory()->create();
    $blocked = Asset::factory()->create();
    $dependent = Asset::factory()->create();

    AssetRelationship::factory()->create([
        'from_asset_id' => $dependent->id,
        'to_asset_id'   => $blocked->id,
        'type'          => AssetRelationship::TYPE_SOURCE_FOR,
    ]);

    $this->postJson(route('admin.dam.assets.mass_delete'), [
        'indices' => [$unblocked->id, $blocked->id],
    ])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('analysis.'.$blocked->id.'.blocking.0.type', AssetRelationship::TYPE_SOURCE_FOR);

    expect(Asset::find($unblocked->id))->not->toBeNull();
    expect(Asset::find($blocked->id))->not->toBeNull();
});

it('force deletes blocking dependencies through mass deletion', function () {
    $asset = Asset::factory()->create();
    $dependent = Asset::factory()->create();

    $edge = AssetRelationship::factory()->create([
        'from_asset_id' => $dependent->id,
        'to_asset_id'   => $asset->id,
        'type'          => AssetRelationship::TYPE_TRANSCRIPT_OF,
    ]);

    $this->postJson(route('admin.dam.assets.mass_delete'), [
        'indices' => [$asset->id],
        'force'   => true,
    ])
        ->assertOk()
        ->assertJsonPath('analysis.'.$asset->id.'.blocking.0.id', $edge->id);

    expect(Asset::find($asset->id))->toBeNull();
    purgeFromTrash($this, $asset->id);
    expect(AssetRelationship::find($edge->id))->toBeNull();
});

it('commits database deletion before physical cleanup and retries a failed unlink from tombstones', function () {
    $disk = Directory::getAssetDisk();
    // The blobs are the locators; the Library file is absent, so its tombstone never reaches delete().
    $pathA = storeFixtureBlob(hash('sha256', 'failure-A'), 'failure-A');
    $pathB = storeFixtureBlob(hash('sha256', 'failure-B'), 'failure-B');

    $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Root/storage-failure-a.bin']));
    $objectA = FileObject::factory()->create([
        'sha256' => hash('sha256', 'failure-A'),
        'disk'   => $disk,
        'path'   => $pathA,
    ]);
    $objectB = FileObject::factory()->create([
        'sha256' => hash('sha256', 'failure-B'),
        'disk'   => $disk,
        'path'   => $pathB,
    ]);
    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $objectA->id,
        'role'           => AssetFile::ROLE_ORIGINAL,
    ]);
    AssetFile::factory()->create([
        'dam_asset_id'   => $asset->id,
        'file_object_id' => $objectB->id,
        'role'           => AssetFile::ROLE_SIDECAR,
    ]);

    $realDisk = Storage::disk($disk);
    $failingDisk = Mockery::mock($realDisk)->makePartial();
    $failingDisk->shouldReceive('delete')->once()->with($pathA)
        ->andReturnUsing(fn () => $realDisk->delete($pathA));
    $failingDisk->shouldReceive('delete')->once()->with($pathB)->andReturnFalse();
    app('filesystem')->set($disk, $failingDisk);

    expect(fn () => app(AssetDeletionService::class)->delete($asset))
        ->toThrow(RuntimeException::class, "Unable to delete asset file [{$disk}:{$pathB}].");

    expect(Asset::find($asset->id))->toBeNull();
    expect(FileObject::whereIn('id', [$objectA->id, $objectB->id])->count())->toBe(0);
    expect($realDisk->exists($pathA))->toBeFalse();
    expect($realDisk->exists($pathB))->toBeTrue();
    expect(DB::table('dam_file_deletion_tombstones')->where('path', $pathB)->value('attempts'))->toBe(1);

    app('filesystem')->set($disk, $realDisk);
    app(AssetDeletionService::class)->processPendingFileDeletions();

    expect($realDisk->exists($pathB))->toBeFalse();
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

it('keeps a blob an unreferenced row names, with its tombstone pending, and reclaims it once the row is gone', function () {
    $disk = Directory::getAssetDisk();
    $path = storeFixtureBlob(hash('sha256', 'abandoned'), 'abandoned');
    $ids = app(AssetDeletionService::class)->queueFileDeletions([['disk' => $disk, 'path' => $path]]);
    // An ingest committed its row but never attached it.
    $row = FileObject::factory()->create(['sha256' => hash('sha256', 'abandoned'), 'disk' => $disk, 'path' => $path, 'size' => 9]);

    app(AssetDeletionService::class)->processPendingFileDeletions($ids);

    Storage::disk($disk)->assertExists($path);
    expect(DB::table('dam_file_deletion_tombstones')->whereIn('id', $ids)->first())
        ->attempts->toBe(1)
        ->last_error->toBe('unreferenced_file_object');

    $row->delete();
    app(AssetDeletionService::class)->processPendingFileDeletions();

    Storage::disk($disk)->assertMissing($path);
    expect(DB::table('dam_file_deletion_tombstones')->whereIn('id', $ids)->count())->toBe(0);
});

it('bulk-loads reference state instead of issuing per-file reference queries', function () {
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create());

    foreach (range(1, 75) as $number) {
        $fileObject = FileObject::factory()->create([
            'disk' => Directory::getAssetDisk(),
        ]);
        AssetFile::factory()->create([
            'dam_asset_id'   => $asset->id,
            'file_object_id' => $fileObject->id,
            'role'           => $number === 1 ? AssetFile::ROLE_ORIGINAL : AssetFile::ROLE_SIDECAR,
        ]);
    }

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    app(AssetDeletionService::class)->delete($asset);

    $referenceSelects = collect(DB::connection()->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query) => str_starts_with(strtolower(ltrim($query)), 'select')
            && (str_contains($query, 'dam_asset_files') || str_contains($query, 'dam_renditions')));

    DB::connection()->disableQueryLog();

    expect($referenceSelects->count())->toBeLessThanOrEqual(8);
});

// Operator report 2026-09-25: after a delete, a re-upload of the same file came back as "name(1).ext". Names
// come from dam_assets.path alone (ADR 0009 slice 3), so a deleted asset frees its name at once, even when a
// pre-slice-3 delete left the old Library file on disk (it queued only an off-tree `.models` copy).
it('gives a re-upload the deleted asset\'s name, even over a stale Library file a pre-slice-3 delete left', function () {
    $disk = Directory::getAssetDisk();
    $folder = Directory::factory()->create(['name' => 'Reuploads', 'parent_id' => null]);
    $upload = fn () => $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('robot.png', 8, 8)],
        'directory_id' => $folder->id,
    ])->assertStatus(201);

    $first = $upload();
    expect($first->json('files.0.file_name'))->toBe('robot.png');
    $this->deleteJson(route('admin.dam.assets.destroy', $first->json('files.0.id')))->assertOk();

    $second = $upload();
    expect($second->json('files.0.file_name'))->toBe('robot.png')
        ->and($second->json('files.0.path'))->toBe('assets/Reuploads/robot.png');
    $this->deleteJson(route('admin.dam.assets.destroy', $second->json('files.0.id')))->assertOk();

    // The production orphan: the old Library file is still there, and no row names it.
    Storage::disk($disk)->put('assets/Reuploads/robot.png', 'stale pre-slice-3 bytes');

    $third = $upload();
    expect($third->json('files.0.file_name'))->toBe('robot.png')
        ->and($third->json('files.0.path'))->toBe('assets/Reuploads/robot.png')
        ->and(Asset::query()->where('path', 'like', 'assets/Reuploads/%')->pluck('file_name')->all())->toBe(['robot.png']);
});

it('removes every file and row named after the asset, through single and mass delete', function (string $route) {
    $disk = Directory::getAssetDisk();
    $path = 'assets/Root/artifacts.glb';
    Storage::disk($disk)->put($path, 'model-bytes');
    $asset = Asset::factory()->create(['path' => $path, 'file_name' => 'artifacts.glb', 'extension' => 'glb', 'file_type' => 'document', 'mime_type' => 'model/gltf-binary']);
    $files = giveDeletionArtifacts($asset);

    $route === 'single'
        ? $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk()
        : $this->postJson(route('admin.dam.assets.mass_delete'), ['indices' => [$asset->id]])->assertOk();
    purgeFromTrash($this, $asset->id);

    expect(Asset::withTrashed()->find($asset->id))->toBeNull();
    assertDeletionArtifactsGone($asset->id, $files);
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
})->with(['single', 'mass']);

it('keeps a shared poster a thumbnail_path names, and every capture of an older model original', function () {
    $disk = Directory::getAssetDisk();
    $store = app(ContentStore::class);
    $path = 'assets/Root/versioned.glb';
    Storage::disk($disk)->put($path, 'model-v1');
    $asset = Asset::factory()->create(['path' => $path, 'file_name' => 'versioned.glb', 'extension' => 'glb', 'file_type' => 'document']);
    $v1 = $store->current($asset);
    putAssetContent($asset, 'model-v2');
    $captures = collect([$v1, $store->current($asset->fresh())])
        ->map(fn (FileObject $object) => ModelThumbnailService::generatedPath($asset, $object->sha256))
        ->each(fn (string $capture) => Storage::disk($disk)->put($capture, 'capture'));

    // A sha-keyed poster in thumbnail_path belongs to the blob, which another asset still shares.
    $shared = Asset::factory()->create(['path' => 'assets/Root/other.glb']);
    AssetFile::factory()->create(['dam_asset_id' => $shared->id, 'file_object_id' => $v1->id, 'role' => AssetFile::ROLE_ORIGINAL]);
    $poster = DerivativeImages::poster($v1->sha256);
    Storage::disk($disk)->put($poster, 'poster');
    $asset->forceFill(['meta_data' => ['thumbnail_path' => $poster]])->saveQuietly();

    app(AssetDeletionService::class)->delete($asset);

    foreach ($captures as $capture) {
        Storage::disk($disk)->assertMissing($capture);
    }

    Storage::disk($disk)->assertExists($poster);
    expect($store->holdsBlob($v1->fresh()))->toBeTrue();
});

it('retries every pending deletion hourly, each on its own, until a deleted item\'s files are gone', function () {
    $disk = Directory::getAssetDisk();
    $realDisk = Storage::disk($disk);
    $stuck = 'assets/Root/leftover-stuck.bin';
    $freed = 'assets/Root/leftover-freed.bin';
    $realDisk->put($stuck, 'stuck');
    $realDisk->put($freed, 'freed');
    // Queued first, so a pass that stopped at its first failure would strand the second.
    app(AssetDeletionService::class)->queueFileDeletions([
        ['disk' => $disk, 'path' => $stuck],
        ['disk' => $disk, 'path' => $freed],
    ]);
    app(AssetDeletionService::class)->queueFileDeletions(array_map(
        fn (int $i): array => ['disk' => $disk, 'path' => 'assets/Root/already-absent-'.$i.'.bin'], range(1, 205),
    ));

    $failingDisk = Mockery::mock($realDisk)->makePartial();
    $failingDisk->shouldReceive('delete')->with($stuck)->andReturnFalse();
    $failingDisk->shouldReceive('delete')->with($freed)->andReturnUsing(fn () => $realDisk->delete($freed));
    app('filesystem')->set($disk, $failingDisk);

    $this->artisan('dam:storage:reap')->assertExitCode(1);

    expect($realDisk->exists($stuck))->toBeTrue()
        ->and($realDisk->exists($freed))->toBeFalse()
        ->and(DB::table('dam_file_deletion_tombstones')->where('path', $stuck)->value('attempts'))->toBe(1)
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(1)
        ->and(DB::table('dam_file_deletion_tombstones')->where('path', $freed)->exists())->toBeFalse();

    app('filesystem')->set($disk, $realDisk);

    $this->artisan('dam:storage:reap')->assertExitCode(0);

    expect($realDisk->exists($stuck))->toBeFalse()
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

// Codex review of PR #63, round 3: a copy inherits its source's meta_data (CopyController, MassCopy), so
// it names the source's `covers/<id>.*` and `thumbnails/models/<id>-<sha>.jpg`. Deleting the source removed both.
it('keeps the cover art and model capture a copy still names, and removes them with the last copy', function (string $coverName) {
    $disk = Directory::getAssetDisk();
    $storage = Storage::disk($disk);
    $admin = $this->loginAsAdmin();
    $sources = Directory::create(['name' => 'shared-artifact-sources']);
    $elsewhere = Directory::create(['name' => 'shared-artifact-copies']);
    $source = function (string $name, array $attributes) use ($storage, $sources): Asset {
        $path = "assets/Root/shared-artifact-sources/{$name}";
        $storage->put($path, "{$name}-bytes");
        $asset = Asset::factory()->create(['path' => $path, 'file_name' => $name] + $attributes);
        $asset->directories()->attach($sources->id);

        return $asset;
    };

    $audio = $source('song.mp3', ['extension' => 'mp3', 'file_type' => 'audio', 'mime_type' => 'audio/mpeg']);
    $cover = sprintf($coverName, $audio->id);
    $model = $source('robot.glb', ['extension' => 'glb', 'file_type' => 'document', 'mime_type' => 'model/gltf-binary']);
    $capture = ModelThumbnailService::generatedPath($model, app(ContentStore::class)->current($model)->sha256);

    foreach ([[$audio, 'cover_art_path', $cover], [$model, 'thumbnail_path', $capture]] as [$asset, $key, $path]) {
        $storage->put($path, 'artifact');
        $asset->forceFill(['meta_data' => [$key => $path]])->saveQuietly();
    }

    // A replaced cover is left behind while its asset lives; it goes with the asset, like the one the row names.
    $orphan = sprintf('covers/%d-fedcba9876543210.png', $audio->id);
    $storage->put($orphan, 'replaced-cover');

    $copies = collect([$audio, $model])->map(fn (Asset $asset) => $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $elsewhere->id,
    ])->assertStatus(201)->json('asset.id'));

    // The audio source by a single delete, the model source with its folder; each copy lives outside that folder.
    $this->deleteJson(route('admin.dam.assets.destroy', $audio->id))->assertOk();
    (new DeleteDirectory($sources->id, $admin->id))->handle();
    purgeFromTrash($this, $audio->id, $model->id);

    expect(Asset::whereIn('id', [$audio->id, $model->id])->count())->toBe(0)
        ->and(Asset::findOrFail($copies[0])->meta_data['cover_art_path'])->toBe($cover)
        ->and(Asset::findOrFail($copies[1])->meta_data['thumbnail_path'])->toBe($capture);
    $storage->assertExists($cover);
    $storage->assertExists($capture);

    foreach ($copies as $copy) {
        $this->deleteJson(route('admin.dam.assets.destroy', $copy))->assertOk();
        purgeFromTrash($this, $copy);
    }

    $storage->assertMissing($cover);
    $storage->assertMissing($orphan);
    $storage->assertMissing($capture);
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
})->with([
    'a cover named for the asset'  => ['covers/%d.jpg'],
    'a cover named for its bytes'  => ['covers/%d-0123456789abcdef.jpg'],
]);

// Codex review round 3: only resource files under assets/.editor/<id>/ were queued, so a legacy
// resource or document file elsewhere, and a file in the editor folder no row names, outlived the asset.
it('removes the editor files its rows name outside its editor folder unless another row names them, and every file in that folder', function () {
    $disk = Directory::getAssetDisk();
    $storage = Storage::disk($disk);
    $storage->put('assets/Root/edited.png', 'edited-bytes');
    $asset = Asset::factory()->create(['path' => 'assets/Root/edited.png', 'file_name' => 'edited.png']);
    $other = Asset::factory()->create(['path' => 'assets/Root/other.png', 'file_name' => 'other.png']);
    $legacy = 'assets/Root/Layers/legacy-base.png';
    $shared = 'assets/Root/Layers/shared-base.png';
    $oldContent = 'assets/Root/edited-before-rename.png';
    $stray = "assets/.editor/{$asset->id}/".Str::uuid();

    foreach ([$legacy, $shared, $oldContent, $stray] as $path) {
        $storage->put($path, 'editor-bytes');
    }

    $resource = fn (Asset $owner, string $path) => ImageEditorResource::create(['id' => (string) Str::uuid(), 'asset_id' => $owner->id, 'admin_id' => 1, 'kind' => 'base', 'status' => 'ready', 'path' => $path, 'width' => 1, 'height' => 1]);
    $resource($asset, $legacy);
    $resource($asset, $shared);
    // Another asset's resource names the shared file under another case, which is the same file on APFS.
    $resource($other, 'assets/Root/Layers/Shared-Base.png');
    ImageEditorDocument::create(['asset_id' => $asset->id, 'revision' => (string) Str::uuid(), 'content_path' => $oldContent, 'original_base' => (string) Str::uuid(), 'document' => []]);

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();
    $storage->assertExists($legacy);
    purgeFromTrash($this, $asset->id);

    $storage->assertMissing($legacy);
    $storage->assertMissing($oldContent);
    $storage->assertMissing($stray);
    $storage->assertExists($shared);
    expect($storage->directoryExists("assets/.editor/{$asset->id}"))->toBeFalse()
        ->and(ImageEditorResource::query()->where('asset_id', $other->id)->count())->toBe(1)
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

// Codex review of PR #63, round 4: a legacy AI draft's details name its working files, and a save-as-new copies
// a resource's details unchanged (ImageEditorController::save()). Only resources.path was re-checked, so deleting
// the source queued a file the copy's details still named, and it was unlinked with no blob behind it.
it('keeps a legacy editor file a save-as-new copy names only through details, and removes it with the last', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $image = UploadedFile::fake()->image('drafted.png', 200, 100);
    $storage->put('assets/Root/drafted.png', file_get_contents($image->getRealPath()));
    $source = Asset::factory()->create(['path' => 'assets/Root/drafted.png', 'file_name' => 'drafted.png', 'file_type' => 'image', 'extension' => 'png']);
    $opened = $this->getJson(route('admin.dam.assets.editor.document', $source->id))->assertOk()->json();
    $input = 'assets/Root/Layers/draft-input.png';
    $storage->put($input, 'draft-input-bytes');
    ImageEditorResource::query()->findOrFail($opened['document']['base'])->update(['details' => ['input' => $input, 'prompt' => 'brighter']]);

    $copy = $this->post(route('admin.dam.assets.editor.save', $source->id), [
        'revision' => $opened['revision'],
        'document' => json_encode($opened['document']),
        'mode'     => 'new',
        'render'   => UploadedFile::fake()->image('result.png', 200, 100),
    ], ['Accept' => 'application/json'])->assertOk()->json('asset_id');

    expect(ImageEditorResource::query()->where('asset_id', $copy)->get()->pluck('details.input')->all())->toBe([$input]);

    $this->deleteJson(route('admin.dam.assets.destroy', $source->id))->assertOk();
    purgeFromTrash($this, $source->id);

    $storage->assertExists($input);

    $this->deleteJson(route('admin.dam.assets.destroy', $copy))->assertOk();
    purgeFromTrash($this, $copy);

    $storage->assertMissing($input);
    expect(ImageEditorResource::query()->count())->toBe(0)
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

// Codex review round 4: every file in the asset's editor folder was queued unconditionally, but a resource of
// another asset (a save-as-new copy of a legacy draft) can name one through its details.
it('keeps a file in its editor folder that another asset\'s resource details name, and removes it with that asset', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $first = Asset::factory()->create(['path' => 'assets/Root/first.png', 'file_name' => 'first.png']);
    $second = Asset::factory()->create(['path' => 'assets/Root/second.png', 'file_name' => 'second.png']);
    $input = "assets/.editor/{$first->id}/".Str::uuid();
    $mask = "{$input}-mask";
    $stray = "assets/.editor/{$first->id}/".Str::uuid();
    $copied = "assets/.editor/{$second->id}/".Str::uuid();

    foreach ([$input, $mask, $stray, $copied] as $path) {
        $storage->put($path, 'editor-bytes');
    }

    $resource = fn (Asset $owner, string $path, ?array $details = null) => ImageEditorResource::create(['id' => (string) Str::uuid(), 'asset_id' => $owner->id, 'admin_id' => 1, 'kind' => 'saved', 'status' => 'saved', 'path' => $path, 'width' => 1, 'height' => 1, 'details' => $details]);
    $resource($first, $input, ['input' => $input, 'mask' => $mask]);
    $resource($second, $copied, ['input' => $input, 'mask' => $mask]);

    $this->deleteJson(route('admin.dam.assets.destroy', $first->id))->assertOk();
    purgeFromTrash($this, $first->id);

    $storage->assertExists($input);
    $storage->assertExists($mask);
    $storage->assertMissing($stray);

    $this->deleteJson(route('admin.dam.assets.destroy', $second->id))->assertOk();
    purgeFromTrash($this, $second->id);

    foreach ([$input, $mask, $copied] as $path) {
        $storage->assertMissing($path);
    }

    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

// Codex review round 5: protection reads details at any depth, so reclamation must too, or the last owner's
// delete would never queue a file only a nested details value names, and nothing else reclaims .editor files.
it('removes a file only nested details name once its last naming asset is deleted', function () {
    $storage = Storage::disk(Directory::getAssetDisk());
    $first = Asset::factory()->create(['path' => 'assets/Root/nested-first.png', 'file_name' => 'nested-first.png']);
    $second = Asset::factory()->create(['path' => 'assets/Root/nested-second.png', 'file_name' => 'nested-second.png']);
    $input = "assets/.editor/{$first->id}/".Str::uuid();
    $own = "assets/.editor/{$second->id}/".Str::uuid();
    $storage->put($input, 'editor-bytes');
    $storage->put($own, 'editor-bytes');

    $resource = fn (Asset $owner, string $path, array $details) => ImageEditorResource::create(['id' => (string) Str::uuid(), 'asset_id' => $owner->id, 'admin_id' => 1, 'kind' => 'saved', 'status' => 'saved', 'path' => $path, 'width' => 1, 'height' => 1, 'details' => $details]);
    $resource($first, $input, []);
    $resource($second, $own, ['legacy' => ['layers' => [['input' => $input]]]]);

    $this->deleteJson(route('admin.dam.assets.destroy', $first->id))->assertOk();
    purgeFromTrash($this, $first->id);
    $storage->assertExists($input);

    $this->deleteJson(route('admin.dam.assets.destroy', $second->id))->assertOk();
    purgeFromTrash($this, $second->id);
    $storage->assertMissing($input);

    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

// Codex review round 3: WebPageBundler::purge() runs after commit and reports nothing, so a failed
// bundle purge was never retried. What it leaves is queued, and the hourly reaper removes it and its folders.
it('queues what a failed web-page bundle purge left for the hourly retry, which removes it and its folders', function () {
    $disk = Directory::getAssetDisk();
    $realDisk = Storage::disk($disk);
    $realDisk->put('assets/Root/page.zip', 'zip-bytes');
    $asset = Asset::factory()->create(['path' => 'assets/Root/page.zip', 'file_name' => 'page.zip', 'file_type' => 'web', 'extension' => 'zip']);
    $bundle = ["bundles/{$asset->id}/1/index.html", "bundles/{$asset->id}/1/js/app.js"];

    foreach ($bundle as $path) {
        $realDisk->put($path, 'bundle-bytes');
    }

    $failingDisk = Mockery::mock($realDisk)->makePartial();
    $failingDisk->shouldReceive('deleteDirectory')->andReturnUsing(fn (string $directory) => str_starts_with($directory, 'bundles/')
        ? false
        : $realDisk->deleteDirectory($directory));
    app('filesystem')->set($disk, $failingDisk);

    $this->deleteJson(route('admin.dam.assets.destroy', $asset->id))->assertOk();
    purgeFromTrash($this, $asset->id);

    expect(Asset::withTrashed()->find($asset->id))->toBeNull()
        ->and(DB::table('dam_file_deletion_tombstones')->orderBy('path')->pluck('path')->all())->toBe($bundle);

    app('filesystem')->set($disk, $realDisk);
    $this->artisan('dam:storage:reap')->assertExitCode(0);

    foreach ($bundle as $path) {
        $realDisk->assertMissing($path);
    }

    expect($realDisk->directoryExists("bundles/{$asset->id}"))->toBeFalse()
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});
