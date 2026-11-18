<?php

declare(strict_types=1);

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\Tag;

beforeEach(fn () => $this->loginAsAdmin());

it('finds an asset by its tag name via the explorer global search', function () {
    $dir = Directory::factory()->create(['name' => 'Campaign', 'parent_id' => null]);

    $tagged = Asset::factory()->create(['file_name' => 'IMG_001.jpg']);
    $tagged->directories()->attach($dir->id);
    $tagged->tags()->attach(Tag::create(['name' => 'sunset'])->id);

    $untagged = Asset::factory()->create(['file_name' => 'IMG_002.jpg']);
    $untagged->directories()->attach($dir->id);

    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $dir->id,
        'search'       => 'sunset',
    ]));

    $response->assertOk();
    $response->assertJsonFragment(['file_name' => 'IMG_001.jpg']);
    $response->assertJsonMissing(['file_name' => 'IMG_002.jpg']);
    expect($response->json('meta.total_assets'))->toBe(1);
});

it('matches tags case-insensitively and on partial terms', function () {
    $dir = Directory::factory()->create(['name' => 'Campaign', 'parent_id' => null]);

    $asset = Asset::factory()->create(['file_name' => 'IMG_010.jpg']);
    $asset->directories()->attach($dir->id);
    $asset->tags()->attach(Tag::create(['name' => 'Summer Sale'])->id);

    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $dir->id,
        'search'       => 'summer',
    ]));

    $response->assertOk();
    $response->assertJsonFragment(['file_name' => 'IMG_010.jpg']);
    expect($response->json('meta.total_assets'))->toBe(1);
});

it('still finds an asset by its file name (regression)', function () {
    $dir = Directory::factory()->create(['name' => 'Campaign', 'parent_id' => null]);

    $asset = Asset::factory()->create(['file_name' => 'beach-holiday.jpg']);
    $asset->directories()->attach($dir->id);

    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $dir->id,
        'search'       => 'beach',
    ]));

    $response->assertOk();
    $response->assertJsonFragment(['file_name' => 'beach-holiday.jpg']);
    expect($response->json('meta.total_assets'))->toBe(1);
});

it('returns each asset once even when multiple of its tags match the term', function () {
    $dir = Directory::factory()->create(['name' => 'Campaign', 'parent_id' => null]);

    $asset = Asset::factory()->create(['file_name' => 'IMG_020.jpg']);
    $asset->directories()->attach($dir->id);
    $asset->tags()->attach(Tag::create(['name' => 'travel'])->id);
    $asset->tags()->attach(Tag::create(['name' => 'travel-blog'])->id);

    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id' => $dir->id,
        'search'       => 'travel',
    ]));

    $response->assertOk();
    expect($response->json('meta.total_assets'))->toBe(1);
    expect($response->json('assets'))->toHaveCount(1);
});

it('finds a decomposed-accent name from a precomposed query and a literal percent from a percent query', function () {
    $dir = Directory::factory()->create(['name' => 'Accents', 'parent_id' => null]);

    $decomposed = Asset::factory()->create(['file_name' => Normalizer::normalize('Café résumé.jpg', Normalizer::FORM_D)]);
    $decomposed->directories()->attach($dir->id);
    $percent = Asset::factory()->create(['file_name' => '100% done.jpg']);
    $percent->directories()->attach($dir->id);
    $plain = Asset::factory()->create(['file_name' => 'plain photo.jpg']);
    $plain->directories()->attach($dir->id);

    $search = fn (string $term) => $this->getJson(route('admin.dam.explorer.index', ['directory_id' => $dir->id, 'search' => $term]))->assertOk();

    expect($search(Normalizer::normalize('résumé', Normalizer::FORM_C))->json('meta.total_assets'))->toBe(1)
        ->and($search('%')->json('meta.total_assets'))->toBe(1)
        ->and($search('_')->json('meta.total_assets'))->toBe(0)
        ->and($search('plain jpg')->json('meta.total_assets'))->toBe(1);
});

it('reads an inverted created-date range the right way round and says so', function () {
    $dir = Directory::factory()->create(['name' => 'Dated', 'parent_id' => null]);
    $asset = Asset::factory()->create(['file_name' => 'dated.jpg', 'created_at' => '2025-06-01 12:00:00']);
    $asset->directories()->attach($dir->id);

    $response = $this->getJson(route('admin.dam.explorer.index', [
        'directory_id'        => $dir->id,
        'filter_created_from' => '2030-01-01',
        'filter_created_to'   => '2020-01-01',
    ]))->assertOk();

    expect($response->json('meta.date_range_swapped'))->toBeTrue()
        ->and($response->json('meta.total_assets'))->toBe(1);
});
