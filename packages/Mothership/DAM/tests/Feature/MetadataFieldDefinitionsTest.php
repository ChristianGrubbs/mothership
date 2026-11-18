<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\MetadataFields\FieldValueValidator;

/*
 * P4a (D9): custom metadata field definitions. The Library settings screen (web) and REST create, change, reorder,
 * delete and restore fields through one service, under their own ACL keys and API scopes.
 */

function mfdField(array $attributes = []): MetadataField
{
    return MetadataField::query()->create($attributes + [
        'code' => 'f_'.uniqid(), 'label' => 'Field '.uniqid(), 'type' => 'text', 'position' => (int) MetadataField::query()->max('position') + 1,
    ]);
}

function mfdGiveValue(MetadataField $field, mixed $value, ?Asset $asset = null): Asset
{
    $asset ??= Asset::factory()->create();
    app(FieldValues::class)->write(asset: $asset, values: app(FieldValueValidator::class)->validate([$field->code => $value]), source: 'test');

    return $asset;
}

it('creates, edits, reorders and lists fields from the Library settings screen', function () {
    $this->loginAsAdmin();

    $this->get(route('admin.dam.configuration.fields.index'))->assertOk()->assertSee('data-metadata-fields', false);

    $sku = $this->postJson(route('admin.dam.configuration.fields.store'), [
        'code' => 'sku', 'label' => 'SKU', 'type' => 'text', 'required' => true, 'card_priority' => 1, 'is_filterable' => true,
    ])->assertCreated()->json('field');
    $kind = $this->postJson(route('admin.dam.configuration.fields.store'), [
        'code'    => 'product_type', 'label' => 'Product type', 'type' => 'select',
        'options' => [['value' => 'shoe', 'label' => 'Shoe'], ['value' => 'bag']],
    ])->assertCreated()->json('field');

    expect($sku)->toMatchArray(['code' => 'sku', 'type' => 'text', 'required' => true, 'card_priority' => 1, 'is_filterable' => true])
        // An option without a label is labelled by its value.
        ->and($kind['options'])->toBe([['value' => 'shoe', 'label' => 'Shoe'], ['value' => 'bag', 'label' => 'bag']]);

    $this->putJson(route('admin.dam.configuration.fields.update', $sku['id']), ['label' => 'Stock unit', 'card_priority' => 2])
        ->assertOk()->assertJsonPath('field.label', 'Stock unit')->assertJsonPath('field.card_priority', 2);

    $this->putJson(route('admin.dam.configuration.fields.order'), ['ids' => [$kind['id'], $sku['id']]])->assertOk();
    expect($this->getJson(route('admin.dam.configuration.fields.index'))->assertOk()->json('fields.*.code'))->toBe(['product_type', 'sku']);

    // The order must name every active field exactly once.
    $this->putJson(route('admin.dam.configuration.fields.order'), ['ids' => [$sku['id']]])->assertUnprocessable()->assertJsonValidationErrors('ids');
});

