<?php

namespace Mothership\DAM\DataGrids\Tag;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DataGrid\DataGrid;

class TagDataGrid extends DataGrid
{
    protected $sortColumn = 'dam_tags.created_at';

    protected $sortOrder = 'desc';

    protected $itemsPerPage = 25;

    public function prepareQueryBuilder()
    {
        $prefix = DB::getTablePrefix();

        // A tag counts only the files the viewer's Library lists (P2 S3 review): never a hidden file, a draft or one
        // outside its window, so the count cannot reveal that such a file carries the tag.
        $listable = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable(query: $listable, context: AccessContext::library(AccessContext::PURPOSE_LIST));

        $queryBuilder = DB::table('dam_tags')
            ->leftJoin('dam_asset_tag', fn ($join) => $join->on('dam_tags.id', '=', 'dam_asset_tag.tag_id')
                ->whereIn('dam_asset_tag.asset_id', $listable->toBase()))
            ->select(
                'dam_tags.id',
                'dam_tags.name',
                'dam_tags.created_at',
                DB::raw('COUNT('.$prefix.'dam_asset_tag.asset_id) as assets_count'),
            )
            ->groupBy('dam_tags.id', 'dam_tags.name', 'dam_tags.created_at');

        $this->addFilter('id', 'dam_tags.id');
        $this->addFilter('name', 'dam_tags.name');
        $this->addFilter('created_at', 'dam_tags.created_at');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'name',
            'label'      => trans('dam::app.admin.dam.tag.datagrid.name'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'assets_count',
            'label'      => trans('dam::app.admin.dam.tag.datagrid.assets-count'),
            'type'       => 'integer',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => false,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('dam::app.admin.dam.tag.datagrid.created-at'),
            'type'       => 'date_range',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
        ]);
    }

    public function prepareActions()
    {
        if (bouncer()->hasPermission('dam.tags.update')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('dam::app.admin.dam.tag.datagrid.edit'),
                'method' => 'edit-tag',
                'url'    => fn ($row) => route('admin.dam.tags.update', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('dam.tags.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('dam::app.admin.dam.tag.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => fn ($row) => route('admin.dam.tags.destroy', $row->id),
            ]);
        }
    }

    public function prepareMassActions()
    {
        if (bouncer()->hasPermission('dam.tags.delete')) {
            $this->addMassAction([
                'title'   => trans('dam::app.admin.dam.tag.datagrid.delete'),
                'url'     => route('admin.dam.tags.mass_delete'),
                'method'  => 'POST',
                'options' => ['actionType' => 'delete'],
            ]);
        }
    }
}
