<?php

namespace Mothership\DAM\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\Core\Eloquent\Repository;
use Mothership\DAM\Exceptions\DirectoryNameReservedException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AssetTrashService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\LibraryDirectoryPresentation;
use Mothership\DAM\Support\AssetPathPrefixGuard;
use Mothership\DAM\Support\ReservedDirectoryNames;
use Mothership\DAM\Support\SearchTerms;

class DirectoryRepository extends Repository
{
    public const DEFAULT_TREE_PAGE_SIZE = 100;

    public function model(): string
    {
        return Directory::class;
    }

    public function findWithChildren($id)
    {
        return Directory::with('children')->find($id);
    }

    // Each writer below reads the tree (parent, paths) and changes it under one Directory::withTreeLock()
    // call, so no other writer's nested-set shift can interleave (ADR 0004). Folders are database rows only
    // (ADR 0009 slice 3): no physical directory exists to probe for writability or touch on disk.
    public function create(array $data)
    {
        $this->assertNameAllowed($data['name'] ?? null);

        return Directory::withTreeLock(fn () => parent::create($data));
    }

    public function update(array $data, $id)
    {
        if (array_key_exists('name', $data)) {
            $this->assertNameAllowed($data['name']);
        }

        return Directory::withTreeLock(function () use ($data, $id) {
            $oldDirectory = $this->find($id);

            $oldPath = $oldDirectory->generatePath();

            $newDirectory = parent::update($data, $id);

            $newPath = $newDirectory->generatePath();

            if ($oldDirectory->name != $newDirectory->name) {
                [$oldPrefix, $newPrefix, $likePattern] = AssetPathPrefixGuard::boundedPrefixes(
                    Directory::ASSETS_DIRECTORY.'/'.$oldPath,
                    Directory::ASSETS_DIRECTORY.'/'.$newPath,
                );

                DB::statement(
                    sprintf('UPDATE %sdam_assets SET path = REPLACE(path, ?, ?) WHERE path LIKE ? ESCAPE ?', DB::getTablePrefix()),
                    [$oldPrefix, $newPrefix, $likePattern, '\\']
                );
            }

            return $newDirectory;
        });
    }

    /**
     * See ReservedDirectoryNames: a folder named `.objects` (or anything else starting with `.`)
     * would collide with the content-addressed blob store's root, so create()/update() refuse it
     * here as a backstop for any caller that skipped DirectoryRequest's own validation.
     */
    private function assertNameAllowed(?string $name): void
    {
        if ($name !== null && ReservedDirectoryNames::isReserved($name)) {
            throw new DirectoryNameReservedException(trans('dam::app.admin.dam.index.directory.reserved-name'));
        }
    }

    /**
     * No live caller reaches this (Jobs\DeleteDirectory owns the real, subtree-aware delete path);
     * kept correct rather than removed, so it moves directly-attached assets to the trash (P2 S4) the
     * same way that job does if something does call it.
     */
    public function delete($id)
    {
        return Directory::withTreeLock(function () use ($id) {
            $directory = $this->find($id);
            $trash = app(AssetTrashService::class);

            foreach ($directory->assets()->get() as $asset) {
                $trash->trash(asset: $asset, force: true);
            }

            return parent::delete($id);
        });
    }

