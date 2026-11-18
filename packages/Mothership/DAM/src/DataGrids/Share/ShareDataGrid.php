<?php

namespace Mothership\DAM\DataGrids\Share;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DataGrid\Contracts\ExportableInterface;
use Mothership\DataGrid\DataGrid;

class ShareDataGrid extends DataGrid implements ExportableInterface
{
    protected $sortColumn = 'dam_shares.created_at';

    protected $sortOrder = 'desc';

    protected $itemsPerPage = 25;

    protected array $actionConditions = [];

    public function addAction(array $action): void
    {
        if (isset($action['condition'])) {
            $this->actionConditions[$action['index']] = $action['condition'];
            unset($action['condition']);
        }

        parent::addAction($action);
    }

    public function addColumn(array $column): void
    {
        $databaseColumnName = $column['database_column_name'] ?? null;

        parent::addColumn($column);

        if ($databaseColumnName !== null) {
            $this->columns[array_key_last($this->columns)]->setDatabaseColumnName($databaseColumnName);
        }
    }

    /**
     * Raw columns the action conditions and the copy-link URL read, selected under their own aliases because the
     * column closures overwrite `expires_at` (with "Never") and `share_type` before any action runs. None of them
     * reaches the grid's JSON (P2 S5a).
     */
    protected const ACTION_ONLY_COLUMNS = ['token', 'token_encrypted', 'link_slug', 'link_expires_at', 'link_created_by', 'link_target_owner_id'];

    public function formatData(): array
    {
        $data = parent::formatData();

        foreach ($data['records'] as $record) {
            $record->actions = array_values(array_filter(
                $record->actions,
                fn ($action) => ! isset($this->actionConditions[$action['index']])
                    || ($this->actionConditions[$action['index']])($record),
            ));

            foreach (self::ACTION_ONLY_COLUMNS as $column) {
                unset($record->{$column});
            }
        }

        return $data;
    }

    /**
     * The export writes the query's raw rows, so the stored token columns and the action-only aliases are dropped
     * here too: no link's URL, plaintext or encrypted, leaves through a download (#126, P2 S5a).
     */
    public function getExportableData(array $parameters = [])
    {
        return $this->queryBuilder->get()->map(function ($row) {
            foreach (self::ACTION_ONLY_COLUMNS as $column) {
                unset($row->{$column});
            }

            return $row;
        });
    }

    public function prepareQueryBuilder()
    {
        $lists = app(AccessModel::class)->lists();

        $queryBuilder = DB::table('dam_shares')
            ->leftJoin('admins', 'dam_shares.created_by', '=', 'admins.id')
            ->leftJoin('dam_assets', function ($join) {
                $join->on('dam_assets.id', '=', 'dam_shares.target_id')
                    ->where('dam_shares.share_type', '=', Share::TYPE_ASSET);
            })
            ->leftJoin('dam_directories', function ($join) {
                $join->on('dam_directories.id', '=', 'dam_shares.target_id')
                    ->where('dam_shares.share_type', '=', Share::TYPE_DIRECTORY);
            })
            ->select([
                'dam_shares.id',
                'dam_shares.token',
                'dam_shares.token_encrypted',
                'dam_shares.slug as link_slug',
                'dam_shares.expires_at as link_expires_at',
                'dam_shares.created_by as link_created_by',
                DB::raw('COALESCE('.DB::getTablePrefix().'dam_assets.created_by, '.DB::getTablePrefix().'dam_directories.created_by) as link_target_owner_id'),
                'dam_shares.name as share_name',
                'dam_shares.share_type',
                'dam_shares.target_id',
                'dam_shares.expires_at',
                'dam_shares.revoked_at',
                'dam_shares.view_count',
                'dam_shares.download_count',
                'dam_shares.last_accessed_at',
                'dam_shares.created_at',
                DB::raw('COALESCE('.DB::getTablePrefix().'admins.name, NULL) as created_by_name'),
                DB::raw('COALESCE('.DB::getTablePrefix().'dam_assets.file_name, '.DB::getTablePrefix().'dam_directories.name) as target_name'),
            ]);

        $this->addFilter('share_type', 'dam_shares.share_type');
        $this->addFilter('created_at', 'dam_shares.created_at');
        $this->addFilter('expires_at', 'dam_shares.expires_at');

        // P4 D2: a collection's link is managed on its collection's page, only by whoever manages the collection.
        $queryBuilder->where('dam_shares.share_type', '!=', Share::TYPE_COLLECTION);

        $this->scopeToOwnLinks($queryBuilder, $lists);
        $this->scopeToVisibleTargets($queryBuilder);

        return $queryBuilder;
    }

