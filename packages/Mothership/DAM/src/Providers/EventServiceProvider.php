<?php

namespace Mothership\DAM\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mothership\AdminApi\Services\McpTokenExchanged;
use Mothership\DAM\Listeners\DeleteAdminAccessRows;
use Mothership\DAM\Listeners\NullAdminFeedbackReports;
use Mothership\DAM\Listeners\RecordMcpTokenExchange;
use Mothership\DAM\Listeners\RecordSecurityEvent;
use Mothership\DAM\Listeners\ReleaseAdminHubResources;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Repositories\DirectoryRolePermissionRepository;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\Review\ReviewNotifier;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\Notification\Models\Notification;
use Mothership\Theme\ViewRenderEventManager;
use Mothership\User\Models\AdminProxy;

class EventServiceProvider extends ServiceProvider
{
    const ASSET_ATTRIBUTE_TYPE = 'asset';

    const ASSET_CATEGORY_FIELD_TYPE = 'asset';

    protected $listen = [
        'catalog.product.create.after' => [
            'Mothership\DAM\Listeners\Product@afterCreateOrupdate',
        ],

        'catalog.product.update.after' => [
            'Mothership\DAM\Listeners\Product@afterCreateOrupdate',
        ],

        'catalog.category.create.after' => [
            'Mothership\DAM\Listeners\Category@afterUpdateOrCreate',
        ],

        'catalog.category.update.after' => [
            'Mothership\DAM\Listeners\Category@afterUpdateOrCreate',
        ],
    ];

    public function boot()
    {
        Event::listen('mothership.admin.categories.dynamic-fields.control.'.self::ASSET_CATEGORY_FIELD_TYPE.'.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('dam::asset.catalog.categories.dynamic-fields.asset-control');
        });

