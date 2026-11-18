<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Jobs\CopyDirectoryStructure;
use Mothership\DAM\Jobs\DeleteDirectory;
use Mothership\DAM\Jobs\MassCopy;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\ActionRequest;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Repositories\DirectoryRepository;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

function createPendingActionRequest(string $eventType): void
{
    ActionRequest::create([
        'event_type' => $eventType,
        'status'     => 'pending',
        'admin_id'   => auth()->id(),
    ]);
}

it('dispatches delete directory job', function () {
    Bus::fake();

    $directory = Directory::factory()->create();

    DeleteDirectory::dispatch($directory->id, auth()->id());

    Bus::assertDispatched(DeleteDirectory::class);
});

it('delete directory job is queueable', function () {
    Queue::fake();

    $directory = Directory::factory()->create();

    DeleteDirectory::dispatch($directory->id, auth()->id());

    Queue::assertPushed(DeleteDirectory::class);
});

it('delete directory job deletes directory and its assets', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $parent = Directory::factory()->create(['name' => 'Parent']);
    $directory = Directory::factory()->create(['name' => 'ToDelete', 'parent_id' => $parent->id]);

    $asset = Asset::factory()->create([
        'file_name' => 'test.jpg',
        'path'      => 'assets/Parent/ToDelete/test.jpg',
    ]);
    $directory->assets()->attach($asset->id);

    Storage::disk($disk)->put('assets/Parent/ToDelete/test.jpg', 'content');
    Storage::disk($disk)->makeDirectory('assets/Parent/ToDelete');

    $directoryId = $directory->id;

    createPendingActionRequest('delete_directory');

    $job = new DeleteDirectory($directoryId, auth()->id());
    $job->handle();

    $this->assertDatabaseMissing('dam_directories', ['id' => $directoryId]);
});

it('rechecks delete-own-only at execution before deleting a changed directory tree', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $user = auth('admin')->user();
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['dam', 'dam.directory', 'dam.directory.destroy'],
    ]);
    $user->update(['role_id' => $role->id]);
    DB::table('dam_role_settings')->insert([
        'role_id'         => $role->id,
        'delete_own_only' => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $parent = Directory::factory()->create(['name' => 'Parent']);
    $directory = Directory::factory()->create([
        'name'       => 'Owned',
        'parent_id'  => $parent->id,
        'created_by' => $user->id,
    ]);
    $lateAsset = Asset::factory()->create(['created_by' => $user->id]);
    $directory->assets()->attach($lateAsset->id);
    Storage::disk($disk)->makeDirectory('assets/Parent/Owned');
    createPendingActionRequest('delete_directory');

    (new DeleteDirectory($directory->id, $user->id))->handle();

    $this->assertDatabaseHas('dam_directories', ['id' => $directory->id]);
    $this->assertDatabaseHas('dam_assets', ['id' => $lateAsset->id]);
});

it('dispatches copy directory structure job', function () {
    Bus::fake();

    $directory = Directory::factory()->create();

    CopyDirectoryStructure::dispatch($directory->id, auth()->id());

    Bus::assertDispatched(CopyDirectoryStructure::class);
});

it('copy directory structure job is queueable', function () {
    Queue::fake();

    $directory = Directory::factory()->create();

    CopyDirectoryStructure::dispatch($directory->id, auth()->id());

    Queue::assertPushed(CopyDirectoryStructure::class);
});

it('copy directory structure creates a new directory with copy suffix', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $parent = Directory::factory()->create(['name' => 'Root']);
    $originalCreator = Admin::factory()->create();
    $directory = Directory::factory()->create([
        'name'       => 'Original',
        'parent_id'  => $parent->id,
        'created_by' => $originalCreator->id,
    ]);
    Directory::factory()->create([
        'name'       => 'Child',
        'parent_id'  => $directory->id,
        'created_by' => $originalCreator->id,
    ]);

    Storage::disk($disk)->makeDirectory('assets/Root/Original');

    createPendingActionRequest('copy_directory_structure');

    $job = new CopyDirectoryStructure($directory->id, auth()->id());
    $job->handle();

    $this->assertDatabaseHas('dam_directories', [
        'name'       => 'Original copy',
        'parent_id'  => $parent->id,
        'created_by' => auth()->id(),
    ]);
    $copiedRoot = Directory::query()->where('name', 'Original copy')->firstOrFail();
    $this->assertDatabaseHas('dam_directories', [
        'name'       => 'Child',
        'parent_id'  => $copiedRoot->id,
        'created_by' => auth()->id(),
    ]);
});

