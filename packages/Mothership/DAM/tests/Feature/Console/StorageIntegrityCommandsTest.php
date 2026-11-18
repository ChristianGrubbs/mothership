<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorResource;
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
function insertLegacyFileObjectRow(string $bytes, string $path, string $disk): int
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

it('dam:storage:check exits 0 when clean and 1 while a locator is not content-addressed', function () {
    // Slice 3: an asset whose Library path names no file is clean; the "Library path missing" check is retired.
    $staged = $this->store->stagingPath('txt');
    Storage::disk($this->disk)->put($staged, 'blob-only');
    $object = $this->store->adoptStored(disk: $this->disk, sourcePath: $staged);
    Asset::factory()->make(['path' => 'assets/Root/blob-only.txt', 'file_size' => 9])->withGraphContent($object)->save();

    $this->artisan('dam:storage:check')
        ->expectsOutputToContain('"clean":true')
        ->assertExitCode(0);

    Storage::disk($this->disk)->put('assets/Photos/a.jpg', 'bytes-a');
    insertLegacyFileObjectRow('bytes-a', 'assets/Photos/a.jpg', $this->disk);

    $this->artisan('dam:storage:check')
        ->expectsOutputToContain('"clean":false')
        ->assertExitCode(1);
});

it('dam:storage:repair changes nothing without --force and repoints the row with --force', function () {
    $storage = Storage::disk($this->disk);
    $storage->put('assets/Photos/a.jpg', 'bytes-a');
    $legacy = insertLegacyFileObjectRow('bytes-a', 'assets/Photos/a.jpg', $this->disk);

    $this->artisan('dam:storage:repair')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(FileObject::find($legacy)->path)->toBe('assets/Photos/a.jpg');
    $this->artisan('dam:storage:check')->assertExitCode(1);

    $this->artisan('dam:storage:repair', ['--force' => true])->assertExitCode(0);

    expect(FileObject::find($legacy)->path)->toBe($this->store->objectPath(hash('sha256', 'bytes-a')));
    $this->artisan('dam:storage:check')->assertExitCode(0);
});

it('dam:storage:repair --verify reports divergence as a failing check and refuses --force', function () {
    $storage = Storage::disk($this->disk);
    $storage->put('assets/Root/e.txt', 'bytes-e');
    $asset = Asset::factory()->create(['path' => 'assets/Root/e.txt']);

    $this->artisan('dam:storage:repair', ['--verify' => true])->assertExitCode(0);

    // A stale Library file: since slice 3 the asset's content lives only in its blob.
    $storage->put('assets/Root/e.txt', 'stale-bytes-e');

    $this->artisan('dam:storage:repair', ['--verify' => true])
        ->expectsOutputToContain('"divergent":1')
        ->assertExitCode(1);

    $this->artisan('dam:storage:repair', ['--verify' => true, '--force' => true])
        ->expectsOutputToContain('report-only')
        ->assertExitCode(1);

    expect($asset->fresh()->versions()->count())->toBe(0)
        ->and($this->store->current($asset->fresh())->sha256)->toBe(hash('sha256', 'bytes-e'));
});

/** An asset whose content is `$bytes` in its blob; its Library path names no file (slice 3). */
function orphanTestAsset(string $bytes, string $path, string $disk): Asset
{
    $store = app(ContentStore::class);
    $staged = $store->stagingPath('txt');
    Storage::disk($disk)->put($staged, $bytes);
    $asset = Asset::factory()->make(['path' => $path, 'file_name' => basename($path), 'file_size' => strlen($bytes)])
        ->withGraphContent($store->adoptStored(disk: $disk, sourcePath: $staged));
    $asset->save();

    return $asset;
}

/** The storage-integrity.jsonl records of one run. */
function integrityRecords(string $runId): array
{
    return collect(file(storage_path(StorageIntegrity::LOG)))
        ->map(fn (string $line) => json_decode($line, true))
        ->filter(fn ($record) => is_array($record) && $record['run_id'] === $runId)
        ->values()
        ->all();
}

