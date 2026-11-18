<?php

/*
 * dam:storage:reclaim and its backup gate (ADR 0009 slice 4), run through the real Artisan command against the
 * asset disk (faked to a temporary root), the database and a backup pair whose archive the system tar writes.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\ImageEditorDocument;
use Mothership\DAM\Models\ImageEditorResource;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ImageEditorSchema;
use Mothership\DAM\Services\StorageIntegrity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->loginAsAdmin();
    $this->disk = Directory::getAssetDisk();
    Storage::fake($this->disk);
    $this->store = app(ContentStore::class);
    $this->backups = [];

    // Whole-table scans: start from an empty graph (rolled back with the test transaction).
    foreach (['dam_image_editor_documents', 'dam_image_editor_resources', 'dam_file_deletion_tombstones', 'dam_asset_versions', 'dam_renditions', 'dam_asset_files', 'dam_file_objects'] as $table) {
        DB::table($table)->delete();
    }
    $this->damResetAssetTables();
});

afterEach(function () {
    foreach ($this->backups as $directory) {
        File::deleteDirectory($directory);
    }
});

if (! function_exists('reclaimAsset')) {
    /** An asset whose content is `$bytes` in its blob; since slice 3 its Library path names no file. */
    function reclaimAsset(string $bytes, string $path, string $disk): Asset
    {
        $store = app(ContentStore::class);
        $staged = $store->stagingPath('bin');
        Storage::disk($disk)->put($staged, $bytes);
        $asset = Asset::factory()->make(['path' => $path, 'file_name' => basename($path), 'file_size' => strlen($bytes)])
            ->withGraphContent($store->adoptStored(disk: $disk, sourcePath: $staged));
        $asset->save();

        return $asset;
    }
}

if (! function_exists('reclaimNewVersion')) {
    /** Replace an asset's content: its old original stays, demoted to a version, with its blob. */
    function reclaimNewVersion(Asset $asset, string $bytes, string $disk): void
    {
        $store = app(ContentStore::class);
        $staged = $store->stagingPath('bin');
        Storage::disk($disk)->put($staged, $bytes);
        $asset->fill(['file_size' => strlen($bytes)])->withGraphContent($store->adoptStored(disk: $disk, sourcePath: $staged))->save();
    }
}

if (! function_exists('reclaimLegacyFileObject')) {
    /** A pre-ADR-0009 FileObject row whose locator is a Library path; the model guard refuses this shape. */
    function reclaimLegacyFileObject(string $bytes, string $path, string $disk): int
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
}

if (! function_exists('reclaimResource')) {
    /** A layered-editor resource row of `$asset`, the shape pre-blob and migrated rows have. */
    function reclaimResource(Asset $asset, ?string $path, ?int $fileObjectId, ?array $details = null): ImageEditorResource
    {
        return ImageEditorResource::create([
            'id'             => (string) Str::uuid(),
            'asset_id'       => $asset->id,
            'admin_id'       => 1,
            'kind'           => 'upload',
            'status'         => 'ready',
            'path'           => $path,
            'file_object_id' => $fileObjectId,
            'width'          => 1,
            'height'         => 1,
            'details'        => $details,
        ]);
    }
}

if (! function_exists('reclaimTree')) {
    /**
     * Every file on the disk with its size and sha256, and every directory.
     *
     * @return array{files: array<string, string>, directories: list<string>}
     */
    function reclaimTree(string $disk): array
    {
        $storage = Storage::disk($disk);
        $files = [];

        foreach ($storage->allFiles('') as $path) {
            $files[$path] = $storage->size($path).':'.hash('sha256', (string) $storage->get($path));
        }

        $directories = $storage->allDirectories('');
        sort($directories);

        return ['files' => $files, 'directories' => $directories];
    }
}

if (! function_exists('reclaimRows')) {
    /** The rows a reclaim could touch. */
    function reclaimRows(): array
    {
        $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row) => (array) $row)->all();

        return [
            'resources'    => $rows('dam_image_editor_resources', ['id', 'path', 'file_object_id', 'details']),
            'documents'    => $rows('dam_image_editor_documents', ['id', 'content_path']),
            'file_objects' => $rows('dam_file_objects', ['id', 'path', 'sha256']),
            'assets'       => $rows('dam_assets', ['id', 'path']),
            'tombstones'   => DB::table('dam_file_deletion_tombstones')->count(),
        ];
    }
}

if (! function_exists('reclaimBackup')) {
    /**
     * A backup pair the way dam:storage:backup writes it: the dump is a stand-in (the dump itself is
     * DamUpdater's), the archive is the real one, `tar -czf … -C <disk root> assets`.
     */
    function reclaimBackup($test, string $dump = '-- dump'): string
    {
        $name = 'reclaim-test-'.bin2hex(random_bytes(4));
        $directory = storage_path('dam-backups/'.$name);
        File::ensureDirectoryExists($directory);
        $test->backups[] = $directory;
        file_put_contents($directory.'/dam-tables.sql', $dump);
        expect(app(DamUpdater::class)->archiveAssetFiles($directory))->toBe($directory.'/dam-files.tgz');

        return $name;
    }
}

if (! function_exists('reclaimRun')) {
    /**
     * Run dam:storage:reclaim; its first output line is the JSON result.
     *
     * @return array{exit: int, result: array<string, mixed>, output: string}
     */
    function reclaimRun(array $options = []): array
    {
        $exit = Artisan::call('dam:storage:reclaim', $options);
        $output = Artisan::output();

        return ['exit' => $exit, 'result' => json_decode(strtok($output, "\n"), true, 512, JSON_THROW_ON_ERROR), 'output' => $output];
    }
}

