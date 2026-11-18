<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\FolderAccessService;
use Mothership\User\Models\Admin;

/**
 * Today's folder grant rules (role, user and group grants), moved verbatim out of
 * DirectoryPermissionService and parameterised by admin (ADR 0010). It answers every service method
 * while the access model is `grants`, and it is the "before" side of `dam:access:report --compare`.
 * Its caches hold one admin at a time, exactly as the service's own caches did.
 */
class LegacyGrantReader
{
    protected ?array $viewableIdsCache = null;

    protected ?int $cachedForAdminId = null;

    protected ?array $directlyGrantedCache = null;

    protected ?int $directlyGrantedForAdminId = null;

    /**
     * The whole-surface short circuit: a bypass role can skip every directory filter outright.
     */
    public function bypass(?Admin $admin): bool
    {
        return $this->roleBypass(admin: $admin);
    }

    /**
     * A bypass role reaches every directory, without consulting any grant table.
     */
    public function bypassesDirectory(?Admin $admin, int $directoryId): bool
    {
        return $this->roleBypass(admin: $admin);
    }

    /**
     * Only the explicit `all` permission type is a superadmin. Asking whether the type
     * was "not custom" handed the whole-surface bypass to every unrecognised value, so
     * a role stored with a malformed type read as full access here. Anything other than
     * `all` now takes the restricted path: its own grants, plus the all_directories
     * opt-in an administrator set for that role deliberately.
     *
     * An admin with no role row at all keeps its previous bypass — admins.role_id is
     * nullable and that branch is unrelated to the permission_type boundary.
     */
    public function roleBypass(?Admin $admin): bool
    {
        if (! $admin) {
            return true;
        }

        $role = $admin->role;

        if (! $role) {
            return true;
        }

        if ($role->permission_type === 'all') {
            return true;
        }

        return DB::table('dam_role_settings')
            ->where('role_id', $admin->role_id)
            ->where('all_directories', true)
            ->exists();
    }

    public function viewableIds(?Admin $admin): array
    {
        if ($this->bypass(admin: $admin)) {
            return Directory::query()->pluck('id')->all();
        }

        if ($this->viewableIdsCache !== null && $this->cachedForAdminId === $admin?->id) {
            return $this->viewableIdsCache;
        }

        $granted = $this->directlyGrantedIds(admin: $admin);

        if (empty($granted)) {
            $this->viewableIdsCache = [];
            $this->cachedForAdminId = $admin?->id;

            return [];
        }

        $ids = DB::table('dam_directories as ancestor')
            ->join('dam_directories as descendant', function ($join) {
                $join->whereColumn('ancestor._lft', '<=', 'descendant._lft')
                    ->whereColumn('ancestor._rgt', '>=', 'descendant._rgt');
            })
            ->whereIn('descendant.id', $granted)
            ->distinct()
            ->pluck('ancestor.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->viewableIdsCache = $ids;
        $this->cachedForAdminId = $admin?->id;

        return $ids;
    }

    public function directlyGrantedIds(?Admin $admin): array
    {
        if ($this->roleBypass(admin: $admin)) {
            return Directory::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if (! $admin) {
            return [];
        }

        if ($this->directlyGrantedCache !== null && $this->directlyGrantedForAdminId === $admin?->id) {
            return $this->directlyGrantedCache;
        }

        $explicit = DB::table('dam_directory_role')
            ->where('role_id', $admin->role_id)
            ->pluck('directory_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $inheritChildren = (bool) DB::table('dam_role_settings')
            ->where('role_id', $admin->role_id)
            ->value('inherit_children');

        if ($inheritChildren && ! empty($explicit)) {
            $descendants = DB::table('dam_directories as ancestor')
                ->join('dam_directories as descendant', function ($join) {
                    $join->whereColumn('descendant._lft', '>=', 'ancestor._lft')
                        ->whereColumn('descendant._rgt', '<=', 'ancestor._rgt');
                })
                ->whereIn('ancestor.id', $explicit)
                ->distinct()
                ->pluck('descendant.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ids = array_values(array_unique(array_merge($explicit, $descendants)));
        } else {
            $ids = $explicit;
        }

        $ids = array_values(array_unique(array_merge($ids, app(FolderAccessService::class)->grantedIds(adminId: (int) $admin?->id))));

        $this->directlyGrantedCache = $ids;
        $this->directlyGrantedForAdminId = $admin?->id;

        return $ids;
    }

    public function canView(?Admin $admin, int $directoryId): bool
    {
        if ($this->bypass(admin: $admin)) {
            return true;
        }

        return in_array($directoryId, $this->viewableIds(admin: $admin), true);
    }

    public function canAccess(?Admin $admin, int $directoryId): bool
    {
        if ($this->bypass(admin: $admin) || $this->bypassesDirectory(admin: $admin, directoryId: $directoryId)) {
            return true;
        }

        return in_array($directoryId, $this->directlyGrantedIds(admin: $admin), true);
    }

    /**
     * The one asset access rule: an asset is reachable when ANY of its directories is. This is the
     * same membership applyAssetDirectoryGrantFilter() lists by, so a page the index shows is a page
     * that opens (audit A1). An asset in no directory is reachable by a bypass role only.
     */
    public function canAccessAsset(?Admin $admin, int $assetId): bool
    {
        if ($this->bypass(admin: $admin)) {
            return true;
        }

        $directoryIds = DB::table('dam_asset_directory')->where('asset_id', $assetId)->pluck('directory_id');

        foreach ($directoryIds as $directoryId) {
            if ($this->canAccess(admin: $admin, directoryId: (int) $directoryId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restrict an asset query to directly-granted directories. A bypass role sees
     * everything, so the filter is skipped outright rather than applied as a no-op.
     *
     * $locked, $candidateIds: kept only for signature parity with AccessListReader's sibling method —
     * every 2b caller that might pass either gates on AccessModel::lists() first, so this reader never
     * actually receives them, but a locking, candidate-restricted read of the grant subquery would be
     * harmless here too.
     */
    public function applyAssetDirectoryGrantFilter(?Admin $admin, $query, string $column = 'dam_assets.id', bool $locked = false, mixed $candidateIds = null): void
    {
        if ($this->bypass(admin: $admin)) {
            return;
        }

        $grants = DB::table('dam_asset_directory')
            ->whereIn('directory_id', $this->directlyGrantedIds(admin: $admin))
            ->select('asset_id');

        if ($locked) {
            $grants->sharedLock();

            if ($candidateIds !== null) {
                $grants->whereIn('asset_id', $candidateIds);
            }
        }

        $query->whereIn($column, $grants);
    }

    public function flush(): void
    {
        $this->viewableIdsCache = null;
        $this->cachedForAdminId = null;
        $this->directlyGrantedCache = null;
        $this->directlyGrantedForAdminId = null;
    }
}
