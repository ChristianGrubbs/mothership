<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Jobs\CopyDirectory;
use Mothership\DAM\Jobs\CopyDirectoryStructure;
use Mothership\DAM\Jobs\MassCopy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Sharing slice 2b: under `lists`, a copy includes only what the actor can access (spec, "Copies"). A
 * folder they cannot open is skipped with its whole subtree, path-only included; a file keeps its own
 * list, so it is filtered on its own accessibility rather than its folder's. Under `grants` nothing here
 * changes: every existing copy test keeps passing unchanged, and each case below pins that explicitly.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());

    $this->actor = copyGuardAdmin([
        'dam', 'dam.directory', 'dam.directory.store', 'dam.directory.copy_structure', 'dam.asset', 'dam.asset.upload',
    ]);
    $this->actingAs($this->actor, 'admin');
});

function copyGuardAdmin(array $permissions): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function copyGuardModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/**
 * A fixture asset with a real original FileObject, through the legacy-ingest fallback (put bytes then
 * create). created_by fills from the authenticated actor on create (Asset::creating); forced back to
 * null so a fixture asset starts genuinely ownerless and each test grants access explicitly, rather than
 * the actor already owning everything they create.
 */
function copyGuardAsset(string $fileName, int $directoryId): Asset
{
    $disk = Directory::getAssetDisk();
    $path = 'assets/CopyGuard/'.$fileName;
    Storage::disk($disk)->put($path, 'copy-guard-bytes-'.$fileName);

    $asset = Asset::factory()->create(['file_name' => $fileName, 'path' => $path]);
    $asset->directories()->attach($directoryId);
    DB::table('dam_assets')->where('id', $asset->id)->update(['created_by' => null]);

    return $asset;
}

/** Same reasoning as copyGuardAsset(): created_by forced back to null after Directory::creating fills it. */
function copyGuardDir(string $name, ?int $parentId = null): Directory
{
    $directory = Directory::create(['name' => $name, 'parent_id' => $parentId]);
    DB::table('dam_directories')->where('id', $directory->id)->update(['created_by' => null]);

    return $directory->fresh();
}

// --- CopyController::copyAsset — a file's own list, not its folder's, under lists --------------

it('copies a file the actor cannot see through its folder but can see through its own list, under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    $folder = copyGuardDir('CopyAsset own-list folder');
    $target = copyGuardDir('CopyAsset own-list target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);
    // $folder carries no list of its own: the actor cannot open it directly.

    $asset = copyGuardAsset('own-list.png', $folder->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->actor->id]);

    $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ])->assertStatus(201);
});

it('refuses to copy a file whose own list excludes the actor even though its folder is listed, under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    $folder = copyGuardDir('CopyAsset narrow-file folder');
    $target = copyGuardDir('CopyAsset narrow-file target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $folder->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);

    $asset = copyGuardAsset('narrow-file.png', $folder->id);
    // A file's own list never widens from its folder's (ADR 0010): given to somebody else, not the
    // actor, so the folder's own listing must not carry the copy through.
    $other = copyGuardAdmin(['dam']);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $other->id]);

    $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ])->assertStatus(422)->assertJsonValidationErrors('asset_id');
});

it('copies through the any-directory rule under grants, exactly as before this slice', function () {
    copyGuardModel(AccessModel::GRANTS);
    $folder = copyGuardDir('CopyAsset grants folder');
    $target = copyGuardDir('CopyAsset grants target');
    foreach ([$folder->id, $target->id] as $directoryId) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directoryId, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $asset = copyGuardAsset('grants.png', $folder->id);

    $this->postJson(route('admin.dam.explorer.copy.asset'), [
        'asset_id'            => $asset->id,
        'target_directory_id' => $target->id,
    ])->assertStatus(201);
});

// --- CopyController::massCopy — assets and directories -------------------------------------------

