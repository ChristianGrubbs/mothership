<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ContentStore;

/*
 * CopyController::copyAsset() had zero test coverage before ADR 0009 slice 3 (packet-b-folders
 * recon, site A49c). It is the one copy path that goes through Eloquent Asset::create() rather than
 * a raw insert, so it is the most likely to silently regress if only its Storage::copy call were
 * removed and nothing replaced the FileObject it used to get "for free" from the observer's
 * hash-of-Library-file dedup.
 */

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
});

it('copies an asset by sharing the source\'s current original FileObject, with no bytes copied', function () {
    $disk = Directory::getAssetDisk();

    $source = Directory::factory()->create(['name' => 'CopyAssetSource']);
    $target = Directory::factory()->create(['name' => 'CopyAssetTarget']);

    $path = 'assets/CopyAssetSource/photo.png';
    Storage::disk($disk)->put($path, 'source-bytes');
    $asset = Asset::factory()->create(['file_name' => 'photo.png', 'path' => $path]);
    $source->assets()->attach($asset->id);

    $sourceOriginal = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();
    $asset->update(['meta_data' => ['artist_note' => 'Retain active metadata', 'stack_member_states' => [$sourceOriginal->id.':old' => ['artist_note' => 'Source history']]]]);

    $response = $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ]);

    $response->assertStatus(201);

    $copiedId = $response->json('asset.id');
    $copiedAsset = Asset::findOrFail($copiedId);
    $copiedOriginal = $copiedAsset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();

    expect($copiedAsset->file_name)->toBe('photo.png')
        ->and($copiedAsset->meta_data)->toBe(['artist_note' => 'Retain active metadata'])
        ->and($copiedAsset->path)->toBe('assets/CopyAssetTarget/photo.png')
        ->and($copiedAsset->directories()->pluck('dam_directories.id')->all())->toBe([$target->id])
        ->and($copiedOriginal->file_object_id)->toBe($sourceOriginal->file_object_id);

    // No makeDirectory, no byte copy at the copy's own Library path.
    Storage::disk($disk)->assertMissing($copiedAsset->path);
});

it('picks a free name when the target directory already has an asset with that name', function () {
    $disk = Directory::getAssetDisk();

    $source = Directory::factory()->create(['name' => 'CopyAssetSource2']);
    $target = Directory::factory()->create(['name' => 'CopyAssetTarget2']);

    $path = 'assets/CopyAssetSource2/dup.png';
    Storage::disk($disk)->put($path, 'source-bytes');
    $asset = Asset::factory()->create(['file_name' => 'dup.png', 'path' => $path]);
    $source->assets()->attach($asset->id);

    $existing = Asset::factory()->create([
        'file_name' => 'dup.png',
        'path'      => 'assets/CopyAssetTarget2/dup.png',
    ]);
    $existing->directories()->attach($target->id);

    $response = $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('asset.file_name', 'dup(1).png')
        ->assertJsonPath('asset.path', 'assets/CopyAssetTarget2/dup(1).png');

    expect($existing->fresh()->file_name)->toBe('dup.png');
});

// Codex review of PR #63 round 1, finding 2: CopyController::copyAsset used to read the destination
// directory and derive its path BEFORE acquiring Directory::withTreeLock, so a rename committing while
// the request waited for the lock left the copy linked to the right directory id but carrying the
// pre-rename path prefix. The fix reads the destination and derives its path INSIDE the lock.
it('uses the destination directory\'s current name even when it is renamed exactly as the copy takes the tree lock', function () {
    $disk = Directory::getAssetDisk();

    $source = Directory::factory()->create(['name' => 'RaceCopySource']);
    $target = Directory::factory()->create(['name' => 'RaceCopyTargetOld']);

    $path = 'assets/RaceCopySource/race.png';
    Storage::disk($disk)->put($path, 'race-bytes');
    $asset = Asset::factory()->create(['file_name' => 'race.png', 'path' => $path]);
    $source->assets()->attach($asset->id);

    renameDirectoryOnFirstTreeLock($target->id, 'RaceCopyTargetNew');

    $response = $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ]);

    $response->assertStatus(201);

    $copiedAsset = Asset::findOrFail($response->json('asset.id'));

    expect($copiedAsset->path)->toBe('assets/RaceCopyTargetNew/race.png');
});

// Codex review round 3, finding 3: $sourceObject used to come from a plain ContentStore::current()
// read taken before Directory::withTreeLock — an editor save that changes both the source's format
// (extension/mime) and its bytes, committing exactly as the copy takes the tree lock, used to yield a
// copy with the NEW descriptors but the OLD bytes (a JPEG-to-PNG editor save gave a PNG-labelled copy
// still backed by the previous JPEG). The fix locks the source row inside the transaction and resolves
// its current FileObject from that same protected read.
it('copies the source\'s bytes and descriptors from the same state even when an edit changes both exactly as the copy takes the tree lock', function () {
    $disk = Directory::getAssetDisk();

    $source = Directory::factory()->create(['name' => 'CopyAssetRaceEditSource']);
    $target = Directory::factory()->create(['name' => 'CopyAssetRaceEditTarget']);

    $path = 'assets/CopyAssetRaceEditSource/photo.jpg';
    Storage::disk($disk)->put($path, 'old-jpeg-bytes');
    $asset = Asset::factory()->create([
        'file_name' => 'photo.jpg',
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'path'      => $path,
    ]);
    $source->assets()->attach($asset->id);

    $newBytes = 'new-png-bytes';
    replaceAssetContentOnFirstTreeLock($asset->id, $newBytes, 'png', 'image/png');

    $response = $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ]);

    $response->assertStatus(201);

    $copiedAsset = Asset::findOrFail($response->json('asset.id'));
    $copiedObject = app(ContentStore::class)->current($copiedAsset);

    expect($copiedAsset->extension)->toBe('png')
        ->and($copiedAsset->mime_type)->toBe('image/png')
        ->and($copiedObject->sha256)->toBe(hash('sha256', $newBytes));
});

