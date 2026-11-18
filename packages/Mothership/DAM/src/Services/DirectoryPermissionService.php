<?php

namespace Mothership\DAM\Services;

use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListReader;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\LegacyGrantReader;
use Mothership\User\Models\Admin;

/**
 * The one directory and file access rule every DAM surface asks (ADR 0010). The public methods are
 * unchanged; each one delegates to the reader for the current access model, evaluated for the current
 * admin: LegacyGrantReader under `grants` (the default, today's answers) and AccessListReader under
 * `lists`. Each reader caches per admin, so the caches are keyed by (admin, model).
 */
class DirectoryPermissionService
{
    /** Set by forAdmin() for session-free checks; wins over the auth guards. */
    protected ?Admin $forcedAdmin = null;

    protected ?LegacyGrantReader $legacyReader = null;

    protected ?AccessListReader $listReader = null;

    /** Evaluates every rule for $admin instead of the authenticated user (grant-authorised requests). */
    public function forAdmin(Admin $admin): static
    {
        $this->forcedAdmin = $admin;
        $this->flush();

        return $this;
    }

    /**
     * Session-free access check for a grant-authorised page request (ADR 0007 amendment, audit A2):
     * the same rule as canAccessAsset() under the current access model, evaluated for the admin named
     * in the grant.
     */
    public static function adminCanAccessAsset(int $adminId, int $assetId): bool
    {
        $admin = Admin::query()->find($adminId);

        // The seam refuses a disabled admin, as the Bouncer middleware refuses a disabled session.
        return $admin !== null && app(AssetAccessDecision::class)->visible(AccessContext::forAdmin($admin), $assetId);
    }

    /**
     * The whole-surface short circuit: a bypass role can skip every directory filter outright.
     */
    public function bypass(): bool
    {
        return $this->roleBypass();
    }

    /**
     * A full admin: a role whose permission type is `all`. Not bypass(), which under grants is also true for a
     * custom role with all_directories (it reaches every folder, but is no full admin) and for an admin with no
     * role. Who may see a share's URL (Share::tokenVisibleTo) and the lists-mode share gates use this one
     * (P2 S5a review).
     */
    public function fullAdmin(): bool
    {
        return $this->currentAdmin()?->role?->permission_type === 'all';
    }

    /**
     * A bypass role reaches every directory, without consulting any grant table.
     */
    public function bypassesDirectory(int $directoryId): bool
    {
        return $this->roleBypass();
    }

    /**
     * Grants: full admins, `all_directories` roles and admins with no role. Lists: full admins and
     * admins with no role.
     */
    public function roleBypass(): bool
    {
        return $this->reader()->roleBypass(admin: $this->currentAdmin());
    }

    public function viewableIds(): array
    {
        return $this->reader()->viewableIds(admin: $this->currentAdmin());
    }

    public function directlyGrantedIds(): array
    {
        return $this->reader()->directlyGrantedIds(admin: $this->currentAdmin());
    }

    public function canView(int $directoryId): bool
    {
        return $this->reader()->canView(admin: $this->currentAdmin(), directoryId: $directoryId);
    }

    public function canAccess(int $directoryId): bool
    {
        return $this->reader()->canAccess(admin: $this->currentAdmin(), directoryId: $directoryId);
    }

    /**
     * The one asset access rule, the same membership applyAssetDirectoryGrantFilter() lists by, so a
     * page the index shows is a page that opens (audit A1).
     */
    public function canAccessAsset(int $assetId): bool
    {
        return $this->reader()->canAccessAsset(admin: $this->currentAdmin(), assetId: $assetId);
    }

    /**
     * Restrict an asset query to what the admin can open. A bypass role sees everything, so the
     * filter is skipped outright rather than applied as a no-op. The check stays here, ahead of the
     * reader, so a caller that stubs bypass() never reaches the database.
     *
     * $locked: a shared lock on the filter's own list subqueries (AccessListReader::whereListed()),
     * for a caller re-validating an already-locked asset row from inside that lock (Codex review of
     * PR #75 round 2, finding 2) — see ClonesOriginalFileObjects::accessibleAssetIds(). $locked never
     * widens or narrows who is let through; it only decides whether the reads that answer the
     * question take a lock. $candidateIds (round 3, finding 2): restricts each locked subquery to the
     * ids actually being re-validated, rather than every row the admin can see library-wide.
     */
    public function applyAssetDirectoryGrantFilter($query, string $column = 'dam_assets.id', bool $locked = false, mixed $candidateIds = null): void
    {
        if ($this->bypass()) {
            return;
        }

        $this->reader()->applyAssetDirectoryGrantFilter(admin: $this->currentAdmin(), query: $query, column: $column, locked: $locked, candidateIds: $candidateIds);
    }

    /** The admin every rule here is evaluated for: the named one, else the session's (admin, then api guard). */
    public function viewer(): ?Admin
    {
        $admin = $this->currentAdmin();

        return $admin instanceof Admin ? $admin : null;
    }

    public function flush(): void
    {
        $this->legacyReader = null;
        $this->listReader = null;
    }

    protected function reader(): LegacyGrantReader|AccessListReader
    {
        if (app(AccessModel::class)->lists()) {
            return $this->listReader ??= new AccessListReader;
        }

        return $this->legacyReader ??= new LegacyGrantReader;
    }

    protected function currentAdmin()
    {
        if ($this->forcedAdmin !== null) {
            return $this->forcedAdmin;
        }

        try {
            return auth()->guard('admin')->user()
                ?? auth()->guard('api')->user();
        } catch (\BadMethodCallException) {
            return null;
        }
    }
}
