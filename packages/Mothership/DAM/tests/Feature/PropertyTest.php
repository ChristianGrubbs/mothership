<?php

use Illuminate\Support\Facades\DB;
use Mothership\Core\Models\Locale;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('renders imported language names and locale codes alongside numeric locale ids', function () {
    $asset = Asset::factory()->create();
    $locale = Locale::where('code', 'en_US')->firstOrFail();
    foreach (['English', 'en_US', (string) $locale->id, 'Unknown language'] as $language) {
        AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'language' => $language]);
    }
    $this->getJson(route('admin.dam.asset.properties.index', $asset->id), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertJsonCount(4, 'records')->assertJsonFragment(['language' => 'English'])
        ->assertJsonFragment(['language' => 'Unknown language']);
});

it('should return all the property of the Asset', function () {
    $asset = Asset::factory()->create();

    $response = $this->get(route('admin.dam.asset.properties.index', $asset->id));
    $response->assertOk();
});

it('should create a new property', function () {
    $asset = Asset::factory()->create();

    $data = [
        'name'     => 'Text',
        'type'     => 'Text',
        'value'    => 'Testing',
        'language' => 'English',
    ];

    $response = $this->post(route('admin.dam.asset.property.store', $asset->id), $data);
    $response->assertOk();
    $response->assertSeeText(trans('dam::app.admin.dam.asset.properties.index.create-success'));
});

it('should reject a filterable property with no sort order', function () {
    $asset = Asset::factory()->create();

    $data = [
        'name'          => 'Text',
        'type'          => 'Text',
        'value'         => 'Testing',
        'language'      => 'English',
        'is_filterable' => 1,
        'sort_order'    => '',
    ];

    $this->postJson(route('admin.dam.asset.property.store', $asset->id), $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort_order')
        ->assertJsonFragment([
            'sort_order' => [trans('dam::app.admin.validation.property.sort-order.required')],
        ]);

    $this->assertDatabaseMissing('dam_asset_properties', ['dam_asset_id' => $asset->id]);
});

it('should accept zero as a valid sort order on a filterable property', function () {
    $asset = Asset::factory()->create();

    $data = [
        'name'          => 'Text',
        'type'          => 'Text',
        'value'         => 'Testing',
        'language'      => 'English',
        'is_filterable' => 1,
        'sort_order'    => 0,
    ];

    $this->postJson(route('admin.dam.asset.property.store', $asset->id), $data)
        ->assertOk();

    $this->assertDatabaseHas('dam_asset_properties', [
        'dam_asset_id'  => $asset->id,
        'is_filterable' => 1,
        'sort_order'    => 0,
    ]);
});

it('should reject a sort order above the allowed maximum', function () {
    $asset = Asset::factory()->create();

    $data = [
        'name'          => 'Text',
        'type'          => 'Text',
        'value'         => 'Testing',
        'language'      => 'English',
        'is_filterable' => 1,
        'sort_order'    => 10000,
    ];

    $this->postJson(route('admin.dam.asset.property.store', $asset->id), $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort_order');
});

it('should create a non filterable property without a sort order', function () {
    $asset = Asset::factory()->create();

    $data = [
        'name'     => 'Text',
        'type'     => 'Text',
        'value'    => 'Testing',
        'language' => 'English',
    ];

    $this->postJson(route('admin.dam.asset.property.store', $asset->id), $data)
        ->assertOk();

    $this->assertDatabaseHas('dam_asset_properties', [
        'dam_asset_id'  => $asset->id,
        'is_filterable' => 0,
        'sort_order'    => 0,
    ]);
});

it('should reject an update that makes a property filterable with no sort order', function () {
    $property = AssetProperty::factory()->create();

    $data = [
        'name'          => 'Text',
        'value'         => 'Testing',
        'is_filterable' => 1,
        'sort_order'    => '',
    ];

    $this->putJson(route('admin.dam.asset.properties.update', $property->id), $data)
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort_order');
});

it('should return the edit page with data by Id', function () {
    $property = AssetProperty::factory()->create();

    $response = $this->get(route('admin.dam.asset.property.edit', $property->id));
    $response->assertOk();
});

it('should update the Property', function () {
    $property = AssetProperty::factory()->create();

    $data = [
        'name'  => 'Text',
        'value' => 'Testing',
    ];

    $this->put(route('admin.dam.asset.properties.update', $property->id), $data)
        ->assertOk()
        ->assertJson([
            'message' => trans('dam::app.admin.dam.asset.properties.index.update-success'),
        ]);
});

it('holds an update to the 1,000-character limit create uses (UX eval G2-04)', function () {
    $property = AssetProperty::factory()->create(['name' => 'Caption', 'value' => 'short']);
    $url = route('admin.dam.asset.properties.update', $property->id);

    $this->putJson($url, ['name' => 'Caption', 'value' => str_repeat('y', 1001)])->assertUnprocessable()->assertJsonValidationErrors('value');
    expect($property->fresh()->value)->toBe('short');

    $this->putJson($url, ['name' => 'Caption', 'value' => str_repeat('y', 1000)])->assertOk();
    expect(mb_strlen($property->fresh()->value))->toBe(1000);
});

it('treats property names that differ only in case as the same name, on create and on rename (UX eval G2-16)', function () {
    $asset = Asset::factory()->create();
    $create = fn (string $name) => $this->postJson(route('admin.dam.asset.property.store', $asset->id), ['name' => $name, 'type' => 'text', 'language' => 'English', 'value' => 'x']);

    $create('G2-PROP')->assertOk();
    $create('g2-prop')->assertUnprocessable()->assertJsonValidationErrors('name');
    $create(' G2-Prop ')->assertUnprocessable()->assertJsonValidationErrors('name');
    $create('Other')->assertOk();

    $other = AssetProperty::query()->where('dam_asset_id', $asset->id)->where('name', 'Other')->firstOrFail();
    $this->putJson(route('admin.dam.asset.properties.update', $other->id), ['name' => 'g2-PROP', 'value' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    // Renaming a property to its own name in another case is no clash.
    $this->putJson(route('admin.dam.asset.properties.update', $other->id), ['name' => 'OTHER', 'value' => 'x'])->assertOk();

    expect(AssetProperty::query()->where('dam_asset_id', $asset->id)->pluck('name')->sort()->values()->all())->toBe(['G2-PROP', 'OTHER']);
});

it('keeps both rows editable where older data already holds case-variant names (rev-B 2)', function () {
    $asset = Asset::factory()->create();
    $upper = AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'language' => 'English', 'name' => 'Caption', 'value' => 'a']);
    $lower = AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'language' => 'English', 'name' => 'caption', 'value' => 'b']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'language' => 'English', 'name' => 'Notes']);
    $update = fn (AssetProperty $row, string $name, string $value) => $this->putJson(route('admin.dam.asset.properties.update', $row->id), ['name' => $name, 'value' => $value]);

    // A value-only edit, and a recasing of the row's own name, add no duplicate.
    $update($upper, 'Caption', 'a2')->assertOk();
    $update($lower, 'caption', 'b2')->assertOk();
    $update($lower, 'CAPTION', 'b3')->assertOk();
    // A rename onto another row's name is still refused.
    $update($lower, 'notes', 'b4')->assertUnprocessable()->assertJsonValidationErrors('name');

    expect($upper->fresh()->only(['name', 'value']))->toBe(['name' => 'Caption', 'value' => 'a2'])
        ->and($lower->fresh()->only(['name', 'value']))->toBe(['name' => 'CAPTION', 'value' => 'b3']);
});

