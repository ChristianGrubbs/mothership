<?php

namespace Mothership\DAM\ApiDataSource;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mothership\AdminApi\ApiDataSource;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Repositories\AssetRepository;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\MetadataFields\FieldFilters;

class AssetDataSource extends ApiDataSource
{
    protected $sortColumn = 'dam_assets.id';

    public function __construct(
        protected AssetRepository $assetRepository,
    ) {}

    public function prepareApiQueryBuilder()
    {
        $this->addFilter('file_type', ['='], 'dam_assets');
        $this->addFilter('mime_type', ['=', 'LIKE'], 'dam_assets');
        $this->addFilter('extension', ['=', 'LIKE'], 'dam_assets');
        $this->addFilter('file_size', ['=', '<', '>', '<=', '>='], 'dam_assets');
        $this->addFilter('file_name', ['=', 'LIKE'], 'dam_assets');
        $this->addFilter('code', ['=', 'LIKE'], 'dam_assets');
        $this->addFilter('created_at', ['=', '>=', '<='], 'dam_assets');
        $this->addFilter('updated_at', ['=', '>=', '<='], 'dam_assets');

        // P4a (D10): one `field.<code>` filter per filterable field, with the operators its type supports.
        foreach (app(FieldFilters::class)->apiOperators() as $key => $operators) {
            $this->addFilter($key, $operators);
        }

        return $this->assetRepository->queryBuilder();
    }

    public function setDefaultFilters($queryBuilder)
    {
        app(AssetAccessDecision::class)->scopeListable($queryBuilder, AccessContext::api(AccessContext::PURPOSE_LIST));

        return $queryBuilder;
    }

    public function formatData(): array
    {
        $paginator = $this->paginator->toArray();

        return array_map([$this, 'normalizeAsset'], $paginator['data'] ?? []);
    }

    public function getByCode(string $code)
    {
        $this->prepareForSingleData();

        $requestedFilters = [
            'code' => [
                [
                    'operator' => '=',
                    'value'    => $code,
                ],
            ],
        ];

        $this->queryBuilder = $this->processRequestedFilters($requestedFilters);

        $asset = $this->queryBuilder->first()?->toArray();

        if (! $asset) {
            throw new ModelNotFoundException(
                sprintf('Asset with code %s could not be found.', (string) $code)
            );
        }

        return $this->normalizeAsset($asset);
    }

    public function operatorByFilter($scopeQueryBuilder, $requestedColumn, $value)
    {
        if (str_starts_with((string) $requestedColumn, 'field.')) {
            app(FieldFilters::class)->applyApi(query: $scopeQueryBuilder, code: substr((string) $requestedColumn, 6), filter: $value);

            return $scopeQueryBuilder;
        }

        $filterTable = isset($this->fieldFiltersAndOperators[$requestedColumn]['filterTable'])
            ? $this->fieldFiltersAndOperators[$requestedColumn]['filterTable'].'.'
            : 'assets.';

        switch ($requestedColumn) {
            case 'file_type':
                $scopeQueryBuilder->where($filterTable.'file_type', $value['value']);
                break;

            case 'mime_type':
                $scopeQueryBuilder->where($filterTable.'mime_type', 'LIKE', "%{$value['value']}%");
                break;

            case 'extension':
                $scopeQueryBuilder->where($filterTable.'extension', 'LIKE', "%{$value['value']}%");
                break;

            case 'code':
                $scopeQueryBuilder->where($filterTable.'file_name', 'LIKE', "%{$value['value']}%");
                break;

            case 'file_size':
                $operator = $value['operator'] ?? '=';
                $scopeQueryBuilder->where($filterTable.'file_size', $operator, (int) $value['value']);
                break;

            case 'created_at':
            case 'updated_at':

                if (isset($value['from']) && isset($value['to'])) {
                    $scopeQueryBuilder->whereBetween($filterTable.$requestedColumn, [$value['from'], $value['to']]);
                } else {
                    $scopeQueryBuilder->whereDate($filterTable.$requestedColumn, $value['value']);
                }
                break;

            default:
                $operator = $value['operator'] ?? '=';
                $scopeQueryBuilder->where($filterTable.$requestedColumn, $operator, $value['value']);
                break;
        }

        return $scopeQueryBuilder;
    }

    protected function normalizeAsset(array $asset): array
    {
        $previewPath = isset($asset['path'])
            ? AssetHelper::getPreviewUrl(
                $asset['path'],
                isset($asset['file_size']) ? (int) $asset['file_size'] : null
            )
            : null;

        $responseData = [
            'id'           => $asset['id'],
            'file_name'    => $asset['file_name'],
            'file_type'    => $asset['file_type'] ?? null,
            'file_size'    => (int) ($asset['file_size'] ?? 0),
            'mime_type'    => $asset['mime_type'] ?? null,
            'extension'    => $asset['extension'] ?? null,
            'path'         => $asset['path'] ?? null,
            'preview_path' => $previewPath,
            'created_at'   => $asset['created_at'] ?? null,
            'updated_at'   => $asset['updated_at'] ?? null,
        ];

        return $responseData;
    }
}
