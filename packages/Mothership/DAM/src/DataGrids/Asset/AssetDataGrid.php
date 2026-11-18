<?php

namespace Mothership\DAM\DataGrids\Asset;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\Helpers\AssetHelper;
use Mothership\DAM\Http\Controllers\FileController;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\LibraryMediaFilter;
use Mothership\DAM\Services\PanoramaClassifier;
use Mothership\DAM\Services\VideoProxyStatus;
use Mothership\DAM\Support\SearchTerms;
use Mothership\DataGrid\DataGrid;
use Mothership\DataGrid\Enums\ColumnTypeEnum;

class AssetDataGrid extends DataGrid
{
    protected $sortColumn = 'dam_assets.updated_at';

    protected $sortOrder = 'desc';

    protected $customFilterColumns = [];

    protected array $propNameMap = [];

    protected $itemsPerPage = 50;

    public function __construct(
        protected FileController $fileController
    ) {}

    public function prepareQueryBuilder()
    {
        $prefix = DB::getTablePrefix();

        $visibleDirectoryIds = app(DirectoryPermissionService::class)->viewableIds();
        $visibleDirectorySql = implode(',', array_map('intval', $visibleDirectoryIds)) ?: '0';
        $queryBuilder = DB::table('dam_directories')
            ->join('dam_asset_directory', 'dam_directories.id', '=', 'dam_asset_directory.directory_id')
            ->join('dam_assets', 'dam_asset_directory.asset_id', '=', 'dam_assets.id')
            ->leftJoin('dam_asset_tag', 'dam_assets.id', '=', 'dam_asset_tag.asset_id')
            ->leftJoin('dam_tags', 'dam_asset_tag.tag_id', '=', 'dam_tags.id')
            ->select(
                DB::raw('MIN(CASE WHEN '.$prefix.'dam_directories.id IN ('.$visibleDirectorySql.') THEN '.$prefix.'dam_directories.id ELSE NULL END) as directory_id'),
                'dam_assets.id',
                'dam_assets.file_name',
                'dam_assets.file_type',
                'dam_assets.file_size',
                'dam_assets.mime_type',
                'dam_assets.extension',
                'dam_assets.path',
                'dam_assets.created_at',
                'dam_assets.updated_at',
                DB::raw('MIN('.$prefix.'dam_asset_directory.asset_id) as directory_asset_id'),
            )
            ->groupBy('dam_assets.id')
            ->whereIn('dam_assets.id', Asset::query()->visibleCaptures()->select('dam_assets.id'));

        $this->addFilter('id', 'dam_assets.id');
        $this->addFilter('file_name', 'dam_assets.file_name');
        $this->addFilter('extension', 'dam_assets.extension');
        $this->addFilter('path', 'dam_assets.path');
        $this->addFilter('tag', 'dam_tags.name');
        $this->addFilter('created_at', 'dam_assets.created_at');
        $this->addFilter('updated_at', 'dam_assets.updated_at');

        $this->customFilterColumns = [
            'directory_asset_id' => 'dam_asset_directory.asset_id',
            'directory_id'       => 'dam_directories.id',
        ];

        // The Library's "Include unavailable" filter: off, it lists only what everyone the files are shared with sees.
        $context = AccessContext::library(AccessContext::PURPOSE_LIST);
        app(AssetAccessDecision::class)->scopeListable($queryBuilder, request()->boolean('include_unavailable', true) ? $context : $context->withoutUnavailable());

        LibraryMediaFilter::apply($queryBuilder, request()->input('media_type'));

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'file_name',
            'label'      => trans('dam::app.admin.dam.index.datagrid.file-name'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
            'closure'    => function ($row) {
                $fileName = $row->file_name;

                return $fileName ? AssetHelper::getDisplayFileName($fileName) : trans('dam::app.admin.dam.asset.datagrid.no-file-name');
            },
        ]);

