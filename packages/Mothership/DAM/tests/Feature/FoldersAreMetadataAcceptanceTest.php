<?php

/*
 * Storage slice 3 acceptance (ADR 0009, "folders are metadata", 2026-09-25 amendment): a real folder
 * tree, upload, rename, move, copy, in-place edit, re-upload and delete — driven through the actual
 * admin HTTP routes and the real jobs those routes dispatch (QUEUE_CONNECTION=sync in tests, so a
 * dispatched job runs inline, in-request) — must never place a byte at a Library path or any other
 * off-tree physical path. Every byte lives only under `assets/.objects/`, `assets/.staging/` is always
 * empty once a request completes, and a folder is a database row only. A copy shares its source's
 * FileObject; deleting the folder that holds the source must keep that shared blob alive as long as the
 * copy still references it, and only reclaim it once the copy itself is gone too.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());
});

if (! function_exists('foldersMetaAssertOnlyBlobs')) {
    /**
     * (a) Every file under `assets/` on the asset disk is a content-addressed blob — no Library-path
     * file, no off-tree physical path, no folder directory entry — and staging is empty. Called after
     * every write in the lifecycle below, not only at the end.
     */
    function foldersMetaAssertOnlyBlobs(string $disk): void
    {
        $files = collect(Storage::disk($disk)->allFiles('assets'));

        expect($files->reject(fn (string $file) => str_starts_with($file, ContentStore::ROOT.'/'))->values()->all())
            ->toBe([]);
        expect(Storage::disk($disk)->allFiles(ContentStore::STAGING))->toBe([]);
    }
}

if (! function_exists('foldersMetaBody')) {
    /** The body of a local response, whatever response class carried it. */
    function foldersMetaBody(TestResponse $response): string
    {
        $base = $response->baseResponse;

        return match (true) {
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            $base instanceof StreamedResponse   => (string) $response->streamedContent(),
            default                             => (string) $base->getContent(),
        };
    }
}