    public function getDirectoryTree($id = null)
    {
        $service = app(DirectoryPermissionService::class);
        $applyFilter = ! $service->bypass();
        $allowedIds = $applyFilter ? $service->viewableIds() : null;

        // "Viewable" includes path-only ancestors the viewer cannot open, and under `lists` a file's own
        // list can leave out someone who opens its folder, so the eager-loaded assets carry the same
        // per-viewer filter the Explorer listing applies: only files the viewer can open.
        $assets = function ($q) {
            $q->visibleCaptures();

            app(AssetAccessDecision::class)->scopeListable($q, AccessContext::library(AccessContext::PURPOSE_LIST));
        };

        // Each listed file names every folder it sits in; a non-bypass viewer sees only the folders the
        // tree itself would show them, so a file shared into a hidden folder never names that folder.
        $assetDirectories = function ($q) use ($allowedIds, $applyFilter) {
            if ($applyFilter) {
                $q->whereIn('dam_directories.id', $allowedIds);
            }
        };

        if ($id !== null) {
            if ($applyFilter && ! in_array((int) $id, $allowedIds, true)) {
                return null;
            }

            $directory = $this->model->with(['assets' => $assets, 'assets.directories' => $assetDirectories, 'children' => fn ($q) => $q->whereIn('id', $service->viewableIds())])
                ->where('id', $id)
                ->first();
            if ($directory) {
                $this->markPresentation(collect([$directory]));
            }

            return $directory;
        }

        $query = $this->model->with(['assets' => $assets, 'assets.directories' => $assetDirectories]);

        if ($applyFilter) {
            $query->whereIn('id', $allowedIds);
        }

        $rollup = $applyFilter
            ? $this->getAssetCountsRollup($service->directlyGrantedIds())
            : $this->getAssetCountsRollup();

        return $this->markPresentation($query->get())
            ->each(fn ($dir) => $dir->assets_total_count = (int) ($rollup[$dir->id] ?? 0))
            ->toTree();
    }

    /**
     * The withCount() argument for a folder's subfolders: only those the viewer can see (P2 S3), so children_count
     * and has_children never point at a hidden subfolder.
     */
    private function visibleChildren(?DirectoryPermissionService $service = null): array|string
    {
        $service ??= app(DirectoryPermissionService::class);

        if ($service->bypass()) {
            return 'children';
        }

        $viewable = $service->viewableIds();

        return ['children' => fn ($children) => $children->whereIn($children->qualifyColumn('id'), $viewable)];
    }

    private function markPresentation($directories)
    {
        $ids = app(LibraryDirectoryPresentation::class)->structuralIds();

        return $directories->each(function ($directory) use ($ids) {
            $directory->is_structural_wrapper = in_array((int) $directory->id, $ids, true);
        });
    }

    public function getDirectoryTreeOnly()
    {
        $service = app(DirectoryPermissionService::class);

        $rootQuery = $this->model->withCount($this->visibleChildren($service))->whereNull('parent_id');

        if (! $service->bypass()) {
            $rootQuery->whereIn('id', $service->viewableIds());
        }

        $roots = $rootQuery->get();

        foreach ($roots as $root) {
            $root->has_children = $root->children_count > 0;

            $page = $this->getShallowChildren($root->id, $service);
            $root->children = $page['data']->all();
            $root->children_has_more = $page['has_more'];
        }

        return $this->markPresentation($roots);
    }

    public function getShallowChildren(int $parentId, ?DirectoryPermissionService $service = null, int $offset = 0, int $limit = self::DEFAULT_TREE_PAGE_SIZE): array
    {
        $service ??= app(DirectoryPermissionService::class);

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $query = $this->model
            ->withCount($this->visibleChildren($service))
            ->where('parent_id', $parentId)
            ->orderBy('name');

        if (! $service->bypass()) {
            $query->whereIn('id', $service->viewableIds());
        }

        $rows = $query->skip($offset)->take($limit + 1)->get();

        $hasMore = $rows->count() > $limit;

        $children = $rows->take($limit)->map(function ($dir) {
            $dir->has_children = $dir->children_count > 0;
            $dir->children = [];

            return $dir;
        })->values();

        return [
            'data'     => $this->markPresentation($children),
            'has_more' => $hasMore,
        ];
    }

    /**
     * The first page of the Library root's folders, as the folder strip and the sidebar tree would fetch
     * them after mount. Rendering it into the page lets both reserve their final size at first paint, so
     * the asset grid below them doesn't move (layout shift). The root is the first one
     * getDirectoryTreeOnly() returns, which is the one the tree emits.
     */
    public function getLibraryRootPage(): ?array
    {
        $service = app(DirectoryPermissionService::class);

        $rootQuery = $this->model->whereNull('parent_id');

        if (! $service->bypass()) {
            $rootQuery->whereIn('id', $service->viewableIds());
        }

        $root = $rootQuery->first(['id']);

        if (! $root) {
            return null;
        }

        $page = $this->getShallowChildren($root->id, $service);

        return [
            'root_id'  => $root->id,
            'children' => $page['data']->values(),
            'has_more' => $page['has_more'],
        ];
    }