// Codex review of PR #65: listing order was left to the disk, and APFS's name-hash order happened to be path
// order for these folders, so on a Mac this passed without the service's sort. The disk now lists in reverse.
it('dam:storage:orphans reports unnamed Library files in path order, ignores named and dot-segment paths, and changes nothing', function () {
    $storage = Storage::disk($this->disk);
    orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);

    $storage->put('assets/Renamed/Deep/renamed.txt', 'kept-bytes');  // a slice-3 rename left the old file
    $storage->put('assets/Moved/moved.txt', 'kept-bytes');           // and so did a move
    $storage->put('assets/stray.txt', 'kept-bytes');                 // at the top of the tree
    $storage->put('assets/Old/lost.txt', 'lost-bytes');              // same size, bytes no blob holds
    $storage->put('assets/Old/Archive/gone.txt', 'gone-bytes');
    $storage->put('assets/Root/live.txt', 'kept-bytes');             // a named Library file is never an orphan
    foreach (['assets/.staging/x.part', 'assets/.models/3f/robot.glb', 'assets/Root/.hidden/w.txt', 'assets/Root/.DS_Store'] as $dotted) {
        $storage->put($dotted, 'kept-bytes');
    }
    $storage->makeDirectory('assets/Empty/Deeper');
    $before = $storage->allFiles('assets');

    // Every listing comes back in reverse path order: only the service's own sort can put what follows in path order.
    $listing = Mockery::mock($storage)->makePartial();
    foreach (['files', 'directories', 'allFiles'] as $method) {
        $listing->shouldReceive($method)->andReturnUsing(function (...$arguments) use ($storage, $method): array {
            $paths = $storage->{$method}(...$arguments);
            rsort($paths, SORT_STRING);

            return $paths;
        });
    }
    app('filesystem')->set($this->disk, $listing);

    $result = app(StorageIntegrity::class)->libraryOrphans();

    $inPathOrder = ['assets/Moved/moved.txt', 'assets/Old/Archive/gone.txt', 'assets/Old/lost.txt', 'assets/Renamed/Deep/renamed.txt', 'assets/stray.txt'];
    expect($result['dry_run'])->toBeTrue()
        ->and(collect($result['files'])->pluck('path')->all())->toBe($inPathOrder)
        ->and($result['orphans'])->toMatchArray(['count' => 5, 'bytes' => 50])
        ->and($result['orphans']['sample'])->toBe($inPathOrder)
        ->and($result['blob_backed']['sample'])->toBe(['assets/Moved/moved.txt', 'assets/Renamed/Deep/renamed.txt', 'assets/stray.txt'])
        ->and($result['unbacked']['sample'])->toBe(['assets/Old/Archive/gone.txt', 'assets/Old/lost.txt'])
        ->and(collect($result['files'])->firstWhere('path', 'assets/Old/lost.txt')['sha256'])->toBe(hash('sha256', 'lost-bytes'));
    expect($storage->allFiles('assets'))->toBe($before)
        ->and($storage->directoryExists('assets/Empty/Deeper'))->toBeTrue();
    expect(integrityRecords($result['run_id'])[0])->toMatchArray(['event' => 'integrity.orphans_reported', 'level' => 'warning']);

    $this->artisan('dam:storage:orphans')
        ->expectsOutputToContain('review by hand: assets/Old/lost.txt')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(1);

    // Informational in the hourly check: orphans alone never fail it (exit 0 means clean). Its sample is in path order too.
    $this->artisan('dam:storage:check')
        ->expectsOutputToContain('"library_orphans":{"count":5,"bytes":50,"sample":'.json_encode($inPathOrder))
        ->assertExitCode(0);
    expect($storage->allFiles('assets'))->toBe($before);
});

