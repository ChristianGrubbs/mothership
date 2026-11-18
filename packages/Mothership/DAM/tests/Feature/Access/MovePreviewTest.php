<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * GET dam/access/move-preview (sharing slice 2b, `AccessController::movePreview`): how many folders and
 * files would end up with a different access list if the move happened now. 404 under `grants` — this
 * endpoint does not exist there — and 404 whenever the actor cannot open one of the ids or the target, so
 * it never confirms an id exists that some other route would not already have.
 */

beforeEach(function () {
    $this->actor = previewAdmin(['dam', 'dam.asset', 'dam.asset.moved', 'dam.directory', 'dam.directory.moved']);
    $this->actingAs($this->actor, 'admin');
});

function previewAdmin(array $permissions, string $permissionType = 'custom'): Admin
{
    $role = Role::factory()->create(['permission_type' => $permissionType, 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

function previewModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

/** created_by fills from the authenticated actor on create (Directory::creating); forced back to null. */
function previewDir(string $name, ?int $parentId = null): Directory
{
    $directory = Directory::create(['name' => $name, 'parent_id' => $parentId]);
    DB::table('dam_directories')->where('id', $directory->id)->update(['created_by' => null]);

    return $directory->fresh();
}

/** Same reasoning as previewDir(): created_by forced back to null after Asset::creating fills it. */
function previewAsset(): Asset
{
    $asset = Asset::factory()->create();
    DB::table('dam_assets')->where('id', $asset->id)->update(['created_by' => null]);

    return $asset->fresh();
}

function previewUrl(string $type, array $ids, int $target): string
{
    $query = ['type' => $type, 'target' => $target];

    return '/admin/dam/access/move-preview?'.http_build_query($query).'&'.collect($ids)->map(fn ($id) => 'ids[]='.$id)->implode('&');
}

it('answers 404 on every state other than exactly lists', function (string $state) {
    previewModel($state);
    $target = previewDir('Preview 404 target');
    $asset = previewAsset();

    $this->getJson(previewUrl('asset', [$asset->id], $target->id))->assertNotFound();
})->with([AccessModel::GRANTS, 'pending:preview-run', 'nonsense']);

it('404s when the actor cannot open the target, confirming nothing about its existence', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview hidden target');
    // No list row and no owner for $target: the actor cannot open it.
    $asset = previewAsset();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->actor->id]);

    $this->getJson(previewUrl('asset', [$asset->id], $target->id))->assertNotFound();
});

it('404s when the actor cannot open one of the ids', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview open target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);
    $asset = previewAsset();
    // No list row and no owner for $asset: the actor cannot open it either.

    $this->getJson(previewUrl('asset', [$asset->id], $target->id))->assertNotFound();
});

it('403s without the move capability the type requires', function () {
    previewModel(AccessModel::LISTS);
    $noCapability = previewAdmin(['dam']);
    $this->actingAs($noCapability, 'admin');

    $target = previewDir('Preview no-cap target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $noCapability->id]);
    $asset = previewAsset();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $noCapability->id]);

    $this->getJson(previewUrl('asset', [$asset->id], $target->id))->assertForbidden();
});

it('422s with the guard\'s own message when the move would itself be refused', function () {
    previewModel(AccessModel::LISTS);
    $folder = previewDir('Preview refused folder');
    $hiddenChild = previewDir('Preview refused hidden child', $folder->id);
    $target = previewDir('Preview refused target');
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $folder->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $target->id, 'admin_id' => $this->actor->id],
    ]);
    // $hiddenChild carries no list and no owner: the actor cannot see it, which is what makes moving
    // $folder a refused move rather than merely one that changes visibility.

    $this->getJson(previewUrl('directory', [$folder->id], $target->id))
        ->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.access.move-blocked'));
});

it('counts a single asset move that would change who can see it', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview asset count target');
    $friend = previewAdmin(['dam']);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $friend->id]);
    // The actor reaches the target through ownership rather than its own list, so the target's list
    // (friend only) can differ from the asset's (the actor only) without the actor losing access to ask.
    DB::table('dam_directories')->where('id', $target->id)->update(['created_by' => $this->actor->id]);

    $asset = previewAsset();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->actor->id]);

    $this->getJson(previewUrl('asset', [$asset->id], $target->id))
        ->assertOk()
        ->assertJson(['folders' => 0, 'files' => 1]);
});

