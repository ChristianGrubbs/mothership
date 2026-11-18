<?php

use Mothership\Category\Models\Category;

function categoryOptions(array $params): array
{
    return test()->getJson(route('admin.catalog.options.fetch-all', $params + ['entityName' => 'category']))
        ->assertOk()
        ->json('options');
}

it('lists categories for the product category filter', function () {
    $this->loginAsAdmin();

    $category = Category::factory()->create();

    $codes = array_column(categoryOptions(['page' => 1]), 'code');

    expect($codes)->toContain($category->code);
});

/**
 * The multiselect re-queries this endpoint with the selected codes to rebuild its chips.
 * Ignoring `identifiers` made every category on the first page look selected.
 */
it('returns only the requested codes when hydrating selected values', function () {
    $this->loginAsAdmin();

    $selected = Category::factory()->count(2)->create();

    Category::factory()->count(3)->create();

    $options = categoryOptions([
        'identifiers' => [
            'columnName' => 'code',
            'values'     => $selected->pluck('code')->all(),
        ],
    ]);

    expect(array_column($options, 'code'))->toEqualCanonicalizing($selected->pluck('code')->all());
});

/**
 * The search matches a substring of the code or the name, so a random factory code
 * as short as 'ck' also matched another category whose random name contained 'ck'.
 * Factory codes are at most 18 characters, so this longer code cannot occur inside
 * another fixture's code or name.
 */
it('searches categories by code', function () {
    $this->loginAsAdmin();

    $category = Category::factory()->create(['code' => 'categoryFilterSearchTarget']);

    Category::factory()->count(2)->create();

    $options = categoryOptions(['query' => $category->code]);

    expect(array_column($options, 'code'))->toBe([$category->code]);
});