it('proves a full folder lifecycle — create, rename, move, copy, edit, re-upload, delete — never writes a Library-path file, only content-addressed blobs (ADR 0009 slice 3)', function () {
    $this->loginAsAdmin();
    $disk = Directory::getAssetDisk();
    $store = app(ContentStore::class);
    $check = fn () => foldersMetaAssertOnlyBlobs($disk);

    $check();

    // 1. Folder tree through the real directory routes (create, then create a child), anchored to a
    // plain DB row — a directory is a database row only (ADR 0009), so seeding one via the factory
    // carries no filesystem meaning of its own.
    $libraryRoot = Directory::factory()->create(['name' => 'FoldersMetaLibraryRoot', 'parent_id' => null]);
    $check();

    $parentId = $this->postJson(route('admin.dam.directory.store'), [
        'name'      => 'FoldersMetaParent',
        'parent_id' => $libraryRoot->id,
    ])->assertOk()->json('data.id');
    $check();

    $childId = $this->postJson(route('admin.dam.directory.store'), [
        'name'      => 'FoldersMetaChild',
        'parent_id' => $parentId,
    ])->assertOk()->json('data.id');
    $check();

    $moveTargetId = $this->postJson(route('admin.dam.directory.store'), [
        'name'      => 'FoldersMetaMoveTarget',
        'parent_id' => $libraryRoot->id,
    ])->assertOk()->json('data.id');
    $check();

    $copyTargetId = $this->postJson(route('admin.dam.directory.store'), [
        'name'      => 'FoldersMetaCopyTarget',
        'parent_id' => $libraryRoot->id,
    ])->assertOk()->json('data.id');
    $check();

    // 2. Upload an image through the real admin upload route.
    $file = UploadedFile::fake()->image('lifecycle.png', 64, 64)->size(23);
    $bytes = $file->get();

    $uploadResponse = $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [$file],
        'directory_id' => $childId,
    ])->assertStatus(201);
    $check();

    $assetId = (int) $uploadResponse->json('files.0.id');
    $asset = Asset::findOrFail($assetId);

    // (b) The upload's bytes live only in the blob, and the fetch route serves them.
    expect($store->holdsBlob($store->current($asset)))->toBeTrue();
    expect(foldersMetaBody($this->get(route('admin.dam.file.fetch', ['path' => $asset->path]))->assertOk()))
        ->toBe($bytes);

    // 3. Rename the parent folder — a `dam_assets.path` prefix rewrite only.
    $this->postJson(route('admin.dam.directory.update'), [
        'id'   => $parentId,
        'name' => 'FoldersMetaParentRenamed',
    ])->assertOk();
    $check();

    $asset->refresh();
    expect($asset->path)->toContain('FoldersMetaParentRenamed');

    // 4a. Move the asset to another folder.
    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $assetId,
        'new_parent_id' => $moveTargetId,
    ])->assertOk();
    $check();

    $asset->refresh();
    expect($asset->directories()->pluck('dam_directories.id')->all())->toBe([$moveTargetId]);

    // 4b. Copy the asset (Explorer copy route) — the copy shares the source's current original
    // FileObject, no bytes copied.
    $sourceOriginal = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    $sharedFileObjectId = $sourceOriginal->file_object_id;

    $copyResponse = $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $assetId,
        'target_directory_id' => $copyTargetId,
    ])->assertStatus(201);
    $check();

    $copyId = (int) $copyResponse->json('asset.id');
    $copyAsset = Asset::findOrFail($copyId);
    $copyOriginal = $copyAsset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();

    // (c) The copy shares its source's FileObject id at copy time.
    expect($copyOriginal->file_object_id)->toBe($sharedFileObjectId);

    // 4c. Copy the (now empty) child folder too, via the directory-copy route — a folder copy is also
    // database-only.
    $this->postJson(route('admin.dam.directory.copy_structure'), ['id' => $childId])->assertOk();
    $check();
    expect(Directory::query()->where('id', '!=', $childId)->where('name', 'like', 'FoldersMetaChild%')->exists())
        ->toBeTrue();

    // 5. Edit the image in place (resize) — the asset gets a new version.
    $this->postJson(route('admin.dam.assets.image_edit.resize', $assetId), ['width' => 32])->assertOk();
    $check();

    $asset->refresh();
    $editedOriginal = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    expect($editedOriginal->file_object_id)->not->toBe($sharedFileObjectId)
        ->and($asset->files()->where('role', AssetFile::ROLE_VERSION)->where('file_object_id', $sharedFileObjectId)->exists())->toBeTrue()
        ->and($asset->versions()->count())->toBe(1);

    // 6. Re-upload the original asset — another new version.
    $reupload = UploadedFile::fake()->image('lifecycle-reupload.png', 48, 48)->size(12);
    $reuploadBytes = $reupload->get();

    $this->postJson(route('admin.dam.assets.re_upload'), [
        'file'     => $reupload,
        'asset_id' => $assetId,
    ])->assertStatus(201);
    $check();

    $asset->refresh();
    $finalOriginal = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();

    // (e) An original plus at least one version AssetFile, and dam_asset_versions rows increased.
    expect($finalOriginal->file_object_id)->not->toBe($editedOriginal->file_object_id)
        ->and($asset->files()->where('role', AssetFile::ROLE_VERSION)->count())->toBe(2)
        ->and($asset->versions()->count())->toBe(2);

    // (b) Both surviving assets still read correctly from blobs alone: the source now serves the
    // re-uploaded bytes, and the untouched copy still serves the original upload's bytes.
    expect($store->holdsBlob($store->current($asset)))->toBeTrue();
    expect(foldersMetaBody($this->get(route('admin.dam.file.fetch', ['path' => $asset->fresh()->path]))->assertOk()))
        ->toBe($reuploadBytes);

    expect($store->holdsBlob($store->current($copyAsset)))->toBeTrue();
    expect(foldersMetaBody($this->get(route('admin.dam.file.fetch', ['path' => $copyAsset->fresh()->path]))->assertOk()))
        ->toBe($bytes);

    // 7. Delete the source folder (holding the edited/re-uploaded asset) while the copy lives
    // elsewhere. The shared blob the copy still references must survive.
    $sharedBlobPath = $store->objectPath(FileObject::findOrFail($sharedFileObjectId)->sha256);
    Storage::disk($disk)->assertExists($sharedBlobPath);

    $this->delete(route('admin.dam.directory.destroy', $moveTargetId))->assertOk();
    $check();

    // The folder's asset went to the trash (P2 S4); deleting it for good there is the delete this checks.
    $this->assertSoftDeleted('dam_assets', ['id' => $assetId]);
    $this->deleteJson(route('admin.dam.trash.destroy', $assetId))->assertOk();
    $check();

    $this->assertDatabaseMissing('dam_assets', ['id' => $assetId]);
    $this->assertDatabaseHas('dam_assets', ['id' => $copyId]);

    // (d) The blob the copy uses is still intact after the source folder's delete.
    expect(FileObject::find($sharedFileObjectId))->not->toBeNull();
    Storage::disk($disk)->assertExists($sharedBlobPath);
    expect(foldersMetaBody($this->get(route('admin.dam.file.fetch', ['path' => $copyAsset->fresh()->path]))->assertOk()))
        ->toBe($bytes);

    // Delete the copy through its own delete route, then process tombstones explicitly.
    $this->delete(route('admin.dam.assets.destroy', $copyId))->assertOk();
    $this->deleteJson(route('admin.dam.trash.destroy', $copyId))->assertOk();
    app(AssetDeletionService::class)->processPendingFileDeletions();
    $check();

    $this->assertDatabaseMissing('dam_assets', ['id' => $copyId]);

    // (d) Only now that nothing references it is the shared blob gone.
    expect(FileObject::find($sharedFileObjectId))->toBeNull();
    Storage::disk($disk)->assertMissing($sharedBlobPath);
});

