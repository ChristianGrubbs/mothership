<?php

namespace Mothership\DAM\Helpers\Importers\Category;

use Mothership\Attribute\Repositories\AttributeRepository;
use Mothership\Category\Repositories\CategoryFieldRepository;
use Mothership\Category\Repositories\CategoryRepository;
use Mothership\Core\Repositories\ChannelRepository;
use Mothership\Core\Repositories\LocaleRepository;
use Mothership\DAM\Helpers\Importers\Concerns\ResolvesAssetPaths;
use Mothership\DAM\Models\Asset;
use Mothership\DataTransfer\Helpers\Importers\Category\Importer as CategoryImporter;
use Mothership\DataTransfer\Helpers\Importers\Category\Storage;
use Mothership\DataTransfer\Helpers\Importers\FieldProcessor;
use Mothership\DataTransfer\Repositories\JobTrackBatchRepository;
use Mothership\DataTransfer\Validators\Import\CategoryRulesExtractor;

class Importer extends CategoryImporter
{
    use ResolvesAssetPaths;

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected CategoryRepository $categoryRepository,
        protected CategoryFieldRepository $categoryFieldRepository,
        protected Storage $categoryStorage,
        protected AttributeRepository $attributeRepository,
        protected LocaleRepository $localeRepository,
        protected ChannelRepository $channelRepository,
        protected CategoryRulesExtractor $categoryRulesExtractor,
        protected FieldProcessor $fieldProcessor,
    ) {
        parent::__construct(
            $importBatchRepository,
            $categoryRepository,
            $categoryFieldRepository,
            $categoryStorage,
            $attributeRepository,
            $localeRepository,
            $channelRepository,
            $categoryRulesExtractor,
            $fieldProcessor
        );
    }

    public function prepareCategories(array $rowData, array &$categories): void
    {
        $isCategory = $this->isCategoryExist($rowData['code']);

        $categoryValues = $categories['update'][$rowData['code']]['additional_data'] ?? [];

        if (empty($categoryValues) && $isCategory) {
            $categoryValues = $this->categoryRepository->findOneByField('code', $rowData['code'])?->additional_data ?? [];
        }

        $data = [
            'code'            => $rowData['code'],
            'parent'          => $rowData['parent'],
            'additional_data' => $categoryValues,
        ];

        $categoryFields = $this->getCategoryFields();
        $imageDirPath = $this->import->images_directory_path;

        foreach ($rowData as $field => $value) {
            if (! in_array($field, $categoryFields)) {
                continue;
            }

            $catalogField = $this->categoryFieldRepository->where('code', $field)->first();

            if ($catalogField->type === Asset::ASSET_ATTRIBUTE_TYPE) {
                $assets = $this->resolveAssetIds((string) $value);

                if ($assets !== []) {
                    $data['additional_data']['common'][$field] = implode(',', $assets);
                }

                continue;
            }

            $value = $this->fieldProcessor->handleField($catalogField, $value, $imageDirPath);

            if ($catalogField->value_per_locale) {
                $locale = $rowData['locale'] ?? null;
                if ($locale) {
                    $data['additional_data']['locale_specific'][$locale][$field] = $value;
                }
            } else {
                $data['additional_data']['common'][$field] = $value;
            }
        }

        if ($this->isCategoryExist($rowData['code'])) {
            $data['additional_data'] = $this->mergeCategoryFieldValues($data['additional_data'], $categories['update'][$rowData['code']]['additional_data'] ?? []);

            $categories['update'][$rowData['code']] = array_merge($categories['update'][$rowData['code']] ?? [], $data);
        } else {
            $data['additional_data'] = $this->mergeCategoryFieldValues($data['additional_data'], $categories['insert'][$rowData['code']]['additional_data'] ?? []);

            $categories['insert'][$rowData['code']] = array_merge($categories['insert'][$rowData['code']] ?? [], $data);
        }
    }
}