it('should delete the property by ID', function () {
    $asset = Asset::factory()->create();
    $assetId = $asset->id;

    $property = AssetProperty::factory()->create();

    $this->delete(route('admin.dam.asset.properties.delete', ['asset_id' => $assetId, 'id' => $property->id]))
        ->assertOk()
        ->assertJson([
            'message' => trans('dam::app.admin.dam.asset.properties.index.delete-success'),
        ]);
});

it('should delete all the properties at once', function () {
    $asset = Asset::factory()->create();
    $assetId = $asset->id;
    $properties = AssetProperty::factory()->count(3)->create([
        'dam_asset_id' => $assetId,
    ]);

    $propertyIds = $properties->pluck('id')->toArray();
    $response = $this->postJson(
        route('admin.dam.asset.properties.mass_delete', ['asset_id' => $assetId]),
        ['indices' => $propertyIds]
    );

    $response->assertOk()
        ->assertJsonFragment([
            'message' => trans('dam::app.admin.dam.asset.datagrid.mass-delete-success'),
        ]);

    foreach ($propertyIds as $id) {
        $this->assertDatabaseMissing('dam_asset_properties', ['id' => $id]);
    }

    $this->assertDatabaseMissing('dam_asset_properties', ['dam_asset_id' => $assetId]);
});

it('lists the properties of the route asset only, whatever id the query string names', function () {
    $directory = Directory::factory()->create();
    $visible = Asset::factory()->create();
    $hidden = Asset::factory()->create();
    $directory->assets()->attach([$visible->id, $hidden->id]);
    AssetProperty::factory()->create(['dam_asset_id' => $visible->id, 'value' => 'public value']);
    AssetProperty::factory()->create(['dam_asset_id' => $hidden->id, 'value' => 'secret value']);
    $member = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => collect(config('acl'))->pluck('key')->unique()->values()->all()])->id]);
    DB::table('dam_configuration')->updateOrInsert(['key' => AccessModel::KEY], ['value' => AccessModel::LISTS]);
    app(AccessModel::class)->flush();
    DB::table('dam_directory_user_access')->insert(['directory_id' => $directory->id, 'admin_id' => $member->id]);
    DB::table('dam_asset_user_access')->insert(['asset_id' => $visible->id, 'admin_id' => $member->id]);
    app(DirectoryPermissionService::class)->flush();
    $this->actingAs($member, 'admin');

    // `?id=` used to win over the route id, so the grid listed a hidden asset's properties.
    $response = $this->getJson(route('admin.dam.asset.properties.index', $visible->id).'?id='.$hidden->id, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

    expect($response->json('records'))->toHaveCount(1)
        ->and($response->getContent())->toContain('public value')->not->toContain('secret value');

    // A JSON body's `id` also used to win over the route.
    $body = $this->json('GET', route('admin.dam.asset.properties.index', $visible->id), ['id' => $hidden->id], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    expect($body->getContent())->toContain('public value')->not->toContain('secret value');
});