if (! function_exists('reclaimOnFirstBlobLock')) {
    /**
     * Run `$change` once, as the first blob lock is taken: after the reclaim's own plan, just before its first
     * re-check under the locks. The seam the orphan command's race tests use.
     */
    function reclaimOnFirstBlobLock(Closure $change): void
    {
        app()->instance(ContentStore::class, new class($change) extends ContentStore
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
    }
}

if (! function_exists('reclaimRecords')) {
    /** The storage-integrity.jsonl records of one run. */
    function reclaimRecords(string $runId): array
    {
        return collect(file(storage_path(StorageIntegrity::LOG)))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($record) => is_array($record) && $record['run_id'] === $runId)
            ->values()
            ->all();
    }
}

if (! function_exists('reclaimSeedEverything')) {
    /**
     * One file of every kind a reclaim meets, keyed by what it is.
     *
     * @return array{paths: array<string, string>, rows: array<string, string>}
     */
    function reclaimSeedEverything(string $disk): array
    {
        $storage = Storage::disk($disk);
        $named = reclaimAsset('named-bytes', 'assets/Root/named.txt', $disk);
        $namedBlob = app(ContentStore::class)->current($named);
        $versioned = reclaimAsset('version-one', 'assets/Root/versioned.txt', $disk);
        reclaimNewVersion($versioned, 'version-two!', $disk);
        reclaimAsset('edited-now', 'assets/Root/edited.txt', $disk);
        $model = reclaimAsset('model-bytes', 'assets/.models/0f3c/robot.glb', $disk);
        $video = reclaimAsset('video-bytes', 'assets/Root/clip.mp4', $disk);
        // A pre-slice-2 poster the video's metadata still names.
        DB::table('dam_assets')->where('id', $video->id)->update(['meta_data' => json_encode(['thumbnail_path' => 'thumbnails/assets/Root/clip.mp4.jpg'])]);

        $files = [
            // Set 1, Library copies.
            'named_copy'       => ['assets/Root/named.txt', 'named-bytes'],            // the current original's bytes
            'stale_version'    => ['assets/Root/versioned.txt', 'version-one'],        // an older version's blob holds these
            'stale_unique'     => ['assets/Root/edited.txt', 'edited-old'],            // no blob holds these
            'orphan'           => ['assets/Renamed/Deep/moved.txt', 'named-bytes'],    // no row names it
            'locator_named'    => ['assets/Photos/legacy.jpg', 'legacy-bytes'],        // a legacy FileObject locator reads it
            'details_named'    => ['assets/Root/Layers/mask.txt', 'named-bytes'],      // a resource's details name it
            'legacy_dot'       => ['assets/.models/0f3c/robot.glb', 'model-bytes'],    // the legacy .models write area
            'unlisted_dot'     => ['assets/.mindmaps/7/map.wxml', 'named-bytes'],      // a dot-folder no set covers
            'dot_file'         => ['assets/Root/.hidden.txt', 'named-bytes'],          // a dot-file is never a candidate
            'finder'           => ['assets/Renamed/Deep/.DS_Store', 'finder'],         // goes with its emptied folder
            // Set 2, legacy editor working files.
            'editor_blob_row'  => ["assets/.editor/{$named->id}/".Str::uuid(), 'named-bytes'],
            'editor_null_row'  => ["assets/.editor/{$named->id}/".Str::uuid(), 'named-bytes'],
            'editor_details'   => ["assets/.editor/{$model->id}/".Str::uuid(), 'named-bytes'],
            'editor_unnamed'   => ['assets/.editor/results/'.Str::uuid().'.png', 'version-one'],
            // Set 3, path-keyed derivatives.
            'thumbnail'        => ['thumbnails/assets/Root/named.txt.webp', 'thumbnail'],
            'preview'          => ['preview/1024/assets/Root/named.txt.webp', 'preview'],
            'preview_finder'   => ['preview/1024/assets/Root/.DS_Store', 'finder'],
            'poster_named'     => ['thumbnails/assets/Root/clip.mp4.jpg', 'poster'],
            // Never touched.
            'staging'          => ['assets/.staging/upload.part', 'named-bytes'],
            'sha_derivative'   => ['derivatives/ab/'.str_repeat('ab', 32).'/thumbnail.webp', 'derived'],
            'model_capture'    => ['thumbnails/models/12-abcdef123456.jpg', 'capture'],
            'cover'            => ['covers/12.jpg', 'cover'],
            'custom_thumbnail' => ['custom-thumbnails/12/custom.png', 'custom'],
            'bundle'           => ['bundles/12/1/index.html', '<h1>page</h1>'],
            'odd_preview'      => ['preview/tmp/assets/Root/named.txt.webp', 'not-a-size'],
            'elsewhere'        => ['exports/assets/Root/named.txt', 'named-bytes'],
        ];

        foreach ($files as [$path, $bytes]) {
            $storage->put($path, $bytes);
        }

        $storage->makeDirectory('assets/Empty/Deeper');
        $paths = array_map(fn (array $file): string => $file[0], $files);
        reclaimLegacyFileObject('legacy-bytes', $paths['locator_named'], $disk);

        return ['paths' => $paths, 'rows' => [
            'blob_row' => reclaimResource($named, path: $paths['editor_blob_row'], fileObjectId: (int) $namedBlob->id)->id,
            'null_row' => reclaimResource($named, path: $paths['editor_null_row'], fileObjectId: null)->id,
            'details'  => reclaimResource($model, path: null, fileObjectId: (int) $namedBlob->id, details: [
                'input' => $paths['editor_details'],
                'mask'  => $paths['details_named'],
            ])->id,
        ]];
    }
}