it('reclaims an asset\'s blob once its folder is deleted and nothing else shares it', function () {
    $this->loginAsAdmin();
    $disk = Directory::getAssetDisk();
    foldersMetaAssertOnlyBlobs($disk);

    $root = Directory::factory()->create(['name' => 'FoldersMetaSoloRoot', 'parent_id' => null]);
    $folderId = $this->postJson(route('admin.dam.directory.store'), [
        'name'      => 'FoldersMetaSoloFolder',
        'parent_id' => $root->id,
    ])->assertOk()->json('data.id');
    foldersMetaAssertOnlyBlobs($disk);

    $file = UploadedFile::fake()->image('solo.png', 40, 40)->size(10);
    $uploadResponse = $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [$file],
        'directory_id' => $folderId,
    ])->assertStatus(201);
    foldersMetaAssertOnlyBlobs($disk);

    $assetId = (int) $uploadResponse->json('files.0.id');
    $asset = Asset::findOrFail($assetId);
    $fileObjectId = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->value('file_object_id');
    $blobPath = app(ContentStore::class)->objectPath(FileObject::findOrFail($fileObjectId)->sha256);
    Storage::disk($disk)->assertExists($blobPath);

    $this->delete(route('admin.dam.directory.destroy', $folderId))->assertOk();
    foldersMetaAssertOnlyBlobs($disk);

    // In the trash (P2 S4) the blob stays; deleting it for good there reclaims it.
    $this->assertSoftDeleted('dam_assets', ['id' => $assetId]);
    expect(FileObject::find($fileObjectId))->not->toBeNull();
    $this->deleteJson(route('admin.dam.trash.destroy', $assetId))->assertOk();
    foldersMetaAssertOnlyBlobs($disk);

    $this->assertDatabaseMissing('dam_assets', ['id' => $assetId]);
    expect(FileObject::find($fileObjectId))->toBeNull();
    Storage::disk($disk)->assertMissing($blobPath);
});
