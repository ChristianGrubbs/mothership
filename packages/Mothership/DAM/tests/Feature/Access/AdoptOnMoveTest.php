<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mothership\DAM\Jobs\MassMove;
use Mothership\DAM\Jobs\MoveDirectoryStructure;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * A moved file, or a moved folder with its whole subtree, takes the destination's access list and nothing of
 * where it came from (ADR 0010, spec "Move") — one case per mover in the sharing slice 2a plan, driven
 * through the controller or job it really runs in. Owners never change: a move changes who can see an item,
 * not who decides.
 *
 * The source and destination are shared with different people on purpose, so a writer that added the
 * destination's list instead of replacing the source's would leave the union and fail here.
 */

beforeEach(function () {
    Storage::fake(Directory::getAssetDisk());

    $this->admin = $this->loginAsAdmin();
    $this->here = adoptAdmin();
    $this->there = adoptAdmin();

    $this->from = Directory::create(['name' => 'Adopt from '.Str::random(6), 'parent_id' => null]);
    $this->to = Directory::create(['name' => 'Adopt to '.Str::random(6), 'parent_id' => null]);
    DB::table('dam_directory_user_access')->insert([
        ['directory_id' => $this->from->id, 'admin_id' => $this->here->id],
        ['directory_id' => $this->to->id, 'admin_id' => $this->there->id],
    ]);

    // The owner of everything below, so a move that touched created_by would show.
    $this->owner = adoptAdmin();
});

it('gives a moved file the destination\'s list through the asset move endpoint', function () {
    $file = adoptFile($this, $this->from->id);

    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $file->id,
        'new_parent_id' => $this->to->id,
    ])->assertOk();

    expect(adoptUsers('asset', $file->id))->toBe([$this->there->id])
        ->and((int) $file->fresh()->created_by)->toBe($this->owner->id);
});

it('gives every file in a mass move the destination\'s list', function () {
    $first = adoptFile($this, $this->from->id);
    $second = adoptFile($this, $this->from->id);

    (new MassMove([$first->id, $second->id], [], $this->to->id, $this->admin->id))->handle();

    expect(adoptUsers('asset', $first->id))->toBe([$this->there->id])
        ->and(adoptUsers('asset', $second->id))->toBe([$this->there->id])
        ->and((int) $first->fresh()->created_by)->toBe($this->owner->id)
        ->and((int) $second->fresh()->created_by)->toBe($this->owner->id);
});

it('cascades the destination\'s list through a mass-moved folder\'s whole subtree', function () {
    [$moved, $leaf, $file] = adoptSubtree($this);

    (new MassMove([], [$moved->id], $this->to->id, $this->admin->id))->handle();

    expect(adoptUsers('directory', $moved->id))->toBe([$this->there->id])
        ->and(adoptUsers('directory', $leaf->id))->toBe([$this->there->id])
        ->and(adoptUsers('asset', $file->id))->toBe([$this->there->id])
        ->and((int) $moved->fresh()->created_by)->toBe($this->owner->id)
        ->and((int) $leaf->fresh()->created_by)->toBe($this->owner->id)
        ->and((int) $file->fresh()->created_by)->toBe($this->owner->id);
});

it('cascades the destination\'s list through a moved folder structure', function () {
    [$moved, $leaf, $file] = adoptSubtree($this);

    (new MoveDirectoryStructure($moved->id, $this->to->id, $this->admin->id))->handle();

    expect($moved->fresh()->parent_id)->toBe($this->to->id)
        ->and(adoptUsers('directory', $moved->id))->toBe([$this->there->id])
        ->and(adoptUsers('directory', $leaf->id))->toBe([$this->there->id])
        ->and(adoptUsers('asset', $file->id))->toBe([$this->there->id])
        ->and((int) $leaf->fresh()->created_by)->toBe($this->owner->id);
});

it('empties a moved item\'s list when the destination shares with nobody', function () {
    $private = Directory::create(['name' => 'Adopt private '.Str::random(6), 'parent_id' => null]);
    $file = adoptFile($this, $this->from->id);

    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $file->id,
        'new_parent_id' => $private->id,
    ])->assertOk();

    // Only me, the destination's own setting — not the source's list carried along.
    expect(adoptUsers('asset', $file->id))->toBe([]);
});

it('replaces a moved file\'s own customised list with exactly the destination\'s', function () {
    $file = adoptFile($this, $this->from->id);
    $custom = adoptAdmin();

    // The owner had set this file's own list through the Share dialog, narrower than its folder's. A move
    // replaces it: the destination folder's owner governs what lands there (spec "Move", point 1).
    DB::table('dam_asset_user_access')->where('asset_id', $file->id)->delete();
    DB::table('dam_asset_user_access')->insert(['asset_id' => $file->id, 'admin_id' => $custom->id]);

    $this->postJson(route('admin.dam.assets.moved'), [
        'move_item_id'  => $file->id,
        'new_parent_id' => $this->to->id,
    ])->assertOk();

    expect(adoptUsers('asset', $file->id))->toBe([$this->there->id]);
});

it('keeps a file outside the moved subtree untouched', function () {
    [$moved] = adoptSubtree($this);
    $bystander = adoptFile($this, $this->from->id);

    (new MoveDirectoryStructure($moved->id, $this->to->id, $this->admin->id))->handle();

    expect(adoptUsers('asset', $bystander->id))->toBe([$this->here->id]);
});

/** A file uploaded into $directoryId through the real route, owned by the fixture owner. */
function adoptFile($test, int $directoryId): Asset
{
    $id = $test->postJson(route('admin.dam.assets.upload'), [
        'files'        => [UploadedFile::fake()->image('adopt-'.Str::random(6).'.png', 40, 20)],
        'directory_id' => $directoryId,
    ])->assertStatus(201)->json('files.0.id');

    DB::table('dam_assets')->where('id', $id)->update(['created_by' => $test->owner->id]);

    return Asset::findOrFail($id);
}

/**
 * A two-deep subtree inside the source folder, holding one file — all three of them listed for the source's
 * principal by inherit-on-create, and all three owned by the fixture owner.
 *
 * @return array{0: Directory, 1: Directory, 2: Asset}
 */
function adoptSubtree($test): array
{
    $moved = Directory::create(['name' => 'Adopt moved '.Str::random(6), 'parent_id' => $test->from->id]);
    $leaf = Directory::create(['name' => 'Adopt leaf '.Str::random(6), 'parent_id' => $moved->id]);
    $file = adoptFile($test, $leaf->id);

    expect(adoptUsers('directory', $leaf->id))->toBe([$test->here->id])
        ->and(adoptUsers('asset', $file->id))->toBe([$test->here->id]);

    DB::table('dam_directories')->whereIn('id', [$moved->id, $leaf->id])->update(['created_by' => $test->owner->id]);

    return [$moved, $leaf, $file];
}

function adoptAdmin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

/** @return list<int> the admin ids listed on one item */
function adoptUsers(string $type, int $id): array
{
    return DB::table("dam_{$type}_user_access")->where("{$type}_id", $id)->orderBy('admin_id')->pluck('admin_id')->map(fn ($v) => (int) $v)->all();
}
