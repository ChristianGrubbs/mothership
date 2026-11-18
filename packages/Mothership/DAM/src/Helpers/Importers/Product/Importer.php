<?php

namespace Mothership\DAM\Helpers\Importers\Product;

use Mothership\Attribute\Repositories\AttributeFamilyRepository;
use Mothership\Attribute\Repositories\AttributeOptionRepository;
use Mothership\Attribute\Repositories\AttributeRepository;
use Mothership\Category\Repositories\CategoryRepository;
use Mothership\Core\Repositories\ChannelRepository;
use Mothership\DAM\Helpers\Importers\Concerns\ResolvesAssetPaths;
use Mothership\DAM\Models\Asset;
use Mothership\DataTransfer\Helpers\Importers\FieldProcessor;
use Mothership\DataTransfer\Helpers\Importers\Product\Importer as ProductImporter;
use Mothership\DataTransfer\Helpers\Importers\Product\SKUStorage;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;
use Mothership\Product\Repositories\ProductRepository;

class Importer extends ProductImporter
{
    use ResolvesAssetPaths;

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected AttributeFamilyRepository $attributeFamilyRepository,
        protected AttributeRepository $attributeRepository,
        protected AttributeOptionRepository $attributeOptionRepository,
        protected CategoryRepository $categoryRepository,
        protected ProductRepository $productRepository,
        protected SKUStorage $skuStorage,
        protected ChannelRepository $channelRepository,
        protected FieldProcessor $fieldProcessor,
    ) {
        parent::__construct(
            $importBatchRepository,
            $attributeFamilyRepository,
            $attributeRepository,
            $attributeOptionRepository,
            $categoryRepository,
            $productRepository,
            $skuStorage,
            $channelRepository,
            $fieldProcessor
        );
    }

    /**
     * Save every non-measurement attribute value from the current row.
     *
     * Overriding this hook, not prepareAttributeValues(), keeps the parent's
     * measurement pass: it strips the "<code>(unit)" columns before this loop
     * would read them as a currency-scoped "<code>" and store the unit label.
     */
    protected function prepareNonMeasurementAttributeValues(array $rowData, array &$attributeValues): void
    {
        $familyAttributes = $this->getProductTypeFamilyAttributes($rowData['type'], $rowData[self::ATTRIBUTE_FAMILY_CODE]);
        $imageDirPath = $this->import->images_directory_path;

        foreach ($rowData as $attributeCode => $value) {
            if (is_null($value)) {
                continue;
            }

            [$attributeCode, $currencyCode] = $this->getAttributeCodeAndCurrency($attributeCode);

            $attribute = $familyAttributes->where('code', $attributeCode)->first();

            if (! $attribute) {
                continue;
            }

            if ($attribute->type === 'gallery') {
                $value = explode(',', $value);
            }

            if ($attribute->type === Asset::ASSET_ATTRIBUTE_TYPE) {
                $assets = $this->resolveAssetIds((string) $value);

                if ($assets !== []) {
                    $attribute->setProductValue(implode(',', $assets), $attributeValues, $rowData['channel'] ?? null, $rowData['locale'] ?? null);
                }

                continue;
            }

            $value = $this->fieldProcessor->handleField($attribute, $value, $imageDirPath);

            if ($attribute->type === 'price') {
                $value = $this->formatPriceValueWithCurrency($currencyCode, $value, $attribute->getValueFromProductValues($attributeValues, $rowData['channel'] ?? null, $rowData['locale'] ?? null));
            }

            $attribute->setProductValue($value, $attributeValues, $rowData['channel'] ?? null, $rowData['locale'] ?? null);
        }
    }
}
