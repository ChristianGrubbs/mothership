<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\MetadataField;
use Mothership\DAM\Services\MetadataFields\FieldValues;

/*
 * P4a (D9): `dam:fields:adopt` turns free-text property names into typed fields. A dry run writes nothing; an apply
 * links what each field accepts and leaves every other row a working free-text property; both report counts that
 * reconcile (rows = linked + leftovers).
 */

function mfaProp(Asset $asset, string $name, string $value, string $language = '1'): AssetProperty
{
    return AssetProperty::query()->create(['dam_asset_id' => $asset->id, 'name' => $name, 'type' => 'text', 'language' => $language, 'value' => $value, 'is_filterable' => false]);
}

/** Runs the command and returns [exit code, decoded JSON report]. */
function mfaRun(array $options): array
{
    $code = Artisan::call('dam:fields:adopt', $options);
    $output = Artisan::output();

    return [$code, json_decode(substr($output, strpos($output, '{'), strrpos($output, '}') - strpos($output, '{') + 1), true)];
}

beforeEach(function () {
    $this->assets = Asset::factory()->count(16)->create()->values();
    $a = $this->assets;

    // Numbers, with one value a number field refuses and one empty.
    foreach ([['12.5'], ['7'], ['-3'], ['100']] as $i => [$value]) {
        mfaProp($a[$i], 'Price', $value);
    }
    mfaProp($a[4], 'price', '  ');
    // Dates: ISO dates and an ISO date-time, plus a second-language duplicate for one asset.
    mfaProp($a[0], 'Taken', '2026-03-01');
    mfaProp($a[1], 'Taken', '2026-04-15T10:30:00Z');
    mfaProp($a[1], 'Taken', '2026-04-16', '2');
    // A small set of distinct values becomes a select.
    foreach (['Red', 'Blue', 'Red', 'Green'] as $i => $value) {
        mfaProp($a[$i], 'Colour', $value);
    }
    // More than 12 distinct values stays text.
    foreach (range(0, 13) as $i) {
        mfaProp($a[$i], 'Caption', "caption number {$i}");
    }
});

it('reports the proposal on a dry run and writes nothing', function () {
    $before = AssetProperty::query()->orderBy('id')->get()->toArray();

    [$code, $report] = mfaRun(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($report['mode'])->toBe('dry-run')
        ->and($report['reconciled'])->toBeTrue()
        ->and(collect($report['fields'])->mapWithKeys(fn ($f) => [$f['code'] => $f['type']])->all())
        ->toBe(['caption' => 'text', 'colour' => 'select', 'price' => 'number', 'taken' => 'date'])
        ->and($report['totals'])->toMatchArray(['names' => 4, 'rows' => 26, 'linked' => 24, 'leftovers' => 2])
        ->and(MetadataField::query()->count())->toBe(0)
        ->and(AssetProperty::query()->orderBy('id')->get()->toArray())->toBe($before);
});

it('applies: creates typed fields, links what they accept, and reconciles every row', function () {
    [$code, $report] = mfaRun(['--apply' => true]);

    expect($code)->toBe(0)->and($report['reconciled'])->toBeTrue();

    $entries = collect($report['fields'])->keyBy('code');
    expect($entries['price'])->toMatchArray(['type' => 'number', 'rows' => 5, 'linked' => 4, 'reconciled' => true])
        ->and($entries['price']['leftovers'])->toBe(['duplicate' => 0, 'empty' => 1, 'invalid' => 0, 'total' => 1])
        ->and($entries['taken'])->toMatchArray(['type' => 'date', 'rows' => 3, 'linked' => 2])
        ->and($entries['taken']['leftovers'])->toBe(['duplicate' => 1, 'empty' => 0, 'invalid' => 0, 'total' => 1])
        ->and($entries['colour'])->toMatchArray(['type' => 'select', 'options' => 3, 'linked' => 4])
        ->and($entries['caption'])->toMatchArray(['type' => 'text', 'linked' => 14]);

    // The database agrees with the report.
    foreach ($entries as $codeName => $entry) {
        $field = MetadataField::query()->where('code', $codeName)->sole();
        expect(AssetProperty::query()->where('field_id', $field->id)->count())->toBe($entry['linked']);
    }

    $colour = MetadataField::query()->where('code', 'colour')->sole();
    expect($colour->optionValues())->toBe(['Blue', 'Green', 'Red']);

    // Typed values read back through the same service the modal and REST use.
    $values = app(FieldValues::class)->forAsset((int) $this->assets[1]->id)['values'];
    expect($values)->toMatchArray(['price' => 7.0, 'taken' => '2026-04-15', 'colour' => 'Blue', 'caption' => 'caption number 1']);

    // A second run finds nothing left to link: the leftovers are reported again, and nothing changes.
    [, $again] = mfaRun(['--apply' => true]);
    expect($again['totals'])->toMatchArray(['linked' => 0, 'leftovers' => 2, 'fields_created' => 0]);
});

it('leaves the leftovers working as free text after apply', function () {
    mfaRun(['--apply' => true]);
    $leftover = AssetProperty::query()->where('dam_asset_id', $this->assets[1]->id)->where('language', '2')->sole();
    expect($leftover->field_id)->toBeNull()->and($leftover->value)->toBe('2026-04-16');

    $this->loginAsAdmin();
    Directory::factory()->create(['name' => 'Shoots', 'parent_id' => null])->assets()->attach($this->assets[1]->id);

    // Editable through the property endpoint as free text: no date rule applies to it.
    $this->putJson(route('admin.dam.asset.properties.update', $this->assets[1]->id), ['id' => $leftover->id, 'name' => 'Taken', 'value' => 'sometime in April'])->assertOk();
    expect($leftover->fresh()->value)->toBe('sometime in April')->and($leftover->fresh()->field_id)->toBeNull();

    // Saving the typed field does not touch it.
    $this->putJson(route('admin.dam.assets.fields.update', $this->assets[1]->id), ['fields' => ['taken' => '2026-04-20']])->assertOk();
    expect($leftover->fresh()->value)->toBe('sometime in April');

    // And it still answers the free-text property filter.
    $this->getJson(route('admin.dam.explorer.index', ['scope' => 'all', 'filter_prop_Taken' => 'april']))->assertOk()
        ->assertJsonPath('meta.total_assets', 1);
});

it('reuses an active field whose code or label matches, and counts values it refuses as invalid', function () {
    $existing = MetadataField::query()->create(['code' => 'colour', 'label' => 'Colour', 'type' => 'select', 'options' => [['value' => 'Red', 'label' => 'Red'], ['value' => 'Blue', 'label' => 'Blue']], 'position' => 1]);

    [, $report] = mfaRun(['--apply' => true, '--name' => ['colour']]);
    $entry = $report['fields'][0];

    expect($report['totals']['names'])->toBe(1)
        ->and($entry)->toMatchArray(['code' => 'colour', 'existing' => true, 'rows' => 4, 'linked' => 3, 'reconciled' => true])
        ->and($entry['leftovers'])->toMatchArray(['invalid' => 1])
        ->and(MetadataField::query()->count())->toBe(1)
        ->and(AssetProperty::query()->where('field_id', $existing->id)->count())->toBe(3)
        ->and(AssetProperty::query()->where('value', 'Green')->value('field_id'))->toBeNull();
});

it('refuses to run without exactly one mode', function () {
    expect(Artisan::call('dam:fields:adopt'))->toBe(2)
        ->and(Artisan::call('dam:fields:adopt', ['--dry-run' => true, '--apply' => true]))->toBe(2)
        ->and(MetadataField::query()->count())->toBe(0);
});
