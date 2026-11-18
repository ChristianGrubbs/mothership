<?php

namespace Tests\Mothership\DataTransfer\Unit\Helpers\Importers\Category;

use Mothership\DataTransfer\Helpers\Importers\Attribute\Importer as AttributeImporter;
use Mothership\DataTransfer\Helpers\Importers\Category\Importer;
use Mothership\DataTransfer\Helpers\Importers\CategoryField\Importer as CategoryFieldImporter;
use ReflectionClass;

function validColumnNamesOf(string $importer): array
{
    return (new ReflectionClass($importer))->getDefaultProperties()['validColumnNames'];
}

it('accepts the productCounts column written by the category export', function () {
    expect(validColumnNamesOf(Importer::class))->toContain('productCounts');
});

it('keeps the productCounts allowance consistent across catalog importers', function () {
    expect(validColumnNamesOf(AttributeImporter::class))->toContain('productCounts')
        ->and(validColumnNamesOf(CategoryFieldImporter::class))->toContain('productCounts');
});
