<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    config(['ai-index.search_enabled' => true]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    $folder = Directory::factory()->create(['name' => 'Shared', 'parent_id' => null]);
    $this->seed = Asset::factory()->create(['file_name' => 'seed.jpg']);
    $this->near = Asset::factory()->create(['file_name' => 'near.jpg']);
    $this->hiddenNear = Asset::factory()->create(['file_name' => 'hidden-near.jpg']);
    foreach ([[$this->seed, [1, 0]], [$this->near, [0.9, 0.1]], [$this->hiddenNear, [0.95, 0.05]]] as [$asset, $vector]) {
        $asset->directories()->attach($folder->id);
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode($vector), 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
    }
    $member = Admin::factory()->create(['status' => 1, 'role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']])->id]);
    DB::table('dam_directory_user_access')->insert(['directory_id' => $folder->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert([['asset_id' => $this->seed->id, 'admin_id' => $member->id], ['asset_id' => $this->near->id, 'admin_id' => $member->id]]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');
});

// The hidden-seed-equals-missing case is already pinned by SmartSearchAccessTest.
it('lists only visible neighbours, never the seed or a hidden file', function () {
    $response = $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'similar_to' => $this->seed->id]))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toBe([$this->near->id])
        ->and($response->json('meta.total_assets'))->toBe(1)
        ->and($response->json('directories'))->toBe([]);
});

it('keeps the seed authoritative when a restored search field still contains old words', function () {
    $response = $this->getJson(route('admin.dam.explorer.index', [
        'scope' => 'all', 'similar_to' => $this->seed->id, 'search' => 'words-that-match-nothing',
    ]))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toBe([$this->near->id])
        ->and($response->json('directories'))->toBe([]);
});