    /**
     * getLibraryRootPage() with each folder's subtree asset count, for the folder strip's count badges.
     * Separate so the tree placeholder, which only needs the rows, never pays for the aggregation.
     */
    public function withSubtreeAssetCounts(?array $page): ?array
    {
        if (! $page) {
            return null;
        }

        $counts = $this->getVisibleSubtreeAssetCounts($page['children']->pluck('id')->all());

        $page['children'] = $page['children']->map(function ($dir) use ($counts) {
            $dir = clone $dir;
            $dir->assets_total_count = $counts[$dir->id] ?? null;

            return $dir;
        });

        return $page;
    }

    /**
     * Subtree asset counts for the folders the current user may view, keyed by folder id.
     */
    public function getVisibleSubtreeAssetCounts(array $ids, ?DirectoryPermissionService $service = null): array
    {
        $service ??= app(DirectoryPermissionService::class);

        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if (! $service->bypass()) {
            $viewable = array_flip($service->viewableIds());
            $ids = $ids->filter(fn ($id) => isset($viewable[$id]))->values();
        }

        if ($ids->isEmpty()) {
            return [];
        }

        $allowedDescendantIds = ! $service->bypass()
            ? $service->directlyGrantedIds()
            : null;

        return $this->getSubtreeAssetCounts($ids->all(), $allowedDescendantIds);
    }

    public function getAncestorPath(int $id): Collection
    {
        $target = $this->model->select('id', '_lft', '_rgt', 'parent_id', 'name')->find($id);

        if (! $target || ! app(DirectoryPermissionService::class)->canView($id)) {
            return collect();
        }

        return $this->markPresentation($this->model
            ->withCount($this->visibleChildren())
            ->whereIn('id', app(DirectoryPermissionService::class)->viewableIds())
            ->where('_lft', '<=', $target->_lft)
            ->where('_rgt', '>=', $target->_rgt)
            ->orderBy('_lft')
            ->get()
            ->map(function ($dir) {
                $dir->has_children = $dir->children_count > 0;
                $dir->children = [];

                return $dir;
            }));
    }