it('dispatches move directory structure job', function () {
    Bus::fake();

    $source = Directory::factory()->create();
    $target = Directory::factory()->create();

    MoveDirectoryStructure::dispatch($source->id, $target->id, auth()->id());

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it('move directory structure job changes parent', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $root = Directory::factory()->create(['name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Source', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => $root->id]);

    Storage::disk($disk)->makeDirectory('assets/Root/Source');
    Storage::disk($disk)->makeDirectory('assets/Root/Target');

    createPendingActionRequest('move_directory_structure');

    $job = new MoveDirectoryStructure($source->id, $target->id, auth()->id());
    $job->handle();

    $source->refresh();
    expect($source->parent_id)->toBe($target->id);
});

it('move directory structure job updates asset paths', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $root = Directory::factory()->create(['name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Source', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => $root->id]);

    $asset = Asset::factory()->create([
        'file_name' => 'file.jpg',
        'path'      => 'assets/Root/Source/file.jpg',
    ]);
    $source->assets()->attach($asset->id);

    Storage::disk($disk)->makeDirectory('assets/Root/Source');
    Storage::disk($disk)->makeDirectory('assets/Root/Target');
    Storage::disk($disk)->put('assets/Root/Source/file.jpg', 'content');

    createPendingActionRequest('move_directory_structure');

    $job = new MoveDirectoryStructure($source->id, $target->id, auth()->id());
    $job->handle();

    $asset->refresh();
    expect($asset->path)->toContain('Target');
});

// Regression: MoveDirectoryStructure rethrew every failure and never touched the action request,
// unlike CopyDirectoryStructure, DeleteDirectory and MassMove. The row stayed 'pending' for ever,
// so the sidebar polled until the tab closed and the explorer gave up silently after 30 tries —
// the user's move just never finished and never said why.

it('marks the move action request failed immediately, without waiting for a retry, when the move is refused', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $root = Directory::factory()->create(['name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Source', 'parent_id' => $root->id]);
    $child = Directory::factory()->create(['name' => 'Child', 'parent_id' => $source->id]);

    createPendingActionRequest('move_directory_structure');

    // Moving a directory into its own descendant is DirectoryMoveRefusedException: not worth a queue
    // retry, since the same refusal happens every attempt, so handle() catches it and reports the
    // failure on this call rather than rethrowing and leaving the request pending through however
    // many attempts the queue would otherwise allow.
    $job = new MoveDirectoryStructure($source->id, $child->id, auth()->id());
    $job->handle();

    $request = ActionRequest::findOneWhere([
        'event_type' => 'move_directory_structure',
        'admin_id'   => auth()->id(),
    ]);

    expect($request->status)->toBe('failed')
        ->and($request->error_message)->toBe(trans('dam::app.admin.dam.index.directory.cannot-move'))
        ->and($source->fresh()->parent_id)->toBe($root->id);
});

