<?php

use Illuminate\Support\Facades\DB;
use Mothership\Core\Models\Locale;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->headers = $this->getAuthenticationHeaders();
});

it('fetches a property by id via api', function () {
    $asset = Asset::factory()->create();
    $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id]);

    $response = $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.property.get', $property->id));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $property->id);
});

it('returns 404 when property is not found via api', function () {
    $this->withHeaders($this->headers)
        ->getJson(route('admin.api.dam.property.get', 999999))
        ->assertStatus(404);
});

it('adds a new property via api', function () {
    $asset = Asset::factory()->create();
    $locale = Locale::where('status', 1)->first();

    $response = $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.property.add', $asset->id), [
            'name'     => 'Author Name',
            'type'     => 'text',
            'language' => $locale->code,
            'value'    => 'John Doe',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('dam_asset_properties', [
        'dam_asset_id' => $asset->id,
        'name'         => 'Author Name',
        'value'        => 'John Doe',
    ]);
});

it('validates property creation fields via api', function () {
    $asset = Asset::factory()->create();

    $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.property.add', $asset->id), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type', 'language', 'value']);
});

it('rejects property creation for unknown locale via api', function () {
    $asset = Asset::factory()->create();

    $response = $this->withHeaders($this->headers)
        ->postJson(route('admin.api.dam.property.add', $asset->id), [
            'name'     => 'Field',
            'type'     => 'text',
            'language' => 'zz_ZZ',
            'value'    => 'v',
        ]);

    $response->assertStatus(400)->assertJsonPath('success', false);
});

it('updates a property via api', function () {
    $asset = Asset::factory()->create();
    $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id]);

    $response = $this->withHeaders($this->headers)
        ->patchJson(route('admin.api.dam.property.update', $property->id), [
            'name'  => 'Updated Name',
            'value' => 'new value',
        ]);

    $response->assertOk()->assertJsonPath('success', true);

    $this->assertDatabaseHas('dam_asset_properties', [
        'id'    => $property->id,
        'name'  => 'Updated Name',
        'value' => 'new value',
    ]);
});

it('holds an api update to the create limits: 1,000 characters, and no name that differs only in case (UX eval G2-04, G2-16)', function () {
    $asset = Asset::factory()->create();
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Caption']);
    $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Notes', 'value' => 'short']);
    $url = route('admin.api.dam.property.update', $property->id);

    $this->withHeaders($this->headers)->patchJson($url, ['name' => 'Notes', 'value' => str_repeat('y', 1001)])
        ->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->withHeaders($this->headers)->patchJson($url, ['name' => 'CAPTION', 'value' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    expect($property->fresh()->only(['name', 'value']))->toBe(['name' => 'Notes', 'value' => 'short']);
});

it('keeps both api rows editable where older data already holds case-variant names (rev-B 2)', function () {
    $asset = Asset::factory()->create();
    $upper = AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Caption', 'value' => 'a']);
    $lower = AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'caption', 'value' => 'b']);
    $update = fn (AssetProperty $row, string $value) => $this->withHeaders($this->headers)
        ->patchJson(route('admin.api.dam.property.update', $row->id), ['name' => $row->name, 'value' => $value]);

    $update($upper, 'a2')->assertOk();
    $update($lower, 'b2')->assertOk();

    expect($upper->fresh()->value)->toBe('a2')->and($lower->fresh()->value)->toBe('b2');
});

it('returns 404 when updating a non-existent property via api', function () {
    $this->withHeaders($this->headers)
        ->patchJson(route('admin.api.dam.property.update', 999999), [
            'name'  => 'X',
            'value' => 'v',
        ])
        ->assertStatus(404);
});

it('deletes a property via api', function () {
    $asset = Asset::factory()->create();
    $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id]);

    $response = $this->withHeaders($this->headers)
        ->deleteJson(route('admin.api.dam.property.delete', $property->id));

    $response->assertOk()->assertJsonPath('success', true);
    $this->assertDatabaseMissing('dam_asset_properties', ['id' => $property->id]);
});

it('returns 404 when deleting a non-existent property via api', function () {
    $this->withHeaders($this->headers)
        ->deleteJson(route('admin.api.dam.property.delete', 999999))
        ->assertStatus(404);
});

function makePropCustomHeaders(Directory $grantedDir): array
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => []]);
    DB::table('dam_directory_role')->insert([
        'directory_id' => $grantedDir->id,
        'role_id'      => $role->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    $headers = test()->getAuthenticationHeaders();
    Admin::latest('id')->first()->update(['role_id' => $role->id]);
    app(DirectoryPermissionService::class)->flush();

    return $headers;
}

it('returns 404, like a missing id, when adding a property to an asset in a denied directory', function () {
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($denied->id);
    $granted = Directory::factory()->create();
    $headers = makePropCustomHeaders($granted);

    $this->withHeaders($headers)
        ->postJson(route('admin.api.dam.property.add', $asset->id), [
            'name'     => 'color',
            'type'     => 'text',
            'language' => 'en',
            'value'    => 'red',
        ])
        ->assertStatus(404);
});

it('returns 404, like a missing id, when fetching a property whose asset is in a denied directory', function () {
    $denied = Directory::factory()->create();
    $asset = Asset::factory()->create();
    $asset->directories()->attach($denied->id);
    $property = AssetProperty::factory()->create(['dam_asset_id' => $asset->id]);
    $granted = Directory::factory()->create();
    $headers = makePropCustomHeaders($granted);

    $this->withHeaders($headers)
        ->getJson(route('admin.api.dam.property.get', $property->id))
        ->assertStatus(404);
});
