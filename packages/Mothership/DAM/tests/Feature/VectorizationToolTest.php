<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

it('offers a standalone vectorization tool without requiring a chat', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    $this->get('/admin/home/tools/vectorization/index.html')->assertOk()
        ->assertSee('Image Vectorization')
        ->assertSee('Copy SVG code')
        ->assertSee('Download PDF')
        ->assertDontSee('MOTHERSHIP_VECTORIZER_API_SECRET');

    $catalog = $this->getJson('/admin/home/layout')->assertOk()->json('catalog');

    expect(collect($catalog)->firstWhere('id', 'vectorization')['kind'])->toBe('tool');
});

it('enforces role access on every vectorization route', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets']]);
    $user = Admin::factory()->create(['role_id' => $role->id]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["recent"]']);
    $this->actingAs($user, 'admin');

    $this->get('/admin/home/tools/vectorization/index.html')->assertForbidden();
    $this->getJson('/admin/home/vectorization/state')->assertForbidden();
    $this->postJson('/admin/home/vectorization', [])->assertForbidden();
});

it('refuses the asset-level vectorization routes for a role whose allowlist lacks the widget', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.assets', 'dam.asset.edit', 'dam.asset.files']]);
    $user = Admin::factory()->create(['role_id' => $role->id]);
    DB::table('dam_role_settings')->insert(['role_id' => $role->id, 'home_widget_ids' => '["recent","favorites"]']);
    $this->actingAs($user, 'admin');
    $asset = Asset::factory()->create(['extension' => 'png', 'file_type' => 'image']);

    $this->postJson(route('admin.dam.assets.vectorization.store', $asset->id), ['request_id' => (string) Str::uuid(), 'format' => 'svg'])->assertForbidden();
    $this->getJson(route('admin.dam.assets.vectorization.state', $asset->id))->assertForbidden();
});
