<?php

use Illuminate\Support\Facades\DB;
use Mothership\Category\Models\Category;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetResourceMapping;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\Product\Models\Product;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->headers = $this->getAuthenticationHeaders();
});

it('returns a linked resource by id via api', function () {
    $asset = Asset::factory()->create();
    $category = Category::factory()->create();

    $mapping = AssetResourceMapping::create([
        'type'          => 'category',
        'category_id'   => $category->id,
        'dam_asset_id'  => $asset->id,
        'related_field' => 'icon',
    ]);

    $response = $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.linked_resource.get', $mapping->id));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $mapping->id);
});

it('returns 404 when linked resource is not found via api', function () {
    $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.linked_resource.get', 999999))
        ->assertStatus(404);
});

it('answers a link on an asset the caller cannot open like a missing link, on the API and the asset grid', function () {
    $directory = Directory::factory()->create();
    $visible = Asset::factory()->create();
    $hidden = Asset::factory()->create();
    $directory->assets()->attach([$visible->id, $hidden->id]);
    $category = Category::factory()->create();
    $link = fn (Asset $asset) => AssetResourceMapping::create(['type' => 'category', 'category_id' => $category->id, 'dam_asset_id' => $asset->id, 'related_field' => 'icon']);
    $own = $link($visible);
    $secret = $link($hidden);
    $member = Admin::latest('id')->first();
    $member->update(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->unique()->values()->all()])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    config(['app.debug' => false]);

    $hiddenRead = $this->withHeaders($this->headers)->getJson(route('admin.api.dam.linked_resource.get', $secret->id))->assertNotFound();
    $missingRead = $this->withHeaders($this->headers)->getJson(route('admin.api.dam.linked_resource.get', $secret->id + 1000))->assertNotFound();
    expect($hiddenRead->getContent())->toBe($missingRead->getContent());
    $this->withHeaders($this->headers)->getJson(route('admin.api.dam.linked_resource.get', $own->id))->assertOk()->assertJsonPath('data.id', $own->id);

    // The asset grid: without an asset it used to list every asset's links; a hidden asset reads as missing.
    $this->actingAs($member, 'admin');
    $grid = fn (array $query) => $this->getJson(route('admin.dam.asset.linked_resources.index', $query), ['X-Requested-With' => 'XMLHttpRequest']);
    $grid([])->assertNotFound();
    $grid(['dam_asset_id' => 0])->assertNotFound();
    $grid(['dam_asset_id' => $hidden->id])->assertNotFound();
    expect(collect($grid(['dam_asset_id' => $visible->id])->assertOk()->json('records'))->pluck('id')->all())->toBe([$own->id]);

    // Sorting names the mapping's own columns (products and categories have an `id` and a `type` too), honours the
    // direction, and a malformed column or order falls back to the default newest-first instead of a 500.
    // A product link added after the category one: `type` sorts on the mapping's type, not the product's.
    $product = Product::factory()->create();
    $newer = AssetResourceMapping::create(['type' => 'product', 'product_id' => $product->id, 'dam_asset_id' => $visible->id, 'related_field' => 'image']);
    $ids = fn (array $sort) => collect($grid(['dam_asset_id' => $visible->id, 'sort' => $sort])->assertOk()->json('records'))->pluck('id')->all();
    expect($ids(['column' => 'id', 'order' => 'asc']))->toBe([$own->id, $newer->id])
        ->and($ids(['column' => 'id', 'order' => 'desc']))->toBe([$newer->id, $own->id])
        ->and($ids(['column' => 'type', 'order' => 'asc']))->toBe([$own->id, $newer->id])
        ->and($ids(['column' => 'type', 'order' => 'desc']))->toBe([$newer->id, $own->id])
        ->and($ids(['column' => ['id'], 'order' => 'asc']))->toBe([$own->id, $newer->id])
        ->and($ids(['column' => 'id', 'order' => 'sideways']))->toBe([$newer->id, $own->id]);

    // Columns the grid does not sort on, including a selected alias and injection-shaped input, sort on the default
    // column; the requested direction still applies.
    foreach (['bogus', 'resource', 'product_sku', 'id; DROP TABLE x', 'dam_asset_resource_mappings.id desc, (select 1)'] as $column) {
        expect($ids(['column' => $column, 'order' => 'asc']))->toBe([$own->id, $newer->id], $column);
    }
});