it('counts zero folders and files when the whole subtree already carries the target\'s exact list', function () {
    previewModel(AccessModel::LISTS);
    $friend = previewAdmin(['dam']);
    $target = previewDir('Preview exact-match target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $friend->id]);
    // The actor reaches $target and $child through ownership, never through a list row of their own —
    // a list row would change the very sets this test compares. $target's and $child's own lists stay
    // exactly {friend}, which is the equality the comparison must find.
    DB::table('dam_directories')->where('id', $target->id)->update(['created_by' => $this->actor->id]);

    $source = previewDir('Preview exact-match source');
    $child = previewDir('Preview exact-match child', $source->id);
    DB::table('dam_directories')->where('id', $child->id)->update(['created_by' => $this->actor->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $child->id, 'admin_id' => $friend->id]);

    $asset = previewAsset();
    $asset->directories()->attach($child->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $friend->id]);
    // Owned by the actor too, so the move-refusal guard (which the endpoint also runs) finds nothing
    // in $child's subtree the actor cannot see — ownership never joins the list comparison itself.
    DB::table('dam_assets')->where('id', $asset->id)->update(['created_by' => $this->actor->id]);

    $this->getJson(previewUrl('directory', [$child->id], $target->id))
        ->assertOk()
        ->assertJson(['folders' => 0, 'files' => 0]);
});

it('counts every folder and file whose list would change under a subtree move', function () {
    previewModel(AccessModel::LISTS);
    $friend = previewAdmin(['dam']);
    $target = previewDir('Preview subtree target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $friend->id]);
    // The actor reaches $target through ownership, not a list row, so the target's own list stays
    // exactly {friend} — the comparison $source and $child below must differ from.
    DB::table('dam_directories')->where('id', $target->id)->update(['created_by' => $this->actor->id]);

    $source = previewDir('Preview subtree source');
    $child = previewDir('Preview subtree child', $source->id);
    // Both $source and $child carry only the actor — neither matches the target's ({friend}) — so the
    // move's nested-set range counts both folders as changing, plus the one file in $child.
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $source->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $child->id, 'admin_id' => $this->actor->id],
    ]);
    $asset = previewAsset();
    $asset->directories()->attach($child->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->actor->id]);

    $this->getJson(previewUrl('directory', [$source->id], $target->id))
        ->assertOk()
        ->assertJson(['folders' => 2, 'files' => 1]);
});

it('422s a bad type shape, but never confirms an id or target exists (Codex review of PR #75, finding 5)', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview validation target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);

    // type is a static shape check with no lookup, so an invalid one still 422s.
    $this->getJson('/admin/dam/access/move-preview?type=bogus&ids[]=1&target='.$target->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    // A nonexistent id and target now 404, exactly like a hidden one — never Laravel's exists: rule,
    // whose own 422 would otherwise tell an unprivileged caller that the id exists.
    $this->getJson('/admin/dam/access/move-preview?type=asset&ids[]=999999&target='.$target->id)
        ->assertNotFound();

    $this->getJson('/admin/dam/access/move-preview?type=asset&ids[]=1&target=999999')
        ->assertNotFound();
});

it('caps how many ids one request may name, with a 422 that names no specific id', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview cap target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);

    $tooMany = 'type=asset&target='.$target->id.'&'.collect(range(1, 501))->map(fn ($n) => 'ids[]='.$n)->implode('&');

    $this->getJson('/admin/dam/access/move-preview?'.$tooMany)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ids']);
});

it('never caps exactly at the boundary, only past it (Codex review of PR #75, finding 6)', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview cap boundary target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);

    // ids.* rejects duplicates, so the boundary needs 500 distinct, all-accessible assets rather than
    // one id repeated — a shape check on the count alone, not on how many distinct ids get resolved.
    $assets = Asset::factory()->count(500)->create();
    DB::table('dam_assets')->whereIn('id', $assets->pluck('id'))->update(['created_by' => null]);
    DB::table('dam_asset_user_access')->insert($assets->map(fn ($asset) => ['asset_id' => $asset->id, 'admin_id' => $this->actor->id])->all());

    $exactlyAtCap = 'type=asset&target='.$target->id.'&'.$assets->map(fn ($asset) => 'ids[]='.$asset->id)->implode('&');

    $this->getJson('/admin/dam/access/move-preview?'.$exactlyAtCap)->assertOk();
});

it('a nonexistent id and a hidden one produce the same response, byte for byte (Codex review of PR #75, finding 5)', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview oracle target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);
    $hidden = previewAsset();
    // No list row and no owner for $hidden: the actor cannot open it, same as the nonexistent id below.

    $missing = $this->getJson('/admin/dam/access/move-preview?type=asset&ids[]=999999&target='.$target->id);
    $hiddenResponse = $this->getJson('/admin/dam/access/move-preview?type=asset&ids[]='.$hidden->id.'&target='.$target->id);

    $missing->assertNotFound();
    $hiddenResponse->assertNotFound();
    expect($missing->getStatusCode())->toBe($hiddenResponse->getStatusCode());
    // Not raw getContent(): debug-mode error JSON carries a stack trace whose line number depends on
    // which abort_unless() call site fired, which would fail this on incidental noise, not a real leak.
    expect($missing->json('message'))->toBe($hiddenResponse->json('message'));
});