it('refuses a malformed definition with 422', function () {
    $this->loginAsAdmin();
    $existing = mfdField(['code' => 'taken', 'card_priority' => 3]);
    $store = fn (array $body) => $this->postJson(route('admin.dam.configuration.fields.store'), $body + ['label' => 'X', 'type' => 'text']);

    $store(['code' => 'Bad Code'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $store(['code' => 'taken'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $store(['code' => 'kind', 'type' => 'colour'])->assertUnprocessable()->assertJsonValidationErrors('type');
    $store(['code' => 'kind', 'type' => 'select'])->assertUnprocessable()->assertJsonValidationErrors('options');
    $store(['code' => 'kind', 'type' => 'select', 'options' => [['value' => 'a'], ['value' => 'a']]])->assertUnprocessable()->assertJsonValidationErrors('options.1.value');
    $store(['code' => 'kind', 'card_priority' => 6])->assertUnprocessable()->assertJsonValidationErrors('card_priority');

    // The code is fixed once saved.
    $this->putJson(route('admin.dam.configuration.fields.update', $existing->id), ['code' => 'renamed'])->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('keeps card positions 1 to 5 unique, and a card payload lists them in priority order', function () {
    $this->loginAsAdmin();
    $fields = collect([3, 1, 5, 2, 4])->map(fn (int $priority) => mfdField(['code' => "card{$priority}", 'label' => "Card {$priority}", 'card_priority' => $priority]));
    $off = mfdField(['code' => 'off_card', 'label' => 'Off card']);

    $this->postJson(route('admin.dam.configuration.fields.store'), ['code' => 'sixth', 'label' => 'Sixth', 'type' => 'text', 'card_priority' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors('card_priority');
    $this->putJson(route('admin.dam.configuration.fields.update', $off->id), ['card_priority' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('card_priority');
    // The database enforces it too. A savepoint, so PostgreSQL does not abort the test transaction on the failed statement.
    expect(fn () => DB::transaction(fn () => MetadataField::query()->where('id', $off->id)->update(['card_priority' => 1])))->toThrow(QueryException::class);

    $asset = Asset::factory()->create();
    $fields->each(fn (MetadataField $field) => mfdGiveValue($field, "value {$field->card_priority}", $asset));
    mfdGiveValue($off, 'never on a card', $asset);

    expect(app(FieldValues::class)->cardFields([$asset->id])[$asset->id])->toBe([
        ['code' => 'card1', 'label' => 'Card 1', 'value' => 'value 1'],
        ['code' => 'card2', 'label' => 'Card 2', 'value' => 'value 2'],
        ['code' => 'card3', 'label' => 'Card 3', 'value' => 'value 3'],
        ['code' => 'card4', 'label' => 'Card 4', 'value' => 'value 4'],
        ['code' => 'card5', 'label' => 'Card 5', 'value' => 'value 5'],
    ]);
});

it('deletes an unused field, archives a used one only on confirmation, and restores it with its values', function () {
    $this->loginAsAdmin();
    $unused = mfdField();
    $used = mfdField(['card_priority' => 1, 'is_filterable' => true]);
    $asset = mfdGiveValue($used, 'kept');

    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $unused->id))->assertOk()->assertJsonPath('deleted', true);
    expect(MetadataField::query()->find($unused->id))->toBeNull();

    // Without confirmation nothing changes: 409 with the value count.
    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $used->id))
        ->assertStatus(409)->assertJsonPath('requires_confirmation', true)->assertJsonPath('values', 1);
    expect($used->fresh()->archived_at)->toBeNull();

    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $used->id), ['confirm' => true])
        ->assertOk()->assertJsonPath('archived', true);
    $archived = $used->fresh();
    expect($archived->archived_at)->not->toBeNull()
        ->and($archived->card_priority)->toBeNull()
        ->and(AssetProperty::query()->where('field_id', $used->id)->count())->toBe(1)
        // An archived field is out of the editor, cards and validation.
        ->and(app(FieldValues::class)->forAsset($asset->id)['values'])->toBe([])
        ->and(fn () => app(FieldValueValidator::class)->validate([$used->code => 'x']))->toThrow(ValidationException::class);

    $this->postJson(route('admin.dam.configuration.fields.restore', $used->id))->assertOk();
    expect(app(FieldValues::class)->forAsset($asset->id)['values'])->toBe([$used->code => 'kept']);
});

it('renaming a field renames its values, and the type and in-use options are locked once values exist', function () {
    $this->loginAsAdmin();
    $field = mfdField(['code' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]);
    $asset = mfdGiveValue($field, 'a');

    $this->putJson(route('admin.dam.configuration.fields.update', $field->id), ['label' => 'Product kind'])->assertOk();
    expect(AssetProperty::query()->where('dam_asset_id', $asset->id)->value('name'))->toBe('Product kind');

    $this->putJson(route('admin.dam.configuration.fields.update', $field->id), ['type' => 'text'])->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->putJson(route('admin.dam.configuration.fields.update', $field->id), ['options' => [['value' => 'b']]])->assertUnprocessable()->assertJsonValidationErrors('options');
    // Dropping an unused option is fine.
    $this->putJson(route('admin.dam.configuration.fields.update', $field->id), ['options' => [['value' => 'a', 'label' => 'A']]])->assertOk();
});

it('gates the settings screen on the dam.metadata_fields keys', function () {
    $field = mfdField();
    $this->loginWithPermissions(permissions: ['dam', 'dam.asset', 'dam.asset.view']);

    $this->get(route('admin.dam.configuration.fields.index'))->assertForbidden();
    $this->postJson(route('admin.dam.configuration.fields.store'), ['code' => 'nope', 'label' => 'N', 'type' => 'text'])->assertForbidden();
    $this->putJson(route('admin.dam.configuration.fields.update', $field->id), ['label' => 'N'])->assertForbidden();
    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $field->id))->assertForbidden();

    // View only: the list opens, writes stay refused.
    $this->loginWithPermissions(permissions: ['dam', 'dam.metadata_fields']);
    $this->getJson(route('admin.dam.configuration.fields.index'))->assertOk();
    $this->postJson(route('admin.dam.configuration.fields.store'), ['code' => 'nope', 'label' => 'N', 'type' => 'text'])->assertForbidden();

    $this->loginWithPermissions(permissions: ['dam', 'dam.metadata_fields', 'dam.metadata_fields.create']);
    $this->postJson(route('admin.dam.configuration.fields.store'), ['code' => 'yes', 'label' => 'Y', 'type' => 'text'])->assertCreated();
    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $field->id))->assertForbidden();
});