    public function getSubtreeAssetCounts(array $ids, ?array $allowedDescendantIds = null): array
    {
        $ids = array_values(array_intersect($ids, app(DirectoryPermissionService::class)->viewableIds()));
        $allowedDescendantIds ??= app(DirectoryPermissionService::class)->directlyGrantedIds();
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));

        if (empty($ids)) {
            return [];
        }

        if ($allowedDescendantIds !== null && empty($allowedDescendantIds)) {
            return array_fill_keys($ids, 0);
        }

        $prefix = DB::getTablePrefix();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $descendantFilter = '';
        $bindings = $ids;

        if ($allowedDescendantIds !== null) {
            $dPlaceholders = implode(',', array_fill(0, count($allowedDescendantIds), '?'));
            $descendantFilter = "AND descendant.id IN ({$dPlaceholders})";
            $bindings = array_merge($allowedDescendantIds, $ids);
        }

        $visibleAssets = $this->countableAssets();
        $visibleAssetSql = $visibleAssets->toSql();
        $visibleBindings = $visibleAssets->getBindings();

        $rows = DB::select("
            SELECT ancestor.id AS id, COUNT(DISTINCT ad.asset_id) AS total
            FROM {$prefix}dam_directories AS ancestor
            LEFT JOIN {$prefix}dam_directories AS descendant
                ON descendant._lft >= ancestor._lft AND descendant._rgt <= ancestor._rgt
                {$descendantFilter}
            LEFT JOIN {$prefix}dam_asset_directory AS ad
                ON ad.directory_id = descendant.id
                AND ad.asset_id IN ({$visibleAssetSql})
                AND NOT EXISTS (
                    SELECT 1 FROM {$prefix}dam_assets AS hidden_asset
                    JOIN {$prefix}dam_capture_groups AS capture_group ON capture_group.id = hidden_asset.capture_group_id
                    JOIN {$prefix}dam_assets AS capture_primary ON capture_primary.id = capture_group.primary_asset_id
                    WHERE hidden_asset.id = ad.asset_id AND capture_group.primary_asset_id IS NOT NULL
                        AND capture_group.primary_asset_id <> hidden_asset.id
                        AND capture_primary.deleted_at IS NULL
                )
            WHERE ancestor.id IN ({$placeholders})
            GROUP BY ancestor.id
        ", array_merge($allowedDescendantIds ?? [], $visibleBindings, $ids));

        return collect($rows)
            ->mapWithKeys(fn ($row) => [(int) $row->id => (int) $row->total])
            ->all();
    }

    public function getFullDirectoryTreeOnly()
    {
        $rollup = $this->getAssetCountsRollup();
        $directories = $this->model->withCount(['assets' => fn ($q) => $q->visibleCaptures()])
            ->whereIn('id', app(DirectoryPermissionService::class)->viewableIds())->get();

        return $this->markPresentation($directories)
            ->each(fn ($dir) => $dir->assets_total_count = (int) ($rollup[$dir->id] ?? 0))->toTree();
    }

    public function getAncestorPathsForIds(array $ids): Collection
    {
        $ids = array_values(array_intersect($ids, app(DirectoryPermissionService::class)->viewableIds()));
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => (int) $id > 0)));

        if (empty($ids)) {
            return collect();
        }

        $prefix = DB::getTablePrefix();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = DB::select("
            SELECT DISTINCT ancestor.id, ancestor._lft
            FROM {$prefix}dam_directories AS ancestor
            INNER JOIN {$prefix}dam_directories AS descendant
                ON ancestor._lft <= descendant._lft
                AND ancestor._rgt >= descendant._rgt
            WHERE descendant.id IN ({$placeholders})
            ORDER BY ancestor._lft
        ", $ids);

        $ancestorIds = collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (empty($ancestorIds)) {
            return collect();
        }

        return $this->markPresentation($this->model
            ->withCount($this->visibleChildren())
            ->whereIn('id', $ancestorIds)
            ->orderBy('_lft')
            ->get()
            ->map(function ($dir) {
                $dir->has_children = $dir->children_count > 0;
                $dir->assets_total_count = 0;
                $dir->children = [];

                return $dir;
            }));
    }

    public function getDescendantIds(int $id): array
    {
        $node = $this->model->where('id', $id)->first(['_lft', '_rgt']);

        if (! $node) {
            return [];
        }

        return $this->model
            ->whereIn('id', app(DirectoryPermissionService::class)->viewableIds())
            ->where('_lft', '>', $node->_lft)
            ->where('_rgt', '<', $node->_rgt)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The files a folder count counts: what the viewer's listing shows, through the seam (P2 S3). The folder rule
     * alone counted a draft or out-of-window file a member never sees in the folder.
     */
    private function countableAssets(): Builder
    {
        $query = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        return $query->toBase();
    }

    public function getAssetCountsRollup(?array $allowedDirectoryIds = null): array
    {
        $allowedDirectoryIds ??= app(DirectoryPermissionService::class)->directlyGrantedIds();
        $prefix = DB::getTablePrefix();

        if ($allowedDirectoryIds !== null && empty($allowedDirectoryIds)) {
            $rows = DB::select("SELECT id FROM {$prefix}dam_directories");

            return collect($rows)
                ->mapWithKeys(fn ($row) => [(int) $row->id => 0])
                ->all();
        }

        $descendantFilter = '';
        $bindings = [];

        if ($allowedDirectoryIds !== null) {
            $placeholders = implode(',', array_fill(0, count($allowedDirectoryIds), '?'));
            $descendantFilter = "AND descendant.id IN ({$placeholders})";
            $bindings = $allowedDirectoryIds;
        }

        $visibleAssets = $this->countableAssets();
        $visibleAssetSql = $visibleAssets->toSql();
        $visibleBindings = $visibleAssets->getBindings();

        $rows = DB::select("
            SELECT ancestor.id AS id, COUNT(DISTINCT ad.asset_id) AS total
            FROM {$prefix}dam_directories AS ancestor
            LEFT JOIN {$prefix}dam_directories AS descendant
                ON descendant._lft >= ancestor._lft
                AND descendant._rgt <= ancestor._rgt
                {$descendantFilter}
            LEFT JOIN {$prefix}dam_asset_directory AS ad
                ON ad.directory_id = descendant.id
                AND ad.asset_id IN ({$visibleAssetSql})
                AND NOT EXISTS (
                    SELECT 1 FROM {$prefix}dam_assets AS hidden_asset
                    JOIN {$prefix}dam_capture_groups AS capture_group ON capture_group.id = hidden_asset.capture_group_id
                    JOIN {$prefix}dam_assets AS capture_primary ON capture_primary.id = capture_group.primary_asset_id
                    WHERE hidden_asset.id = ad.asset_id AND capture_group.primary_asset_id IS NOT NULL
                        AND capture_group.primary_asset_id <> hidden_asset.id
                        AND capture_primary.deleted_at IS NULL
                )
            GROUP BY ancestor.id
        ", array_merge($bindings, $visibleBindings));

        return collect($rows)
            ->mapWithKeys(fn ($row) => [(int) $row->id => (int) $row->total])
            ->all();
    }

    public function search(string $query, int $limit = 20, int $offset = 0)
    {
        $builder = $this->buildSearchQuery($query);

        if ($builder === null) {
            return collect();
        }

        $matches = $builder
            ->orderBy('name')
            ->orderBy('id')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get(['id', 'name', 'parent_id', '_lft', '_rgt']);

        return $this->markPresentation($this->attachAncestorPaths($matches));
    }

    public function searchCount(string $query): int
    {
        $builder = $this->buildSearchQuery($query);

        return $builder === null ? 0 : $builder->count();
    }

    protected function buildSearchQuery(string $query)
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return null;
        }

        $service = app(DirectoryPermissionService::class);

        $builder = $this->model->newQuery()
            ->whereNotIn('id', app(LibraryDirectoryPresentation::class)->structuralIds());

        SearchTerms::apply($builder, $query, fn ($w, string $pattern) => $w->whereRaw(SearchTerms::likeSql('name'), [$pattern]));

        if (! $service->bypass()) {
            $builder->whereIn('id', $service->viewableIds());
        }

        return $builder;
    }

    protected function attachAncestorPaths($directories)
    {
        if ($directories->isEmpty()) {
            return $directories->values();
        }

        $table = $this->model->getTable();
        $ids = $directories->pluck('id')->all();

        $rows = DB::table("{$table} as anc")
            ->join("{$table} as child", function ($join) {
                $join->whereColumn('anc._lft', '<=', 'child._lft')
                    ->whereColumn('anc._rgt', '>=', 'child._rgt');
            })
            ->whereIn('child.id', $ids)
            ->orderBy('child.id')
            ->orderBy('anc._lft')
            ->select('anc.id as anc_id', 'anc.name as anc_name', 'child.id as for_id')
            ->get()
            ->groupBy('for_id');

        $structuralIds = app(LibraryDirectoryPresentation::class)->structuralIds();

        return $directories->map(function ($directory) use ($rows, $structuralIds) {
            $ancestors = $rows->get($directory->id, collect())->reject(fn ($ancestor) => in_array((int) $ancestor->anc_id, $structuralIds, true));
            $directory->path_names = $ancestors->pluck('anc_name')->all();
            $directory->path_ids = $ancestors->pluck('anc_id')->all();

            return $directory;
        })->values();
    }
}
