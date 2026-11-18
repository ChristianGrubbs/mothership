<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\FinalizeChunkUpload;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\UploadTracker;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/*
 * FU: a dropped or picked folder keeps its structure. The upload panel makes the folders first (create_structure),
 * then every file's chunk upload names its relative path and lands in the matching folder. Names are sanitized, never
 * refused, by one rule both steps share (FolderUploadPaths); only too deep or too many folders is refused, before any
 * folder is made. New folders and files take their parent's access list; an existing folder the uploader cannot open is
 * never written into.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake(Directory::getAssetDisk());
    config(['dam.explorer.upload.chunk_bytes' => 4]);
});

function fuTarget(string $name = 'Drop target'): Directory
{
    $target = Directory::create(['name' => $name.' '.Str::random(5), 'parent_id' => null]);
    Storage::disk(Directory::getAssetDisk())->makeDirectory(Directory::ASSETS_DIRECTORY.'/'.$target->generatePath());

    return $target;
}

/** The child folders under `$parentId`, by name, recursively: `['Shoot' => ['Day 1' => []]]`. */
function fuTree(int $parentId): array
{
    // Sorted by byte value here, not by the database: PostgreSQL's collation orders `escape` before `Q3_`, SQLite's does not.
    return Directory::query()->where('parent_id', $parentId)->get()
        ->mapWithKeys(fn (Directory $folder) => [$folder->name => fuTree((int) $folder->id)])
        ->sortKeys(SORT_STRING)->all();
}

/** One file of a dropped folder through the chunk upload, finalized; returns the upload row. */
function fuUpload(Directory $target, string $relativePath, bool $preserveRoot, string $contents = 'folder bytes'): ChunkUpload
{
    $tracker = UploadTracker::create([
        'uuid'         => (string) Str::uuid(),
        'user_id'      => auth('admin')->id(),
        'directory_id' => $target->id,
        'state'        => UploadTracker::STATE_PROCESSING,
    ]);
    $init = test()->postJson(route('admin.dam.assets.chunk-upload.initialize'), [
        'session_uuid'  => $tracker->uuid,
        'directory_id'  => $target->id,
        'original_name' => basename($relativePath),
        'mime_type'     => 'text/plain',
        'size'          => strlen($contents),
        'last_modified' => 1_788_864_000_000,
        'fingerprint'   => hash('sha256', $relativePath.$contents),
        'relative_path' => $relativePath,
        'preserve_root' => $preserveRoot,
    ]);

    if ($init->getStatusCode() !== 201) {
        return new ChunkUpload(['state' => 'refused:'.$init->json('code')]);
    }

    $upload = ChunkUpload::query()->where('uuid', $init->json('upload.uuid'))->firstOrFail();

    foreach (str_split($contents, (int) $upload->chunk_bytes) as $index => $chunk) {
        $start = $index * (int) $upload->chunk_bytes;
        test()->call('PUT', route('admin.dam.assets.chunk-upload.store', [$upload->uuid, $index]), server: [
            'CONTENT_TYPE'        => 'application/octet-stream',
            'HTTP_CONTENT_RANGE'  => sprintf('bytes %d-%d/%d', $start, $start + strlen($chunk) - 1, strlen($contents)),
            'HTTP_X_CHUNK_SHA256' => hash('sha256', $chunk),
        ], content: $chunk)->assertOk();
    }

    test()->postJson(route('admin.dam.assets.chunk-upload.complete', $upload->uuid))->assertAccepted();
    (new FinalizeChunkUpload($upload->id))->handle(app(ChunkUploadService::class));

    return $upload->fresh();
}

function fuFolderOf(ChunkUpload $upload): int
{
    return (int) DB::table('dam_asset_directory')->where('asset_id', $upload->asset_id)->value('directory_id');
}

function fuFolderAt(Directory $target, array $names): int
{
    $id = (int) $target->id;

    foreach ($names as $name) {
        $id = (int) Directory::query()->where('parent_id', $id)->where('name', $name)->valueOrFail('id');
    }

    return $id;
}

it('recreates a dropped folder\'s tree and puts every file in its own folder', function () {
    $this->loginAsAdmin();
    $target = fuTarget();

    // The panel sends every folder path once, the dropped folder itself included when it is kept.
    $this->postJson(route('admin.dam.directory.create_structure'), [
        'directory_id' => $target->id,
        'paths'        => ['Shoot', 'Shoot/Day 1', 'Shoot/Day 1/Raw', 'Shoot/Day 2'],
    ])->assertOk();

    $raw = fuUpload($target, 'Shoot/Day 1/Raw/frame.txt', preserveRoot: true);
    $day2 = fuUpload($target, 'Shoot/Day 2/notes.txt', preserveRoot: true);
    $top = fuUpload($target, 'Shoot/cover.txt', preserveRoot: true);

    expect(fuTree((int) $target->id))->toBe(['Shoot' => ['Day 1' => ['Raw' => []], 'Day 2' => []]])
        ->and(fuFolderOf($raw))->toBe(fuFolderAt($target, ['Shoot', 'Day 1', 'Raw']))
        ->and(fuFolderOf($day2))->toBe(fuFolderAt($target, ['Shoot', 'Day 2']))
        ->and(fuFolderOf($top))->toBe(fuFolderAt($target, ['Shoot']))
        ->and(Asset::query()->findOrFail($raw->asset_id)->file_name)->toBe('frame.txt');

    // Without "keep the dropped folder", its contents land straight in the target.
    $this->postJson(route('admin.dam.directory.create_structure'), ['directory_id' => $target->id, 'paths' => ['Clips']])->assertOk();
    $clip = fuUpload($target, 'Dropped/Clips/clip.txt', preserveRoot: false);
    $loose = fuUpload($target, 'Dropped/loose.txt', preserveRoot: false);

    expect(fuFolderOf($clip))->toBe(fuFolderAt($target, ['Clips']))
        ->and(fuFolderOf($loose))->toBe((int) $target->id)
        ->and(Directory::query()->where('name', 'Dropped')->exists())->toBeFalse();
});