    /**
     * P2 S3, both models: a link lists only when the viewer may see its target, so the page never names a file or
     * folder the viewer cannot open, or hands out its public URL. A file goes through the seam's listing rule
     * (folder, file list and availability); a folder must be one the viewer can open. Full admins see every link.
     * A folder bypass (a custom role that reaches every folder) skips only the folder rule: availability still
     * applies to its asset links unless it may manage availability (P2 S3 review).
     */
    protected function scopeToVisibleTargets($queryBuilder): void
    {
        $permissions = app(DirectoryPermissionService::class);
        $decision = app(AssetAccessDecision::class);
        $context = AccessContext::library(AccessContext::PURPOSE_LIST);
        $bypass = $permissions->bypass();

        if ($bypass && $decision->seesEveryUnavailableTo(context: $context)) {
            return;
        }

        $visibleAssets = Asset::query()->select('dam_assets.id');
        $decision->scopeListable(query: $visibleAssets, context: $context);
        $folderIds = $bypass ? null : $permissions->directlyGrantedIds();

        $queryBuilder->where(function ($query) use ($visibleAssets, $folderIds) {
            $query->where(fn ($asset) => $asset->where('dam_shares.share_type', Share::TYPE_ASSET)->whereIn('dam_shares.target_id', $visibleAssets))
                ->orWhere(fn ($folder) => $folder->where('dam_shares.share_type', Share::TYPE_DIRECTORY)
                    ->when($folderIds !== null, fn ($granted) => $granted->whereIn('dam_shares.target_id', $folderIds)));
        });
    }

