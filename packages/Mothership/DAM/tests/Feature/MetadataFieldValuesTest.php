<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\MetadataFields\FieldValues;

/*
 * P4a (D9): typed field values. One validator serves the modal's Details editor, the REST asset update, the property
 * endpoints and bulk edit: a bad value answers 422 with the same message on every path and writes nothing.
 */

function mfvField(string $type, array $attributes = []): MetadataField
{
    return MetadataField::query()->create($attributes + [
        'code'     => $attributes['code'] ?? $type.'_field',
        'label'    => $attributes['label'] ?? ucfirst(str_replace('_', ' ', $type)).' field',
        'type'     => $type,
        'options'  => in_array($type, ['select', 'multi_select'], true) ? [['value' => 'red', 'label' => 'Red'], ['value' => 'green', 'label' => 'Green'], ['value' => 'blue', 'label' => 'Blue']] : null,
        'position' => (int) MetadataField::query()->max('position') + 1,
    ]);
}

/** [type, bad value, the message every path answers with] */
dataset('invalid values', [
    'number'          => ['number', 'twelve', fn () => trans('dam::app.admin.metadata-fields.validation.number', ['field' => 'Number field'])],
    // What a browser's number input holds as badInput; refused, never coerced to a clear (UX eval R3).
    'number overflow' => ['number', '1e400', fn () => trans('dam::app.admin.metadata-fields.validation.number', ['field' => 'Number field'])],
    'date'            => ['date', '2026-02-30', fn () => trans('dam::app.admin.metadata-fields.validation.date', ['field' => 'Date field'])],
    'select option'   => ['select', 'purple', fn () => trans('dam::app.admin.metadata-fields.validation.option', ['field' => 'Select field', 'value' => 'purple'])],
    'multi option'    => ['multi_select', ['red', 'purple'], fn () => trans('dam::app.admin.metadata-fields.validation.option', ['field' => 'Multi select field', 'value' => 'purple'])],
    'multi not list'  => ['multi_select', 'red', fn () => trans('dam::app.admin.metadata-fields.validation.list', ['field' => 'Multi select field'])],
    'url'             => ['url', 'javascript:alert(1)', fn () => trans('dam::app.admin.metadata-fields.validation.url', ['field' => 'Url field'])],
    'boolean'         => ['boolean', 'maybe', fn () => trans('dam::app.admin.metadata-fields.validation.boolean', ['field' => 'Boolean field'])],
    'text too long'   => ['text', str_repeat('x', 1001), fn () => trans('dam::app.admin.metadata-fields.validation.text-length', ['field' => 'Text field', 'max' => 1000])],
    'required, empty' => ['text', '', fn () => trans('dam::app.admin.metadata-fields.validation.required', ['field' => 'Text field'])],
]);

it('refuses an invalid value with the same 422 on the web editor, REST and bulk edit, and writes nothing', function (string $type, mixed $bad, string $message) {
    $field = mfvField($type, ['required' => true]);
    $asset = Asset::factory()->create();
    $key = 'fields.'.$field->code;

    $this->loginAsAdmin();
    $web = $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => [$field->code => $bad]])->assertUnprocessable();
    $bulk = $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$asset->id], 'fields' => [$field->code => $bad]])->assertUnprocessable();
    $rest = $this->withHeaders($this->getAuthenticationHeaders())->putJson(route('admin.api.dam.assets.update', $asset->id), ['fields' => [$field->code => $bad]])->assertUnprocessable();

    expect($web->json('errors')[$key] ?? null)->toBe([$message])
        ->and($bulk->json('errors')[$key] ?? null)->toBe([$message])
        ->and($rest->json('errors')[$key] ?? null)->toBe([$message])
        ->and(AssetProperty::query()->where('field_id', $field->id)->count())->toBe(0);
})->with('invalid values');

it('refuses an unknown or archived field code on every path', function () {
    $archived = mfvField('text', ['code' => 'gone', 'archived_at' => now()]);
    $asset = Asset::factory()->create();
    $message = trans('dam::app.admin.metadata-fields.validation.unknown', ['code' => 'gone']);

    $this->loginAsAdmin();
    expect($this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['gone' => 'x']])->assertUnprocessable()->json('errors')['fields.gone'])->toBe([$message])
        ->and($this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$asset->id], 'fields' => ['gone' => 'x']])->assertUnprocessable()->json('errors')['fields.gone'])->toBe([$message])
        ->and(AssetProperty::query()->where('field_id', $archived->id)->exists())->toBeFalse();
});

