<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mothership\DAM\AiIndex\VectorCodec;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    config(['ai-index.search_enabled' => true, 'ai-index.api' => 'http://bridge.test', 'ai-index.lexical' => 'like']);
    Http::fake(['bridge.test/embed' => Http::response(['vectors' => [[1, 0]], 'model' => 'm'])]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();

    $this->folder = Directory::factory()->create(['name' => 'Shared', 'parent_id' => null]);
    $this->visible = Asset::factory()->create(['file_name' => 'open.jpg']);
    $this->hidden = Asset::factory()->create(['file_name' => 'secret.jpg']);
    foreach ([$this->visible, $this->hidden] as $asset) {
        $asset->directories()->attach($this->folder->id);
        DB::table('dam_ai_index')->insert(['asset_id' => $asset->id, 'status' => 'done', 'lexical_text' => 'falcon mascot', 'caption' => 'falcon mascot', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_ai_vectors')->insert(['asset_id' => $asset->id, 'kind' => 'summary', 'vector' => VectorCodec::encode([1, 0]), 'text' => 'Mascot booking on page 3', 'model' => 'm', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('dam_ai_tags')->insert(['asset_id' => $asset->id, 'term' => 'campus-event', 'created_at' => now(), 'updated_at' => now()]);
    }

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.update']]);
    $this->member = Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    // The folder is shared with the member; only one file inside it is. A folder never widens a file (ADR 0010).
    DB::table('dam_directory_user_access')->insert(['directory_id' => $this->folder->id, 'admin_id' => $this->member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $this->visible->id, 'admin_id' => $this->member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($this->member, 'admin');
});

function accessSearch(array $params): array
{
    app(DirectoryPermissionService::class)->flush();

    return array_column(test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', ...$params]))->assertOk()->json('assets'), 'id');
}

it('never returns a hidden file by words or by meaning, and counts only visible files', function () {
    $response = test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => 'mascot']))->assertOk();

    expect(array_column($response->json('assets'), 'id'))->toBe([$this->visible->id])
        ->and($response->json('meta.total_assets'))->toBe(1)
        ->and(json_encode($response->json()))->not->toContain('secret.jpg');
});

it('cannot elevate hidden exact filenames or hidden owners through exact members', function () {
    $slot = Asset::factory()->create(['file_name' => 'hidden-member.png', 'merged_into_id' => $this->hidden->id]);
    expect(accessSearch(['search' => ' SECRET.JPG ']))->toBe([$this->visible->id])
        ->and(accessSearch(['search' => ' hidden-MEMBER.png ']))->toBe([$this->visible->id]);
});

it('follows group membership changes on the next search', function () {
    $group = DB::table('dam_groups')->insertGetId(['name' => 'Crew', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_asset_group_access')->insert(['asset_id' => $this->hidden->id, 'group_id' => $group]);
    expect(accessSearch(['search' => 'mascot']))->not->toContain($this->hidden->id);

    DB::table('dam_group_members')->insert(['group_id' => $group, 'admin_id' => $this->member->id]);
    expect(accessSearch(['search' => 'mascot']))->toContain($this->hidden->id);

    DB::table('dam_group_members')->where('group_id', $group)->delete();
    expect(accessSearch(['search' => 'mascot']))->not->toContain($this->hidden->id);
});

it('includes a file listed for Everyone', function () {
    $everyone = DB::table('dam_groups')->where('system_key', AccessListReader::EVERYONE)->value('id')
        ?? DB::table('dam_groups')->insertGetId(['name' => 'Everyone', 'system_key' => AccessListReader::EVERYONE, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('dam_asset_group_access')->insert(['asset_id' => $this->hidden->id, 'group_id' => $everyone]);

    expect(accessSearch(['search' => 'mascot']))->toContain($this->hidden->id);
});

it('gives a disabled member nothing', function () {
    $this->member->update(['status' => 0]);

    $response = test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'search' => 'mascot']));

    expect($response->status() === 200 ? array_column($response->json('assets'), 'id') : [])->toBe([]);
});

it('answers a hidden Find-similar seed exactly like a missing one', function () {
    $hidden = test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'similar_to' => $this->hidden->id]));
    $missing = test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'similar_to' => 999999]));

    expect($hidden->status())->toBe(404)->and($missing->status())->toBe(404)
        ->and($hidden->json())->toBe($missing->json());
});