it('mass-copies only the files the actor can see, skipping the rest silently, under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    $folder = copyGuardDir('MassCopy asset folder');
    $target = copyGuardDir('MassCopy asset target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $folder->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);

    $visible = copyGuardAsset('mc-visible.png', $folder->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $this->actor->id]);

    $hidden = copyGuardAsset('mc-hidden.png', $folder->id);
    // The folder's own listing never widens $hidden's (ADR 0010): no user-access row of its own.

    (new MassCopy([$visible->id, $hidden->id], [], $target->id, $this->actor->id))->handle();

    expect(Asset::where('file_name', 'mc-visible.png')->where('id', '!=', $visible->id)->exists())->toBeTrue()
        ->and(Asset::where('file_name', 'mc-hidden.png')->where('id', '!=', $hidden->id)->exists())->toBeFalse();
});

it('mass-copies every selected file under grants, unfiltered by its own list', function () {
    copyGuardModel(AccessModel::GRANTS);
    $folder = copyGuardDir('MassCopy grants asset folder');
    $target = copyGuardDir('MassCopy grants asset target');
    foreach ([$folder->id, $target->id] as $directoryId) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directoryId, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $asset = copyGuardAsset('mc-grants.png', $folder->id);

    (new MassCopy([$asset->id], [], $target->id, $this->actor->id))->handle();

    expect(Asset::where('file_name', 'mc-grants.png')->where('id', '!=', $asset->id)->exists())->toBeTrue();
});

