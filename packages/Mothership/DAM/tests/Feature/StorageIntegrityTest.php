<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\StorageIntegrity;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);
    $this->store = app(ContentStore::class);

    // Whole-table scans: start from an empty graph (rolled back with the test transaction).
    foreach (['dam_asset_versions', 'dam_renditions', 'dam_asset_files', 'dam_file_objects'] as $table) {
        DB::table($table)->delete();
    }
    $this->damResetAssetTables();

    // verifyContent() resumes from a cursor file; never inherit one from an interrupted run.
    foreach (['', '-force'] as $mode) {
        @unlink(storage_path("app/storage-integrity-verify{$mode}.json"));
    }
});

/** A pre-ADR-0009 row: the model guard refuses this shape, so it is written the way old data exists. */
function legacyFileObject(string $bytes, string $path, string $disk): int
{
    return DB::table('dam_file_objects')->insertGetId([
        'sha256'     => hash('sha256', $bytes),
        'size'       => strlen($bytes),
        'disk'       => $disk,
        'path'       => $path,
        'mime_type'  => 'application/octet-stream',
        'created_at' => now(),
    ]);
}

function libraryAssetWith(int $fileObjectId, string $path): Asset
{
    $asset = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => $path]));
    DB::table('dam_asset_files')->insert([
        'dam_asset_id' => $asset->id, 'file_object_id' => $fileObjectId, 'role' => AssetFile::ROLE_ORIGINAL, 'sort' => 0, 'created_at' => now(),
    ]);

    return $asset;
}

it('repoints a legacy row, copies a missing blob, and reports an unresolvable one and a raw copy', function () {
    $storage = Storage::disk($this->disk);

    // 1. The old observer already copied the blob: metadata-only repoint.
    $storage->put('assets/Photos/a.jpg', 'bytes-a');
    $storage->put($this->store->objectPath(hash('sha256', 'bytes-a')), 'bytes-a');
    $repointed = legacyFileObject('bytes-a', 'assets/Photos/a.jpg', $this->disk);
    libraryAssetWith($repointed, 'assets/Photos/a.jpg');

    // 2. No blob, and the locator went stale in a folder move; the asset's Library path still has the bytes.
    $storage->put('assets/Moved/b.mp4', 'bytes-b');
    $copied = legacyFileObject('bytes-b', 'assets/Photos/b.mp4', $this->disk);
    libraryAssetWith($copied, 'assets/Moved/b.mp4');

    // 3. Nothing holds these bytes any more.
    $storage->put('assets/Moved/c.png', 'edited-bytes-c');
    $unresolved = legacyFileObject('bytes-c', 'assets/Photos/c.png', $this->disk);
    libraryAssetWith($unresolved, 'assets/Moved/c.png');

    // 4. A raw copy: an asset row with no graph rows. Its Library file is never trusted as its content (slice 3).
    $storage->put('assets/Copy/d.txt', 'bytes-d');
    $rawCopy = Asset::withoutEvents(fn () => Asset::factory()->create(['path' => 'assets/Copy/d.txt']));

    $integrity = app(StorageIntegrity::class);
    $before = $integrity->report();
    expect($before['clean'])->toBeFalse()
        ->and($before['findings']['non_object_locators']['count'])->toBe(3)
        ->and($before['findings']['assets_without_original']['sample'])->toBe([$rawCopy->id]);

    $plan = $integrity->repair(dryRun: true);
    expect(FileObject::find($repointed)->path)->toBe('assets/Photos/a.jpg')
        ->and($plan['repointed']['sample'])->toBe([$repointed])
        ->and($plan['copied']['sample'])->toBe([$copied]);

    $result = $integrity->repair();

    expect($result['repointed']['sample'])->toBe([$repointed])
        ->and($result['copied']['sample'])->toBe([$copied])
        ->and($result['unresolved']['sample'])->toBe([$unresolved])
        ->and($result['originals_unresolved']['sample'])->toBe([$rawCopy->id]);
    expect(FileObject::find($repointed)->path)->toBe($this->store->objectPath(hash('sha256', 'bytes-a')));
    expect($this->store->holdsBlob(FileObject::find($copied)))->toBeTrue()
        ->and($storage->get(FileObject::find($copied)->path))->toBe('bytes-b');
    expect(FileObject::find($unresolved)->path)->toBe('assets/Photos/c.png');
    expect($rawCopy->files()->count())->toBe(0);

    // Idempotent: a second run changes nothing and still reports what it cannot resolve.
    $again = $integrity->repair();
    expect($again['repointed']['count'] + $again['copied']['count'])->toBe(0)
        ->and($again['unresolved']['sample'])->toBe([$unresolved])
        ->and($again['originals_unresolved']['sample'])->toBe([$rawCopy->id]);
    expect($integrity->report()['findings']['non_object_locators']['sample'])->toBe([$unresolved]);
});

