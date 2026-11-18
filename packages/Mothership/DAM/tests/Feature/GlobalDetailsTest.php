<?php

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\GenerationAsset;
use Mothership\DAM\Services\DetailLayoutService;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

beforeEach(function () {
    $this->loginAsAdmin();
});

it('applies the global ordered fields to existing and future formats over type overrides', function () {
    $existing = Asset::factory()->create(['extension' => 'mp4', 'file_type' => 'video']);
    AssetProperty::factory()->create(['dam_asset_id' => $existing->id, 'name' => 'Camera', 'value' => 'DJI']);
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['property:Camera', 'file_name', 'codec'], 'hide_empty' => false],
        ['match' => 'mp4', 'fields' => ['extension'], 'hide_empty' => true],
    ]])->assertOk();
    $future = Asset::factory()->create(['extension' => 'futureformat']);
    // Rows keep the global order; configured fields an asset has no value for are hidden.
    foreach ([[$existing, ['property:Camera', 'file_name']], [$future, ['file_name']]] as [$asset, $keys]) {
        $info = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->json('detail_fields');
        expect(collect($info)->pluck('key')->all())->toBe($keys);
        $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()
            ->assertJsonPath('detailFields.0.key', $keys[0]);
        $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()
            ->assertSee('Configure Details')->assertSee('data-dam-detail-fields', false);
    }
});

it('requires a global default and rejects duplicate visible fields', function () {
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => []])->assertUnprocessable();
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['file_name', 'file_name'], 'hide_empty' => true],
    ]])->assertUnprocessable();
});

it('preserves global mode when older clients omit the flag and validates field names', function () {
    $layout = ['match' => '*', 'fields' => ['file_name'], 'hide_empty' => false];
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [$layout]])->assertOk();
    $this->putJson('/admin/dam/configuration/detail-layouts', ['layouts' => [$layout]])->assertOk();
    expect(app(DetailLayoutService::class)->isGlobal())->toBeTrue();
    foreach (['bogus', 'property:'] as $key) {
        $this->putJson('/admin/dam/configuration/detail-layouts', ['layouts' => [[...$layout, 'fields' => [$key]]]])->assertUnprocessable();
    }
});

it('shows all distinct translated values for a selected property', function () {
    $asset = Asset::factory()->create();
    foreach (['English' => 'Camera', 'French' => 'Appareil', 'German' => 'Camera'] as $language => $value) {
        AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Title', 'value' => $value, 'language' => $language]);
    }
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['property:Title'], 'hide_empty' => false],
    ]])->assertOk();
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('detail_fields.0.value', "Camera\nAppareil");
});

it('keeps a Campus property as an ordinary property, with no campus name readout (campus GPS removed)', function () {
    $asset = Asset::factory()->create(['extension' => 'mp4', 'file_type' => 'video']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'Campus', 'value' => 'Main Campus']);

    $response = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->assertJsonMissingPath('campus_name');
    expect($response->json('properties'))->toBe([['name' => 'Campus', 'value' => 'Main Campus']]);
    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->assertJsonMissingPath('campusName');
    $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->assertDontSee('data-dam-campus-name', false);
});

it('always hides rows the asset has no value for and treats placeholders as empty', function () {
    $asset = Asset::factory()->create(['extension' => 'png', 'file_type' => 'image', 'title' => 'Fair panorama']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'flight_session', 'value' => '—']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'tech_camera_device', 'value' => '  ']);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'tech_gps', 'value' => 'n/a']);
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['title', 'aspect_ratio', 'codec', 'property:flight_session', 'property:tech_camera_device', 'property:tech_gps', 'property:missing'], 'hide_empty' => false],
    ]])->assertOk();
    $fields = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->json('detail_fields');
    expect(collect($fields)->pluck('key')->all())->toBe(['title'])
        ->and($fields[0]['value'])->toBe('Fair panorama');
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['title']],
    ]])->assertOk();
});

