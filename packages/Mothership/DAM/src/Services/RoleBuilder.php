<?php

namespace Mothership\DAM\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\ShareKeyGrant;
use Mothership\DAM\Support\RequestId;
use Mothership\User\Models\Admin;

class RoleBuilder
{
    public const CAPABILITIES = [
        'library' => [
            'dam', 'dam.assets', 'dam.asset', 'dam.asset.view', 'dam.asset.upload',
            'dam.asset.download', 'dam.directory', 'dam.directory.index', 'dam.directory.store',
        ],
        'metadata' => [
            'dam.asset.edit', 'dam.asset.update', 'dam.asset.tag', 'dam.asset.meta_data', 'dam.asset.property',
            'dam.asset.property.view', 'dam.asset.property.create', 'dam.asset.property.update',
        ],
        'image_editor'  => ['dam.asset.edit', 'dam.asset.upload', 'dam.asset.editor', 'dam.asset.re_upload'],
        'public_shares' => ['dam.asset.share', 'dam.directory.share'],
        'web_pages'     => ['dam.pages', 'dam.pages.organize', 'dam.asset.upload', 'dam.asset.view'],
        'creative_hub'  => ['dam.asset.view', 'dam.asset.download', 'dam.asset.upload'],
        'delete_own'    => ['dam.asset.destroy', 'dam.asset.mass_delete', 'dam.directory.destroy'],
        // Tool widgets that declare their own ACL root (dam-home.widgets[*].permission). Without a capability
        // here, "Use Sandbox defaults" ticks the widget but never grants the permission, and
        // HomeWidgetRegistry silently drops the widget from that role's Home catalog.
        'sphereseam'    => ['sphereseam'],
    ];

    public function prepareRequest(): void
    {
        $submittedPermissions = array_values(array_unique(array_map('strval', (array) request('permissions', []))));

        if (request()->boolean('dam_role_builder_managed')) {
            $selectedCapabilities = (array) request('dam_capabilities', []);
            $originalCapabilities = (array) request('dam_original_capabilities', []);
            $permissions = collect((array) request('permissions', []));

            foreach (array_diff($originalCapabilities, $selectedCapabilities) as $capability) {
                $revokedPermissions = self::CAPABILITIES[$capability] ?? [];
                $permissions = $permissions->reject(
                    fn ($permission) => in_array($permission, $revokedPermissions, true)
                );
            }

            foreach ($selectedCapabilities as $capability) {
                $permissions = $permissions->merge(self::CAPABILITIES[$capability] ?? []);
            }

            request()->merge(['permissions' => $permissions->unique()->values()->all()]);
            $this->record('permissions.prepared', [
                'capability_count' => count((array) request('dam_capabilities', [])),
                'permission_count' => count((array) request('permissions', [])),
            ]);
        }

        $this->keepShareKeysUnderLists();
        $this->rememberReconciliation($submittedPermissions);
    }

    /**
     * The capability checkboxes and the permission tree are coupled on purpose (CAPABILITIES above), so a save
     * can grant or revoke keys the tree did not submit. Keeps the difference between what the form posted and
     * what will be stored, so the save response can name it instead of reporting a bare success.
     */
    protected function rememberReconciliation(array $submittedPermissions): void
    {
        $added = [];
        $removed = [];

        if (strtolower((string) request('permission_type')) === 'custom') {
            $finalPermissions = array_map('strval', (array) request('permissions', []));
            $added = array_values(array_diff($finalPermissions, $submittedPermissions));
            $removed = array_values(array_diff($submittedPermissions, $finalPermissions));
        }

        request()->attributes->set('role_builder_reconciliation', ['added' => $added, 'removed' => $removed]);
        $this->record('permissions.reconciled', ['added' => $added, 'removed' => $removed]);
    }

    /**
     * Appends the reconciliation notice, when the save changed the submitted permissions, to the success
     * message of a role create or update.
     */
    public function withReconciliationNotice(string $message): string
    {
        $reconciliation = (array) request()->attributes->get('role_builder_reconciliation', []);
        $added = (array) ($reconciliation['added'] ?? []);
        $removed = (array) ($reconciliation['removed'] ?? []);

        if ($added === [] && $removed === []) {
            return $message;
        }

        $notice = trans('dam::app.admin.permissions.reconciled', [
            'added'   => $added === [] ? '-' : implode(', ', $added),
            'removed' => $removed === [] ? '-' : implode(', ', $removed),
        ]);

        return $message.' '.$notice;
    }