        Event::listen('mothership.admin.products.dynamic-attribute-fields.control.'.self::ASSET_ATTRIBUTE_TYPE.'.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('dam::asset.catalog.products.dynamic-attribute-fields.asset-control');
        });

        Event::listen('mothership.admin.layout.content.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            if (! request()->routeIs('admin.catalog.products.bulkedit')) {
                return;
            }

            $viewRenderEventManager->addTemplate('dam::catalog.products.bulk-edit.asset');
        });

        Event::listen('mothership.admin.layout.head.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('dam::style');
        });

        // P1b usage analytics: the notice line under every admin page, and the collector at the end of the body.
        Event::listen('mothership.admin.layout.content.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            if (config('dam_events.enabled') && auth('admin')->check()) {
                $viewRenderEventManager->addTemplate('dam::analytics.footer');
            }
        });

        Event::listen('mothership.admin.layout.body.after', static function (ViewRenderEventManager $viewRenderEventManager) {
            if (config('dam_events.enabled') && auth('admin')->check()) {
                $viewRenderEventManager->addTemplate('dam::analytics.admin-collector');
            }
        });

        Event::listen('mothership.admin.settings.roles.edit.card.access-control.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('dam::admin.roles.dam-permissions-tab');
        });

        Event::listen('mothership.admin.settings.roles.create.card.access_control.before', static function (ViewRenderEventManager $viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('dam::admin.roles.dam-permissions-tab');
        });

        // Authentication and SSO outcomes as dam_security_events rows (P1a); access changes are recorded where
        // FolderAccessLog writes them.
        Event::listen(Login::class, [RecordSecurityEvent::class, 'login']);
        Event::listen(Failed::class, [RecordSecurityEvent::class, 'failed']);
        Event::listen(Lockout::class, [RecordSecurityEvent::class, 'lockout']);
        Event::listen(Logout::class, [RecordSecurityEvent::class, 'logout']);
        Event::listen(PasswordReset::class, [RecordSecurityEvent::class, 'passwordReset']);
        Event::listen('mothership.admin.sso.login.before', [RecordSecurityEvent::class, 'ssoLoginStarting']);
        Event::listen('mothership.admin.sso.login.after', [RecordSecurityEvent::class, 'ssoLoginCompleted']);
        Event::listen('mothership.admin.sso.login.failed', [RecordSecurityEvent::class, 'ssoLoginFailed']);

        // MCP connector grant types: refresh means the connection lasted, a code grant means consent again.
        Event::listen(McpTokenExchanged::class, RecordMcpTokenExchange::class);

        // P7: a review notification is read only as its reader may see it now (ReviewNotifier::listed()).
        Notification::retrieved(fn (Notification $notification) => app(ReviewNotifier::class)->listed($notification));

        Event::listen('user.role.create.before', fn () => app(RoleBuilder::class)->prepareRequest());
        Event::listen('user.role.update.before', fn () => app(RoleBuilder::class)->prepareRequest());
        Event::listen('user.role.create.after', fn ($role) => app(RoleBuilder::class)->persistSettings($role));
        Event::listen('user.role.update.after', fn ($role) => app(RoleBuilder::class)->persistSettings($role));

        $syncDirectoryGrants = static function ($role) {
            if (! $role) {
                return;
            }

            // The Roles editor's folder part is absent under lists (ADR 0010, sharing slice 4): the legacy
            // folder-grant writer stays inert so nothing here fights the access lists.
            if (app(AccessModel::class)->lists()) {
                return;
            }

            if (! request()->boolean('dam_directory_grants_managed')) {
                return;
            }

            $directoryIds = array_values(array_filter(
                array_map('intval', (array) request('directories', [])),
                fn ($id) => $id > 0
            ));

            if (! empty($directoryIds)) {
                $directoryIds = Directory::whereIn('id', $directoryIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if (empty($directoryIds)) {
                $rootId = Directory::whereNull('parent_id')
                    ->orderBy('id')
                    ->value('id');

                if ($rootId) {
                    $directoryIds = [(int) $rootId];
                }
            }

            $allDirectories = request()->boolean('dam_all_directories');
            $inheritChildren = request()->boolean('dam_inherit_children');

            if ($inheritChildren && count($directoryIds) > 1) {
                $existingGrants = app(DirectoryRolePermissionRepository::class)
                    ->getDirectoryIdsForRole((int) $role->id);

                $directoryIds = DB::table('dam_directories as d')
                    ->whereIn('d.id', $directoryIds)
                    ->where(function ($q) use ($directoryIds, $existingGrants) {
                        if (! empty($existingGrants)) {
                            $q->whereIn('d.id', $existingGrants);
                        }
                        $q->orWhereNotExists(function ($sub) use ($directoryIds) {
                            $sub->from('dam_directories as ancestor')
                                ->whereIn('ancestor.id', $directoryIds)
                                ->whereColumn('ancestor._lft', '<', 'd._lft')
                                ->whereColumn('ancestor._rgt', '>', 'd._rgt');
                        });
                    })
                    ->pluck('d.id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            DB::table('dam_role_settings')->updateOrInsert(
                ['role_id' => (int) $role->id],
                [
                    'all_directories'  => $allDirectories,
                    'inherit_children' => $inheritChildren,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );

            app(DirectoryRolePermissionRepository::class)
                ->syncForRole((int) $role->id, $directoryIds);

            app(DirectoryPermissionService::class)->flush();
        };

        Event::listen('user.role.update.after', $syncDirectoryGrants);
        Event::listen('user.role.create.after', $syncDirectoryGrants);

        // SQL Server holds admin_id on the user access lists as NO ACTION (ADR 0010); every other driver
        // cascades those rows with the admin delete, atomically.
        if (config('database.connections.'.config('database.default').'.driver') === 'sqlsrv') {
            Event::listen('eloquent.deleting: '.AdminProxy::modelClass(), DeleteAdminAccessRows::class);
        }

        // ADR 0015: an admin's fonts and brushes go with them on every driver, their blobs released, not orphaned.
        Event::listen('eloquent.deleting: '.AdminProxy::modelClass(), ReleaseAdminHubResources::class);
        Event::listen('eloquent.deleting: '.AdminProxy::modelClass(), NullAdminFeedbackReports::class);
    }
}
