<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Rendition;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
    Storage::fake(Directory::getAssetDisk());
    $this->asset = Asset::factory()->create();
    $this->url = '/'.config('app.admin_url').'/dam/assets/'.$this->asset->id.'/files';
});

it('attaches a file at its sha256 path with the shared listing shape', function () {
    $bytes = 'attachment content';
    $sha = hash('sha256', $bytes);
    $row = $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('notes.txt', $bytes), 'role' => 'sidecar'])->assertCreated()->json();
    $file = AssetFile::findOrFail($row['id']);
    expect($file->dam_asset_id)->toBe($this->asset->id);
    expect($row)->toMatchArray(['asset_id' => $this->asset->id, 'role' => 'sidecar', 'label' => 'notes.txt', 'sha256' => $sha, 'size' => strlen($bytes)]);
    expect($file->fileObject->path)->toBe('assets/.objects/'.substr($sha, 0, 2).'/'.$sha);
    expect(Storage::disk(Directory::getAssetDisk())->get($file->fileObject->path))->toBe($bytes);
    expect($this->getJson($this->url)->assertOk()->json())->not->toBeEmpty();
});

it('reuses the same blob when identical bytes are attached twice', function () {
    $rows = [];
    for ($i = 0; $i < 2; $i++) {
        $rows[] = $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('same.txt', 'same bytes'), 'role' => 'sidecar'])->assertCreated()->json();
    }
    $first = AssetFile::findOrFail($rows[0]['id']);
    $second = AssetFile::findOrFail($rows[1]['id']);
    expect($second->file_object_id)->toBe($first->file_object_id);
    expect($second->id)->toBe($first->id);
    $third = $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('same.txt', 'same bytes'), 'role' => 'source'])->assertCreated()->json();
    expect(AssetFile::findOrFail($third['id'])->file_object_id)->toBe($first->file_object_id);
    expect($third['sort'])->toBe($first->sort + 1);
});

it('recreates a FileObject deleted by a concurrent last-reference destroy between lookup and insert', function () {
    $bytes = 'race-fixture-bytes';
    $sha = hash('sha256', $bytes);
    $disk = Directory::getAssetDisk();

    // The row this request's own lookup will find, plus its ONLY other reference — the
    // genuine last-reference destroy() this test simulates racing against.
    $path = storeFixtureBlob($sha, $bytes);
    $existing = FileObject::factory()->create(['sha256' => $sha, 'disk' => $disk, 'path' => $path, 'size' => strlen($bytes)]);
    $sole = AssetFile::factory()->create(['dam_asset_id' => Asset::factory()->create()->id, 'file_object_id' => $existing->id, 'role' => 'sidecar']);

    // Stands in for AssetFileController::store()'s in-transaction putLocal() call racing a
    // concurrent destroy(): the FIRST call returns the (about to be stale) row and then, as
    // that concurrent request would, deletes its only other reference, the row itself, and
    // unlinks its blob — simulating a destroy() that fully committed in the window before
    // this request's own re-lock step. Every later call behaves exactly like the real
    // ContentStore. The genuine two-process ordering (real locks, real processes) is
    // BlobLockRaceTest (PostgreSQL); this pins the upload's recovery from the resulting gap.
    $fakeStore = new class extends ContentStore
    {
        public int $calls = 0;

        public ?int $soleAssetFileId = null;

        public function putLocal(
            string $disk,
            string $localPath,
            ?string $sha256 = null,
            ?string $mimeType = null,
            ?string $extension = null,
            array $attributes = [],
        ): FileObject {
            $object = parent::putLocal($disk, $localPath, $sha256, $mimeType, $extension, $attributes);
            $this->calls++;

            if ($this->calls === 1) {
                AssetFile::whereKey($this->soleAssetFileId)->delete();
                FileObject::whereKey($object->id)->delete();
                Storage::disk($object->disk)->delete($object->path);
            }

            return $object;
        }
    };
    $fakeStore->soleAssetFileId = $sole->id;
    app()->instance(ContentStore::class, $fakeStore);

    $row = $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('race.txt', $bytes), 'role' => 'sidecar'])
        ->assertCreated()->json();

    $file = AssetFile::findOrFail($row['id']);
    $object = $file->fileObject;

    expect($object)->not->toBeNull()
        ->and(app(ContentStore::class)->holdsBlob($object))->toBeTrue()
        ->and(hash('sha256', Storage::disk($object->disk)->get($object->path)))->toBe($object->sha256);
});