    /**
     * Every custom role keeps the six share ACL keys once DAM reads use the lists (sharing slice 5, D7): a
     * role save must never drop them back off, whether or not this request went through the DAM tab. Runs
     * on every create and update; a no-op under grants, and a no-op for a role saved as `all`, since a full
     * admin's access never reads the permissions array.
     */
    protected function keepShareKeysUnderLists(): void
    {
        if (strtolower((string) request('permission_type')) !== 'custom') {
            return;
        }

        if (! app(AccessModel::class)->lists()) {
            return;
        }

        $permissions = collect((array) request('permissions', []))
            ->merge(ShareKeyGrant::KEYS)
            ->unique()
            ->values()
            ->all();

        request()->merge(['permissions' => $permissions]);
    }

    public function persistSettings(object $role): void
    {
        if ($role->permission_type === 'all') {
            DB::table('dam_role_settings')->updateOrInsert(
                ['role_id' => (int) $role->id],
                ['delete_own_only' => false, 'home_widget_ids' => null, 'created_at' => now(), 'updated_at' => now()]
            );
            $this->record('settings.reset_for_all_access', [
                'role_id' => (int) $role->id,
            ]);

            return;
        }

        if (! request()->boolean('dam_role_builder_managed')) {
            return;
        }

        $widgetIds = array_values(array_intersect(
            array_keys(config('dam-home.widgets', [])),
            array_map('strval', (array) request('dam_home_widgets', []))
        ));

        DB::table('dam_role_settings')->updateOrInsert(
            ['role_id' => (int) $role->id],
            [
                'delete_own_only'  => in_array('delete_own', (array) request('dam_capabilities', []), true),
                'home_widget_ids'  => json_encode($widgetIds),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]
        );
        $this->record('settings.persisted', [
            'role_id'         => (int) $role->id,
            'delete_own_only' => in_array('delete_own', (array) request('dam_capabilities', []), true),
            'widget_count'    => count($widgetIds),
        ]);
    }

    public function canDelete(Model $model): bool
    {
        $admin = $this->actingAdmin();
        if (! $admin) {
            return false;
        }

        return $this->canUserDelete(model: $model, admin: $admin);
    }

    /**
     * The signed-in admin of this request: the session guard on the web, the Passport guard on the REST API. The
     * own-items rule below must read the same person on both, or a REST call would be refused outright (or, worse,
     * waved through) for want of a session.
     */
    public function actingAdmin(): ?Admin
    {
        $admin = auth('admin')->user() ?? auth('api')->user();

        return $admin instanceof Admin ? $admin : null;
    }

    /**
     * Whether this admin may touch only what they created: a custom role with delete_own_only (the normal-user
     * shape). Full administrators and roles without the flag are never restricted. One reading, shared by every
     * destructive, rename, move, restore and reject gate, so they cannot drift from the delete rule.
     */
    public function restrictedToOwn(?Admin $admin = null): bool
    {
        $admin ??= $this->actingAdmin();

        return $admin !== null && $this->deleteOwnOnly(admin: $admin);
    }

    /**
     * Whether the signed-in admin may change `$model` (an asset or a folder) under the own-items rule: always for an
     * unrestricted admin, otherwise only for what they created. Same answer as canDelete(), named for the gates that
     * are not deletes.
     */
    public function mayChange(Model $model): bool
    {
        return $this->canDelete($model);
    }

    /** The Library root (the folder the Library cannot delete or move either): a restricted role never renames or moves it. */
    public function isLibraryRoot(Directory $directory): bool
    {
        return ! $directory->isDeletable();
    }