    /**
     * Under lists, a full admin sees every link (D7's "Shared Links page" rule); everyone else sees the
     * links they created plus links to items they own. Under grants, parity keeps the grid unscoped, as
     * today.
     */
    protected function scopeToOwnLinks($queryBuilder, bool $lists): void
    {
        if (! $lists) {
            return;
        }

        $permissions = app(DirectoryPermissionService::class);

        if ($permissions->bypass()) {
            return;
        }

        $adminId = auth()->guard('admin')->id();

        $queryBuilder->where(function ($query) use ($adminId) {
            $query->where('dam_shares.created_by', $adminId)
                ->orWhere('dam_assets.created_by', $adminId)
                ->orWhere('dam_directories.created_by', $adminId);
        });
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'share_type',
            'label'      => trans('dam::app.admin.dam.share.datagrid.type'),
            'type'       => 'dropdown',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => ucfirst((string) $row->share_type),
            'options'    => [
                'type'   => 'basic',
                'params' => [
                    'options' => [
                        ['label' => trans('dam::app.admin.dam.share.datagrid.asset'), 'value' => Share::TYPE_ASSET],
                        ['label' => trans('dam::app.admin.dam.share.datagrid.directory'), 'value' => Share::TYPE_DIRECTORY],
                    ],
                ],
            ],
        ]);

        $this->addColumn([
            'index'                => 'target_name',
            'label'                => trans('dam::app.admin.dam.share.datagrid.target'),
            'type'                 => 'string',
            'searchable'           => true,
            'filterable'           => false,
            'sortable'             => false,
            'database_column_name' => DB::raw('COALESCE('.DB::getTablePrefix().'dam_assets.file_name, '.DB::getTablePrefix().'dam_directories.name)'),
        ]);

        $this->addColumn([
            'index'                => 'share_name',
            'label'                => trans('dam::app.admin.dam.share.datagrid.custom-name'),
            'type'                 => 'string',
            'searchable'           => true,
            'filterable'           => false,
            'sortable'             => true,
            'database_column_name' => 'dam_shares.name',
        ]);

        $this->addColumn([
            'index'                => 'created_by_name',
            'label'                => trans('dam::app.admin.dam.share.datagrid.created-by'),
            'type'                 => 'string',
            'searchable'           => true,
            'filterable'           => false,
            'sortable'             => false,
            'database_column_name' => 'admins.name',
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => trans('dam::app.admin.dam.share.datagrid.status'),
            'type'       => 'string',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => false,
            'closure'    => function ($row) {
                $badge = fn (string $label, string $classes) => sprintf(
                    '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold %s">%s</span>',
                    $classes,
                    e($label),
                );

                if ($row->revoked_at) {
                    return $badge(
                        trans('dam::app.admin.dam.share.datagrid.status-revoked'),
                        'bg-red-100 text-red-700 dark:bg-red-900/40'
                    );
                }
                if ($row->expires_at && Carbon::parse($row->expires_at)->isPast()) {
                    return $badge(
                        trans('dam::app.admin.dam.share.datagrid.status-expired'),
                        'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'
                    );
                }

                return $badge(
                    trans('dam::app.admin.dam.share.datagrid.status-active'),
                    'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                );
            },
        ]);

        $this->addColumn([
            'index'      => 'expires_at',
            'label'      => trans('dam::app.admin.dam.share.datagrid.expires-at'),
            'type'       => 'date_range',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => $row->expires_at
                ? Carbon::parse($row->expires_at)->toDateTimeString()
                : trans('dam::app.admin.dam.share.datagrid.never'),
        ]);

        $this->addColumn([
            'index'      => 'view_count',
            'label'      => trans('dam::app.admin.dam.share.datagrid.views'),
            'type'       => 'integer',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'download_count',
            'label'      => trans('dam::app.admin.dam.share.datagrid.downloads'),
            'type'       => 'integer',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => trans('dam::app.admin.dam.share.datagrid.created-at'),
            'type'       => 'date_range',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
        ]);
    }

    public function prepareActions()
    {
        // The ACL key offers an action at all; the row decides whether this viewer may take it: the link's
        // creator, the target's owner or a full admin, in both access models, exactly as ShareController::canManage()
        // answers (UX eval 2026-10-10, R14). Under lists every custom role holds the keys (D7).
        if (bouncer()->hasPermission('dam.shares.revoke')) {
            $this->addAction([
                'index'     => 'edit',
                'icon'      => 'icon-edit',
                'title'     => trans('dam::app.admin.dam.share.datagrid.edit'),
                'method'    => 'edit-share',
                'url'       => fn ($row) => route('admin.dam.shared-links.update', $row->id),
                'condition' => fn ($row) => $this->tokenVisibleOn($row),
            ]);
        }

        $this->addAction([
            'index'     => 'copy_link',
            'icon'      => 'icon-copy',
            'title'     => trans('dam::app.admin.dam.share.datagrid.copy-link'),
            'method'    => 'copy',
            // The #126 rule, under both access models: the URL is decrypted only for a viewer who may see it, and
            // anyone else's row carries no copy action at all.
            'url'       => function ($row) {
                $token = $this->tokenVisibleOn($row) ? Share::urlTokenOf($row) : null;

                // P4 D23: a link with a custom slug copies its /s/ address, shown only where the token is.
                return match (true) {
                    $token === null          => null,
                    $row->link_slug !== null => route('dam.share.slug', ['slug' => $row->link_slug]),
                    default                  => route('dam.share.show', ['token' => $token]),
                };
            },
            'condition' => fn ($row) => $this->tokenVisibleOn($row) && Share::urlTokenOf($row) !== null,
        ]);

        if (bouncer()->hasPermission('dam.shares.revoke')) {
            // Reissue (P2 S5a) follows revoke: the same key and row rule, and only for a link that still opens.
            $this->addAction([
                'index'     => 'reissue',
                'icon'      => 'icon-dam-link',
                'title'     => trans('dam::app.admin.dam.share.datagrid.reissue'),
                'method'    => 'POST',
                'url'       => fn ($row) => route('admin.dam.shared-links.reissue', $row->id),
                'condition' => fn ($row) => empty($row->revoked_at)
                    && ! ($row->link_expires_at && Carbon::parse($row->link_expires_at)->isPast())
                    && $this->tokenVisibleOn($row),
            ]);
        }

        if (bouncer()->hasPermission('dam.shares.revoke')) {
            $this->addAction([
                'index'     => 'revoke',
                'icon'      => 'icon-cancel',
                'title'     => trans('dam::app.admin.dam.share.datagrid.revoke'),
                'method'    => 'PATCH',
                'url'       => fn ($row) => route('admin.dam.shared-links.revoke', $row->id),
                'condition' => fn ($row) => empty($row->revoked_at) && $this->tokenVisibleOn($row),
            ]);
        }

        if (bouncer()->hasPermission('dam.shares.delete')) {
            $this->addAction([
                'index'     => 'delete',
                'icon'      => 'icon-delete',
                'title'     => trans('dam::app.admin.dam.share.datagrid.delete'),
                'method'    => 'DELETE',
                'condition' => fn ($row) => $this->tokenVisibleOn($row),
                'url'       => fn ($row) => route('admin.dam.shared-links.destroy', $row->id),
            ]);
        }
    }

    /**
     * Share::tokenVisibleTo() for a grid row: the link's creator, the target's owner or a full admin. The row's raw
     * creator and target owner stand in for the model and its target, so the one predicate decides here too.
     */
    protected function tokenVisibleOn(object $row): bool
    {
        $owner = $row->link_target_owner_id ?? null;
        $share = (new Share)->forceFill(['created_by' => $row->link_created_by ?? null, 'share_type' => Share::TYPE_ASSET]);
        $share->setRelation('asset', $owner === null ? null : (new Asset)->forceFill(['created_by' => (int) $owner]));
        $adminId = auth()->guard('admin')->id();

        return $share->tokenVisibleTo(adminId: $adminId === null ? null : (int) $adminId, fullAdmin: app(DirectoryPermissionService::class)->fullAdmin());
    }
}