it('mass-copies a source subtree, skipping a child folder the actor cannot see, under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    $source = copyGuardDir('MassCopy dir source');
    $visibleChild = copyGuardDir('MassCopy dir visible child', $source->id);
    $hiddenChild = copyGuardDir('MassCopy dir hidden child', $source->id);
    $target = copyGuardDir('MassCopy dir target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $visibleChild->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);
    // $hiddenChild carries no list of its own: the actor cannot open it, so it and everything it
    // might hold is skipped, path-only included (spec, "Copies").

    (new MassCopy([], [$source->id], $target->id, $this->actor->id))->handle();

    $newRoot = Directory::where('name', 'MassCopy dir source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('name', 'MassCopy dir visible child')->where('parent_id', $newRoot->id)->exists())->toBeTrue()
        ->and(Directory::where('name', 'MassCopy dir hidden child')->where('parent_id', $newRoot->id)->exists())->toBeFalse();
});

it('MassCopy never locks a file whose own list makes it accessible but a hidden ancestor sits between it and the selected source (Codex review of PR #75 round 2, finding 4)', function () {
    copyGuardModel(AccessModel::LISTS);
    $source = copyGuardDir('MassCopy reachability source');
    $hiddenChild = copyGuardDir('MassCopy reachability hidden child', $source->id);
    $target = copyGuardDir('MassCopy reachability target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);
    $hiddenFile = copyGuardAsset('mc-reachability-hidden.png', $hiddenChild->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $hiddenFile->id, 'admin_id' => $this->actor->id]);

    $job = Mockery::mock(MassCopy::class, [[], [$source->id], $target->id, $this->actor->id])->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('lockSourceAssets')
        ->once()
        ->withArgs(function (array $ids) use ($hiddenFile) {
            expect($ids)->not->toContain($hiddenFile->id);

            return true;
        })
        ->passthru();

    $job->handle();

    $newRoot = Directory::where('name', 'MassCopy reachability source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('parent_id', $newRoot->id)->count())->toBe(0)
        ->and(Asset::where('file_name', 'mc-reachability-hidden.png')->count())->toBe(1);
});

it('mass-copies every descendant of a source subtree under grants, unfiltered', function () {
    copyGuardModel(AccessModel::GRANTS);
    $source = copyGuardDir('MassCopy grants dir source');
    $child = copyGuardDir('MassCopy grants dir child', $source->id);
    $target = copyGuardDir('MassCopy grants dir target');
    foreach ([$source->id, $target->id] as $directoryId) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directoryId, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    (new MassCopy([], [$source->id], $target->id, $this->actor->id))->handle();

    $newRoot = Directory::where('name', 'MassCopy grants dir source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('name', 'MassCopy grants dir child')->where('parent_id', $newRoot->id)->exists())->toBeTrue();
});

// --- CopyDirectory job (Explorer::copyDirectory) --------------------------------------------------

it('CopyDirectory skips a hidden child folder and its file under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    $source = copyGuardDir('CopyDirectory source');
    $hiddenChild = copyGuardDir('CopyDirectory hidden child', $source->id);
    $target = copyGuardDir('CopyDirectory target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);
    copyGuardAsset('cd-hidden.png', $hiddenChild->id);

    (new CopyDirectory($source->id, $target->id, $this->actor->id))->handle();

    $newRoot = Directory::where('name', 'CopyDirectory source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('parent_id', $newRoot->id)->count())->toBe(0)
        ->and(Asset::where('file_name', 'cd-hidden.png')->count())->toBe(1);
});

it('CopyDirectory never locks a file whose own list makes it accessible but a hidden ancestor sits between it and the source (Codex review of PR #75 round 2, finding 4)', function () {
    copyGuardModel(AccessModel::LISTS);
    $source = copyGuardDir('Reachability source');
    $hiddenChild = copyGuardDir('Reachability hidden child', $source->id);
    $target = copyGuardDir('Reachability target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);
    // $hiddenChild carries no list of its own: the actor cannot open it.
    $hiddenFile = copyGuardAsset('reachability-hidden.png', $hiddenChild->id);
    // But the file's own list names the actor directly (ADR 0010: a file's list never widens from its
    // folder's, but it also never narrows from it) — individually accessible on its own, even though
    // the only path to it crosses a folder the actor cannot open.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $hiddenFile->id, 'admin_id' => $this->actor->id]);

    $job = Mockery::mock(CopyDirectory::class, [$source->id, $target->id, $this->actor->id])->makePartial();
    $job->shouldAllowMockingProtectedMethods();
    $job->shouldReceive('lockSourceAssets')
        ->once()
        ->withArgs(function (array $ids) use ($hiddenFile) {
            expect($ids)->not->toContain($hiddenFile->id);

            return true;
        })
        ->passthru();

    $job->handle();

    $newRoot = Directory::where('name', 'Reachability source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('parent_id', $newRoot->id)->count())->toBe(0)
        ->and(Asset::where('file_name', 'reachability-hidden.png')->count())->toBe(1);
});

it('CopyDirectory copies every descendant and file under grants, unfiltered', function () {
    copyGuardModel(AccessModel::GRANTS);
    $source = copyGuardDir('CopyDirectory grants source');
    $child = copyGuardDir('CopyDirectory grants child', $source->id);
    $target = copyGuardDir('CopyDirectory grants target');
    foreach ([$source->id, $target->id] as $directoryId) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directoryId, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }
    copyGuardAsset('cd-grants.png', $child->id);

    (new CopyDirectory($source->id, $target->id, $this->actor->id))->handle();

    $newRoot = Directory::where('name', 'CopyDirectory grants source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('name', 'CopyDirectory grants child')->where('parent_id', $newRoot->id)->exists())->toBeTrue()
        ->and(Asset::where('file_name', 'cd-grants.png')->count())->toBe(2);
});

// --- CopyController::copyStructureTo / CopyDirectoryStructure job (folders only, no files) --------

it('copyStructureTo skips a hidden descendant folder under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    $source = copyGuardDir('StructureTo source');
    $hiddenChild = copyGuardDir('StructureTo hidden child', $source->id);
    $target = copyGuardDir('StructureTo target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);

    $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), [
        'source_id' => $source->id,
        'target_id' => $target->id,
    ])->assertStatus(202);

    $newRoot = Directory::where('name', 'StructureTo source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('parent_id', $newRoot->id)->count())->toBe(0);
});

it("refuses copyStructureTo when the source's access was revoked after this request's own cache warmed it, before the tree lock (Codex review of PR #75 round 2, finding 1)", function () {
    copyGuardModel(AccessModel::LISTS);
    $source = copyGuardDir('Stale-cache structure source');
    $target = copyGuardDir('Stale-cache structure target');
    // The listed child keeps the source in the actor's tree after the revocation below, so the request
    // gets past the visibility check and the stale cache is what the post-lock re-check must catch.
    $child = copyGuardDir('Stale-cache structure child', $source->id);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $child->id, 'admin_id' => $this->actor->id],
    ]);

    // Warms the request-scoped DirectoryPermissionService's cached reader the same way the
    // controller's own pre-lock canAccess($sourceId) call would: DAMServiceProvider binds this
    // scoped(), so the object this test resolves is the same one the controller's constructor
    // injection reuses for the rest of this request.
    app(DirectoryPermissionService::class)->canAccess($source->id);

    // Stands in for a cascade committing while the request waited for the tree lock: the bug this
    // proves is the cached reader object surviving into the post-lock check, not the database
    // snapshot (that hazard is finding 2's own, proved with real concurrency in
    // MoveCopyAssetLockCommittedTest), so a plain write here is enough.
    DB::table('dam_directory_user_access')->where('directory_id', $source->id)->delete();

    $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), [
        'source_id' => $source->id,
        'target_id' => $target->id,
    ])->assertStatus(403);

    expect(Directory::where('name', 'Stale-cache structure source')->where('id', '!=', $source->id)->exists())->toBeFalse();
});

