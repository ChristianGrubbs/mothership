<?php

namespace Mothership\Admin\DataGrids\Settings;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mothership\DataGrid\DataGrid;
use Mothership\User\Models\Admin as AdminUser;
use Mothership\User\Repositories\AdminRepository;

class UserDataGrid extends DataGrid
{
    /**
     * Index.
     *
     * @var string
     */
    protected $primaryColumn = 'user_id';

    /**
     * Prepare query builder.
     *
     * @return Builder
     */
    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('admins as u')
            ->leftJoin('roles as ro', 'u.role_id', '=', 'ro.id')
            // The invite's address under its own name, so the join adds no second `email` column to filter or sort on.
            ->leftJoinSub(DB::table('admin_invites')->select('email as invite_email'), 'ai', 'ai.invite_email', '=', 'u.email')
            ->where('u.type', 'user')
            ->addSelect(
                'u.id as user_id',
                'u.name as user_name',
                'u.image as user_image',
                'u.use_gravatar',
                'u.status',
                'u.email',
                'ro.name as role_name',
                // Non-null when an invite row exists; pending (inactive plus this) is decided in PHP, never in SQL.
                'ai.invite_email'
            );

        $this->addFilter('user_id', 'u.id');
        $this->addFilter('user_name', 'u.name');
        $this->addFilter('role_name', 'ro.name');
        $this->addFilter('status', 'u.status');
        $this->addFilter('email', 'u.email');

        return $queryBuilder;
    }

    /**
     * Add columns.
     *
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'user_id',
            'label'      => trans('admin::app.settings.users.index.datagrid.id'),
            'type'       => 'integer',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'user_name',
            'label'      => trans('admin::app.settings.users.index.datagrid.name'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'user_img',
            'label'      => trans('admin::app.settings.users.index.datagrid.image'),
            'type'       => 'string',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => false,
            'closure'    => function ($row) {
                if ($row->user_image) {
                    return Storage::url($row->user_image);
                }

                if (! $row->use_gravatar) {
                    return null;
                }

                return AdminUser::gravatarCachedForEmail($row->email)
                    ? AdminUser::getGravatarUrlFromEmail($row->email)
                    : null;
            },
        ]);

        $this->addColumn([
            'index'      => 'status',
            'label'      => trans('admin::app.settings.users.index.datagrid.status'),
            'type'       => 'dropdown',
            'searchable' => false,
            'filterable' => true,
            'sortable'   => true,
            'options'    => [
                'type' => 'basic',

                'params' => [
                    'options' => [
                        [
                            'label' => trans('admin::app.common.enable'),
                            'value' => 1,
                        ], [
                            'label' => trans('admin::app.common.disable'),
                            'value' => 0,
                        ],
                    ],
                ],
            ],
            'closure'    => function ($value) {
                // Decided here in PHP (the status column is a boolean on PostgreSQL): the row actions read this
                // flag after this closure has replaced `status` with its label.
                $value->invite_pending = ! $value->status && $value->invite_email !== null;

                if ($value->invite_pending) {
                    return '<span class="label-info">'.e(trans('admin::app.invites.status')).'</span>';
                }

                if ($value->status) {
                    return '<span class="label-active">'.trans('admin::app.common.enable').'</span>';
                }

                return '<span class="label-info">'.trans('admin::app.common.disable').'</span>';
            },
        ]);

        $this->addColumn([
            'index'      => 'email',
            'label'      => trans('admin::app.settings.users.index.datagrid.email'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'role_name',
            'label'      => trans('admin::app.settings.users.index.datagrid.role'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);
    }

    /**
     * Prepare actions.
     *
     * @return void
     */
    public function prepareActions()
    {
        if (bouncer()->hasPermission('settings.users.users.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.settings.users.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => function ($row) {
                    return route('admin.settings.users.edit', $row->user_id);
                },
                'condition' => fn ($row): bool => $this->actorMayManage((int) $row->user_id),
            ]);
        }

        if (bouncer()->hasPermission('settings.users.users.create')) {
            $this->addAction([
                'index'  => 'resend',
                'icon'   => '',
                'title'  => trans('admin::app.invites.resend'),
                'method' => 'POST',
                'url'    => function ($row) {
                    return route('admin.settings.users.invite.resend', $row->user_id);
                },
                'condition' => fn ($row): bool => ! empty($row->invite_pending) && $this->actorMayManage((int) $row->user_id),
            ]);
        }

        if (bouncer()->hasPermission('settings.users.users.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.settings.users.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => function ($row) {
                    return route('admin.settings.users.delete', $row->user_id);
                },
                'condition' => fn ($row): bool => $this->actorMayManage((int) $row->user_id),
            ]);
        }
    }

    /**
     * @var array<int, bool>
     */
    private array $manageable = [];

    /**
     * Whether the signed-in admin may edit or delete this user: not when the user's role outranks theirs. The
     * controller refuses those with a 403 already; the grid stops offering them (UX eval 2026-10-10, PERM-08).
     */
    private function actorMayManage(int $userId): bool
    {
        if (isset($this->manageable[$userId])) {
            return $this->manageable[$userId];
        }

        $actor = auth()->guard('admin')->user();
        $target = AdminUser::query()->with('role')->find($userId);

        return $this->manageable[$userId] = $actor && $target
            && ! app(AdminRepository::class)->outranks(target: $target, actor: $actor);
    }
}