it('dam:storage:orphans --force deletes only blob-backed orphans, prunes empty folders and writes a receipt', function () {
    $storage = Storage::disk($this->disk);
    orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);
    $storage->put('assets/Renamed/Deep/renamed.txt', 'kept-bytes');
    $storage->put('assets/Old/lost.txt', 'lost-bytes');
    $storage->makeDirectory('assets/Empty/Deeper');
    $storage->makeDirectory('assets/.staging');

    $this->artisan('dam:storage:orphans', ['--force' => true])
        ->expectsOutputToContain('review by hand: assets/Old/lost.txt')
        ->assertExitCode(1);

    $storage->assertMissing('assets/Renamed/Deep/renamed.txt');
    $storage->assertExists('assets/Old/lost.txt');
    expect($storage->directoryExists('assets/Renamed'))->toBeFalse()
        ->and($storage->directoryExists('assets/Empty'))->toBeFalse()
        ->and($storage->directoryExists('assets/Old'))->toBeTrue()
        ->and($storage->directoryExists('assets/.staging'))->toBeTrue()
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);

    $summary = collect(file(storage_path(StorageIntegrity::LOG)))->map(fn (string $line) => json_decode($line, true))
        ->last(fn ($record) => ($record['event'] ?? null) === 'integrity.orphans_reclaimed');
    expect($summary['ctx'])->toMatchArray(['dry_run' => false])
        ->and($summary['ctx']['deleted'])->toMatchArray(['count' => 1, 'bytes' => 10])
        ->and($summary['ctx']['unbacked']['sample'])->toBe(['assets/Old/lost.txt'])
        ->and($summary['ctx']['pruned_directories']['count'])->toBe(4);
    expect(collect(integrityRecords($summary['run_id']))->where('event', 'integrity.orphan_deleted')->pluck('ctx.path')->all())
        ->toBe(['assets/Renamed/Deep/renamed.txt']);

    // Nothing left that a blob holds: a second run deletes nothing and still lists the one for a person.
    $again = app(StorageIntegrity::class)->libraryOrphans(force: true);
    expect($again['orphans']['count'])->toBe(1)->and($again['deleted']['count'])->toBe(0);
});

// Layered-editor resources (ADR 0009 known gap) hold their asset's bytes, so they look blob-backed: deleting one
// would break reopening a document. Rows that name a file keep it, wherever it lives under assets/.
it('dam:storage:orphans --force keeps editor resources and every file a row names', function () {
    $storage = Storage::disk($this->disk);
    $asset = orphanTestAsset('kept-bytes', 'assets/Root/edited.txt', $this->disk);
    $editorFile = "assets/.editor/{$asset->id}/".Str::uuid();
    $storage->put($editorFile, 'kept-bytes');
    ImageEditorResource::create(['id' => basename($editorFile), 'asset_id' => $asset->id, 'admin_id' => 1, 'kind' => 'base', 'status' => 'ready', 'path' => $editorFile, 'width' => 1, 'height' => 1]);

    // A Library-tree file an editor resource row names, and one a legacy FileObject locator still names.
    $storage->put('assets/Root/Layers/base.txt', 'kept-bytes');
    ImageEditorResource::create(['id' => (string) Str::uuid(), 'asset_id' => $asset->id, 'admin_id' => 1, 'kind' => 'saved', 'status' => 'saved', 'path' => 'assets/Root/Layers/base.txt', 'width' => 1, 'height' => 1]);
    $storage->put('assets/Photos/legacy.jpg', 'legacy-bytes');
    insertLegacyFileObjectRow('legacy-bytes', 'assets/Photos/legacy.jpg', $this->disk);

    $result = app(StorageIntegrity::class)->libraryOrphans(force: true);

    expect($result['orphans']['count'])->toBe(0)->and($result['library_files'])->toBe(2);
    $storage->assertExists($editorFile);
    $storage->assertExists('assets/Root/Layers/base.txt');
    $storage->assertExists('assets/Photos/legacy.jpg');
});

/** An editor resource row of `$asset` that names `$path` (the layered editor's shape). */
function orphanTestResource(Asset $asset, string $path): ImageEditorResource
{
    return ImageEditorResource::create(['id' => (string) Str::uuid(), 'asset_id' => $asset->id, 'admin_id' => 1, 'kind' => 'saved', 'status' => 'saved', 'path' => $path, 'width' => 1, 'height' => 1]);
}