it('sanitizes folder names instead of refusing them, the same way when the files arrive', function () {
    $this->loginAsAdmin();
    $target = fuTarget();

    $this->postJson(route('admin.dam.directory.create_structure'), [
        'directory_id' => $target->id,
        'paths'        => ['Q3: "Final"?', 'Q3: "Final"?/a*b|c', '.hidden/  spaced   name. ', '../../escape', "tab\there"],
    ])->assertOk();

    $file = fuUpload($target, 'Q3: "Final"?/a*b|c/one.txt', preserveRoot: true);
    $climb = fuUpload($target, '../../escape/two.txt', preserveRoot: true);

    // `..` segments are dropped, so nothing is made beside or above the target.
    expect(fuTree((int) $target->id))->toBe([
        'Q3_ _Final__' => ['a_b_c' => []],
        'escape'       => [],
        'hidden'       => ['spaced name' => []],
        'tabhere'      => [],
    ])
        ->and(fuFolderOf($file))->toBe(fuFolderAt($target, ['Q3_ _Final__', 'a_b_c']))
        ->and(fuFolderOf($climb))->toBe(fuFolderAt($target, ['escape']))
        ->and(Directory::query()->where('name', 'escape')->where('parent_id', '!=', $target->id)->exists())->toBeFalse();
});

it('refuses a folder deeper or a drop with more folders than the limits, before making any', function () {
    $this->loginAsAdmin();
    $target = fuTarget();
    config(['dam.explorer.upload.folder_max_depth' => 3, 'dam.explorer.upload.folder_max_count' => 4]);

    $deep = $this->postJson(route('admin.dam.directory.create_structure'), ['directory_id' => $target->id, 'paths' => ['a', 'a/b/c/d']]);
    $many = $this->postJson(route('admin.dam.directory.create_structure'), ['directory_id' => $target->id, 'paths' => ['one', 'two', 'three', 'four', 'five']]);
    $fits = $this->postJson(route('admin.dam.directory.create_structure'), ['directory_id' => $target->id, 'paths' => ['a/b/c', 'x']]);

    expect($deep->getStatusCode())->toBe(422)
        ->and($deep->json('code'))->toBe('folder_too_deep')
        ->and($deep->json('message'))->toContain('3')
        ->and($many->getStatusCode())->toBe(422)
        ->and($many->json('code'))->toBe('too_many_folders')
        ->and($fits->getStatusCode())->toBe(200)
        ->and(fuTree((int) $target->id))->toBe(['a' => ['b' => ['c' => []]], 'x' => []])
        // A file whose own path is too deep is refused at init, never flattened.
        ->and(fuUpload($target, 'a/b/c/d/file.txt', preserveRoot: true)->state)->toBe('refused:folder_too_deep');
});

it('gives new folders and their files the target\'s access list, and never writes into a folder the uploader cannot open', function () {
    p5aAccessModel(AccessModel::LISTS);
    $target = fuTarget('Listed');
    $member = p7ReviewMember(folders: [$target], editor: true);
    $friend = Admin::factory()->create(['status' => 1]);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $target->id, 'admin_id' => $member->id],
        ['directory_id' => $target->id, 'admin_id' => $friend->id],
    ]);
    // An existing folder in the target that the member's list does not include.
    $secret = Directory::create(['name' => 'Secret', 'parent_id' => $target->id]);
    DB::table('dam_directory_user_access')->where('directory_id', $secret->id)->delete();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $secret->id, 'admin_id' => $friend->id]);
    $this->actingAs($member, 'admin');

    $this->postJson(route('admin.dam.directory.create_structure'), ['directory_id' => $target->id, 'paths' => ['Shoot/Day 1']])->assertOk();
    // Each request is a fresh process in production; the test app keeps the permission memo between calls.
    app(DirectoryPermissionService::class)->flush();
    $upload = fuUpload($target, 'Shoot/Day 1/frame.txt', preserveRoot: true);
    $shoot = fuFolderAt($target, ['Shoot']);
    $day1 = fuFolderAt($target, ['Shoot', 'Day 1']);
    $listOf = fn (string $table, string $column, int $id) => DB::table($table)->where($column, $id)->orderBy('admin_id')->pluck('admin_id')->map(fn ($v) => (int) $v)->all();
    $expected = collect([$member->id, $friend->id])->sort()->values()->all();

    $hidden = $this->postJson(route('admin.dam.directory.create_structure'), ['directory_id' => $target->id, 'paths' => ['Secret/inner']]);

    expect($listOf('dam_directory_user_access', 'directory_id', $shoot))->toBe($expected)
        ->and($listOf('dam_directory_user_access', 'directory_id', $day1))->toBe($expected)
        ->and(fuFolderOf($upload))->toBe($day1)
        ->and($listOf('dam_asset_user_access', 'asset_id', (int) $upload->asset_id))->toBe($expected)
        ->and($hidden->getStatusCode())->toBe(403)
        ->and(Directory::query()->where('parent_id', $secret->id)->exists())->toBeFalse();
});