it('holds the blob lock inside the transaction that commits the attachment', function () {
    // The two-process ordering itself is BlobLockRaceTest (PostgreSQL); this pins the upload's side of it.
    $spy = new class extends ContentStore
    {
        /** @var list<array{sha256: string, level: int}> */
        public array $locks = [];

        public function lockBlob(string $sha256): void
        {
            $this->locks[] = ['sha256' => $sha256, 'level' => DB::transactionLevel()];
            parent::lockBlob($sha256);
        }
    };
    app()->instance(ContentStore::class, $spy);
    $baseLevel = DB::transactionLevel();

    $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('locked.txt', 'locked bytes'), 'role' => 'sidecar'])
        ->assertCreated();

    expect(collect($spy->locks)->where('sha256', hash('sha256', 'locked bytes'))->max('level'))->toBeGreaterThan($baseLevel);
});

it('leaves no unreferenced FileObject row when a failure follows a successful ingest, so its tombstone still cleans up', function () {
    $bytes = 'orphan-hazard-bytes';
    $sha = hash('sha256', $bytes);
    $disk = Directory::getAssetDisk();
    $path = app(ContentStore::class)->objectPath($sha);

    // The blob is already on disk (as if left over from an earlier, since-cleaned-up
    // ingest) with no FileObject row naming it yet, and a tombstone is already queued for
    // it — exactly the state a legitimate cleanup pass would find pending.
    Storage::disk($disk)->put($path, $bytes);
    $tombstoneIds = app(AssetDeletionService::class)->queueFileDeletions([['disk' => $disk, 'path' => $path]]);

    // Simulates a failure that happens right after ContentStore::record() ingests the row:
    // since putLocal() now runs inside AssetFileController::store()'s own transaction, that
    // whole transaction — including record()'s already-"committed" savepoint — rolls the
    // row back with it, so this blob's tombstone is never left blocked by an orphan.
    app()->instance(ContentStore::class, new class extends ContentStore
    {
        public function putLocal(
            string $disk,
            string $localPath,
            ?string $sha256 = null,
            ?string $mimeType = null,
            ?string $extension = null,
            array $attributes = [],
        ): FileObject {
            $object = parent::putLocal($disk, $localPath, $sha256, $mimeType, $extension, $attributes);

            throw new RuntimeException('simulated post-ingest failure');
        }
    });

    $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('orphan.txt', $bytes), 'role' => 'sidecar'])
        ->assertStatus(500);

    expect(FileObject::where('sha256', $sha)->exists())->toBeFalse();

    app(AssetDeletionService::class)->processPendingFileDeletions($tombstoneIds);

    expect(Storage::disk($disk)->exists($path))->toBeFalse();
    expect(DB::table('dam_file_deletion_tombstones')->whereIn('id', $tombstoneIds)->exists())->toBeFalse();
});

it('rejects attaching an original or forbidden executable', function () {
    $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'notes'), 'role' => 'original'])->assertUnprocessable();
    $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('bad.php', '<?php echo 1;'), 'role' => 'sidecar'])->assertUnprocessable();
});