it('leaves the move action request pending through a retryable failure and completes on the next attempt', function () {
    Storage::fake(Directory::getAssetDisk());

    $root = Directory::factory()->create(['name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Source', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => $root->id]);

    $asset = Asset::factory()->create([
        'file_name' => 'file.jpg',
        'path'      => 'assets/Root/Source/file.jpg',
    ]);
    $source->assets()->attach($asset->id);

    createPendingActionRequest('move_directory_structure');

    // find() is the first thing move() does — a stand-in for a transient DB hiccup (a deadlock or a
    // dropped connection reading the tree). It throws on the first call and delegates to the real
    // repository afterwards, so the second attempt is a genuine retry of the same job instance.
    //
    // A Mockery partial mock of DirectoryRepository won't do here: Mockery builds it from the class
    // name without running the constructor, and DirectoryRepository extends Prettus's
    // BaseRepository, whose constructor calls makeModel() to set $this->model — every other method
    // would hit a null model. This plain (non-extending) delegator wraps the container's
    // already-constructed instance instead, so that state stays intact; it deliberately does not
    // extend DirectoryRepository, because an inherited method would shadow __call() below and run
    // unset-model code again. Only find() is faked.
    $repositoryDouble = new class(app(DirectoryRepository::class))
    {
        public int $findCalls = 0;

        public function __construct(private DirectoryRepository $inner) {}

        public function find($id, $columns = ['*'])
        {
            $this->findCalls++;

            if ($this->findCalls === 1) {
                throw new RuntimeException('directory lookup timed out');
            }

            return $this->inner->find($id, $columns);
        }

        public function __call($name, $arguments)
        {
            return $this->inner->{$name}(...$arguments);
        }
    };

    app()->instance(DirectoryRepository::class, $repositoryDouble);

    $job = new MoveDirectoryStructure($source->id, $target->id, auth()->id());

    // First attempt: handle() must not swallow this — leaving it uncaught is what makes the queue
    // worker eligible to retry the job (Laravel calls failed() only once retries are exhausted).
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'directory lookup timed out');

    $request = ActionRequest::findOneWhere([
        'event_type' => 'move_directory_structure',
        'admin_id'   => auth()->id(),
    ]);

    // Nothing moved on the failed attempt, and the request is still pending, not failed — that's the
    // bug this test guards. A retry that later succeeds must still have a pending row for completed()
    // to update.
    expect($request->status)->toBe('pending')
        ->and($source->fresh()->parent_id)->toBe($root->id)
        ->and($asset->fresh()->path)->toBe('assets/Root/Source/file.jpg');

    // Second attempt (the queue's retry of the same job): the directory lookup succeeds this time.
    $job->handle();

    $request->refresh();

    expect($request->status)->toBe('completed')
        ->and($request->error_message)->toBeNull()
        ->and($source->fresh()->parent_id)->toBe($target->id)
        ->and($asset->fresh()->path)->toBe('assets/Root/Target/Source/file.jpg')
        // Two attempts were made, but the first never reached the real repository.
        ->and($repositoryDouble->findCalls)->toBe(3);
});

it('reports a move failure that never reached the job body', function () {
    createPendingActionRequest('move_directory_structure');

    $source = Directory::factory()->create(['name' => 'Timeout source']);
    $target = Directory::factory()->create(['name' => 'Timeout target']);

    // What the queue calls when a job times out or exhausts its attempts without handle() catching.
    (new MoveDirectoryStructure($source->id, $target->id, auth()->id()))
        ->failed(new RuntimeException('Job has timed out.'));

    $request = ActionRequest::findOneWhere([
        'event_type' => 'move_directory_structure',
        'admin_id'   => auth()->id(),
    ]);

    expect($request->status)->toBe('failed')
        ->and($request->error_message)->toBe('Job has timed out.');
});

// Regression: batchReplaceAssetPaths() used a bare REPLACE(path, oldPrefix, newPrefix) with no
// path-boundary check, so moving ".../Photos" also rewrote a multi-folder asset physically stored
// under the sibling ".../Photos2" just because that path starts with the same characters.
it('does not rewrite an asset under a sibling-prefix folder when moving a directory', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $root = Directory::factory()->create(['name' => 'SiblingRoot']);
    $photos = Directory::factory()->create(['name' => 'Photos', 'parent_id' => $root->id]);
    $photos2 = Directory::factory()->create(['name' => 'Photos2', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'MoveTarget', 'parent_id' => $root->id]);

    foreach ([$root, $photos, $photos2, $target] as $directory) {
        Storage::disk($disk)->makeDirectory('assets/'.$directory->generatePath());
    }

    // An asset attached to both Photos (being moved) and its sibling Photos2, but physically
    // stored under Photos2 — the shape that exposed the bug.
    $siblingPath = 'assets/SiblingRoot/Photos2/keep.jpg';
    Storage::disk($disk)->put($siblingPath, 'sibling-content');
    $siblingAsset = Asset::factory()->create(['file_name' => 'keep.jpg', 'path' => $siblingPath]);
    $siblingAsset->directories()->attach([$photos->id, $photos2->id]);

    $movedPath = 'assets/SiblingRoot/Photos/moved.jpg';
    Storage::disk($disk)->put($movedPath, 'moved-content');
    $movedAsset = Asset::factory()->create(['file_name' => 'moved.jpg', 'path' => $movedPath]);
    $movedAsset->directories()->attach($photos->id);

    createPendingActionRequest('move_directory_structure');

    (new MoveDirectoryStructure($photos->id, $target->id, auth()->id()))->handle();

    expect($siblingAsset->fresh()->path)->toBe($siblingPath)
        ->and($movedAsset->fresh()->path)->toContain('MoveTarget/Photos/moved.jpg');
});