it('copyStructureTo copies every descendant folder under grants, unfiltered', function () {
    copyGuardModel(AccessModel::GRANTS);
    $source = copyGuardDir('StructureTo grants source');
    $child = copyGuardDir('StructureTo grants child', $source->id);
    $target = copyGuardDir('StructureTo grants target');
    foreach ([$source->id, $target->id] as $directoryId) {
        DB::table('dam_directory_role')->insert(['directory_id' => $directoryId, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->postJson(route('admin.dam.explorer.directory.copy_structure_to'), [
        'source_id' => $source->id,
        'target_id' => $target->id,
    ])->assertStatus(202);

    $newRoot = Directory::where('name', 'StructureTo grants source')->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('name', 'StructureTo grants child')->where('parent_id', $newRoot->id)->exists())->toBeTrue();
});

it('CopyDirectoryStructure job skips a hidden descendant folder under lists', function () {
    copyGuardModel(AccessModel::LISTS);
    // setDirectoryNameForCopy() requires a non-null parent_id: the job copies the source as a sibling
    // of itself, under its own parent, never at the library root.
    $parent = copyGuardDir('StructureJob parent');
    $source = copyGuardDir('StructureJob source', $parent->id);
    $hiddenChild = copyGuardDir('StructureJob hidden child', $source->id);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $source->id, 'admin_id' => $this->actor->id]);

    (new CopyDirectoryStructure($source->id, $this->actor->id))->handle();

    // The copy lands beside $source, under their shared parent, and setDirectoryNameForCopy() gives it
    // a name of its own — so the copy is "$parent's other child", not a name match on $source's own.
    $newRoot = Directory::where('parent_id', $parent->id)->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('parent_id', $newRoot->id)->count())->toBe(0);
});

it('CopyDirectoryStructure job copies every descendant folder under grants, unfiltered', function () {
    copyGuardModel(AccessModel::GRANTS);
    $parent = copyGuardDir('StructureJob grants parent');
    $source = copyGuardDir('StructureJob grants source', $parent->id);
    $child = copyGuardDir('StructureJob grants child', $source->id);

    (new CopyDirectoryStructure($source->id, $this->actor->id))->handle();

    $newRoot = Directory::where('parent_id', $parent->id)->where('id', '!=', $source->id)->firstOrFail();
    expect(Directory::where('name', 'StructureJob grants child')->where('parent_id', $newRoot->id)->exists())->toBeTrue();
});