it('changes nothing on a dry run and classifies every file', function () {
    ['paths' => $paths, 'rows' => $rows] = reclaimSeedEverything($this->disk);
    $tree = reclaimTree($this->disk);
    $database = reclaimRows();

    $run = reclaimRun();

    expect($run['exit'])->toBe(1)
        ->and(reclaimTree($this->disk))->toBe($tree)
        ->and(reclaimRows())->toBe($database);

    $result = $run['result'];
    $files = collect($result['files'])->keyBy('path');
    $expected = [
        'named_copy'      => ['library', 'reclaimable', ['dam_assets.path']],
        'stale_version'   => ['library', 'reclaimable', ['dam_assets.path']],
        'stale_unique'    => ['library', 'kept_no_blob', ['dam_assets.path']],
        'orphan'          => ['library', 'reclaimable', []],
        'locator_named'   => ['library', 'kept_referenced', ['dam_file_objects.path']],
        'details_named'   => ['library', 'kept_referenced', ['dam_image_editor_resources.details']],
        'legacy_dot'      => ['library', 'reclaimable', ['dam_assets.path']],
        'editor_blob_row' => ['editor', 'reclaimable', ['dam_image_editor_resources.path']],
        'editor_null_row' => ['editor', 'kept_referenced', ['dam_image_editor_resources.path']],
        'editor_details'  => ['editor', 'kept_referenced', ['dam_image_editor_resources.details']],
        'editor_unnamed'  => ['editor', 'reclaimable', []],
        'thumbnail'       => ['derivatives', 'reclaimable', []],
        'preview'         => ['derivatives', 'reclaimable', []],
        'poster_named'    => ['derivatives', 'kept_referenced', ['dam_assets.meta_data']],
    ];

    expect($files->keys()->sort()->values()->all())->toBe(collect($expected)->keys()->map(fn ($key) => $paths[$key])->sort()->values()->all());

    foreach ($expected as $key => [$set, $action, $namedBy]) {
        expect($files[$paths[$key]])->toMatchArray(['set' => $set, 'action' => $action, 'named_by' => $namedBy]);
    }

    expect($files[$paths['editor_blob_row']]['editor_rows'])->toBe([$rows['blob_row']])
        ->and($files[$paths['named_copy']])->toMatchArray(['sha256' => hash('sha256', 'named-bytes'), 'size' => 11])
        ->and($files[$paths['stale_unique']]['sha256'])->toBe(hash('sha256', 'edited-old'))
        ->and($result)->toMatchArray(['dry_run' => true, 'refused' => null, 'backup' => null, 'clean' => false])
        ->and($result['sets']['library'])->toMatchArray(['count' => 4, 'bytes' => 44])
        ->and($result['sets']['editor'])->toMatchArray(['count' => 2, 'bytes' => 22])
        ->and($result['sets']['derivatives'])->toMatchArray(['count' => 2, 'bytes' => 16])
        ->and($result['reclaimable']['count'])->toBe(8)
        ->and($result['deleted']['count'])->toBe(0)
        ->and($result['skipped_dot_folder']['sample'])->toBe([$paths['unlisted_dot'], $paths['dot_file']])
        ->and($result['editor_paths_cleared']['count'])->toBe(0)
        ->and($result['pruned_directories']['count'])->toBe(0);
    expect($run['output'])
        ->toContain('No blob holds these bytes; never deleted, review by hand: '.$paths['stale_unique'])
        ->toContain('never deleted, review by hand: '.$paths['unlisted_dot'])
        ->toContain('Dry run: nothing was changed. 8 files (82 bytes) are reclaimable; rerun with --force --backup=<name>.');
    expect(reclaimRecords($result['run_id']))->toHaveCount(1)
        ->and(reclaimRecords($result['run_id'])[0])->toMatchArray(['event' => 'integrity.reclaim_planned', 'level' => 'warning'])
        ->and(reclaimRecords($result['run_id'])[0]['ctx'])->not->toHaveKey('files');

    // --dry-run says the same thing out loud.
    expect(reclaimRun(['--dry-run' => true])['result']['reclaimable']['count'])->toBe(8)
        ->and(reclaimTree($this->disk))->toBe($tree);
});

it('refuses without changing anything when the gate does not hold', function (Closure $arrange, array $options, string $reason) {
    ['paths' => $paths] = reclaimSeedEverything($this->disk);
    $options = $arrange($this, $paths) + $options;
    $tree = reclaimTree($this->disk);
    $database = reclaimRows();

    $run = reclaimRun($options);

    expect($run['exit'])->toBe(1)
        ->and($run['result']['refused']['reason'])->toBe($reason)
        ->and($run['result']['deleted']['count'])->toBe(0)
        ->and(reclaimTree($this->disk))->toBe($tree)
        ->and(reclaimRows())->toBe($database)
        ->and(collect(reclaimRecords($run['result']['run_id']))->pluck('event')->all())->toBe(['integrity.reclaim_refused']);
    expect($run['output'])->toContain('Refused: ');
})->with([
    '--force without --backup'                => [fn () => [], ['--force' => true], 'backup_required'],
    '--dry-run with --force'                  => [fn ($test) => ['--backup' => reclaimBackup($test)], ['--force' => true, '--dry-run' => true], 'dry_run_with_force'],
    'a backup name that climbs out'           => [fn () => ['--backup' => '../x'], ['--force' => true], 'backup_name_invalid'],
    'a backup name with a slash'              => [fn () => ['--backup' => 'a/b'], ['--force' => true], 'backup_name_invalid'],
    'an empty backup name'                    => [fn () => ['--backup' => ''], ['--force' => true], 'backup_name_invalid'],
    'a backup that does not exist'            => [fn () => ['--backup' => 'no-such-backup'], ['--force' => true], 'backup_missing'],
    'a backup with an empty dump'             => [fn ($test) => ['--backup' => reclaimBackup($test, dump: '')], ['--force' => true], 'backup_dump_missing'],
    'a backup with no archive'                => [function ($test) {
        $name = reclaimBackup($test);
        unlink(storage_path("dam-backups/{$name}/dam-files.tgz"));

        return ['--backup' => $name];
    }, ['--force' => true], 'backup_archive_missing'],
    'an archive that is not a tar'            => [function ($test) {
        $name = reclaimBackup($test);
        file_put_contents(storage_path("dam-backups/{$name}/dam-files.tgz"), gzencode(str_repeat('not a tar header ', 64)));

        return ['--backup' => $name];
    }, ['--force' => true], 'backup_unreadable'],
    'an archive that lacks a file it deletes' => [function ($test, array $paths) {
        Storage::disk($test->disk)->move($paths['orphan'], 'assets/Renamed/Deep/moved.txt.aside');
        $name = reclaimBackup($test);
        Storage::disk($test->disk)->move('assets/Renamed/Deep/moved.txt.aside', $paths['orphan']);

        return ['--backup' => $name];
    }, ['--force' => true], 'backup_incomplete'],
    'an archive that holds one at another size' => [function ($test, array $paths) {
        $name = reclaimBackup($test);
        // Still reclaimable: the versioned asset's current blob holds these bytes, one byte longer.
        Storage::disk($test->disk)->put($paths['named_copy'], 'version-two!');

        return ['--backup' => $name];
    }, ['--force' => true], 'backup_incomplete'],
    // Codex review of slice 4, round 1: an archive cut in its gzip trailer (CRC-32 and length) read as complete.
    'an archive missing its gzip trailer'     => [function ($test) {
        $name = reclaimBackup($test);
        $archive = storage_path("dam-backups/{$name}/dam-files.tgz");
        file_put_contents($archive, substr((string) file_get_contents($archive), 0, -8));

        return ['--backup' => $name];
    }, ['--force' => true], 'backup_unreadable'],
]);