it('gives a raw directory copy an original AssetFile pointing at the source FileObject', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $parent = Directory::factory()->create(['name' => 'CopySource']);
    $target = Directory::factory()->create(['name' => 'CopyTarget']);

    $path = 'assets/CopySource/photo.png';
    Storage::disk($disk)->put($path, 'source-bytes');
    $asset = Asset::factory()->create(['file_name' => 'photo.png', 'path' => $path]);
    $parent->assets()->attach($asset->id);

    $sourceOriginal = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();

    createPendingActionRequest('copy_directory');

    (new CopyDirectory($parent->id, $target->id, auth()->id()))->handle();

    $copiedAsset = Asset::where('file_name', 'photo.png')->where('id', '!=', $asset->id)->firstOrFail();
    $copiedOriginal = $copiedAsset->files()->where('role', AssetFile::ROLE_ORIGINAL)->first();

    expect($copiedOriginal)->not->toBeNull()
        ->and($copiedOriginal->file_object_id)->toBe($sourceOriginal->file_object_id)
        ->and($copiedOriginal->sort)->toBe(0);

    // ADR 0009 slice 3: the copy shares the source's blob — no makeDirectory, no byte copy at the
    // copy's own Library path.
    Storage::disk($disk)->assertMissing($copiedAsset->path);
});

it('gives a raw mass-copied asset an original AssetFile pointing at the source FileObject', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $target = Directory::factory()->create(['name' => 'MassCopyTarget']);

    $path = 'assets/Root/masscopy-source.png';
    Storage::disk($disk)->put($path, 'mass-copy-bytes');
    $asset = Asset::factory()->create(['file_name' => 'masscopy-source.png', 'extension' => 'png', 'path' => $path]);

    $sourceOriginal = $asset->files()->where('role', AssetFile::ROLE_ORIGINAL)->firstOrFail();

    createPendingActionRequest('mass_copy');

    (new MassCopy([$asset->id], [], $target->id, auth()->id()))->handle();

    $copiedAsset = Asset::where('file_name', 'masscopy-source.png')->where('id', '!=', $asset->id)->firstOrFail();
    $copiedOriginal = $copiedAsset->files()->where('role', AssetFile::ROLE_ORIGINAL)->first();

    expect($copiedOriginal)->not->toBeNull()
        ->and($copiedOriginal->file_object_id)->toBe($sourceOriginal->file_object_id)
        ->and($copiedOriginal->sort)->toBe(0);

    // ADR 0009 slice 3: the copy shares the source's blob — no makeDirectory, no byte copy at the
    // copy's own Library path.
    Storage::disk($disk)->assertMissing($copiedAsset->path);
});

it('resolves unique names against dam_assets.path, not in-memory guesses, when a mass move collides with an existing file', function () {
    $target = Directory::factory()->create(['name' => 'MoveCollisionTarget']);
    $sourceA = Directory::factory()->create(['name' => 'MoveCollisionSourceA']);
    $sourceB = Directory::factory()->create(['name' => 'MoveCollisionSourceB']);

    $existing = Asset::factory()->create([
        'file_name' => 'photo.jpg',
        'path'      => 'assets/MoveCollisionTarget/photo.jpg',
    ]);
    $existing->directories()->attach($target->id);

    $assetA = Asset::factory()->create(['file_name' => 'photo.jpg', 'path' => 'assets/MoveCollisionSourceA/photo.jpg']);
    $sourceA->assets()->attach($assetA->id);
    $assetB = Asset::factory()->create(['file_name' => 'photo.jpg', 'path' => 'assets/MoveCollisionSourceB/photo.jpg']);
    $sourceB->assets()->attach($assetB->id);

    createPendingActionRequest('mass_move');

    (new MassMove([$assetA->id, $assetB->id], [], $target->id, auth()->id()))->handle();

    $names = Asset::whereIn('id', [$assetA->id, $assetB->id])->orderBy('id')->pluck('file_name')->all();

    // Both moved assets collide with the same existing name and with each other — each gets its
    // own free name (LibraryPaths, shared with C6), the pre-existing asset is left alone, and dam_assets.path's
    // UNIQUE index is never violated.
    expect($names)->toBe(['photo(1).jpg', 'photo(2).jpg'])
        ->and($existing->fresh()->file_name)->toBe('photo.jpg');
});