it('reports a missing blob but never a missing Library file, which names no file since slice 3', function () {
    $storage = Storage::disk($this->disk);
    $storage->put('assets/Root/e.txt', 'bytes-e');
    $asset = Asset::factory()->create(['path' => 'assets/Root/e.txt']);
    $object = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail()->fileObject;

    $storage->delete('assets/Root/e.txt');
    $clean = app(StorageIntegrity::class)->report();

    expect($clean)->toMatchArray(['clean' => true, 'assets' => 1])
        ->and($clean['findings'])->not->toHaveKey('library_paths_missing');

    $storage->delete($object->path);
    $report = app(StorageIntegrity::class)->report();

    expect($report['findings']['missing_blobs']['sample'])->toBe([$object->id]);
    expect(json_decode(last(file(storage_path(StorageIntegrity::LOG))), true))
        ->toMatchArray(['event' => 'integrity.checked', 'level' => 'warning'])
        ->and(json_decode(last(file(storage_path(StorageIntegrity::LOG))), true)['ctx']['findings'])
        ->not->toHaveKey('library_paths_missing');
});

it('verifies Library files against their originals, resumably, and never ingests a divergent one', function () {
    $storage = Storage::disk($this->disk);
    $assets = collect(['f', 'g', 'h'])->map(function (string $name) use ($storage) {
        $storage->put("assets/Root/{$name}.png", "bytes-{$name}");

        return Asset::factory()->create(['path' => "assets/Root/{$name}.png"]);
    });
    // The Library file no longer holds the asset's content: stale after a slice-3 edit, or the bytes of a
    // deleted asset whose path was reused.
    $storage->put('assets/Root/g.png', 'stale-library-bytes');
    $integrity = app(StorageIntegrity::class);

    $paused = $integrity->verifyContent(limit: 1);
    expect($paused['complete'])->toBeFalse()
        ->and($paused['after_id'])->toBe($assets[0]->id);

    $resumed = $integrity->verifyContent();
    expect($resumed['complete'])->toBeTrue()
        ->and($resumed['run_id'])->toBe($paused['run_id'])
        ->and($resumed['totals'])->toMatchArray(['checked' => 3, 'matched' => 2, 'divergent' => 1])
        ->and($resumed['divergent'])->toBe([$assets[1]->id]);

    // Report-only: the Library bytes never become the asset's content or any blob.
    $divergent = $assets[1]->fresh();
    expect($divergent->files()->where('role', AssetFile::ROLE_ORIGINAL)->with('fileObject')->first()->fileObject->sha256)
        ->toBe(hash('sha256', 'bytes-g'))
        ->and($divergent->versions()->count())->toBe(0)
        ->and(FileObject::query()->where('sha256', hash('sha256', 'stale-library-bytes'))->exists())->toBeFalse();
    expect($integrity->verifyContent()['totals'])->toMatchArray(['checked' => 3, 'matched' => 2, 'divergent' => 1]);
});