it('refuses --force on an asset disk that is not local', function () {
    config(['filesystems.default' => Directory::ASSETS_DISK_AWS]);
    Storage::fake(Directory::ASSETS_DISK_AWS);

    $run = reclaimRun(['--force' => true, '--backup' => 'any-name']);

    expect($run['exit'])->toBe(1)
        ->and($run['result'])->toMatchArray(['disk' => Directory::ASSETS_DISK_AWS])
        ->and($run['result']['refused']['reason'])->toBe('remote_disk');
});

it('names dam:storage:backup when --force comes without a backup, and reports what a backup lacks', function () {
    ['paths' => $paths] = reclaimSeedEverything($this->disk);

    expect(reclaimRun(['--force' => true])['output'])->toContain('php artisan dam:storage:backup');

    Storage::disk($this->disk)->move($paths['orphan'], 'aside.txt');
    $name = reclaimBackup($this);
    Storage::disk($this->disk)->move('aside.txt', $paths['orphan']);
    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect($run['result']['refused'])->toMatchArray(['reason' => 'backup_incomplete'])
        ->and($run['result']['refused']['missing'])->toBe(['count' => 1, 'sample' => [$paths['orphan']]])
        ->and($run['result']['backup'])->toMatchArray(['name' => $name, 'dump_bytes' => 7, 'covered' => false])
        ->and($run['output'])->toContain('Not in the backup archive: '.$paths['orphan']);

    // A dry run checks a backup the same way, and a covering one is reported.
    $covering = reclaimBackup($this);
    $dry = reclaimRun(['--backup' => $covering]);
    expect($dry['result']['refused'])->toBeNull()
        ->and($dry['result']['backup'])->toMatchArray(['name' => $covering, 'dump_bytes' => 7, 'covered' => true])
        ->and($dry['result']['backup']['archive_entries'])->toBeGreaterThan(20);
});

it('deletes exactly the reclaimable files under --force, clears their editor rows, prunes and writes receipts', function () {
    ['paths' => $paths, 'rows' => $rows] = reclaimSeedEverything($this->disk);
    $name = reclaimBackup($this);
    $tree = reclaimTree($this->disk);
    $database = reclaimRows();

    $run = reclaimRun(['--force' => true, '--backup' => $name]);
    $result = $run['result'];

    $deleted = ['named_copy', 'stale_version', 'orphan', 'legacy_dot', 'editor_blob_row', 'editor_unnamed', 'thumbnail', 'preview'];
    $finderFiles = ['finder', 'preview_finder'];
    $gone = array_map(fn (string $key): string => $paths[$key], [...$deleted, ...$finderFiles]);

    // Every other file is untouched, blobs, staging, sha-keyed derivatives, captures, covers, custom
    // thumbnails, bundles and everything outside the reclaim roots included.
    expect($run['exit'])->toBe(1)
        ->and(reclaimTree($this->disk)['files'])->toBe(array_diff_key($tree['files'], array_flip($gone)))
        ->and(collect($result['files'])->where('action', 'deleted')->pluck('path')->sort()->values()->all())
        ->toBe(collect($deleted)->map(fn ($key) => $paths[$key])->sort()->values()->all())
        ->and($result)->toMatchArray(['dry_run' => false, 'refused' => null, 'clean' => false])
        ->and($result['backup'])->toMatchArray(['name' => $name, 'covered' => true])
        ->and($result['deleted']['count'])->toBe(8)
        ->and($result['kept_no_blob']['sample'])->toBe([$paths['stale_unique']])
        ->and($result['kept_referenced']['count'])->toBe(5)
        ->and($result['skipped_dot_folder']['count'])->toBe(2)
        ->and($result['editor_paths_cleared'])->toBe(['count' => 1, 'sample' => [$rows['blob_row']]]);

    // The row whose working file went reads its bytes from its blob; every other row is as it was.
    $after = reclaimRows();
    $resources = collect($after['resources'])->keyBy('id');
    expect($resources[$rows['blob_row']]['path'])->toBeNull()
        ->and($resources[$rows['null_row']]['path'])->toBe($paths['editor_null_row'])
        ->and($resources->except([$rows['blob_row']])->all())->toBe(collect($database['resources'])->keyBy('id')->except([$rows['blob_row']])->all())
        ->and(array_diff_key($after, ['resources' => true]))->toBe(array_diff_key($database, ['resources' => true]));

    $storage = Storage::disk($this->disk);
    foreach (['assets/Renamed', 'assets/Empty', 'assets/.models', 'assets/.editor/results', 'preview/1024/assets/Root'] as $pruned) {
        expect($storage->directoryExists($pruned))->toBeFalse();
    }
    foreach (['assets/Root', 'assets/.editor', 'assets/.mindmaps/7', 'assets/.staging', 'assets/.objects', 'thumbnails/assets/Root', 'preview/1024/assets', 'thumbnails/assets'] as $kept) {
        expect($storage->directoryExists($kept))->toBeTrue();
    }
    expect($result['pruned_directories'])->toMatchArray(['count' => 8, 'finder_files' => 2]);

    // One receipt per deleted file, and the summary, all under the run's id; nothing is ever queued.
    $records = collect(reclaimRecords($result['run_id']));
    $receipts = $records->where('event', 'integrity.reclaim_deleted');
    expect($records->pluck('event')->last())->toBe('integrity.reclaimed')
        ->and($receipts->pluck('ctx.path')->sort()->values()->all())->toBe(collect($gone)->sort()->values()->all())
        ->and($receipts->firstWhere('ctx.path', $paths['editor_blob_row'])['ctx'])->toMatchArray([
            'set'                 => 'editor',
            'size'                => 11,
            'sha256'              => hash('sha256', 'named-bytes'),
            'editor_rows_cleared' => [$rows['blob_row']],
        ])
        ->and($receipts->firstWhere('ctx.path', $paths['named_copy'])['ctx']['asset_ids'])
        ->toBe([Asset::query()->where('path', $paths['named_copy'])->value('id')])
        ->and($records->last()['ctx'])->not->toHaveKey('files')
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);

    // Idempotent: a second run deletes nothing and still lists what needs a person.
    $again = reclaimRun(['--force' => true, '--backup' => $name]);
    expect($again['exit'])->toBe(1)
        ->and($again['result']['deleted']['count'])->toBe(0)
        ->and($again['result']['reclaimable']['count'])->toBe(0)
        ->and(reclaimTree($this->disk)['files'])->toBe(array_diff_key($tree['files'], array_flip($gone)));
});

