<?php

use Illuminate\Support\Str;
use Mothership\Admin\Tests\AdminTestCase;
use Mothership\AdminApi\Tests\ApiTestCase;
use Mothership\Attribute\Tests\AttributeTestCase;
use Mothership\Category\Tests\CategoryTestCase;
use Mothership\Completeness\Tests\CompletenessTestCase;
use Mothership\Core\Models\Channel;
use Mothership\Core\Tests\CoreTestCase;
use Mothership\DAM\Tests\DamTestCase;
use Mothership\DataGrid\Tests\DataGridTestCase;
use Mothership\Installer\Tests\UserCreateCommandTestCase;
use Mothership\Measurement\Tests\MeasurementTestCase;
use Mothership\Product\Models\Product;
use Mothership\Product\Tests\ProductTestCase;
use Mothership\User\Tests\UserTestCase;

ini_set('memory_limit', getenv('PEST_MEMORY_LIMIT') ?: '1024M');

/*
|--------------------------------------------------------------------------
| Cross-file test helpers
|--------------------------------------------------------------------------
|
| Pest only requires the spec file(s) named on its command line, so a plain
| top-level function declared in one *Test.php file is invisible to a
| sibling spec run in isolation (e.g. a single-file verify.py invocation).
| Helpers shared across a package's spec files are required here instead.
|
*/

require_once __DIR__.'/../packages/Mothership/DAM/tests/Support/DamTestHelpers.php';
require_once __DIR__.'/../packages/Mothership/DAM/tests/Support/GuestIntakeHelpers.php';
require_once __DIR__.'/../packages/Mothership/DAM/tests/Support/GameAssetFixtures.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(CoreTestCase::class)->in('../packages/Mothership/Core/tests');
uses(AdminTestCase::class)->in('../packages/Mothership/Admin/tests');
uses(ApiTestCase::class)->in('../packages/Mothership/AdminApi/tests');
uses(UserTestCase::class)->in('../packages/Mothership/User/tests');
uses(DataGridTestCase::class)->in('../packages/Mothership/DataGrid/tests');
uses(UserCreateCommandTestCase::class)->in('../packages/Mothership/Installer/tests');
uses(CoreTestCase::class)->in('../packages/Mothership/ElasticSearch/tests');
uses(AdminTestCase::class)->in('../packages/Mothership/DataTransfer/tests');
uses(CompletenessTestCase::class)->in('../packages/Mothership/Completeness/tests');
uses(ProductTestCase::class)->in('../packages/Mothership/Product/tests');
uses(AttributeTestCase::class)->in('../packages/Mothership/Attribute/tests');
uses(CategoryTestCase::class)->in('../packages/Mothership/Category/tests');
uses(MeasurementTestCase::class)->in('../packages/Mothership/Measurement/tests');
uses(CoreTestCase::class)->in('../packages/Mothership/AiAgent/tests');
uses(CoreTestCase::class)->in('../packages/Mothership/Webhook/tests');
uses(AdminTestCase::class)->in('../packages/Mothership/AppUrlGuard/tests');
uses(DamTestCase::class)->in('../packages/Mothership/DAM/tests');
uses(AdminTestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| Coverage edges only capture executed PHP, so assets that are compiled or read at runtime
| (Blade views, language files, JS) would otherwise look untouched to TIA. Map each package's
| non-PHP sources back to its own test directory, and treat schema changes as global.
|
*/

$packageTestPaths = array_map(
    fn (string $path): string => 'packages/Mothership/'.basename(dirname($path)).'/tests',
    glob(dirname(__DIR__).'/packages/Mothership/*/tests', GLOB_ONLYDIR) ?: [],
);

pest()->tia()->baselined();

foreach ($packageTestPaths as $testPath) {
    $package = dirname($testPath);

    pest()->tia()->watch([
        $package.'/src/Resources/views/**'  => $testPath,
        $package.'/src/Resources/lang/**'   => $testPath,
        $package.'/src/Resources/assets/**' => $testPath,
        $package.'/src/Config/**'           => $testPath,
    ]);

    // Only `database/migrations` is classified as schema by the engine itself, so package
    // migrations need the mapping; AiAgent keeps its own at the package root.
    pest()->tia()->watch([
        'packages/Mothership/*/src/Database/Migrations/**' => $testPath,
        'packages/Mothership/*/Database/Migration/**'      => $testPath,
        'database/seeders/**'                              => $testPath,
    ]);
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Seed the family's required attribute values on a factory product.
 *
 * A save is checked against every required attribute of the family, not only the ones the request
 * carried, so a bare factory product cannot be updated with a partial payload until these exist.
 */
function seedRequiredProductValues(Product $product): Product
{
    $scoped = Channel::with(['locales', 'currencies'])->get()->mapWithKeys(fn ($channel) => [
        $channel->code => $channel->locales->pluck('code')->mapWithKeys(fn ($locale) => [
            $locale => [
                'name'              => 'Test Product '.$product->sku,
                'short_description' => 'Short description',
                'description'       => 'Description',
                'price'             => $channel->currencies->pluck('code')
                    ->mapWithKeys(fn ($code) => [$code => '100'])
                    ->all(),
            ],
        ])->all(),
    ])->all();

    $product->values = array_replace_recursive([
        'common' => [
            'sku'     => $product->sku,
            'url_key' => Str::slug($product->sku),
            'weight'  => '1',
        ],
        'channel_locale_specific' => $scoped,
    ], $product->values ?? []);

    $product->save();

    return $product;
}