it('refuses to copy an asset with no resolvable original', function () {
    $target = Directory::factory()->create(['name' => 'CopyAssetTarget3']);

    // No bytes were ever put at this path, so the observer's legacy-ingest fallback skipped it and
    // the asset has no original FileObject at all.
    $asset = Asset::factory()->create(['file_name' => 'never-ingested.png']);

    $response = $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ]);

    $response->assertStatus(404);
    $this->assertDatabaseCount('dam_assets', 1);
});

/*
 * CopyController::copyStructureTo had zero test coverage before this fix and, unlike copyAsset and
 * MassCopy, ran its self/descendant ancestry guard and the whole recursive deepCopyStructure walk with
 * no Directory::withTreeLock at all (ADR 0004; same stale-source/stale-destination hazard fixed for
 * MassCopy and copyAsset in c1535d6). These two regressions use the same reparentDirectoryOnFirstTreeLock
 * / deleteDirectoryOnFirstTreeLock seams as Unit/JobsTest.php's MassCopy regressions.
 */
it('refuses to copy a directory structure when the destination is reparented beneath the source exactly as the request takes the tree lock', function () {
    $root = Directory::factory()->create(['name' => 'StructureReparentRaceRoot']);
    $source = Directory::factory()->create(['name' => 'StructureReparentRaceSource', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'StructureReparentRaceTarget', 'parent_id' => $root->id]);

    reparentDirectoryOnFirstTreeLock($target->id, $source->id);

    $response = $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), [
        'source_id' => $source->id,
        'target_id' => $target->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.cannot-copy'));

    // Nothing was created under the (now-reparented) target beyond the source itself landing there.
    expect(Directory::where('parent_id', $target->id)->where('id', '!=', $source->id)->count())->toBe(0);
});

it('creates nothing when the source directory is deleted between selection and lock acquisition', function () {
    $target = Directory::factory()->create(['name' => 'StructureDeletedSourceTarget']);
    $source = Directory::factory()->create(['name' => 'StructureDeletedSourceSource']);

    deleteDirectoryOnFirstTreeLock($source->id);

    $response = $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), [
        'source_id' => $source->id,
        'target_id' => $target->id,
    ]);

    $response->assertStatus(404);
    expect(Directory::where('parent_id', $target->id)->count())->toBe(0);
});

it('copies a structure from the Explorer only with dam.directory.copy_structure, as the classic route requires (G1-05)', function () {
    $source = Directory::factory()->create(['name' => 'StructureAclSource']);
    $target = Directory::factory()->create(['name' => 'StructureAclTarget']);
    $payload = ['source_id' => $source->id, 'target_id' => $target->id];

    $this->loginWithPermissions('custom', ['dam', 'dam.directory', 'dam.directory.index', 'dam.directory.store']);
    $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), $payload)->assertForbidden();
    $this->postJson(route('admin.dam.directory.copy_structure'), ['id' => $source->id])->assertForbidden();
    expect(Directory::where('parent_id', $target->id)->count())->toBe(0);

    $allowed = $this->loginWithPermissions('custom', ['dam', 'dam.directory', 'dam.directory.index', 'dam.directory.store', 'dam.directory.copy_structure']);
    DB::table('dam_directory_role')->insert([
        ['directory_id' => $source->id, 'role_id' => $allowed->role_id],
        ['directory_id' => $target->id, 'role_id' => $allowed->role_id],
    ]);
    $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), $payload)->assertStatus(202);
    expect(Directory::where('parent_id', $target->id)->count())->toBe(1);
});

it('copies a folder with its files only with dam.asset.upload as well as dam.directory.store, as copying a file requires', function () {
    Bus::fake();

    $source = Directory::factory()->create(['name' => 'FolderCopyAclSource']);
    $target = Directory::factory()->create(['name' => 'FolderCopyAclTarget']);
    $grant = fn ($admin) => DB::table('dam_directory_role')->insert([
        ['directory_id' => $source->id, 'role_id' => $admin->role_id],
        ['directory_id' => $target->id, 'role_id' => $admin->role_id],
    ]);

    $foldersOnly = $this->loginWithPermissions('custom', ['dam', 'dam.directory', 'dam.directory.index', 'dam.directory.store']);
    $grant($foldersOnly);
    $this->postJson(route('admin.dam.explorer.copy.directory'), ['directory_id' => $source->id, 'target_directory_id' => $target->id])->assertForbidden();
    $this->postJson(route('admin.dam.explorer.mass_copy'), ['directory_ids' => [$source->id], 'target_directory_id' => $target->id])->assertForbidden();
    Bus::assertNothingDispatched();

    $both = $this->loginWithPermissions('custom', ['dam', 'dam.directory', 'dam.directory.index', 'dam.directory.store', 'dam.asset.upload']);
    $grant($both);
    $this->postJson(route('admin.dam.explorer.copy.directory'), ['directory_id' => $source->id, 'target_directory_id' => $target->id])->assertStatus(202);
    Bus::assertDispatched(CopyDirectory::class);
});