// A pre-slice-3 layered-editor save pointed the asset's path, and its document's content path, at the rendered file
// under assets/.editor/results/. Both are names only there too: one rule protects sets 1 and 2 alike.
it('treats an asset\'s Library path and a document\'s content path as names under assets/.editor/ too', function () {
    $storage = Storage::disk($this->disk);
    $held = 'assets/.editor/results/'.Str::uuid().'.png';
    $unique = 'assets/.editor/results/'.Str::uuid().'.png';
    $edited = reclaimAsset('edited-png', $held, $this->disk);
    reclaimAsset('edited-new', $unique, $this->disk);
    $storage->put($held, 'edited-png');     // a blob holds these bytes
    $storage->put($unique, 'edited-old');   // the asset's blob holds other bytes of the same size
    ImageEditorDocument::create([
        'asset_id'      => $edited->id,
        'revision'      => (string) Str::uuid(),
        'content_path'  => $held,
        'original_base' => (string) Str::uuid(),
        'document'      => [],
    ]);
    // A save-as-new copy's resource row still names the file, holding the same blob: it is cleared with it.
    $copy = reclaimAsset('copy-bytes', 'assets/Root/edited - copy.png', $this->disk);
    $row = reclaimResource($copy, path: $held, fileObjectId: (int) $this->store->current($edited)->id);
    $name = reclaimBackup($this);

    $files = collect(reclaimRun()['result']['files'])->keyBy('path');

    expect($files[$held])->toMatchArray([
        'set'         => 'editor',
        'action'      => 'reclaimable',
        'named_by'    => ['dam_assets.path', 'dam_image_editor_resources.path', 'dam_image_editor_documents.content_path'],
        'editor_rows' => [$row->id],
    ])
        ->and($files[$unique])->toMatchArray(['set' => 'editor', 'action' => 'kept_no_blob', 'named_by' => ['dam_assets.path']]);

    $run = reclaimRun(['--force' => true, '--backup' => $name]);
    $files = collect($run['result']['files'])->keyBy('path');

    expect($files[$held]['action'])->toBe('deleted')
        ->and($files[$unique]['action'])->toBe('kept_no_blob')
        ->and($run['exit'])->toBe(1)
        ->and($row->fresh()->path)->toBeNull()
        ->and(collect(reclaimRecords($run['result']['run_id']))->firstWhere('ctx.path', $held)['ctx'])->toMatchArray([
            'set'                 => 'editor',
            'asset_ids'           => [$edited->id, $copy->id],
            'editor_rows_cleared' => [$row->id],
        ]);
    $storage->assertMissing($held);
    $storage->assertExists($unique);
    expect($run['output'])->toContain('No blob holds these bytes; never deleted, review by hand: '.$unique);
});

it('exits 0 once nothing is reclaimable and nothing needs a person, and a second --force changes nothing', function () {
    $storage = Storage::disk($this->disk);
    $asset = reclaimAsset('only-bytes', 'assets/Root/only.txt', $this->disk);
    $blob = $this->store->current($asset);
    $storage->put('assets/Root/only.txt', 'only-bytes');
    $storage->put('thumbnails/assets/Root/only.txt.webp', 'thumbnail');
    $working = "assets/.editor/{$asset->id}/".Str::uuid();
    $storage->put($working, 'only-bytes');
    $row = reclaimResource($asset, path: $working, fileObjectId: (int) $blob->id);
    // In use: a legacy locator still reads this file, so it stays and needs nobody.
    $storage->put('assets/Photos/legacy.jpg', 'legacy-bytes');
    reclaimLegacyFileObject('legacy-bytes', 'assets/Photos/legacy.jpg', $this->disk);
    $name = reclaimBackup($this);

    $first = reclaimRun(['--force' => true, '--backup' => $name]);

    expect($first['exit'])->toBe(0)
        ->and($first['result'])->toMatchArray(['clean' => true])
        ->and($first['result']['deleted']['count'])->toBe(3)
        ->and($first['result']['kept_referenced']['sample'])->toBe(['assets/Photos/legacy.jpg'])
        ->and($row->fresh()->path)->toBeNull();

    $tree = reclaimTree($this->disk);
    $database = reclaimRows();
    $second = reclaimRun(['--force' => true, '--backup' => $name]);

    expect($second['exit'])->toBe(0)
        ->and($second['result']['deleted']['count'])->toBe(0)
        ->and($second['result']['pruned_directories']['count'])->toBe(0)
        ->and(reclaimTree($this->disk))->toBe($tree)
        ->and(reclaimRows())->toBe($database);
});

