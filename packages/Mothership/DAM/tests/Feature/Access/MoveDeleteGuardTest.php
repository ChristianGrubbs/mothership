<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mothership\DAM\Enums\EventType;
use Mothership\DAM\Jobs\DeleteDirectory;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * Sharing slice 2b's move and delete guard (ADR 0010): a non-full-admin may not move or delete a folder
 * whose subtree holds any folder or file they cannot see, and only under `lists` — `grants` never asks the
 * question, so every existing move/delete test keeps passing unchanged.
 *
 * Every fixture below gives the actor direct access to the folder they operate on but leaves one
 * descendant unlisted, unowned and outside their groups, so the guard's refusal is never confused with the
 * pre-existing "can the actor even reach the folder" checks these routes already run.
 */

beforeEach(function () {
    $this->actor = guardAdmin(['dam', 'dam.directory', 'dam.directory.moved', 'dam.directory.destroy', 'dam.directory.download_zip']);
    $this->root = Directory::create(['name' => 'Guard root', 'parent_id' => null]);
    $this->hiddenChild = Directory::create(['name' => 'Guard hidden child', 'parent_id' => $this->root->id]);
    $this->target = Directory::create(['name' => 'Guard target', 'parent_id' => null]);

    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $this->root->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $this->target->id, 'admin_id' => $this->actor->id],
    ]);
    // $this->hiddenChild carries no list of its own, and nobody owns it: the actor cannot see it.

    // The grants-parity cases run the very same actor under `grants`, so they need the legacy grant
    // table too — without it every "under grants" case would 403 on the pre-existing access check
    // this slice never touches, not prove anything about the new guard.
    DB::table('dam_directory_role')->insert([
        ['directory_id' => $this->root->id, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()],
        ['directory_id' => $this->target->id, 'role_id' => $this->actor->role_id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($this->actor, 'admin');
});

function guardAdmin(array $permissions, string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function guardModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/** A fixture asset with real, readable bytes — copyGuardAsset()'s pattern (CopyFilteringTest.php). */
function guardBlobAsset(string $fileName, int $directoryId): Asset
{
    $disk = Directory::getAssetDisk();
    $path = 'assets/Guard/'.$fileName;
    Storage::disk($disk)->put($path, 'guard-zip-bytes-'.$fileName);

    $asset = Asset::factory()->create(['file_name' => $fileName, 'path' => $path]);
    $asset->directories()->attach($directoryId);
    DB::table('dam_assets')->where('id', $asset->id)->update(['created_by' => null]);

    return $asset;
}

/** The real streamed zip's own entry names, read back from the bytes the response actually sent. */
function guardZipEntries(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'guard-zip-').'.zip';
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($path);

    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $zip->close();
    unlink($path);

    return $entries;
}

// --- DirectoryController::moved ---------------------------------------------------------------

it('refuses a single folder move under lists when the subtree holds an item the actor cannot see', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
});

it('moves the same folder under grants exactly as before this slice', function () {
    guardModel(AccessModel::GRANTS);
    Bus::fake();

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

// --- MassMoveController::move (folder branch) -------------------------------------------------

it('refuses the whole mass-move request when any selected folder is blocked, under lists', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    $clean = Directory::create(['name' => 'Guard clean sibling', 'parent_id' => null]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $clean->id, 'admin_id' => $this->actor->id]);

    $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [$clean->id, $this->root->id],
        'target_directory_id' => $this->target->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));

    Bus::assertNotDispatched(MassMove::class);
});

it('mass-moves the same folders under grants exactly as before this slice', function () {
    guardModel(AccessModel::GRANTS);
    Bus::fake();

    $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => [],
        'directory_ids'       => [$this->root->id],
        'target_directory_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MassMove::class);
});

// --- DirectoryController::destroy / massDestroy -------------------------------------------------

it('refuses a single folder delete under lists when the subtree holds an item the actor cannot see', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();

    $this->deleteJson(route('admin.dam.directory.destroy', $this->root->id))
        ->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));

    Bus::assertNotDispatched(DeleteDirectory::class);
});

it('deletes the same folder under grants exactly as before this slice', function () {
    guardModel(AccessModel::GRANTS);
    Bus::fake();

    $this->deleteJson(route('admin.dam.directory.destroy', $this->root->id))->assertOk();

    Bus::assertDispatched(DeleteDirectory::class);
});

it('refuses the whole massDestroy request rather than delete part of it, under lists', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    $clean = Directory::create(['name' => 'Guard clean mass-destroy sibling', 'parent_id' => null]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $clean->id, 'admin_id' => $this->actor->id]);

    $this->postJson(route('admin.dam.directory.mass_destroy'), [
        'indices' => [$clean->id, $this->root->id],
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));

    Bus::assertNotDispatched(DeleteDirectory::class);
});