it('resolves unique names against dam_assets.path when a mass copy collides with an existing file', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $target = Directory::factory()->create(['name' => 'CopyCollisionTarget']);

    $existing = Asset::factory()->create([
        'file_name' => 'photo.jpg',
        'path'      => 'assets/CopyCollisionTarget/photo.jpg',
    ]);
    $existing->directories()->attach($target->id);

    $sourcePath = 'assets/Root/copy-source-photo.jpg';
    Storage::disk($disk)->put($sourcePath, 'copy-source-bytes');
    $source = Asset::factory()->create(['file_name' => 'photo.jpg', 'path' => $sourcePath]);

    createPendingActionRequest('mass_copy');

    (new MassCopy([$source->id], [], $target->id, auth()->id()))->handle();

    $copied = Asset::where('file_name', 'photo(1).jpg')->first();

    expect($copied)->not->toBeNull()
        ->and($copied->path)->toBe('assets/CopyCollisionTarget/photo(1).jpg')
        ->and($existing->fresh()->file_name)->toBe('photo.jpg');
});

// Codex review of PR #63 round 1, finding 2: MassCopy read the destination directory and derived its
// path BEFORE acquiring Directory::withTreeLock (~:60), so a rename committing while the job waited
// for the lock left copied assets with the pre-rename path prefix. The fix reads the destination and
// derives its path INSIDE the lock, alongside CopyController::copyAsset and MassMove below.
it('gives a mass-copied asset the destination\'s current path even when it is renamed exactly as the job takes the tree lock', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $target = Directory::factory()->create(['name' => 'MassCopyRaceTargetOld']);

    $sourcePath = 'assets/Root/masscopy-race.png';
    Storage::disk($disk)->put($sourcePath, 'mass-copy-race-bytes');
    $source = Asset::factory()->create(['file_name' => 'masscopy-race.png', 'extension' => 'png', 'path' => $sourcePath]);

    createPendingActionRequest('mass_copy');
    renameDirectoryOnFirstTreeLock($target->id, 'MassCopyRaceTargetNew');

    (new MassCopy([$source->id], [], $target->id, auth()->id()))->handle();

    $copied = Asset::where('file_name', 'masscopy-race.png')->where('id', '!=', $source->id)->firstOrFail();

    expect($copied->path)->toBe('assets/MassCopyRaceTargetNew/masscopy-race.png');
});

// Same race as above, on MassMove's asset branch (~:50).
it('gives a mass-moved asset the destination\'s current path even when it is renamed exactly as the job takes the tree lock', function () {
    $source = Directory::factory()->create(['name' => 'MassMoveRaceSource']);
    $target = Directory::factory()->create(['name' => 'MassMoveRaceTargetOld']);

    $assetPath = 'assets/MassMoveRaceSource/mass-move-race.jpg';
    $asset = Asset::factory()->create(['file_name' => 'mass-move-race.jpg', 'path' => $assetPath]);
    $source->assets()->attach($asset->id);

    createPendingActionRequest('mass_move');
    renameDirectoryOnFirstTreeLock($target->id, 'MassMoveRaceTargetNew');

    (new MassMove([$asset->id], [], $target->id, auth()->id()))->handle();

    expect($asset->fresh()->path)->toBe('assets/MassMoveRaceTargetNew/mass-move-race.jpg');
});

// Codex review of PR #63 round 1, finding 3: MassCopy's directory-copy branch (~:155) ran outside the
// tree lock the selected-asset copy used, each chunk in its own DB::transaction — a failure partway
// through left earlier directories and their assets committed. The fix covers selected-asset copy and
// the whole directory-copy branch with one tree-lock transaction, so a failure anywhere rolls all of it
// back. Copying a directory into itself is an existing, real failure the code already guards against;
// it is used here only to trigger that failure after an earlier directory in the same batch has
// already been fully copied.
it('rolls back every directory it already copied when a later directory in the same mass copy fails', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $target = Directory::factory()->create(['name' => 'BulkCopyTarget']);
    $okSource = Directory::factory()->create(['name' => 'BulkCopyOkSource']);

    $okPath = 'assets/BulkCopyOkSource/ok.png';
    Storage::disk($disk)->put($okPath, 'ok-bytes');
    $okAsset = Asset::factory()->create(['file_name' => 'ok.png', 'path' => $okPath]);
    $okSource->assets()->attach($okAsset->id);

    createPendingActionRequest('mass_copy');

    (new MassCopy([], [$okSource->id, $target->id], $target->id, auth()->id()))->handle();

    expect(Directory::where('parent_id', $target->id)->where('name', 'BulkCopyOkSource')->exists())->toBeFalse()
        ->and(Asset::where('file_name', 'ok.png')->where('id', '!=', $okAsset->id)->exists())->toBeFalse();

    $request = ActionRequest::findOneWhere(['event_type' => 'mass_copy', 'admin_id' => auth()->id()]);
    expect($request->status)->toBe('failed');
});