it('removes orphaned blobs but preserves blobs referenced by another asset', function (bool $shared) {
    $row = $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'remove me'), 'role' => 'sidecar'])->assertCreated()->json();
    $file = AssetFile::findOrFail($row['id']);
    $object = $file->fileObject;
    if ($shared) {
        AssetFile::factory()->create(['dam_asset_id' => Asset::factory()->create()->id, 'file_object_id' => $object->id, 'role' => 'sidecar']);
    }
    $this->deleteJson($this->url.'/'.$file->id)->assertOk()->assertJson(['deleted' => true]);
    expect(AssetFile::find($file->id))->toBeNull();
    expect(FileObject::whereKey($object->id)->exists())->toBe($shared);
    expect(Storage::disk($object->disk)->exists($object->path))->toBe($shared);
})->with([false, true]);

it('protects originals and rejects attachments belonging to another asset', function () {
    $original = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'original']);
    $this->deleteJson($this->url.'/'.$original->id)->assertUnprocessable();
    $other = AssetFile::factory()->create(['dam_asset_id' => Asset::factory()->create()->id, 'role' => 'sidecar']);
    $this->deleteJson($this->url.'/'.$other->id)->assertNotFound();
    expect(AssetFile::whereKey($original->id)->exists())->toBeTrue();
});

it('creates a retryable tombstone when the physical unlink fails after destroy commits', function () {
    $row = $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'tombstone me'), 'role' => 'sidecar'])->assertCreated()->json();
    $file = AssetFile::findOrFail($row['id']);
    $object = $file->fileObject;
    $disk = $object->disk;

    $realDisk = Storage::disk($disk);
    $failingDisk = Mockery::mock($realDisk)->makePartial();
    $failingDisk->shouldReceive('delete')->once()->with($object->path)->andReturnFalse();
    app('filesystem')->set($disk, $failingDisk);

    // The database side commits and the request still reports success: a failed unlink
    // leaves a durable, retryable tombstone rather than rolling back the deletion.
    $this->deleteJson($this->url.'/'.$file->id)->assertOk()->assertJson(['deleted' => true]);

    expect(AssetFile::find($file->id))->toBeNull();
    expect(FileObject::whereKey($object->id)->exists())->toBeFalse();
    expect($realDisk->exists($object->path))->toBeTrue();
    expect(DB::table('dam_file_deletion_tombstones')->where('disk', $disk)->where('path', $object->path)->exists())->toBeTrue();
});

it('keeps a blob still used as rendition output', function () {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'sidecar']);
    $object = $file->fileObject;
    Storage::disk($object->disk)->put($object->path, 'rendered');
    Rendition::factory()->create(['dam_asset_id' => $this->asset->id, 'output_file_object_id' => $object->id]);
    $this->deleteJson($this->url.'/'.$file->id)->assertOk();
    expect(FileObject::whereKey($object->id)->exists())->toBeTrue();
    Storage::disk($object->disk)->assertExists($object->path);
});

it('requires file permission, edit permission and directory access for writes', function (bool $filePermission, bool $edit, bool $access) {
    $file = AssetFile::factory()->create(['dam_asset_id' => $this->asset->id, 'role' => 'sidecar']);
    $permissions = ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.files.index'];
    if ($filePermission) {
        $permissions = array_merge($permissions, ['dam.asset.files.store', 'dam.asset.files.destroy']);
    }
    if ($edit) {
        $permissions[] = 'dam.asset.edit';
    }
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);
    $member = Admin::factory()->create(['role_id' => $role->id]);
    $directory = Directory::factory()->create();
    $this->asset->directories()->attach($directory->id);
    if ($access) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id]);
    }
    $this->actingAs($member, 'admin');
    app(DirectoryPermissionService::class)->flush();
    // An asset the member cannot open answers like a missing one (P2 S1); a visible one they may not change is 403.
    $refused = $access ? 403 : 404;
    $this->postJson($this->url, ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'notes'), 'role' => 'sidecar'])->assertStatus($refused);
    $this->deleteJson($this->url.'/'.$file->id)->assertStatus($refused);
    expect(AssetFile::whereKey($file->id)->exists())->toBeTrue();
})->with([[false, true, true], [true, false, true], [true, true, false]]);