it('mass-destroys the same folders under grants exactly as before this slice', function () {
    guardModel(AccessModel::GRANTS);
    Bus::fake();

    $this->postJson(route('admin.dam.directory.mass_destroy'), [
        'indices' => [$this->root->id],
    ])->assertOk();

    Bus::assertDispatchedTimes(DeleteDirectory::class, 1);
});

// --- Job re-checks: the subtree changed between the controller's check and the job's ----------

it('MoveDirectoryStructure fails the action request when the root itself — not a descendant — loses access before the job ran (Codex review of PR #75 round 2, finding 9)', function () {
    guardModel(AccessModel::LISTS);
    // Authorize the one pre-existing descendant so the root's own revocation is the only reason left
    // for the guard to refuse — the dispatch-time check saw $this->root as accessible; this is that
    // access disappearing while the job sat queued, not a descendant that was already hidden.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->hiddenChild->id, 'admin_id' => $this->actor->id]);
    DB::table('dam_directory_user_access')->where('directory_id', $this->root->id)->where('admin_id', $this->actor->id)->delete();

    $requestType = EventType::MOVE_DIRECTORY_STRUCTURE->value;
    DB::table('dam_action_request')->insert([
        'event_type' => $requestType, 'admin_id' => $this->actor->id, 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    (new MoveDirectoryStructure($this->root->id, $this->target->id, $this->actor->id))->handle();

    expect(DB::table('dam_action_request')->where(['event_type' => $requestType, 'admin_id' => $this->actor->id])->value('status'))
        ->toBe('failed');
    expect($this->root->fresh()->parent_id)->not->toBe($this->target->id);
});

it('MoveDirectoryStructure fails the action request when the subtree became blocked before the job ran', function () {
    guardModel(AccessModel::LISTS);
    $requestType = EventType::MOVE_DIRECTORY_STRUCTURE->value;
    DB::table('dam_action_request')->insert([
        'event_type' => $requestType, 'admin_id' => $this->actor->id, 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    (new MoveDirectoryStructure($this->root->id, $this->target->id, $this->actor->id))->handle();

    expect(DB::table('dam_action_request')->where(['event_type' => $requestType, 'admin_id' => $this->actor->id])->value('status'))
        ->toBe('failed');
    expect($this->root->fresh()->parent_id)->not->toBe($this->target->id);
});

it('MoveDirectoryStructure still moves the folder under grants', function () {
    guardModel(AccessModel::GRANTS);

    (new MoveDirectoryStructure($this->root->id, $this->target->id, $this->actor->id))->handle();

    expect($this->root->fresh()->parent_id)->toBe($this->target->id);
});

it('MassMove skips a blocked folder and fails the request when nothing else moved, under lists', function () {
    guardModel(AccessModel::LISTS);
    $requestType = EventType::MASS_MOVE->value;
    DB::table('dam_action_request')->insert([
        'event_type' => $requestType, 'admin_id' => $this->actor->id, 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    (new MassMove([], [$this->root->id], $this->target->id, $this->actor->id))->handle();

    expect($this->root->fresh()->parent_id)->not->toBe($this->target->id);
    expect(DB::table('dam_action_request')->where(['event_type' => $requestType, 'admin_id' => $this->actor->id])->value('status'))
        ->toBe('failed');
});

it('MassMove reports success when a clean folder moves even though a blocked one is skipped, under lists', function () {
    guardModel(AccessModel::LISTS);
    $clean = Directory::create(['name' => 'Guard mass-move clean', 'parent_id' => null]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $clean->id, 'admin_id' => $this->actor->id]);
    $requestType = EventType::MASS_MOVE->value;
    DB::table('dam_action_request')->insert([
        'event_type' => $requestType, 'admin_id' => $this->actor->id, 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    (new MassMove([], [$this->root->id, $clean->id], $this->target->id, $this->actor->id))->handle();

    expect($clean->fresh()->parent_id)->toBe($this->target->id)
        ->and($this->root->fresh()->parent_id)->not->toBe($this->target->id);
    expect(DB::table('dam_action_request')->where(['event_type' => $requestType, 'admin_id' => $this->actor->id])->value('status'))
        ->toBe('completed');
});

it('DeleteDirectory refuses the delete inside the tree lock when the subtree holds a hidden item, under lists', function () {
    guardModel(AccessModel::LISTS);

    (new DeleteDirectory($this->root->id, $this->actor->id))->handle();

    expect(Directory::find($this->root->id))->not->toBeNull();
    expect(Directory::find($this->hiddenChild->id))->not->toBeNull();
});

it('DeleteDirectory still deletes the subtree under grants', function () {
    guardModel(AccessModel::GRANTS);

    (new DeleteDirectory($this->root->id, $this->actor->id))->handle();

    expect(Directory::find($this->root->id))->toBeNull();
    expect(Directory::find($this->hiddenChild->id))->toBeNull();
});

// --- Guard semantics: who counts as able to see hidden content (Codex review of PR #75, finding 10) --

it('blocks a move when the subtree holds a hidden file directly, with no hidden folder anywhere in it (Codex review of PR #75 round 2, finding 8)', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    // beforeEach's own $this->hiddenChild sits inside $this->root and carries no list of its own — left
    // as it was, a broken file guard would still 422 on the hidden folder alone, proving nothing about
    // files. Authorized here so the file below is the subtree's one and only inaccessible item.
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->hiddenChild->id, 'admin_id' => $this->actor->id]);

    $hiddenFile = Asset::factory()->create(['file_name' => 'guard-file-only.jpg']);
    $hiddenFile->directories()->attach($this->root->id);
    DB::table('dam_assets')->where('id', $hiddenFile->id)->update(['created_by' => null]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));

    Bus::assertNotDispatched(MoveDirectoryStructure::class);

    // The same file, once it is accessible, stops tripping the guard — the 422 above was this file's
    // doing, not some fixture the test never controlled.
    DB::table('dam_asset_user_access')->insert(['asset_id' => $hiddenFile->id, 'admin_id' => $this->actor->id]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it('never blocks a move over a hidden file placed outside the moved subtree entirely (Codex review of PR #75 round 2, finding 8)', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->hiddenChild->id, 'admin_id' => $this->actor->id]);

    // A sibling of $this->root, never part of the subtree being moved — the actor cannot see this
    // folder or its file either, but neither is in the way of the move at all.
    $outside = Directory::create(['name' => 'Guard outside sibling', 'parent_id' => null]);
    $outsideFile = Asset::factory()->create(['file_name' => 'guard-outside.jpg']);
    $outsideFile->directories()->attach($outside->id);
    DB::table('dam_assets')->where('id', $outsideFile->id)->update(['created_by' => null]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it('never blocks the move for content the actor owns, even with no list row of its own', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    DB::table('dam_directories')->where('id', $this->hiddenChild->id)->update(['created_by' => $this->actor->id]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it("never blocks the move for content granted through the actor's own group", function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    $groupId = DB::table('dam_groups')->insertGetId(['name' => 'Guard crew', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_group_members')->insert(['group_id' => $groupId, 'admin_id' => $this->actor->id]);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $this->hiddenChild->id, 'group_id' => $groupId]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it('blocks the move for a disabled actor even with an explicit grant on the root itself (Codex review of PR #75 round 2, finding 9)', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->hiddenChild->id, 'admin_id' => $this->actor->id]);
    $disabledAdmin = guardAdmin(['dam', 'dam.directory', 'dam.directory.moved']);
    DB::table('admins')->where('id', $disabledAdmin->id)->update(['status' => 0]);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $this->root->id, 'admin_id' => $disabledAdmin->id],
        ['directory_id' => $this->target->id, 'admin_id' => $disabledAdmin->id],
    ]);
    $this->actingAs($disabledAdmin, 'admin');

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));

    Bus::assertNotDispatched(MoveDirectoryStructure::class);
});

it('never blocks the move for content granted to Everyone', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    $everyoneId = DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
    DB::table('dam_directory_group_access')->insert(['directory_id' => $this->hiddenChild->id, 'group_id' => $everyoneId]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it('never blocks the move for a file placed in both the moved folder and its hidden child, as long as the file itself is accessible (Codex review of PR #75 round 2, finding 9)', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->hiddenChild->id, 'admin_id' => $this->actor->id]);

    // Multiply placed: the same file, attached to the root being moved and to its still-inaccessible
    // sibling child — accessibility is the file's own list, never which folder counted it.
    $shared = Asset::factory()->create(['file_name' => 'guard-multiply-placed.jpg']);
    DB::table('dam_assets')->where('id', $shared->id)->update(['created_by' => null]);
    $shared->directories()->attach([$this->root->id, $this->hiddenChild->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $shared->id, 'admin_id' => $this->actor->id]);

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

it('caps a mass-move request at the same boundary the preview endpoint enforces (Codex review of PR #75, finding 6, endpoint cap boundary)', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    DB::table('roles')->where('id', $this->actor->role_id)->update([
        'permissions' => json_encode(['dam', 'dam.directory', 'dam.directory.moved', 'dam.asset', 'dam.asset.moved']),
    ]);

    $this->postJson(route('admin.dam.explorer.mass_move'), [
        'asset_ids'           => range(1, 501),
        'directory_ids'       => [],
        'target_directory_id' => $this->target->id,
    ])->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.too-many-move-ids'));

    Bus::assertNotDispatched(MassMove::class);
});

it('never blocks the move for a full admin, regardless of what the subtree hides', function () {
    guardModel(AccessModel::LISTS);
    Bus::fake();
    $fullAdmin = guardAdmin(['dam', 'dam.directory', 'dam.directory.moved'], 'all');
    $this->actingAs($fullAdmin, 'admin');

    $this->postJson(route('admin.dam.directory.moved'), [
        'move_item_id'  => $this->root->id,
        'new_parent_id' => $this->target->id,
    ])->assertOk();

    Bus::assertDispatched(MoveDirectoryStructure::class);
});

// --- Admin zip download (DirectoryController::downloadArchive) ---------------------------------
//
// buildZipStreamFromAssets() resolves real bytes through ContentStore, so these cases prove the
// filter is applied by proving its effect on the query buildZipStreamFromAssets() receives, through
// the "no accessible file leaves the folder empty" branch downloadArchive() already has, rather than
// parsing a real zip's content (a separate concern DirectoryTest already covers unfiltered).

it('treats a folder holding only an inaccessible file as empty under lists', function () {
    guardModel(AccessModel::LISTS);

    $hidden = Asset::factory()->create(['file_name' => 'guard-hidden.jpg']);
    $hidden->directories()->attach($this->root->id);
    // created_by fills from the authenticated actor on create (Asset::creating); forced back to null
    // here so $hidden truly has no owner, and no list row of its own — the folder's own list on
    // $this->root never widens a file that has a narrower list of its own (ADR 0010).
    DB::table('dam_assets')->where('id', $hidden->id)->update(['created_by' => null]);

    $response = $this->get(route('admin.dam.directory.zip_download', $this->root->id));

    $response->assertRedirect();
    expect(session('error'))->toBe(trans('dam::app.admin.dam.index.directory.empty-directory'));
});

it("zips exactly the accessible files under lists, a hidden co-tenant's bytes never included (Codex review of PR #75 round 2, finding 8)", function () {
    guardModel(AccessModel::LISTS);
    Storage::fake(Directory::getAssetDisk());

    $visible = guardBlobAsset('guard-visible.jpg', $this->root->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $this->actor->id]);

    // $this->hiddenChild (beforeEach) holds one file of its own — inaccessible together with the
    // folder, path-only included — and the root has a second, directly hidden co-tenant of $visible.
    $hiddenInChild = guardBlobAsset('guard-hidden-in-child.jpg', $this->hiddenChild->id);
    $hiddenCoTenant = guardBlobAsset('guard-hidden-co-tenant.jpg', $this->root->id);

    $response = $this->get(route('admin.dam.directory.zip_download', $this->root->id));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/zip');

    $entries = guardZipEntries($response);

    expect($entries)->toBe(['Guard/guard-visible.jpg'])
        ->and($entries)->not->toContain('Guard/guard-hidden-in-child.jpg')
        ->and($entries)->not->toContain('Guard/guard-hidden-co-tenant.jpg');
});

it('never omits a file under grants when its folder is not directly granted and the role has inherit_children disabled, unfiltered (Codex review of PR #75 round 2, finding 8)', function () {
    guardModel(AccessModel::GRANTS);
    Storage::fake(Directory::getAssetDisk());

    DB::table('dam_role_settings')->updateOrInsert(
        ['role_id' => $this->actor->role_id],
        ['inherit_children' => false, 'all_directories' => false]
    );

    $rootFile = guardBlobAsset('guard-grants-root.jpg', $this->root->id);
    // $this->hiddenChild is granted neither directly nor, with inherit_children off, through the root's
    // own grant — yet downloadArchive() never filters under grants (finding 3), so its file still zips.
    $childFile = guardBlobAsset('guard-grants-child.jpg', $this->hiddenChild->id);

    $response = $this->get(route('admin.dam.directory.zip_download', $this->root->id));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/zip');

    $entries = guardZipEntries($response);

    expect($entries)->toEqualCanonicalizing(['Guard/guard-grants-root.jpg', 'Guard/guard-grants-child.jpg']);
});