// Codex review of PR #63, round 3: --force used to queue a deletion tombstone after its checks, so the
// unlink (and every hourly retry of it) ran with none of them. Each change below commits just as --force takes the
// blob lock, after its own report pass classified the file as a blob-backed orphan; each keeps the file.
it('dam:storage:orphans --force revalidates under the blob lock and keeps an orphan that changed since the report', function (string $change, string $action) {
    $storage = Storage::disk($this->disk);
    $asset = orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);
    $orphan = 'assets/Renamed/renamed.txt';
    $storage->put($orphan, 'kept-bytes');
    $blob = $this->store->current($asset)->path;
    $changes = [
        'blob'    => fn () => $storage->delete($blob),
        'named'   => fn () => orphanTestResource($asset, 'assets/Renamed/RENAMED.txt'),
        'details' => fn () => orphanTestResource($asset, "assets/.editor/{$asset->id}/draft")->update(['details' => ['mask' => 'assets/Renamed/RENAMED.txt']]),
        'bytes'   => fn () => $storage->put($orphan, 'other-byte'),  // the same size, other bytes
    ];
    app()->instance(ContentStore::class, new class($changes[$change]) extends ContentStore
    {
        public function __construct(private ?Closure $change) {}

        public function lockBlob(string $sha256): void
        {
            if ($this->change !== null) {
                ($this->change)();
                $this->change = null;
            }

            parent::lockBlob($sha256);
        }
    });

    $result = app(StorageIntegrity::class)->libraryOrphans(force: true);

    expect(collect($result['files'])->firstWhere('path', $orphan))->toMatchArray(['blob_backed' => true, 'action' => $action])
        ->and($result['deleted']['count'])->toBe(0)
        ->and($result['kept']['sample'])->toBe([$orphan])
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
    $storage->assertExists($orphan);
})->with([
    'its backing blob was removed'                  => ['blob', 'kept_blob_unverified'],
    'an editor resource names it in another case'   => ['named', 'kept_now_named'],
    'a resource\'s details name it'                 => ['details', 'kept_now_named'],
    'its bytes changed after they were hashed'      => ['bytes', 'kept_changed'],
]);

// Codex review of PR #63, round 4: a legacy AI draft's details name its working files, and a save-as-new copies
// them unchanged, but only resources.path counted, so --force deleted a file that only details still named.
it('dam:storage:orphans --force keeps a file only a resource\'s details name, at any key or depth', function () {
    $storage = Storage::disk($this->disk);
    $asset = orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);
    $input = 'assets/Root/Layers/input.txt';
    $deep = 'assets/Root/Layers/Guide/deep.txt';
    $storage->put($input, 'kept-bytes');
    $storage->put($deep, 'kept-bytes');
    orphanTestResource($asset, "assets/.editor/{$asset->id}/draft")->update(['details' => [
        'input'      => $input,
        'stylepress' => ['notes' => ['guide: https://studio.example/storage/Assets/Root/Layers/Guide/DEEP.txt']],
    ]]);

    $result = app(StorageIntegrity::class)->libraryOrphans(force: true);

    expect($result['library_files'])->toBe(2)
        ->and($result['orphans']['count'])->toBe(0);
    $storage->assertExists($input);
    $storage->assertExists($deep);
});

// Codex review round 3: a failed unlink stayed on a tombstone, and dam:storage:reap later deleted the file
// after its blob was gone or a resource named it. Now nothing is queued, so nothing retries it unchecked.
it('dam:storage:orphans --force reports a failed unlink, queues nothing, and a later reap leaves the file', function () {
    $storage = Storage::disk($this->disk);
    $asset = orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);
    $orphan = 'assets/Renamed/renamed.txt';
    $storage->put($orphan, 'kept-bytes');
    $failing = Mockery::mock($storage)->makePartial();
    $failing->shouldReceive('delete')->with($orphan)->andReturnFalse();
    app('filesystem')->set($this->disk, $failing);

    $result = app(StorageIntegrity::class)->libraryOrphans(force: true);

    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0)
        ->and(collect($result['files'])->firstWhere('path', $orphan)['action'])->toBe('kept_unlink_failed')
        ->and($result['kept']['sample'])->toBe([$orphan])
        ->and($result['deleted']['count'])->toBe(0);
    $this->artisan('dam:storage:orphans', ['--force' => true])
        ->expectsOutputToContain('Could not delete; nothing was queued, rerun --force: '.$orphan)
        ->assertExitCode(1);

    // Before the next hourly pass the only other copy goes (the round-3 probe); the reaper has nothing to act on.
    app('filesystem')->set($this->disk, $storage);
    $storage->delete($this->store->current($asset)->path);
    $this->artisan('dam:storage:reap')->assertExitCode(0);

    $storage->assertExists($orphan);
    expect(collect(app(StorageIntegrity::class)->libraryOrphans(force: true)['files'])->firstWhere('path', $orphan)['action'])
        ->toBe('kept_no_blob');
});

