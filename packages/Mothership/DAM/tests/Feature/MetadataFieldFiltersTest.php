<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Models\Tag;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\MetadataFields\FieldValueValidator;
use Mothership\User\Models\Admin;
use Mothership\User\Models\Role;

/*
 * P4a (D10 part): typed field filters in the Library listing and the REST asset listing, each alone and with text
 * search; the existing tag and free-text property filters; and a hidden asset never matching or being counted.
 */

beforeEach(function () {
    config(['ai-index.search_enabled' => false]);

    $field = fn (string $code, string $type, array $extra = []) => MetadataField::query()->create($extra + [
        'code' => $code, 'label' => ucfirst($code), 'type' => $type, 'is_filterable' => true, 'position' => (int) MetadataField::query()->max('position') + 1,
    ]);
    $field('sku', 'text');
    $field('price', 'number');
    $field('shot_on', 'date');
    $field('colour', 'select', ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]);
    $field('channels', 'multi_select', ['options' => [['value' => 'web', 'label' => 'Web'], ['value' => 'print', 'label' => 'Print'], ['value' => 'social', 'label' => 'Social']]]);
    $field('licensed', 'boolean');
    $field('brief', 'url');
    $field('internal', 'text', ['is_filterable' => false]);

    $this->folder = Directory::factory()->create(['name' => 'Catalogue', 'parent_id' => null]);
    $this->hiddenFolder = Directory::factory()->create(['name' => 'Private', 'parent_id' => null]);

    $make = function (string $name, array $values, ?Directory $folder = null) {
        $asset = Asset::factory()->create(['file_name' => $name]);
        ($folder ?? $this->folder)->assets()->attach($asset->id);
        app(FieldValues::class)->write(asset: $asset, values: app(FieldValueValidator::class)->validate($values), source: 'test');

        return $asset;
    };

    $this->shoe = $make('spring-shoe.jpg', ['sku' => 'SH-100', 'price' => 49.5, 'shot_on' => '2026-03-01', 'colour' => 'red', 'channels' => ['web', 'print'], 'licensed' => true, 'brief' => 'https://example.com/shoe']);
    $this->bag = $make('spring-bag.jpg', ['sku' => 'BG-200', 'price' => 120, 'shot_on' => '2026-05-20', 'colour' => 'blue', 'channels' => ['social'], 'licensed' => false, 'brief' => 'https://example.com/bag']);
    $this->hat = $make('autumn-hat.jpg', ['sku' => 'HT-300', 'price' => 15, 'colour' => 'red', 'internal' => 'secret']);
    // Matches every filter below, but lives where the member cannot see it.
    $this->hidden = $make('spring-hidden.jpg', ['sku' => 'SH-999', 'price' => 50, 'shot_on' => '2026-03-02', 'colour' => 'red', 'channels' => ['web', 'print', 'social'], 'licensed' => true, 'brief' => 'https://example.com/shoe-hidden'], $this->hiddenFolder);

    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dam', 'dam.asset', 'dam.asset.view']]);
    DB::table('dam_directory_role')->insert(['directory_id' => $this->folder->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->member = Admin::factory()->create(['role_id' => $role->id]);
    $this->actingAs($this->member, 'admin');
    app(DirectoryPermissionService::class)->flush();
});

/** The listed asset ids and the listing's count, for one Library request. */
function mffList(array $params): array
{
    $response = test()->getJson(route('admin.dam.explorer.index', ['scope' => 'all'] + $params))->assertOk();

    return [collect($response->json('assets'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(), (int) $response->json('meta.total_assets')];
}

function mffIds(Asset ...$assets): array
{
    return collect($assets)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

it('filters each field type on its own, and never lists or counts a hidden asset', function (string $code, mixed $filter, array $expected) {
    [$ids, $total] = mffList(['filter_field' => [$code => $filter]]);
    $want = mffIds(...array_map(fn ($name) => $this->{$name}, $expected));

    expect($ids)->toBe($want)
        ->and($total)->toBe(count($want))
        ->and($ids)->not->toContain((int) $this->hidden->id);
})->with([
    'text contains'      => ['sku', 'sh-', ['shoe']],
    'url contains'       => ['brief', 'example.com/bag', ['bag']],
    'number min'         => ['price', ['min' => 40], ['shoe', 'bag']],
    'number range'       => ['price', ['min' => 10, 'max' => 50], ['shoe', 'hat']],
    'date range'         => ['shot_on', ['from' => '2026-03-01', 'to' => '2026-03-31'], ['shoe']],
    'date open end'      => ['shot_on', ['from' => '2026-04-01'], ['bag']],
    'select any of'      => ['colour', ['red'], ['shoe', 'hat']],
    'multi-select any'   => ['channels', ['print', 'social'], ['shoe', 'bag']],
    'boolean yes'        => ['licensed', '1', ['shoe']],
    'boolean no'         => ['licensed', '0', ['bag']],
]);

it('combines field filters with text search and with each other', function () {
    expect(mffList(['search' => 'spring', 'filter_field' => ['colour' => ['red']]]))->toBe([mffIds($this->shoe), 1])
        ->and(mffList(['search' => 'spring', 'filter_field' => ['price' => ['min' => 100]]]))->toBe([mffIds($this->bag), 1])
        ->and(mffList(['search' => 'autumn', 'filter_field' => ['channels' => ['web']]]))->toBe([[], 0])
        ->and(mffList(['filter_field' => ['colour' => ['red'], 'price' => ['max' => 20]]]))->toBe([mffIds($this->hat), 1])
        // With the existing filters too.
        ->and(mffList(['filter_file_name' => 'bag', 'filter_field' => ['licensed' => '0']]))->toBe([mffIds($this->bag), 1]);
});

it('ignores unknown, archived and non-filterable codes, and refuses a malformed value', function () {
    MetadataField::query()->where('code', 'colour')->update(['archived_at' => now()]);

    expect(mffList(['filter_field' => ['nope' => 'x', 'colour' => ['blue'], 'internal' => 'secret']]))->toBe([mffIds($this->shoe, $this->bag, $this->hat), 3]);

    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'filter_field' => ['price' => ['min' => 'cheap']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('filter_field.price');
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'filter_field' => ['shot_on' => ['from' => '2026-13-01']]]))
        ->assertUnprocessable();
});

it('offers filterable field definitions in the filter options, without repeating adopted property names', function () {
    AssetProperty::query()->create(['dam_asset_id' => $this->shoe->id, 'name' => 'Photographer', 'type' => 'text', 'language' => '1', 'value' => 'Ana', 'is_filterable' => true]);
    AssetProperty::query()->where('field_id', MetadataField::query()->where('code', 'sku')->value('id'))->update(['is_filterable' => true]);

    $options = $this->getJson(route('admin.dam.explorer.filter-options'))->assertOk();

    expect($options->json('fields.*.code'))->toBe(['sku', 'price', 'shot_on', 'colour', 'channels', 'licensed', 'brief'])
        ->and(collect($options->json('fields'))->firstWhere('code', 'colour')['options'])->toBe([['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']])
        ->and($options->json('properties'))->toBe(['Photographer']);
});

it('puts the card fields on each listed asset, in priority order', function () {
    MetadataField::query()->where('code', 'price')->update(['card_priority' => 1]);
    MetadataField::query()->where('code', 'colour')->update(['card_priority' => 2]);
    MetadataField::query()->where('code', 'channels')->update(['card_priority' => 3]);

    $assets = collect($this->getJson(route('admin.dam.explorer.index', ['scope' => 'all']))->assertOk()->json('assets'))->keyBy('id');

    expect($assets[$this->shoe->id]['card_fields'])->toBe([
        ['code' => 'price', 'label' => 'Price', 'value' => '49.5'],
        ['code' => 'colour', 'label' => 'Colour', 'value' => 'Red'],
        ['code' => 'channels', 'label' => 'Channels', 'value' => 'Web, Print'],
    ])->and($assets[$this->hat->id]['card_fields'])->toBe([
        ['code' => 'price', 'label' => 'Price', 'value' => '15'],
        ['code' => 'colour', 'label' => 'Colour', 'value' => 'Red'],
    ]);
});

it('filters by tag (one tag, substring), alone and with search, leaving hidden assets out', function () {
    $tag = Tag::create(['name' => 'Summer campaign']);
    foreach ([$this->shoe, $this->hat, $this->hidden] as $asset) {
        $asset->tags()->attach($tag->id);
    }

    expect(mffList(['filter_tag' => 'summer']))->toBe([mffIds($this->shoe, $this->hat), 2])
        ->and(mffList(['filter_tag' => 'CAMPAIGN', 'search' => 'autumn']))->toBe([mffIds($this->hat), 1])
        ->and(mffList(['filter_tag' => 'winter']))->toBe([[], 0]);
});

it('filters by a free-text property (filter_prop_*), alone and with search, leaving hidden assets out', function () {
    foreach ([[$this->shoe, 'Ana Lopez'], [$this->bag, 'Ben Ng'], [$this->hidden, 'Ana Hidden']] as [$asset, $who]) {
        AssetProperty::query()->create(['dam_asset_id' => $asset->id, 'name' => 'Photographer', 'type' => 'text', 'language' => '1', 'value' => $who, 'is_filterable' => true]);
    }

    expect(mffList(['filter_prop_Photographer' => 'ana']))->toBe([mffIds($this->shoe), 1])
        ->and(mffList(['filter_prop_Photographer' => 'n', 'search' => 'bag']))->toBe([mffIds($this->bag), 1])
        ->and(mffList(['filter_prop_Photographer' => 'nobody']))->toBe([[], 0]);
});

it('filters by a property whose name has a space or a dot, sent either way', function () {
    // PHP rewrites a space or dot in a query KEY to an underscore, so `filter_prop_Date Taken` used to arrive as `Date_Taken`.
    foreach ([[$this->shoe, 'Date Taken', 'march'], [$this->bag, 'Date Taken', 'may'], [$this->shoe, 'Lens v1.5', 'prime'], [$this->hidden, 'Date Taken', 'march']] as [$asset, $name, $value]) {
        AssetProperty::query()->create(['dam_asset_id' => $asset->id, 'name' => $name, 'type' => 'text', 'language' => '1', 'value' => $value, 'is_filterable' => true]);
    }

    expect(mffList(['filter_prop_Date Taken' => 'march']))->toBe([mffIds($this->shoe), 1])
        ->and(mffList(['filter_prop_Lens v1.5' => 'prime']))->toBe([mffIds($this->shoe), 1])
        ->and(mffList(['filter_props' => ['Date Taken' => 'may']]))->toBe([mffIds($this->bag), 1])
        ->and(mffList(['filter_props' => ['Lens v1.5' => 'prime', 'Date Taken' => 'march']]))->toBe([mffIds($this->shoe), 1])
        ->and(mffList(['filter_prop_Date Taken' => 'nobody']))->toBe([[], 0]);
});

it('offers the same field filters on the REST asset listing', function () {
    // An API request carries no Library session: drop the member signed in above.
    app('auth')->forgetGuards();
    app(DirectoryPermissionService::class)->flush();
    $headers = $this->getAuthenticationHeaders();
    $rest = fn (array $filters) => collect($this->withHeaders($headers)->getJson(route('admin.api.dam.assets.index', ['filters' => json_encode($filters), 'limit' => 100]))
        ->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    // A full-access key sees the private folder too, so the hidden asset is a match here.
    expect($rest(['field.price' => [['operator' => 'BETWEEN', 'value' => [40, 60]]]]))->toBe(mffIds($this->shoe, $this->hidden))
        ->and($rest(['field.price' => [['operator' => '>', 'value' => 49.5]]]))->toBe(mffIds($this->hidden, $this->bag))
        ->and($rest(['field.colour' => [['operator' => 'IN', 'value' => ['blue']]]]))->toBe(mffIds($this->bag))
        ->and($rest(['field.channels' => [['operator' => '=', 'value' => 'social']]]))->toBe(mffIds($this->bag, $this->hidden))
        ->and($rest(['field.licensed' => [['operator' => '=', 'value' => false]]]))->toBe(mffIds($this->bag))
        ->and($rest(['field.shot_on' => [['operator' => '<=', 'value' => '2026-03-01']]]))->toBe(mffIds($this->shoe))
        ->and($rest(['field.sku' => [['operator' => 'LIKE', 'value' => 'bg']]]))->toBe(mffIds($this->bag))
        ->and($rest(['field.sku' => [['operator' => '=', 'value' => 'ht-300']]]))->toBe(mffIds($this->hat));

    $this->withHeaders($headers)->getJson(route('admin.api.dam.assets.index', ['filters' => json_encode(['field.internal' => [['operator' => '=', 'value' => 'secret']]])]))
        ->assertUnprocessable();
});
