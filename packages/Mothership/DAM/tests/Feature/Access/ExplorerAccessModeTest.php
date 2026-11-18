<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * The sharing indicator's data (ADR 0010, sharing slice 3): the explorer listing carries `access_mode` on
 * every folder and file under `lists`, and carries it on nothing at all under `grants`, which is the
 * default. The indicator itself renders only when the key is present, so `grants` parity holds in the UI
 * from this one fact.
 */

beforeEach(function () {
    $this->parent = Directory::create(['name' => 'Modes parent', 'parent_id' => null]);
    $this->private = Directory::create(['name' => 'Modes private', 'parent_id' => $this->parent->id]);
    $this->shared = Directory::create(['name' => 'Modes shared', 'parent_id' => $this->parent->id]);
    $this->open = Directory::create(['name' => 'Modes open', 'parent_id' => $this->parent->id]);

    $this->file = Asset::factory()->create(['file_name' => 'modes.jpg']);
    $this->file->directories()->attach($this->parent->id);

    $this->admin = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
    $this->friend = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset.view']])->id]);

    $everyoneId = (int) DB::table('dam_groups')->where('system_key', 'everyone')->value('id');
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->shared->id, 'admin_id' => $this->friend->id]);
    DB::table('dam_directory_group_access')->insert(['directory_id' => $this->open->id, 'group_id' => $everyoneId]);
    DB::table('dam_asset_group_access')->insert(['asset_id' => $this->file->id, 'group_id' => $everyoneId]);

    $this->actingAs($this->admin, 'admin');
});

function explorerModesModel(string $model): void
{
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => $model]);
    app(AccessModel::class)->flush();
}

function explorerModesListing(int $directoryId): array
{
    return test()->getJson('/admin/dam/explorer/data?scope=directory&directory_id='.$directoryId)->assertOk()->json();
}

it('carries the mode of every folder and file under lists', function () {
    explorerModesModel(AccessModel::LISTS);

    $data = explorerModesListing($this->parent->id);
    $folders = collect($data['directories'])->keyBy('id');

    expect($folders[$this->private->id]['access_mode'])->toBe('private')
        ->and($folders[$this->shared->id]['access_mode'])->toBe('people')
        ->and($folders[$this->open->id]['access_mode'])->toBe('everyone')
        ->and(collect($data['assets'])->firstWhere('id', $this->file->id)['access_mode'])->toBe('everyone');
});

it('carries no mode at all under grants', function () {
    explorerModesModel(AccessModel::GRANTS);

    $data = explorerModesListing($this->parent->id);

    foreach ($data['directories'] as $folder) {
        expect($folder)->not->toHaveKey('access_mode');
    }

    foreach ($data['assets'] as $asset) {
        expect($asset)->not->toHaveKey('access_mode');
    }
});