it('stores each type typed and reads it back the same on the web and over REST', function () {
    $fields = collect(['text', 'number', 'date', 'select', 'multi_select', 'url', 'boolean'])->map(fn ($type) => mfvField($type))->keyBy('type');
    $asset = Asset::factory()->create();

    $this->loginAsAdmin();
    $saved = $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => [
        'text_field'         => '  Spring launch ', 'number_field' => '12.50', 'date_field' => '2026-03-01', 'select_field' => 'green',
        'multi_select_field' => ['blue', 'red'], 'url_field' => 'https://example.com/brief', 'boolean_field' => '1',
    ]])->assertOk();

    $expected = [
        'text_field' => 'Spring launch', 'number_field' => 12.5, 'date_field' => '2026-03-01', 'select_field' => 'green',
        // Multi-select keeps the field's option order.
        'multi_select_field' => ['red', 'blue'], 'url_field' => 'https://example.com/brief', 'boolean_field' => true,
    ];
    expect($saved->json('values'))->toEqual($expected)
        ->and(AssetProperty::query()->where('field_id', $fields['number']->id)->value('value_number'))->toEqual(12.5)
        ->and(AssetProperty::query()->where('field_id', $fields['date']->id)->first()->value_date->format('Y-m-d'))->toBe('2026-03-01')
        // Free-text readers still see the value as text.
        ->and(AssetProperty::query()->where('field_id', $fields['multi_select']->id)->value('value'))->toBe('red, blue');

    $rest = $this->withHeaders($this->getAuthenticationHeaders())->getJson(route('admin.api.dam.assets.show', $asset->id))->assertOk();
    expect($rest->json('data.fields'))->toEqual($expected)
        ->and($this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->json('fields.*.value'))
        ->toEqual(array_values($expected));

    // REST writes go through the same validator and land in the same storage; null clears a field.
    $this->withHeaders($this->getAuthenticationHeaders())->putJson(route('admin.api.dam.assets.update', $asset->id), [
        'fields' => ['number_field' => -3, 'select_field' => null, 'boolean_field' => false],
    ])->assertOk()->assertJsonPath('fields.number_field', -3)->assertJsonPath('fields.boolean_field', false)->assertJsonMissingPath('fields.select_field');
});

it('routes a free-text property named after a field through the field, on the web and REST property endpoints', function () {
    $field = mfvField('number', ['code' => 'price', 'label' => 'Price']);
    $asset = Asset::factory()->create();
    $this->loginAsAdmin();

    $this->postJson(route('admin.dam.asset.property.store', $asset->id), ['name' => 'Price', 'type' => 'text', 'language' => '1', 'value' => 'cheap'])
        ->assertUnprocessable()->assertJsonPath('errors.value.0', trans('dam::app.admin.metadata-fields.validation.number', ['field' => 'Price']));
    $this->postJson(route('admin.dam.asset.property.store', $asset->id), ['name' => 'price', 'type' => 'text', 'language' => '1', 'value' => '9.99'])->assertOk();

    $row = AssetProperty::query()->where('dam_asset_id', $asset->id)->sole();
    expect($row->field_id)->toBe($field->id)->and($row->value_number)->toEqual(9.99);

    $headers = $this->getAuthenticationHeaders();
    $this->withHeaders($headers)->patchJson(route('admin.api.dam.property.update', $row->id), ['name' => 'Price', 'value' => 'free'])
        ->assertUnprocessable()->assertJsonPath('errors.value.0', trans('dam::app.admin.metadata-fields.validation.number', ['field' => 'Price']));
    $this->withHeaders($headers)->patchJson(route('admin.api.dam.property.update', $row->id), ['name' => 'Price', 'value' => '10'])->assertOk();
    expect($row->fresh()->value_number)->toEqual(10.0);

    // A property with no matching field stays free text.
    $this->postJson(route('admin.dam.asset.property.store', $asset->id), ['name' => 'Photographer', 'type' => 'text', 'language' => '1', 'value' => 'anyone'])->assertOk();
    expect(AssetProperty::query()->where('name', 'Photographer')->value('field_id'))->toBeNull();
});