it('403s on a missing capability before any id is resolved, so a garbage id 403s exactly like a real one', function () {
    previewModel(AccessModel::LISTS);
    $noCapability = previewAdmin(['dam']);
    $this->actingAs($noCapability, 'admin');

    $withGarbageId = $this->getJson('/admin/dam/access/move-preview?type=asset&ids[]=999999&target=999999');
    $withRealId = $this->getJson('/admin/dam/access/move-preview?type=asset&ids[]=1&target=1');

    $withGarbageId->assertForbidden();
    $withRealId->assertForbidden();
    expect($withGarbageId->json('message'))->toBe($withRealId->json('message'));
});

it('403s a protected root and 422s a self-target or descendant-target before counting (Codex review of PR #75, finding 8)', function () {
    previewModel(AccessModel::LISTS);
    $target = previewDir('Preview eligibility target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $this->actor->id]);

    $root = Directory::find(1) ?? Directory::factory()->create(['id' => 1, 'name' => 'Root']);
    DB::table('dam_directories')->where('id', $root->id)->update(['created_by' => null]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $root->id, 'admin_id' => $this->actor->id]);

    // A protected root (isDeletable() false) refuses with 403, the real move's own status and message.
    $this->getJson('/admin/dam/access/move-preview?type=directory&ids[]='.$root->id.'&target='.$target->id)
        ->assertStatus(403)
        ->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.cannot-move'));

    $source = previewDir('Preview eligibility source');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $source->id, 'admin_id' => $this->actor->id]);

    // Moving into itself: 422, the real move's own message.
    $this->getJson('/admin/dam/access/move-preview?type=directory&ids[]='.$source->id.'&target='.$source->id)
        ->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.cannot-move'));

    // Directory::created inherits the parent's list (AccessListWriter::inheritFolder, sharing slice 2a),
    // so $child already carries $source's access row — inserting it again would just duplicate that row.
    $child = previewDir('Preview eligibility child', $source->id);

    // Moving into one's own descendant: 422 too.
    $this->getJson('/admin/dam/access/move-preview?type=directory&ids[]='.$source->id.'&target='.$child->id)
        ->assertStatus(422)
        ->assertJsonPath('message', trans('dam::app.admin.dam.index.directory.cannot-move'));
});

it('counts a folder or file once when two selected subtrees overlap (Codex review of PR #75, finding 7)', function () {
    previewModel(AccessModel::LISTS);
    $friend = previewAdmin(['dam']);
    $target = previewDir('Preview overlap target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $friend->id]);
    DB::table('dam_directories')->where('id', $target->id)->update(['created_by' => $this->actor->id]);

    $parent = previewDir('Preview overlap parent');
    $child = previewDir('Preview overlap child', $parent->id);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $parent->id, 'admin_id' => $this->actor->id],
        ['directory_id' => $child->id, 'admin_id' => $this->actor->id],
    ]);
    $asset = previewAsset();
    $asset->directories()->attach($child->id);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->actor->id]);

    // Selecting the parent AND its own child: the union of their nested-set ranges is just the parent's
    // range, which legitimately holds two distinct folders (parent, child) and one file — each counted
    // once, not the old per-id-summed bug's 3 folders (parent's range counting parent+child, plus child's
    // own range counting child again).
    $this->getJson('/admin/dam/access/move-preview?type=directory&ids[]='.$parent->id.'&ids[]='.$child->id.'&target='.$target->id)
        ->assertOk()
        ->assertJson(['folders' => 2, 'files' => 1]);
});

it('counts only live files under a subtree move, never a trashed one (P2 S4)', function () {
    previewModel(AccessModel::LISTS);
    $friend = previewAdmin(['dam']);
    $target = previewDir('Preview trash target');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $target->id, 'admin_id' => $friend->id]);
    DB::table('dam_directories')->where('id', $target->id)->update(['created_by' => $this->actor->id]);

    $source = previewDir('Preview trash source');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $source->id, 'admin_id' => $this->actor->id]);
    [$live, $trashed] = [previewAsset(), previewAsset()];
    foreach ([$live, $trashed] as $asset) {
        $asset->directories()->attach($source->id);
        DB::table('dam_asset_user_access')->insert(['asset_id' => $asset->id, 'admin_id' => $this->actor->id]);
    }
    $trashed->delete();

    // Both files' lists differ from the target's; only the live one would change.
    $this->getJson(previewUrl('directory', [$source->id], $target->id))
        ->assertOk()
        ->assertJson(['folders' => 1, 'files' => 1]);
});