// Codex review round 3: the Studio's asset disk is APFS, where a row naming `Working.txt` or an NFD
// spelling names the listed `working.txt` / NFC file. Exact string matching let --force delete a live resource.
it('dam:storage:orphans --force keeps a file a row names in another case or Unicode normalization', function () {
    $storage = Storage::disk($this->disk);
    $asset = orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);
    $cased = 'assets/Root/Layers/working.txt';
    $composed = "assets/Root/Layers/caf\u{00E9}.txt";
    $storage->put($cased, 'kept-bytes');
    $storage->put($composed, 'kept-bytes');
    orphanTestResource($asset, 'assets/Root/Layers/Working.txt');
    orphanTestResource($asset, "assets/Root/Layers/Cafe\u{0301}.TXT");

    $result = app(StorageIntegrity::class)->libraryOrphans(force: true);

    expect($result['library_files'])->toBe(2)
        ->and($result['orphans']['count'])->toBe(0);
    $storage->assertExists($cased);
    $storage->assertExists($composed);
});

// Codex review round 3: a name that is not valid UTF-8 reached a PostgreSQL text parameter and aborted
// the whole run, and the hourly check then reported a partial count as complete. APFS refuses such a name
// ("Illegal byte sequence"), so the listing is made to return one, the way a Linux or SMB-mounted disk can.
it('dam:storage:orphans never queries or deletes a name that is not UTF-8, and the check marks a failed count incomplete', function () {
    $storage = Storage::disk($this->disk);
    orphanTestAsset('kept-bytes', 'assets/Root/live.txt', $this->disk);
    $storage->put('assets/Old/renamed.txt', 'kept-bytes');
    $storage->put('assets/Old/.DS_Store', 'finder');  // keeps the folder once --force empties it
    $raw = "assets/Old/caf\xE9.txt";
    $listing = Mockery::mock($storage)->makePartial();
    $listing->shouldReceive('allFiles')->andReturnUsing(fn (string $directory) => $directory === 'assets/Old'
        ? [...$storage->allFiles($directory), $raw]
        : $storage->allFiles($directory));
    app('filesystem')->set($this->disk, $listing);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $result = app(StorageIntegrity::class)->libraryOrphans(force: true);
    $bindings = collect(DB::connection()->getQueryLog())->pluck('bindings')->flatten();
    DB::connection()->disableQueryLog();

    expect($bindings->filter(fn ($value) => is_string($value) && ! mb_check_encoding($value, 'UTF-8'))->all())->toBe([])
        ->and($result['invalid_utf8']['count'])->toBe(1)
        ->and(collect($result['files'])->firstWhere('path', $raw))->toMatchArray(['action' => 'kept_invalid_utf8', 'encoded_path' => 'assets/Old/caf%E9.txt'])
        ->and($result['deleted']['sample'])->toBe(['assets/Old/renamed.txt']);

    // Only the name no row can hold is left, and it alone fails the run.
    $this->artisan('dam:storage:orphans')
        ->expectsOutputToContain('Name is not valid UTF-8; never deleted, review by hand: assets/Old/caf%E9.txt')
        ->assertExitCode(1);
    expect(app(StorageIntegrity::class)->report()['library_orphans'])
        ->toMatchArray(['count' => 0, 'invalid_utf8' => 1, 'complete' => true]);

    $listing->shouldReceive('directories')->andThrow(new RuntimeException('listing failed'));

    expect(app(StorageIntegrity::class)->report()['library_orphans'])->toMatchArray(['count' => 0, 'complete' => false]);
});