        $this->addColumn([
            'index'      => 'tag',
            'label'      => trans('dam::app.admin.dam.index.datagrid.tags'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'extension',
            'label'      => trans('dam::app.admin.dam.index.datagrid.extension'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'path',
            'label'      => trans('dam::app.admin.dam.index.datagrid.path'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => false,
            'sortable'   => true,
            'closure'    => function ($row) {
                return isset($row->path) ? route('admin.dam.file.thumbnail', ['path' => urlencode($row->path)]) : '';
            },
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('dam::app.admin.dam.index.datagrid.created-at'),
            'type'       => 'date_range',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'updated_at',
            'label'      => trans('dam::app.admin.dam.index.datagrid.updated-at'),
            'type'       => 'date_range',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        // P2 S3: "Include unavailable", for those who see files members do not (editors, full admins).
        if (bouncer()->hasPermission('dam.asset.update')) {
            $this->addColumn([
                'index'      => 'unavailable',
                'label'      => trans('dam::app.admin.dam.asset.availability.include-unavailable'),
                'type'       => 'dropdown',
                'searchable' => false,
                'filterable' => true,
                'sortable'   => false,
                'visible'    => false,
                'options'    => [
                    'type'   => 'basic',
                    'params' => [
                        'options' => [
                            ['label' => trans('dam::app.admin.dam.asset.availability.include-options.include'), 'value' => 'include'],
                            ['label' => trans('dam::app.admin.dam.asset.availability.include-options.exclude'), 'value' => 'exclude'],
                        ],
                    ],
                ],
                'closure'    => fn ($row) => '',
            ]);
        }

        $this->addFilterablePropertyColumns();
    }

    protected function addFilterablePropertyColumns(): void
    {
        $props = DB::table('dam_asset_properties')
            ->select('name', DB::raw('MIN(sort_order) as sort_order'))
            ->where('is_filterable', true)
            ->groupBy('name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($props as $prop) {
            $normalizedName = preg_replace('/[^a-zA-Z0-9_]/', '_', $prop->name);
            $this->propNameMap[$normalizedName] = $prop->name;

            $this->addColumn([
                'index'      => 'prop_'.$normalizedName,
                'label'      => $prop->name,
                'type'       => 'string',
                'searchable' => false,
                'filterable' => true,
                'sortable'   => false,
            ]);
        }
    }

    public function formatData(): array
    {
        $formattedData = parent::formatData();

        $formattedData['meta']['per_page_options'] = [50, 100, 150, 200, 250];

        $this->addPanoramaFlags($formattedData);

        return $formattedData;
    }

    /**
     * Tag each record with the classifier's verdict so the gallery badge means
     * "this is a 360" rather than "someone once set asset_kind to pano360" —
     * the legacy column misses OSV files, aspect-ratio matches and the
     * force_360/force_flat overrides entirely.
     *
     * The page's models are loaded once, with the files/fileObject the
     * classifier reads for dimensions and codecs, so a 250-row page costs two
     * queries instead of 500.
     */
    private function addPanoramaFlags(array &$formattedData): void
    {
        if (empty($formattedData['records'])) {
            return;
        }

        $ids = collect($formattedData['records'])
            ->map(fn ($record) => (int) data_get($record, 'id'))
            ->filter()
            ->all();

        $assets = $ids === []
            ? collect()
            : Asset::query()->withDisplayName()->with('files.fileObject')->whereIn('id', $ids)->get()->keyBy('id');

        $classifier = app(PanoramaClassifier::class);

        // P6 D26: one query for the page, a badge on a video whose preview proxy is queued, processing or failed.
        $proxyBadges = app(VideoProxyStatus::class)->badges(
            $assets->filter(fn (Asset $asset) => $asset->file_type === 'video')->keys()->map(fn ($id) => (int) $id)->values()->all()
        );

        foreach ($formattedData['records'] as &$record) {
            $asset = $assets->get((int) data_get($record, 'id'));

            data_set($record, 'display_name', $asset?->display_name ?? data_get($record, 'file_name'));
            data_set($record, 'ai_possible', $asset ? AiEligibility::typeMayBeDescribed($asset->extension, $asset->file_type) : true);
            data_set($record, 'is_panorama', $asset ? $classifier->classify($asset)['is_panorama'] : false);

            // P2 S3: why the file is not out for use, for the owner, editors and full admins who still list it.
            $unavailable = $asset?->unavailableBecause();
            data_set($record, 'unavailable', $unavailable);
            data_set($record, 'unavailable_label', $unavailable ? trans('dam::app.admin.dam.asset.availability.badges.'.$unavailable) : null);

            $proxyStatus = $asset ? ($proxyBadges[(int) $asset->id] ?? null) : null;
            data_set($record, 'proxy_status', $proxyStatus);
            data_set($record, 'proxy_status_label', $proxyStatus === null ? null : VideoProxyStatus::badgeLabel($proxyStatus));
        }

        unset($record);
    }

    public function processRequestedFilters(array $requestedFilters)
    {
        $prefix = DB::getTablePrefix();

        foreach ($requestedFilters as $requestedColumn => $requestedValues) {
            if ($requestedColumn === 'all') {
                $this->queryBuilder->where(function ($scopeQueryBuilder) use ($prefix, $requestedValues) {
                    foreach ($requestedValues as $value) {
                        // Every word of a value must match some searched column; % and _ are literal.
                        $scopeQueryBuilder->orWhere(function ($valueQuery) use ($prefix, $value) {
                            SearchTerms::apply($valueQuery, (string) $value, function ($match, string $pattern) use ($prefix) {
                                $match->orWhereExists(DB::table('dam_asset_properties')
                                    ->whereColumn('dam_asset_properties.dam_asset_id', 'dam_assets.id')
                                    ->where('name', 'Campus')->whereRaw(SearchTerms::likeSql('value'), [$pattern])
                                    ->select(DB::raw(1)));
                                collect($this->columns)
                                    ->filter(fn ($column) => $column->searchable
                                        && $column->type !== ColumnTypeEnum::BOOLEAN->value
                                        && $column->type !== ColumnTypeEnum::DATE_RANGE->value
                                        && $column->type !== ColumnTypeEnum::DATE_TIME_RANGE->value)
                                    ->each(fn ($column) => $match->orWhereRaw(
                                        SearchTerms::likeSql($prefix.($column->index === 'tag' ? 'dam_tags.name' : $column->getDatabaseColumnName())),
                                        [$pattern]
                                    ));
                            });
                        });
                    }
                });
            } else {
                $column = collect($this->columns)->first(fn ($c) => $c->index === $requestedColumn);

                // Left out: only what everyone the files are shared with sees (the scope already let them in).
                if ($requestedColumn === 'unavailable') {
                    if (in_array('exclude', (array) $requestedValues, true)) {
                        Asset::whereAvailableNow($this->queryBuilder);
                    }

                    continue;
                }

                if ($requestedColumn === 'directory_id') {
                    $this->queryBuilder->where(function ($q) use ($requestedValues) {
                        foreach ($requestedValues as $rootId) {
                            $root = Directory::select('_lft', '_rgt')->find((int) $rootId);
                            if ($root) {
                                $q->orWhereBetween('dam_directories._lft', [$root->_lft, $root->_rgt]);
                            }
                        }
                    });

                    if (empty($requestedValues)) {
                        $this->queryBuilder->whereRaw('1 = 0');
                    }

                    continue;
                }

                if ($requestedColumn === 'directory_asset_id') {
                    $this->queryBuilder->where(function ($scopeQueryBuilder) use ($requestedColumn, $requestedValues) {
                        foreach ($requestedValues as $value) {
                            $scopeQueryBuilder->orWhere($this->customFilterColumns[$requestedColumn], $value);
                        }
                    });

                    continue;
                }

                if (str_starts_with($requestedColumn, 'prop_')) {
                    $normalizedName = substr($requestedColumn, 5);
                    $propName = $this->propNameMap[$normalizedName] ?? $normalizedName;
                    $prefix = DB::getTablePrefix();

                    $this->queryBuilder->where(function ($scopeQueryBuilder) use ($prefix, $propName, $requestedValues) {
                        foreach ($requestedValues as $value) {
                            $scopeQueryBuilder->orWhereExists(function ($sub) use ($prefix, $propName, $value) {
                                $sub->select(DB::raw(1))
                                    ->from('dam_asset_properties')
                                    ->whereRaw("{$prefix}dam_asset_properties.dam_asset_id = {$prefix}dam_assets.id")
                                    ->where('name', $propName);

                                SearchTerms::apply($sub, (string) $value, fn ($match, string $pattern) => $match->whereRaw(SearchTerms::likeSql('value'), [$pattern]));
                            });
                        }
                    });

                    continue;
                }

                switch ($column->type) {
                    case ColumnTypeEnum::STRING->value:
                        $this->queryBuilder->where(function ($scopeQueryBuilder) use ($prefix, $column, $requestedValues) {
                            foreach ($requestedValues as $value) {
                                $scopeQueryBuilder->orWhere(fn ($valueQuery) => SearchTerms::apply(
                                    $valueQuery,
                                    (string) $value,
                                    fn ($match, string $pattern) => $match->whereRaw(
                                        SearchTerms::likeSql($prefix.($column->index === 'tag' ? 'dam_tags.name' : $column->getDatabaseColumnName())),
                                        [$pattern]
                                    )
                                ));
                            }
                        });

                        break;

                    case ColumnTypeEnum::INTEGER->value:
                        $this->queryBuilder->where(function ($scopeQueryBuilder) use ($column, $requestedValues) {
                            foreach ($requestedValues as $value) {
                                $scopeQueryBuilder->orWhere($column->getDatabaseColumnName(), $value);
                            }
                        });

                        break;

                    case ColumnTypeEnum::DROPDOWN->value:
                        $this->queryBuilder->where(function ($scopeQueryBuilder) use ($column, $requestedValues) {
                            foreach ($requestedValues as $value) {
                                $scopeQueryBuilder->orWhere($column->getDatabaseColumnName(), $value);
                            }
                        });

                        break;

                    case ColumnTypeEnum::DATE_RANGE->value:
                        $this->queryBuilder->where(function ($scopeQueryBuilder) use ($column, $requestedValues) {
                            foreach ($requestedValues as $value) {
                                $scopeQueryBuilder->whereBetween($column->getDatabaseColumnName(), [
                                    ($value[0] ?? '').' 00:00:01',
                                    ($value[1] ?? '').' 23:59:59',
                                ]);
                            }
                        });

                        break;
                    case ColumnTypeEnum::DATE_TIME_RANGE->value:
                        $this->queryBuilder->where(function ($scopeQueryBuilder) use ($column, $requestedValues) {
                            foreach ($requestedValues as $value) {
                                $scopeQueryBuilder->whereBetween($column->getDatabaseColumnName(), [$value[0] ?? '', $value[1] ?? '']);
                            }
                        });

                        break;

                    default:
                        $this->queryBuilder->where(function ($scopeQueryBuilder) use ($prefix, $column, $requestedValues) {
                            foreach ($requestedValues as $value) {
                                $scopeQueryBuilder->orWhere(fn ($valueQuery) => SearchTerms::apply(
                                    $valueQuery,
                                    (string) $value,
                                    fn ($match, string $pattern) => $match->whereRaw(
                                        SearchTerms::likeSql($prefix.($column->index === 'tag' ? 'dam_tags.name' : $column->getDatabaseColumnName())),
                                        [$pattern]
                                    )
                                ));
                            }
                        });

                        break;
                }
            }
        }

        return $this->queryBuilder;
    }

    public function prepareMassActions()
    {
        if (bouncer()->hasPermission('dam.asset.tag')) {
            $this->addMassAction([
                'title'   => trans('dam::app.admin.dam.tag.mass-action.assign-tags'),
                'url'     => route('admin.dam.assets.mass_assign_tags'),
                'method'  => 'POST',
                'options' => ['actionType' => 'assign-tags'],
            ]);
        }

        // P6a: opens the Process dialog on the selection; the dialog starts the batch itself.
        if (bouncer()->hasPermission('dam.asset.process')) {
            $this->addMassAction([
                'title'   => trans('dam::app.admin.batch.process'),
                'url'     => route('admin.dam.batches.store'),
                'method'  => 'POST',
                'options' => ['actionType' => 'process'],
            ]);
        }

        // Rename with AI: opens the review dialog on the selection; nothing renames until the user applies it.
        if (bouncer()->hasPermission('dam.asset.rename') && filled(config('ai-index.api'))) {
            $this->addMassAction([
                'title'   => trans('dam::app.admin.explorer.ai-rename.action'),
                'url'     => route('admin.dam.assets.ai-rename.suggest'),
                'method'  => 'POST',
                'options' => ['actionType' => 'ai-rename'],
            ]);
        }

        // P4 D2: the collection dialog adds through the collection members endpoint, which re-checks the right per file.
        if (bouncer()->hasPermission('dam.collections.update')) {
            $this->addMassAction([
                'title'   => trans('dam::app.admin.dam.collections.add.action'),
                'url'     => route('admin.dam.collections.store'),
                'method'  => 'POST',
                'options' => ['actionType' => 'add-to-collection'],
            ]);
        }

        if (bouncer()->hasPermission('dam.asset.moved')) {
            $this->addMassAction([
                'title'   => trans('dam::app.admin.explorer.mass-actions.move-to'),
                'url'     => route('admin.dam.explorer.mass_move'),
                'method'  => 'POST',
                'options' => ['actionType' => 'move'],
            ]);
        }

        if (bouncer()->hasPermission('dam.asset.mass_delete')) {
            $this->addMassAction([
                'title'   => trans('admin::app.catalog.products.index.datagrid.delete'),
                'url'     => route('admin.dam.assets.mass_delete'),
                'method'  => 'POST',
                'options' => ['actionType' => 'delete'],
            ]);
        }
    }
}