    /**
     * The plain-language refusal when the signed-in admin may not change `$model` under the own-items rule, or null
     * when they may. `$reason` names the lang key under dam::app.admin.permissions. A restricted role never changes
     * the Library root, whoever created it. Every gate calls this, so web and REST answer the same words.
     */
    public function refusal(Model $model, string $reason = 'own-only-change'): ?string
    {
        if (! $this->restrictedToOwn()) {
            return null;
        }

        if ($model instanceof Directory && $this->isLibraryRoot($model)) {
            return trans('dam::app.admin.permissions.own-only-root');
        }

        return $this->mayChange($model) ? null : trans('dam::app.admin.permissions.'.$reason);
    }

    /**
     * The service-level form of refusal(): aborts with the plain-language 403 when a role limited to its own items
     * would change another person's (or an ownerless) item. A caller with no signed-in admin (a queued job, a machine
     * writer) and every unrestricted role pass. Shared services call this so web, REST and bulk answer the same words.
     */
    public function abortUnlessOwn(Model $model, string $reason = 'own-only-details'): void
    {
        if ($message = $this->refusal($model, $reason)) {
            abort(403, $message);
        }
    }

    /**
     * Of `$assetIds`, the ones the signed-in admin may change: all of them for an unrestricted admin, otherwise only
     * what they created. Bulk writers use it to skip the rest and say how many were skipped.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, int>
     */
    public function ownedAssetIds(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));

        if (! $this->restrictedToOwn() || $assetIds === []) {
            return $assetIds;
        }

        $actor = (int) $this->actingAdmin()?->id;

        return Asset::query()->whereIn('id', $assetIds)->where('created_by', $actor)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function canUserDelete(Model $model, Admin $admin): bool
    {
        $ownOnly = $this->deleteOwnOnly(admin: $admin);
        $allowed = ! $ownOnly || (int) $model->created_by === (int) $admin->id;
        $this->record('delete.checked', [
            'role_id'    => (int) $admin->role_id,
            'admin_id'   => (int) $admin->id,
            'model_id'   => (int) $model->getKey(),
            'model_type' => $model instanceof Directory ? 'directory' : 'asset',
            'allowed'    => $allowed,
        ]);

        return $allowed;
    }

    public function canDeleteDirectoryTree(Directory $directory): bool
    {
        $admin = $this->actingAdmin();
        if (! $admin) {
            return false;
        }

        return $this->canUserDeleteDirectoryTree(directory: $directory, admin: $admin);
    }

    public function canUserDeleteDirectoryTree(Directory $directory, Admin $admin): bool
    {
        if (! $this->canUserDelete(model: $directory, admin: $admin)) {
            return false;
        }

        if (! $this->deleteOwnOnly(admin: $admin)) {
            return true;
        }

        $directoryIds = Directory::query()
            ->whereBetween('_lft', [$directory->_lft, $directory->_rgt])
            ->pluck('id');
        $hasForeignDirectory = Directory::query()
            ->whereIn('id', $directoryIds)
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $admin->id))
            ->exists();
        $hasAssets = DB::table('dam_asset_directory')->whereIn('directory_id', $directoryIds)->exists();
        $allowed = ! $hasForeignDirectory && ! $hasAssets;
        $this->record('directory_tree_delete.checked', [
            'role_id'               => (int) $admin->role_id,
            'admin_id'              => (int) $admin->id,
            'model_id'              => (int) $directory->id,
            'directory_count'       => $directoryIds->count(),
            'has_foreign_directory' => $hasForeignDirectory,
            'has_assets'            => $hasAssets,
            'allowed'               => $allowed,
        ]);

        return $allowed;
    }

    private function deleteOwnOnly(Admin $admin): bool
    {
        if ($admin->role?->permission_type === 'all') {
            return false;
        }

        return (bool) DB::table('dam_role_settings')
            ->where('role_id', $admin->role_id)
            ->value('delete_own_only');
    }

    private function record(string $event, array $context): void
    {
        if (! config('app.debug')) {
            return;
        }

        $record = [
            'ts'     => now()->toIso8601String(),
            'run_id' => request()->attributes->get('role_builder_run_id') ?? (string) Str::uuid(),
            'level'  => 'debug',
            'event'  => $event,
            'loc'    => self::class,
            'ctx'    => RequestId::ctx($context),
        ];
        request()->attributes->set('role_builder_run_id', $record['run_id']);

        @file_put_contents(storage_path('logs/role-access.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
