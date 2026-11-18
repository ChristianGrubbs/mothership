<?php

namespace Mothership\DAM\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;

/**
 * Computes previous/next/position/total for an asset in the SAME order the Library's default
 * listing uses (created_at desc, id asc tie-break — see ExplorerDataController::index()'s
 * `sort_by=created_at` default and its `orderBy($sortColumn, $sortOrder)->orderBy('dam_assets.id')`
 * tie-break), permission-scoped the way the public share viewer already is
 * (SharedViewerController::assetView() -> Asset::visibleCaptures() + directory grants).
 *
 * This is a server-side fallback only: the details page's chevrons prefer the caller-supplied
 * on-screen order handed over via sessionStorage (packages/Mothership/DAM/src/Resources/assets/js/
 * details-navigation.js + preview-navigation.js) whenever it is present and fresh, because that
 * reflects the user's actual sort/filter/search/page — this service's default-sort walk is only
 * what a direct link or a stale/missing handoff falls back to.
 *
 * Used by both AssetController::show() and AssetController::edit() so the two no longer carry
 * separate, drifting neighbour queries.
 *
 * `$directoryParam` is the raw `directory_id` request value and is one of three distinct
 * scopes, never conflated:
 *   - a positive-integer string: an explicit browsed folder. Validated (exists, viewable by the
 *     caller, actually contains this asset) before use; an invalid/denied id falls back to the
 *     asset's own first directory (never broadens to "all" — that would leak neighbours from
 *     folders the caller never asked to browse).
 *   - the literal string "all": an explicit Library "all"/search scope request, reachable
 *     regardless of whether the asset itself happens to have a directory — matches
 *     ExplorerDataController's "all" query (every accessible asset with >=1 directory). When the
 *     CURRENT asset is itself unattached, that query can never include it (it has no directory),
 *     so this falls back to the unattached pool instead — same reasoning as the omitted case
 *     below, just reached via an explicit "all" rather than no signal at all.
 *   - null/omitted: no scope signal at all (direct link, bookmark, legacy caller). Falls back to
 *     the asset's own first directory when it has one; when the asset itself is unattached, the
 *     pool is every OTHER unattached asset (not "all"), because unattached assets never appear
 *     in the real "all" Library listing (ExplorerDataController requires >=1 directory even in
 *     "all" scope) — computing this asset's position against a listing it could never appear in
 *     would be meaningless, and would silently exclude it from its own total.
 */
class AssetNeighbours
{
    public function __construct(
        protected DirectoryPermissionService $permissionService,
    ) {}

    /**
     * @return array{previousId: ?int, nextId: ?int, position: int, total: int}
     */
    public function forAsset(Asset $asset, ?string $directoryParam = null): array
    {
        $base = $this->resolveBaseQuery($asset, $directoryParam);

        $createdAt = $asset->created_at;
        $id = $asset->id;

        $beforeCurrent = function (Builder $q) use ($createdAt, $id) {
            $q->where('dam_assets.created_at', '>', $createdAt)
                ->orWhere(fn (Builder $q2) => $q2
                    ->where('dam_assets.created_at', $createdAt)
                    ->where('dam_assets.id', '<', $id));
        };

        $afterCurrent = function (Builder $q) use ($createdAt, $id) {
            $q->where('dam_assets.created_at', '<', $createdAt)
                ->orWhere(fn (Builder $q2) => $q2
                    ->where('dam_assets.created_at', $createdAt)
                    ->where('dam_assets.id', '>', $id));
        };

        // Chevron ‹ = the asset shown to the LEFT/earlier in the Library listing: the closest
        // item that sorts before the current one under (created_at desc, id asc).
        $previousId = (clone $base)->where($beforeCurrent)
            ->orderBy('dam_assets.created_at')
            ->orderByDesc('dam_assets.id')
            ->value('id');

        // Chevron › = the asset to the RIGHT/later in the listing: the closest item after it.
        $nextId = (clone $base)->where($afterCurrent)
            ->orderByDesc('dam_assets.created_at')
            ->orderBy('dam_assets.id')
            ->value('id');

        $position = 1 + (clone $base)->where($beforeCurrent)->count('dam_assets.id');
        $total = (clone $base)->count('dam_assets.id');

        return [
            'previousId' => $previousId !== null ? (int) $previousId : null,
            'nextId'     => $nextId !== null ? (int) $nextId : null,
            'position'   => $position,
            'total'      => $total,
        ];
    }

    protected function resolveBaseQuery(Asset $asset, ?string $directoryParam): Builder
    {
        if ($directoryParam === 'all') {
            // An unattached current asset can never satisfy allScopeQuery()'s whereHas
            // ('directories') filter — same reasoning as the null/omitted branch below: it would
            // silently exclude the asset from its own position/total. Explicit "all" on an
            // unattached asset falls back to the unattached pool rather than a query it can
            // structurally never be part of.
            return $asset->directories()->exists()
                ? $this->allScopeQuery()
                : $this->unattachedScopeQuery();
        }

        if ($directoryParam !== null && $directoryParam !== '' && ctype_digit($directoryParam)) {
            $directory = $this->resolveRequestedDirectory($asset, (int) $directoryParam);

            if ($directory) {
                return $this->directoryScopeQuery($directory);
            }
            // Invalid/denied/unrelated directory: a DEFINED, narrow fallback — never broadens
            // to "all", which could leak neighbours from folders the caller was never browsing.
        }

        $fallbackDirectory = $asset->directories()->first();

        return $fallbackDirectory
            ? $this->directoryScopeQuery($fallbackDirectory)
            : $this->unattachedScopeQuery();
    }

    /** Exists, is viewable by the current admin, and actually contains this asset. */
    protected function resolveRequestedDirectory(Asset $asset, int $directoryId): ?Directory
    {
        $directory = Directory::find($directoryId);

        if (! $directory) {
            return null;
        }

        // canView() (ancestor-aware — the same check ExplorerDataController::index() uses to
        // authorize browsing a folder), not canAccess() (direct-grant only): a directory that is
        // viewable only because an ancestor was granted is exactly what the Library itself lets
        // the caller browse, so rejecting it here would make a perfectly legitimate browsed
        // folder fall back to the asset's own directory for no reason.
        if (! $this->permissionService->bypass() && ! $this->permissionService->canView($directory->id)) {
            return null;
        }

        $containsAsset = DB::table('dam_asset_directory')
            ->where('directory_id', $directory->id)
            ->where('asset_id', $asset->id)
            ->exists();

        return $containsAsset ? $directory : null;
    }

    protected function visibilityScopedQuery(): Builder
    {
        $query = Asset::query()->visibleCaptures();
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        return $query;
    }

    protected function directoryScopeQuery(Directory $directory): Builder
    {
        return $this->visibilityScopedQuery()
            ->whereHas('directories', fn ($q) => $q->where('dam_directories.id', $directory->id));
    }

    /** Matches ExplorerDataController's "all" query: every accessible asset with >=1 directory. */
    protected function allScopeQuery(): Builder
    {
        return $this->visibilityScopedQuery()->whereHas('directories');
    }

    /** Unattached assets never appear in the real "all" Library listing, so they get their own pool. */
    protected function unattachedScopeQuery(): Builder
    {
        return $this->visibilityScopedQuery()->whereDoesntHave('directories');
    }
}