it('never blocks an upload on a required field, and the modal shows what is missing until it is filled', function () {
    Storage::fake(Directory::getAssetDisk());
    $field = mfvField('text', ['code' => 'sku', 'label' => 'SKU', 'required' => true]);
    mfvField('text', ['code' => 'note', 'label' => 'Note']);
    $directory = Directory::factory()->create(['name' => 'Uploads', 'parent_id' => null]);
    $this->loginAsAdmin();

    $id = $this->postJson(route('admin.dam.assets.upload'), ['files' => [UploadedFile::fake()->image('shoe.png', 20, 20)], 'directory_id' => $directory->id])
        ->assertStatus(201)->json('files.0.id');

    $info = $this->getJson(route('admin.dam.assets.info', $id))->assertOk();
    expect($info->json('missing_required'))->toBe(['sku'])
        ->and($info->json('can_edit_fields'))->toBeTrue()
        ->and(collect($info->json('fields'))->firstWhere('code', 'sku'))->toMatchArray(['required' => true, 'value' => null]);

    // Editing enforces it: the modal sends every field, so a required one left empty is refused.
    $this->putJson(route('admin.dam.assets.fields.update', $id), ['fields' => ['sku' => '', 'note' => 'hello']])->assertUnprocessable()->assertJsonValidationErrors('fields.sku');
    $this->putJson(route('admin.dam.assets.fields.update', $id), ['fields' => ['sku' => 'SH-1', 'note' => '']])->assertOk()->assertJsonPath('missing_required', []);

    expect($this->getJson(route('admin.dam.assets.info', $id))->json('missing_required'))->toBe([])
        ->and($field->values()->count())->toBe(1);
});

it('bulk edit sets the same values on every selected asset it may open and skips hidden ones', function () {
    $field = mfvField('select', ['code' => 'colour', 'label' => 'Colour']);
    $granted = Directory::factory()->create(['name' => 'Granted', 'parent_id' => null]);
    $hiddenFolder = Directory::factory()->create(['name' => 'Hidden', 'parent_id' => null]);
    [$a, $b, $hidden] = [Asset::factory()->create(), Asset::factory()->create(), Asset::factory()->create()];
    $granted->assets()->attach([$a->id, $b->id]);
    $hiddenFolder->assets()->attach($hidden->id);
    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.asset', 'dam.asset.view', 'dam.asset.update']);

    $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$a->id, $b->id, $hidden->id], 'fields' => ['colour' => 'blue']])
        ->assertOk()->assertJsonPath('count', 2);

    $values = app(FieldValues::class)->forAssets(assetIds: [$a->id, $b->id, $hidden->id], fields: collect([$field]));
    expect($values)->toBe([$a->id => ['colour' => 'blue'], $b->id => ['colour' => 'blue'], $hidden->id => []]);

    // The modal's editor answers a hidden asset exactly like a missing one (debug off, as in production).
    config(['app.debug' => false]);
    $hiddenAnswer = $this->putJson(route('admin.dam.assets.fields.update', $hidden->id), ['fields' => ['colour' => 'red']]);
    $missingAnswer = $this->putJson(route('admin.dam.assets.fields.update', Asset::withTrashed()->max('id') + 100), ['fields' => ['colour' => 'red']]);
    expect($hiddenAnswer->getStatusCode())->toBe(404)
        ->and($hiddenAnswer->getContent())->toBe($missingAnswer->getContent());
});

it('refuses a stale editor save with 409 and keeps the value someone else saved (UX eval R4)', function () {
    mfvField('text', ['code' => 'note', 'label' => 'Note']);
    mfvField('number', ['code' => 'price', 'label' => 'Price']);
    $asset = Asset::factory()->create();
    $url = route('admin.dam.assets.fields.update', $asset->id);
    $stored = fn () => app(FieldValues::class)->forAsset($asset->id)['values'];

    $this->loginAsAdmin();
    $this->putJson($url, ['fields' => ['note' => 'theirs', 'price' => 12.5]])->assertOk();

    // The editor opened before "theirs" was saved, so it expects no value: nothing is written, not even the other field.
    $this->putJson($url, ['fields' => ['note' => 'mine', 'price' => 3], 'expected' => ['note' => null, 'price' => 12.5]])
        ->assertStatus(409)
        ->assertJsonPath('message', trans('dam::app.admin.metadata-fields.editor.conflict'))
        ->assertJsonPath('conflicts', ['note']);
    expect($stored())->toBe(['note' => 'theirs', 'price' => 12.5]);

    // A clear sent from a stale editor is refused the same way.
    $this->putJson($url, ['fields' => ['note' => null], 'expected' => ['note' => 'older']])->assertStatus(409);
    expect($stored()['note'])->toBe('theirs');

    // An editor that saw the current values saves, a number matching however it is spelled.
    $this->putJson($url, ['fields' => ['note' => 'mine', 'price' => 3], 'expected' => ['note' => 'theirs', 'price' => '12.50']])->assertOk();
    expect($stored())->toBe(['note' => 'mine', 'price' => 3.0]);

    // Without a precondition (REST, bulk) the write goes through as before.
    $this->putJson($url, ['fields' => ['note' => 'plain']])->assertOk();
    expect($stored()['note'])->toBe('plain');
});