// Codex review of PR #63 round 2, finding 1: MassCopy's directory branch (~:159) consumed
// $copyableSources[$id] — loaded before Directory::withTreeLock was even requested (~:66-73) — so a
// destination reparented beneath the source while the job waited for the lock left isDescendantOf()
// comparing the fresh destination against the source's stale, pre-move interval and missing the
// prohibited relationship. Uncaught, deepCopy() then walks the newly-created copy back into the
// destination (now itself inside the source) and recurses until the depth guard aborts — a different,
// generic error from the cannot-copy guard this fixture targets, so asserting on error_message (not
// just status) is what actually distinguishes the fix from the bug.
it('refuses a mass directory copy when the destination is reparented beneath the source exactly as the job takes the tree lock', function () {
    $root = Directory::factory()->create(['name' => 'ReparentRaceRoot']);
    $source = Directory::factory()->create(['name' => 'ReparentRaceSource', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'ReparentRaceTarget', 'parent_id' => $root->id]);

    createPendingActionRequest('mass_copy');
    reparentDirectoryOnFirstTreeLock($target->id, $source->id);

    (new MassCopy([], [$source->id], $target->id, auth()->id()))->handle();

    expect(Directory::where('parent_id', $target->id)->count())->toBe(0);

    $request = ActionRequest::findOneWhere(['event_type' => 'mass_copy', 'admin_id' => auth()->id()]);
    expect($request->status)->toBe('failed')
        ->and($request->error_message)->toBe(trans('dam::app.admin.dam.index.directory.cannot-copy'));
});

// Same finding as above: a source directory deleted between selection and lock acquisition left the
// stale, pre-delete $source model non-null, so the guard above never fired and MassCopy still created
// an empty destination folder for it (deepCopy found nothing under the now-gone source id and just
// stopped) — an empty copy silently reported as success. The fix reloads the source by id inside the
// lock and skips it, the same way any other invalid selection (missing or non-copyable) already is.
it('skips a mass directory copy source deleted between selection and lock acquisition, creating nothing', function () {
    $target = Directory::factory()->create(['name' => 'DeletedSourceCopyTarget']);
    $source = Directory::factory()->create(['name' => 'DeletedSourceCopySource']);

    createPendingActionRequest('mass_copy');
    deleteDirectoryOnFirstTreeLock($source->id);

    (new MassCopy([], [$source->id], $target->id, auth()->id()))->handle();

    expect(Directory::where('parent_id', $target->id)->count())->toBe(0);

    $request = ActionRequest::findOneWhere(['event_type' => 'mass_copy', 'admin_id' => auth()->id()]);
    expect($request->status)->toBe('completed');
});

it('does not overwrite a completed move action request from the failed hook', function () {
    $disk = Directory::getAssetDisk();
    Storage::fake($disk);

    $root = Directory::factory()->create(['name' => 'Root']);
    $source = Directory::factory()->create(['name' => 'Source', 'parent_id' => $root->id]);
    $target = Directory::factory()->create(['name' => 'Target', 'parent_id' => $root->id]);

    Storage::disk($disk)->makeDirectory('assets/Root/Source');
    Storage::disk($disk)->makeDirectory('assets/Root/Target');

    createPendingActionRequest('move_directory_structure');

    $job = new MoveDirectoryStructure($source->id, $target->id, auth()->id());
    $job->handle();
    $job->failed(new RuntimeException('late worker noise'));

    $request = ActionRequest::findOneWhere([
        'event_type' => 'move_directory_structure',
        'admin_id'   => auth()->id(),
    ]);

    expect($request->status)->toBe('completed')
        ->and($request->error_message)->toBeNull();
});

it('names collided folder copies "copy", "copy 2", "copy 3" and continues the old ordinal names', function () {
    $namer = new class
    {
        use Mothership\DAM\Traits\Directory;

        public function next(string $name): string
        {
            return $this->replaceAndIncrementOrdinalCopies($name);
        }
    };

    expect($namer->next('Photos'))->toBe('Photos copy')
        ->and($namer->next('Photos copy'))->toBe('Photos copy 2')
        ->and($namer->next('Photos copy 2'))->toBe('Photos copy 3')
        ->and($namer->next('Photos 1th copy'))->toBe('Photos copy 2');
});