// Each change lands just as --force takes the blob lock, after its own report pass classified the file as
// reclaimable: the same narrow seam the orphan command's race tests use. Every change keeps the file.
it('re-checks a content file under its blob lock and keeps it when anything changed since the report', function (string $change, string $action) {
    $storage = Storage::disk($this->disk);
    $asset = reclaimAsset('race-bytes', 'assets/Root/race.txt', $this->disk);
    $copy = 'assets/Root/race.txt';
    $storage->put($copy, 'race-bytes');
    $blob = $this->store->current($asset)->path;
    $name = reclaimBackup($this);
    $changes = [
        'bytes' => fn () => $storage->put($copy, 'RACE-bytes'),   // the same size, other bytes
        'blob'  => fn () => $storage->put($blob, 'RACE-bytes'),   // the blob still has its size but not its bytes
        'named' => fn () => reclaimResource($asset, path: 'assets/Root/RACE.txt', fileObjectId: null),
    ];
    reclaimOnFirstBlobLock($changes[$change]);

    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect(collect($run['result']['files'])->firstWhere('path', $copy)['action'])->toBe($action)
        ->and($run['result']['deleted']['count'])->toBe(0)
        ->and($run['exit'])->toBe(1)
        ->and(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
    $storage->assertExists($copy);
})->with([
    'its bytes changed after they were hashed' => ['bytes', 'kept_changed'],
    'its blob no longer re-hashes'             => ['blob', 'kept_blob_unverified'],
    'an editor resource names it now'          => ['named', 'kept_now_named'],
]);

it('keeps a legacy editor file and its row path when anything changes under the locks or the unlink fails', function (string $change, string $action) {
    $storage = Storage::disk($this->disk);
    $asset = reclaimAsset('editor-bytes', 'assets/Root/editor.png', $this->disk);
    $working = "assets/.editor/{$asset->id}/".Str::uuid();
    $storage->put($working, 'editor-bytes');
    $row = reclaimResource($asset, path: $working, fileObjectId: (int) $this->store->current($asset)->id);
    $name = reclaimBackup($this);
    $changes = [
        'bytes'    => fn () => $storage->put($working, 'EDITOR-bytes'),
        'another'  => fn () => reclaimResource($asset, path: $working, fileObjectId: null),
        'details'  => fn () => reclaimResource($asset, path: null, fileObjectId: null, details: ['source' => $working]),
        'unlinked' => null,
    ];

    if ($change === 'unlinked') {
        $failing = Mockery::mock($storage)->makePartial();
        $failing->shouldReceive('delete')->with($working)->andReturnFalse();
        app('filesystem')->set($this->disk, $failing);
    } else {
        reclaimOnFirstBlobLock($changes[$change]);
    }

    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect(collect($run['result']['files'])->firstWhere('path', $working)['action'])->toBe($action)
        ->and($run['result']['editor_paths_cleared']['count'])->toBe(0)
        ->and($row->fresh()->path)->toBe($working)
        ->and($run['exit'])->toBe(1);
    $storage->assertExists($working);
})->with([
    'its bytes changed after they were hashed'        => ['bytes', 'kept_changed'],
    'another row names it with no blob'               => ['another', 'kept_now_named'],
    'a resource\'s details name it'                   => ['details', 'kept_now_named'],
    'the unlink fails and the transaction rolls back' => ['unlinked', 'kept_unlink_failed'],
]);

// Codex review of slice 4, round 1: the derivative mirrors set aside only `.DS_Store`, so a dot-file or a file in an
// unknown dot-folder there was deleted unchecked. The mirrors follow the asset tree's dot-segment policy now: a dot
// segment is allowed only where it mirrors one of the two legacy areas the reclaim covers, `.models` and `.editor`.
// A pre-slice-3 edited asset's path was `assets/.editor/results/<uuid>.png`, so its thumbnail mirror sits under
// `thumbnails/assets/.editor/results/`: the Studio has one.
it('keeps dot-folders and dot-files inside the derivative mirrors and reclaims the .models and .editor mirrors', function () {
    $storage = Storage::disk($this->disk);
    $poster = 'thumbnails/assets/.editor/results/poster.png.jpg';
    $edited = reclaimAsset('poster-bytes', 'assets/.editor/results/poster.png', $this->disk);
    DB::table('dam_assets')->where('id', $edited->id)->update(['meta_data' => json_encode(['thumbnail_path' => $poster])]);
    $kept = [
        'preview/300/assets/Folder/.cache/nested.webp',
        'preview/300/assets/Folder/.hidden',
        'thumbnails/assets/.editor/.tmp/stray.webp',   // a dot segment inside an allowed area is still skipped
        'thumbnails/assets/.unknown/only-copy.bin',
        'thumbnails/assets/Root/.cache/nested.webp',
        'thumbnails/assets/Root/.hidden.webp',
    ];
    $reclaimed = [
        'preview/300/assets/.editor/results/edited.png.webp',
        'preview/300/assets/.models/0f3c/robot.glb.webp',
        'thumbnails/assets/.editor/results/71697d64-4eb3-4af7-87e4-440c1c1f1a51.png',
        'thumbnails/assets/.models/0f3c/robot.glb.webp',
        'thumbnails/assets/Root/plain.png.webp',
    ];

    foreach ([...$kept, ...$reclaimed, $poster] as $path) {
        $storage->put($path, 'derived');
    }

    $storage->makeDirectory('thumbnails/assets/Root/.empty');   // an unknown dot-folder is never pruned, even empty
    $name = reclaimBackup($this);

    $run = reclaimRun(['--force' => true, '--backup' => $name]);
    $expected = array_fill_keys($reclaimed, 'deleted') + [$poster => 'kept_referenced'];
    ksort($expected, SORT_STRING);

    expect($run['exit'])->toBe(1)
        ->and($run['result']['skipped_dot_folder'])->toMatchArray(['count' => 6, 'sample' => $kept])
        ->and(collect($run['result']['files'])->pluck('action', 'path')->all())->toBe($expected)
        ->and(collect($run['result']['files'])->firstWhere('path', $poster)['named_by'])->toBe(['dam_assets.meta_data']);

    foreach ([...$kept, $poster] as $path) {
        $storage->assertExists($path);
    }

    foreach ($reclaimed as $path) {
        $storage->assertMissing($path);
    }

    expect($storage->directoryExists('thumbnails/assets/Root/.empty'))->toBeTrue()
        ->and($storage->directoryExists('thumbnails/assets/.unknown'))->toBeTrue()
        ->and($storage->directoryExists('thumbnails/assets/.editor/.tmp'))->toBeTrue()
        ->and($storage->directoryExists('thumbnails/assets/.models'))->toBeFalse()
        ->and($storage->directoryExists('preview/300/assets/.models'))->toBeFalse()
        ->and($storage->directoryExists('preview/300/assets/.editor'))->toBeFalse();
});

// Codex review of slice 4, round 1: a derivative was unlinked on the plan's evidence alone. Just before each unlink,
// every naming family is read again for that one path, every asset's meta_data included.
it('keeps a derivative an asset\'s metadata starts naming after the plan', function () {
    $storage = Storage::disk($this->disk);
    $asset = reclaimAsset('seam-bytes', 'assets/Root/seam.png', $this->disk);
    $storage->put('assets/Root/seam.png', 'seam-bytes');   // a Library copy: its re-check takes the blob lock first
    $named = 'thumbnails/assets/Root/seam.png.webp';
    $plain = 'thumbnails/assets/Root/other.png.webp';
    $storage->put($named, 'derived');
    $storage->put($plain, 'derived');
    $name = reclaimBackup($this);
    reclaimOnFirstBlobLock(fn () => DB::table('dam_assets')->where('id', $asset->id)->update(['meta_data' => json_encode(['thumbnail_path' => $named])]));

    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect(collect($run['result']['files'])->pluck('action', 'path')->all())->toBe([
        'assets/Root/seam.png' => 'deleted',
        $plain                 => 'deleted',
        $named                 => 'kept_now_named',
    ])
        ->and($run['exit'])->toBe(1)
        ->and($run['output'])->toContain('A row named it during the run; kept, rerun --force: '.$named);
    $storage->assertExists($named);
    $storage->assertMissing($plain);
});

// Codex review of slice 4, round 1: pruning removed a directory whose name is not valid UTF-8 when it held nothing,
// or nothing but a .DS_Store. APFS refuses such names ("Illegal byte sequence"), so the listing presents two real
// stand-in directories under them, the way a Linux or SMB-mounted disk lists its own.
it('never removes a directory whose name is not valid UTF-8, nor the .DS_Store in it, and reports it', function () {
    $storage = Storage::disk($this->disk);
    reclaimAsset('pruned-bytes', 'assets/Old/pruned.png', $this->disk);
    $storage->put('assets/Old/pruned.png', 'pruned-bytes');   // reclaimed, so assets/Old is pruned around the stand-ins
    $storage->makeDirectory('assets/Old/stand-in-empty');
    $storage->put('assets/Old/stand-in-finder/.DS_Store', 'finder');
    $name = reclaimBackup($this);
    $shown = ['assets/Old/stand-in-empty' => "assets/Old/caf\xE9", 'assets/Old/stand-in-finder' => "assets/Old/na\xEFve"];
    $listing = Mockery::mock($storage)->makePartial();
    $listing->shouldReceive('allDirectories')->andReturnUsing(
        fn (string $directory): array => array_map(fn (string $found): string => $shown[$found] ?? $found, $storage->allDirectories($directory)),
    );
    $listing->shouldReceive('path')->andReturnUsing(fn (string $path): string => $storage->path(array_flip($shown)[$path] ?? $path));
    app('filesystem')->set($this->disk, $listing);

    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect($run['exit'])->toBe(1)
        ->and($run['result']['deleted']['sample'])->toBe(['assets/Old/pruned.png'])
        ->and($run['result']['pruned_directories']['retained_invalid_utf8'])->toBe(['count' => 2, 'sample' => ['assets/Old/caf%E9', 'assets/Old/na%EFve']])
        ->and($run['output'])->toContain('Directory name is not valid UTF-8; never removed, review by hand: assets/Old/caf%E9');
    expect($storage->directoryExists('assets/Old/stand-in-empty'))->toBeTrue();
    $storage->assertExists('assets/Old/stand-in-finder/.DS_Store');
});

// Codex review of slice 4, round 1: a delete whose unlink of the Library copy failed leaves a pending tombstone. Once
// the reclaim has removed that copy, the hourly reap must clear the tombstone rather than keep it pending forever.
it('lets dam:storage:reap clear a delete\'s tombstone for a Library copy the reclaim already removed', function () {
    $storage = Storage::disk($this->disk);
    $copy = 'assets/Root/deleted.png';
    $deleted = reclaimAsset('shared-bytes', $copy, $this->disk);
    reclaimAsset('shared-bytes', 'assets/Root/survivor.png', $this->disk);   // keeps the shared blob after the delete
    $storage->put($copy, 'shared-bytes');
    $failing = Mockery::mock($storage)->makePartial();
    $failing->shouldReceive('delete')->with($copy)->andReturnFalse();
    app('filesystem')->set($this->disk, $failing);

    expect(fn () => app(AssetDeletionService::class)->delete($deleted->fresh()))
        ->toThrow(RuntimeException::class, "Unable to delete asset file [{$this->disk}:{$copy}].");

    app('filesystem')->set($this->disk, $storage);
    expect(DB::table('dam_file_deletion_tombstones')->where('path', $copy)->value('attempts'))->toBe(1);
    $name = reclaimBackup($this);

    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect(collect($run['result']['files'])->firstWhere('path', $copy)['action'])->toBe('deleted');
    $storage->assertMissing($copy);

    $this->artisan('dam:storage:reap')->assertExitCode(0);
    expect(DB::table('dam_file_deletion_tombstones')->count())->toBe(0);
});

if (! function_exists('reclaimBody')) {
    /** The body of a local response, whatever response class carried it. */
    function reclaimBody(TestResponse $response): string
    {
        $base = $response->baseResponse;

        return match (true) {
            $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
            $base instanceof StreamedResponse   => (string) $response->streamedContent(),
            default                             => (string) $base->getContent(),
        };
    }
}

it('leaves the product working through its real routes after a --force run', function () {
    config(['mothership.media_accel_prefix' => null]);
    $storage = Storage::disk($this->disk);
    $root = Directory::factory()->create(['name' => 'ReclaimRoot-'.Str::random(6), 'parent_id' => null]);
    $folderId = $this->postJson(route('admin.dam.directory.store'), ['name' => 'Reclaimed', 'parent_id' => $root->id])
        ->assertOk()->json('data.id');
    $assetId = $this->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('harbor.png', 200, 100)],
        'directory_id' => $folderId,
    ])->assertStatus(201)->json('files.0.id');
    $asset = Asset::findOrFail($assetId);
    $original = $this->store->current($asset);
    $originalBytes = (string) $storage->get($original->path);

    // The pre-slice-3 state: a Library copy, the path-keyed derivatives, and a layered document whose
    // images are working files under assets/.editor/, which the migration then gave blobs.
    $storage->put($asset->path, $originalBytes);
    $storage->put('thumbnails/'.$asset->path.'.webp', 'old thumbnail');
    $storage->put('preview/300/'.$asset->path.'.webp', 'old preview');
    $backdropFile = UploadedFile::fake()->image('backdrop.png', 120, 60);
    $backdrop = (string) file_get_contents($backdropFile->getRealPath());
    $legacy = function (string $kind, string $bytes, int $width, int $height) use ($asset, $storage): ImageEditorResource {
        $id = (string) Str::uuid();
        $path = "assets/.editor/{$asset->id}/{$id}";
        $storage->put($path, $bytes);

        return ImageEditorResource::create(['id' => $id, 'asset_id' => $asset->id, 'admin_id' => 1, 'kind' => $kind, 'status' => 'ready', 'path' => $path, 'width' => $width, 'height' => $height]);
    };
    $base = $legacy('base', $originalBytes, 200, 100);
    $background = $legacy('upload', $backdrop, 120, 60);
    $document = app(ImageEditorSchema::class)->initial($base->id, 200, 100);
    $document['background'] = array_merge($document['background'], ['type' => 'upload', 'image' => $background->id]);
    ImageEditorDocument::create([
        'asset_id'         => $asset->id,
        'asset_version_id' => $asset->versions()->latest('number')->value('id'),
        'revision'         => (string) Str::uuid(),
        'content_path'     => $asset->path,
        'original_base'    => $base->id,
        'document'         => $document,
    ]);
    expect(app(StorageIntegrity::class)->repairEditorResources()['ingested']['count'])->toBe(2);
    $name = reclaimBackup($this);

    $run = reclaimRun(['--force' => true, '--backup' => $name]);

    expect($run['exit'])->toBe(0)
        ->and($run['result']['deleted']['count'])->toBe(5)
        ->and($run['result']['editor_paths_cleared']['count'])->toBe(2)
        ->and($storage->allFiles('assets/.editor'))->toBe([])
        ->and($storage->exists($asset->path))->toBeFalse()
        ->and($storage->allFiles('thumbnails/assets'))->toBe([])
        ->and($storage->allFiles('preview/300/assets'))->toBe([])
        ->and($base->fresh()->path)->toBeNull()
        ->and($background->fresh()->path)->toBeNull();
    expect(Artisan::call('dam:storage:check'))->toBe(0);

    // Download, fetch, thumbnail and preview all read the blob.
    expect(reclaimBody($this->get(route('admin.dam.assets.download', $asset->id))->assertOk()))->toBe($originalBytes)
        ->and(reclaimBody($this->get(route('admin.dam.file.fetch', ['path' => $asset->path]))->assertOk()))->toBe($originalBytes);
    $this->get(route('admin.dam.file.thumbnail', ['path' => $asset->path]), ['Accept' => 'image/*'])
        ->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(route('admin.dam.file.preview', ['path' => $asset->path, 'size' => 300]))
        ->assertOk()->assertHeader('Content-Type', 'image/webp');

    // The layered document reopens, and every image it names is served from its blob.
    $reopened = $this->getJson(route('admin.dam.assets.editor.document', $asset->id))->assertOk()->json();
    expect(array_keys($reopened['resources']))->toEqualCanonicalizing([$base->id, $background->id]);

    foreach ($reopened['resources'] as $id => $url) {
        $object = ImageEditorResource::findOrFail($id)->fileObject;

        expect($object)->toBeInstanceOf(FileObject::class)
            ->and(reclaimBody($this->get($url)->assertOk()))->toBe($storage->get($object->path));
    }

    expect(reclaimBody($this->get($reopened['resources'][$background->id])))->toBe($backdrop);
});