it('falls back to the measured dimensions when a legacy resolution property is empty', function () {
    $asset = Asset::factory()->create(['file_type' => 'image', 'meta_data' => ['ImageWidth' => 4096, 'ImageHeight' => 2048]]);
    AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => 'tech_resolution', 'value' => '—']);
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['property:tech_resolution', 'aspect_ratio'], 'hide_empty' => true],
    ]])->assertOk();
    $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()
        ->assertJsonPath('detail_fields.0.key', 'property:tech_resolution')
        ->assertJsonPath('detail_fields.0.value', '4096 × 2048 px')
        ->assertJsonPath('detail_fields.1.value', '2:1 (2.000)');
});

it('keeps legitimate zero and false values while dropping every placeholder spelling', function () {
    $asset = Asset::factory()->create();
    foreach (['Count' => '0', 'Dash' => ' - ', 'EnDash' => '–', 'Unknown' => 'UNKNOWN', 'None' => 'None', 'Empty' => ''] as $name => $value) {
        AssetProperty::factory()->create(['dam_asset_id' => $asset->id, 'name' => $name, 'value' => $value]);
    }
    $this->putJson('/admin/dam/configuration/detail-layouts', ['global' => true, 'layouts' => [
        ['match' => '*', 'fields' => ['property:Count', 'property:Dash', 'property:EnDash', 'property:Unknown', 'property:None', 'property:Empty', 'property:resolution']],
    ]])->assertOk();
    $fields = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->json('detail_fields');
    expect($fields)->toBe([['key' => 'property:Count', 'label' => 'Count', 'value' => '0']]);
    expect(DetailLayoutService::isEmpty(0))->toBeFalse()
        ->and(DetailLayoutService::isEmpty(false))->toBeFalse()
        ->and(DetailLayoutService::isEmpty('0'))->toBeFalse()
        ->and(DetailLayoutService::isEmpty([]))->toBeTrue();
});

it('shows a quick view of the recorded generation prompt in the details pane', function () {
    $asset = Asset::factory()->create(['file_type' => 'image', 'extension' => 'png']);
    $manifest = ['kind' => 'image', 'model' => 'gpt_image_2', 'original_prompt' => null, 'prompt' => "Create one seamless 360 panorama.\n\nProjection: equirectangular."];
    GenerationAsset::create(['generation_id' => 'vault-test', 'asset_id' => $asset->id, 'manifest' => $manifest, 'manifest_sha256' => hash('sha256', json_encode($manifest))]);

    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()
        ->assertJsonPath('generationPrompt.prompt', $manifest['prompt'])
        ->assertJsonPath('generationPrompt.model', 'gpt_image_2');
    $html = $this->get(route('admin.dam.assets.edit', $asset->id))->assertOk()->getContent();
    expect(str_contains($html, 'data-dam-generation-prompt'))->toBeTrue()
        ->and(str_contains($html, 'Generation details'))->toBeTrue();

    $plain = Asset::factory()->create();
    $this->getJson(route('admin.dam.assets.show', $plain->id))->assertOk()->assertJsonPath('generationPrompt', null);
});

it('prefers the original request over enhanced and full prompts and hides it without the files permission', function () {
    $asset = Asset::factory()->create();
    $manifest = ['model' => 'gpt_image_2', 'original_prompt' => '  Make it pop  ', 'enhanced_prompt' => 'Enhanced', 'prompt' => 'Full'];
    GenerationAsset::create(['generation_id' => 'vault-a', 'asset_id' => $asset->id, 'manifest' => $manifest, 'manifest_sha256' => hash('sha256', 'a')]);
    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->assertJsonPath('generationPrompt.prompt', 'Make it pop');

    $blank = Asset::factory()->create();
    GenerationAsset::create(['generation_id' => 'vault-b', 'asset_id' => $blank->id, 'manifest' => ['original_prompt' => '', 'enhanced_prompt' => ['not' => 'string'], 'prompt' => 'Full only', 'model' => 7], 'manifest_sha256' => hash('sha256', 'b')]);
    $this->getJson(route('admin.dam.assets.show', $blank->id))->assertOk()
        ->assertJsonPath('generationPrompt.prompt', 'Full only')->assertJsonPath('generationPrompt.model', null);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam.asset.view', 'dam.asset.edit']]);
    $directory = Directory::factory()->create();
    $asset->directories()->attach($directory);
    DB::table('dam_directory_role')->insert(['directory_id' => $directory->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');
    $this->getJson(route('admin.dam.assets.show', $asset->id))->assertOk()->assertJsonPath('generationPrompt', null);
});