it('lists field definitions over REST for any key that may list assets, and writes them only with the field scopes', function () {
    $field = mfdField(['code' => 'sku', 'label' => 'SKU']);
    mfdField(['code' => 'old', 'archived_at' => now()]);

    $reader = $this->getAuthenticationHeaders('custom', ['api.dam', 'api.dam.assets']);
    expect($this->withHeaders($reader)->getJson(route('admin.api.dam.metadata_fields.index'))->assertOk()->json('data.*.code'))->toBe(['sku']);
    $this->withHeaders($reader)->postJson(route('admin.api.dam.metadata_fields.store'), ['code' => 'x', 'label' => 'X', 'type' => 'text'])->assertForbidden();
    $this->withHeaders($reader)->putJson(route('admin.api.dam.metadata_fields.update', $field->id), ['label' => 'X'])->assertForbidden();
    $this->withHeaders($reader)->deleteJson(route('admin.api.dam.metadata_fields.destroy', $field->id))->assertForbidden();

    // The api guard keeps the user it resolved; a second key in one test needs fresh guards.
    app('auth')->forgetGuards();
    $writer = $this->getAuthenticationHeaders('custom', ['api.dam', 'api.dam.assets', 'api.dam.metadata-fields', 'api.dam.metadata-fields.create', 'api.dam.metadata-fields.update', 'api.dam.metadata-fields.delete']);
    $this->withHeaders($writer)->postJson(route('admin.api.dam.metadata_fields.store'), ['code' => 'price', 'label' => 'Price', 'type' => 'number', 'is_filterable' => true])
        ->assertCreated()->assertJsonPath('data.type', 'number');
    // REST answers the same 422 the screen does.
    $this->withHeaders($writer)->postJson(route('admin.api.dam.metadata_fields.store'), ['code' => 'price', 'label' => 'Again', 'type' => 'number'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->withHeaders($writer)->putJson(route('admin.api.dam.metadata_fields.update', $field->id), ['label' => 'Stock unit'])->assertOk()->assertJsonPath('data.label', 'Stock unit');
    $this->withHeaders($writer)->putJson(route('admin.api.dam.metadata_fields.update', 999999), ['label' => 'X'])->assertNotFound();

    mfdGiveValue($field, 'A-1');
    $this->withHeaders($writer)->deleteJson(route('admin.api.dam.metadata_fields.destroy', $field->id))->assertStatus(409);
    $this->withHeaders($writer)->deleteJson(route('admin.api.dam.metadata_fields.destroy', $field->id), ['confirm' => true])->assertOk()->assertJsonPath('archived', true);
});

it('never counts a hidden asset in the field screen or the delete confirmation, but still archives for it', function () {
    $field = mfdField(['code' => 'sku', 'label' => 'SKU']);
    $granted = Directory::factory()->create(['parent_id' => null]);
    $private = Directory::factory()->create(['parent_id' => null]);
    $granted->assets()->attach(mfdGiveValue($field, 'A-1')->id);
    $private->assets()->attach(mfdGiveValue($field, 'B-1')->id);
    $onlyHidden = mfdField(['code' => 'secret', 'label' => 'Secret']);
    $private->assets()->attach(mfdGiveValue($onlyHidden, 'x')->id);

    actAsPlaybackRestrictedAdmin($granted, ['dam', 'dam.metadata_fields', 'dam.metadata_fields.delete']);

    $counts = collect($this->getJson(route('admin.dam.configuration.fields.index'))->assertOk()->json('fields'))->pluck('values_count', 'code');
    expect($counts->all())->toBe(['sku' => 1, 'secret' => 0]);

    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $field->id))->assertStatus(409)->assertJsonPath('values', 1);
    // Values the admin cannot see still make a delete an archive, never a hard delete.
    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $onlyHidden->id))->assertStatus(409)->assertJsonPath('values', 0);
    $this->deleteJson(route('admin.dam.configuration.fields.destroy', $onlyHidden->id), ['confirm' => true])->assertOk()->assertJsonPath('archived', true);
    expect(AssetProperty::query()->where('field_id', $onlyHidden->id)->count())->toBe(1);
});

it('says an archived field holds the code, rather than the generic taken message', function () {
    $this->loginAsAdmin();
    mfdField(['code' => 'old_code', 'archived_at' => now()]);
    mfdField(['code' => 'live_code']);

    $post = fn (string $code) => $this->postJson(route('admin.dam.configuration.fields.store'), ['code' => $code, 'label' => 'Again', 'type' => 'text'])->assertUnprocessable();

    expect($post('old_code')->json('errors.code.0'))->toBe(trans('dam::app.admin.metadata-fields.errors.code-archived'))
        ->and($post('live_code')->json('errors.code.0'))->toBe(trans('dam::app.admin.metadata-fields.errors.code-taken'));
});