/** [type, value saved meanwhile, what the stale editor expects, the same current value as an editor may send it, the write] */
dataset('stale preconditions', [
    'date'                         => ['date', '2026-03-01', '2026-02-01', '2026-03-01', '2026-04-01'],
    'boolean'                      => ['boolean', true, false, '1', false],
    'select'                       => ['select', 'green', 'red', 'green', 'blue'],
    'url'                          => ['url', 'https://example.com/a', 'https://example.com/b', 'https://example.com/a', 'https://example.com/c'],
    'reordered multi-select'       => ['multi_select', ['red', 'blue'], ['red'], ['blue', 'red'], ['green']],
    'empty multi-select'           => ['multi_select', null, ['red'], [], ['green']],
    'empty text, saved meanwhile'  => ['text', 'theirs', '', 'theirs', 'mine'],
    'explicit clear, stale editor' => ['number', 7, 8, '7.0', null],
]);

it('refuses a stale save in every field family with 409 and writes none of its fields (rev-B 5)', function (string $type, mixed $theirs, mixed $stale, mixed $fresh, mixed $mine) {
    mfvField($type, ['code' => 'subject', 'label' => 'Subject']);
    mfvField('text', ['code' => 'note', 'label' => 'Note']);
    $asset = Asset::factory()->create();
    $url = route('admin.dam.assets.fields.update', $asset->id);
    $stored = fn () => app(FieldValues::class)->forAsset($asset->id)['values'];

    $this->loginAsAdmin();
    $this->putJson($url, ['fields' => ['subject' => $theirs, 'note' => 'before']])->assertOk();
    $before = $stored();

    // The note's precondition holds; the subject's does not, so neither is written.
    $this->putJson($url, ['fields' => ['subject' => $mine, 'note' => 'after'], 'expected' => ['subject' => $stale, 'note' => 'before']])
        ->assertStatus(409)->assertJsonPath('conflicts', ['subject']);
    expect($stored())->toBe($before);

    // The current value however the editor spells it (reordered, '1', '7.0', empty) is no conflict.
    $this->putJson($url, ['fields' => ['subject' => $mine, 'note' => 'after'], 'expected' => ['subject' => $fresh, 'note' => 'before']])->assertOk();
    expect($stored()['subject'] ?? null)->toEqual($mine)->and($stored()['note'])->toBe('after');
})->with('stale preconditions');

it('needs dam.asset.update to change values', function () {
    mfvField('text', ['code' => 'sku', 'label' => 'SKU']);
    $folder = Directory::factory()->create(['name' => 'Granted', 'parent_id' => null]);
    $asset = Asset::factory()->create();
    $folder->assets()->attach($asset->id);
    actAsPlaybackRestrictedAdmin($folder, ['dam', 'dam.asset', 'dam.asset.view']);

    $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['sku' => 'x']])->assertForbidden();
    $this->postJson(route('admin.dam.assets.mass_update_fields'), ['indices' => [$asset->id], 'fields' => ['sku' => 'x']])->assertForbidden();
    expect($this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk()->json('can_edit_fields'))->toBeFalse();
});

it('shows a typed value once in the modal: in Details, not again under Properties, and hands the card its new lines', function () {
    mfvField('number', ['code' => 'price', 'label' => 'Price', 'card_priority' => 1]);
    $asset = Asset::factory()->create();
    AssetProperty::query()->create(['dam_asset_id' => $asset->id, 'name' => 'Photographer', 'type' => 'text', 'language' => '1', 'value' => 'Ana']);
    $this->loginAsAdmin();

    $this->putJson(route('admin.dam.assets.fields.update', $asset->id), ['fields' => ['price' => '12.50']])->assertOk()
        ->assertJsonPath('card_fields', [['code' => 'price', 'label' => 'Price', 'value' => '12.5']]);

    $info = $this->getJson(route('admin.dam.assets.info', $asset->id))->assertOk();
    expect($info->json('properties'))->toBe([['name' => 'Photographer', 'value' => 'Ana']])
        ->and(collect($info->json('fields'))->firstWhere('code', 'price')['value'])->toEqual(12.5);
});
