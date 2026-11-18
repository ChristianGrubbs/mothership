<?php

declare(strict_types=1);

namespace Mothership\DAM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Exceptions\WebCollectionException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Models\WebCollection;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Stack\StackMembers;

/**
 * Owns the web page collection tree and placements (ADR 0008). Collections are an organizer only:
 * nothing here grants access, and every page query keeps the directory-grant filter.
 */
class WebCollectionService
{
    public const NAME_MAX = 120;

    /** Listing orders the browser may ask for; anything else falls back to `newest`. */
    public const SORTS = ['newest', 'oldest', 'name_asc', 'name_desc'];

    /** Pages one Share pages in folder confirmation lists; the browser creates their links one request at a time. */
    public const SHARE_CANDIDATE_CAP = 100;

    public function __construct(protected StackMembers $stackMembers) {}

    public function create(string $name, ?int $parentId, ?int $adminId): WebCollection
    {
        $name = $this->validName($name);
        $parent = $parentId !== null ? $this->collectionOrFail($parentId) : null;
        $this->assertDepth(($parent?->depth() ?? 0) + 1);
        $this->assertUnique(name: $name, parentId: $parentId, exceptId: null);

        $collection = WebCollection::create(['name' => $name, 'parent_id' => $parentId, 'created_by' => $adminId]);
        WebPageTelemetry::record('web_pages.collection_created', ['collection_id' => $collection->id, 'parent_id' => $parentId, 'admin_id' => $adminId]);

        return $collection;
    }

    public function rename(WebCollection $collection, string $name): WebCollection
    {
        $this->assertMayChange($collection);
        $name = $this->validName($name);
        $this->assertUnique(name: $name, parentId: $collection->parent_id, exceptId: $collection->id);
        $collection->update(['name' => $name]);
        WebPageTelemetry::record('web_pages.collection_renamed', ['collection_id' => $collection->id]);

        return $collection;
    }

    public function move(WebCollection $collection, ?int $newParentId): WebCollection
    {
        $this->assertMayChange($collection);
        $this->assertPlacement(collection: $collection, name: $collection->name, newParentId: $newParentId);
        $collection->update(['parent_id' => $newParentId]);
        WebPageTelemetry::record('web_pages.collection_moved', ['collection_id' => $collection->id, 'parent_id' => $newParentId]);

        return $collection;
    }

    /** Rename and move in one write, validated against the final (name, parent) state only. */
    public function renameAndMove(WebCollection $collection, string $name, ?int $newParentId): WebCollection
    {
        $this->assertMayChange($collection);
        $name = $this->validName($name);
        $this->assertPlacement(collection: $collection, name: $name, newParentId: $newParentId);
        $collection->update(['name' => $name, 'parent_id' => $newParentId]);
        WebPageTelemetry::record('web_pages.collection_moved', ['collection_id' => $collection->id, 'parent_id' => $newParentId]);

        return $collection;
    }

    /** Cycle, depth and sibling-name checks for $collection named $name under $newParentId. */
    protected function assertPlacement(WebCollection $collection, string $name, ?int $newParentId): void
    {
        if ($newParentId !== null) {
            $target = $this->collectionOrFail($newParentId);

            if ($target->id === $collection->id || $target->ancestors()->contains('id', $collection->id)) {
                throw new WebCollectionException('cycle');
            }

            $this->assertDepth($target->depth() + $this->subtreeHeight($collection));
        }

        $this->assertUnique(name: $name, parentId: $newParentId, exceptId: $collection->id);
    }

    /**
     * Children and placements move up to the deleted node's parent (placements are removed, i.e.
     * the pages become unfiled, at the root). Takes no permission argument on purpose: a placement
     * of a page the actor cannot see is still reparented, never lost. Never touches dam_assets.
     *
     * @return array{children:int, pages:int}
     */
    public function delete(WebCollection $collection): array
    {
        return DB::transaction(function () use ($collection) {
            // Locked first: what the own-items rule reads below cannot change before the delete acts on it.
            $collection = WebCollection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $this->assertMayDelete($collection);
            $parentId = $collection->parent_id;
            $children = WebCollection::query()->where('parent_id', $collection->id)->get();

            foreach ($children as $child) {
                // A child may share its name with a sibling of the deleted node; keep both by suffixing.
                $child->update(['parent_id' => $parentId, 'name' => $this->freeName(name: $child->name, parentId: $parentId, exceptId: $child->id)]);
            }

            $placements = DB::table('dam_web_collection_assets')->where('collection_id', $collection->id);
            $pages = $parentId === null ? $placements->delete() : $placements->update(['collection_id' => $parentId]);
            $collection->delete();

            $counts = ['children' => $children->count(), 'pages' => (int) $pages];
            WebPageTelemetry::record('web_pages.collection_deleted', ['collection_id' => $collection->id, 'parent_id' => $parentId] + $counts);

            return $counts;
        });
    }

    /**
     * Files pages into $collectionId (null = unfile). Only ids that exist, are web pages and pass
     * the caller's directory grant are written; every other id is reported with its reason.
     *
     * @param  int[]  $assetIds
     * @return array{placed: int[], rejected: array<int, array{id:int, reason:string}>}
     */
    public function placePages(array $assetIds, ?int $collectionId, DirectoryPermissionService $permissions): array
    {
        if ($collectionId !== null) {
            $this->collectionOrFail($collectionId);
        }

        $ids = array_values(array_unique(array_map('intval', $assetIds)));
        $found = Asset::query()->whereIn('id', $ids)->pluck('file_type', 'id');
        $accessibleQuery = Asset::query()->whereIn('id', $ids);
        app(AssetAccessDecision::class)->scopeListable($accessibleQuery, AccessContext::library(AccessContext::PURPOSE_LIST));
        $accessible = array_flip($accessibleQuery->pluck('id')->map(fn ($id) => (int) $id)->all());

        // A role limited to its own items files only the pages it created (a page filed elsewhere is a change to it).
        $foreign = $this->foreignPageIds(ids: $ids);

        $placed = [];
        $rejected = [];

        foreach ($ids as $id) {
            // A hidden asset reports as missing, before its type, so the reasons cannot probe for one.
            $reason = match (true) {
                ! $found->has($id), ! isset($accessible[$id]) => 'missing',
                $found[$id] !== 'web'                         => 'not-web',
                isset($foreign[$id])                          => 'not-yours',
                default                                       => null,
            };

            if ($reason === null) {
                $placed[] = $id;
            } else {
                $rejected[] = ['id' => $id, 'reason' => $reason];
            }
        }

        if ($placed !== []) {
            if ($collectionId === null) {
                DB::table('dam_web_collection_assets')->whereIn('asset_id', $placed)->delete();
            } else {
                // One atomic statement per batch: concurrent moves of the same page cannot collide on the key.
                DB::table('dam_web_collection_assets')->upsert(array_map(fn (int $id) => ['asset_id' => $id, 'collection_id' => $collectionId], $placed), ['asset_id'], ['collection_id']);
            }
        }

        WebPageTelemetry::record('web_pages.pages_placed', ['collection_id' => $collectionId, 'placed' => count($placed), 'rejected' => count($rejected)]);

        return ['placed' => $placed, 'rejected' => $rejected];
    }

    /**
     * One browser level. Root lists unfiled pages; a collection lists its own placements; a search
     * term drops the collection scope and every hit carries its collection_path. The directory
     * grant filter is always applied, so a collection never widens what an admin sees.
     *
     * @param  array{owner?:string, q?:string, sort?:string, admin_id?:int|null, directory_id?:int|null, tree?:bool}  $filters
     * @return array{breadcrumb: array, children: array, pages: LengthAwarePaginator, search_mode: bool}
     */
    public function listing(?WebCollection $collection, array $filters, DirectoryPermissionService $permissions, int $perPage = 24): array
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $searchMode = $term !== '';

        $query = Asset::query()->where('file_type', 'web')->withDisplayName()->with(['directories', 'creator'])->withCount('versions');
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        $active = Share::query()->select('id')
            ->whereColumn('target_id', 'dam_assets.id')
            ->where('share_type', Share::TYPE_ASSET)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // Deterministic pick: the viewer's own link first, then the newest.
            ->orderByRaw('CASE WHEN '.DB::getTablePrefix().'dam_shares.created_by = ? THEN 0 ELSE 1 END', [(int) ($filters['admin_id'] ?? 0)])
            ->orderByDesc('dam_shares.id')
            ->limit(1);
        $placement = DB::table('dam_web_collection_assets as p')->select('p.collection_id')->whereColumn('p.asset_id', 'dam_assets.id')->limit(1);
        $query->addSelect(['active_share_id' => $active, 'web_collection_id' => $placement]);

        if (($filters['owner'] ?? 'everyone') === 'me') {
            $query->where('created_by', $filters['admin_id'] ?? null);
        }

        if (! empty($filters['directory_id'])) {
            $query->whereHas('directories', fn ($q) => $q->where('dam_directories.id', (int) $filters['directory_id']));
        }

        if ($searchMode) {
            // Matches the resolved display name the card shows (Asset::displayNameSql()).
            $query->whereRaw(Asset::displayNameSql().' LIKE ?', ['%'.$term.'%']);
        } elseif ($collection === null) {
            $query->whereNotExists(fn ($q) => $q->from('dam_web_collection_assets as p')->whereColumn('p.asset_id', 'dam_assets.id'));
        } else {
            $query->whereExists(fn ($q) => $q->from('dam_web_collection_assets as p')->whereColumn('p.asset_id', 'dam_assets.id')->where('p.collection_id', $collection->id));
        }

        // Name orders use the same COALESCE expression the card shows, so "A–Z" matches the titles
        // on screen rather than the raw file name. Ties fall back to newest so paging is stable.
        $query = match ($filters['sort'] ?? 'newest') {
            'oldest'    => $query->orderBy('updated_at'),
            'name_asc'  => $query->orderByRaw(Asset::displayNameSql().' ASC')->orderByDesc('updated_at'),
            'name_desc' => $query->orderByRaw(Asset::displayNameSql().' DESC')->orderByDesc('updated_at'),
            default     => $query->orderByDesc('updated_at'),
        };
        $query->orderByDesc('id');

        $pages = $query->paginate($perPage)->withQueryString();

        $all = WebCollection::query()->orderBy('name')->get(['id', 'name', 'parent_id', 'created_by'])->keyBy('id');
        $bypass = $permissions->bypass();
        $granted = $bypass ? [] : array_flip($permissions->directlyGrantedIds());

        $pages->getCollection()->each(function (Asset $page) use ($all, $bypass, $granted, $searchMode) {
            $page->library_directory = $page->directories
                ->filter(fn ($directory) => $bypass || isset($granted[$directory->id]))
                ->sortBy('name')->first()?->name;
            $page->collection_path = $searchMode && $page->web_collection_id !== null
                ? $this->pathFromMap(all: $all, id: (int) $page->web_collection_id)
                : null;
        });

        return [
            'breadcrumb'  => $this->breadcrumb($collection),
            'children'    => $this->childNodes(parentId: $collection?->id, all: $all, counts: $this->visiblePageCounts($permissions), abilities: $this->abilities($all), recursive: (bool) ($filters['tree'] ?? false)),
            'pages'       => $pages,
            'search_mode' => $searchMode,
        ];
    }

    /** The batch size of one confirmation; `dam.web_pages.share_candidate_cap` overrides the default (tests, tuning). */
    public function shareCandidateCap(): int
    {
        return max(1, (int) config('dam.web_pages.share_candidate_cap', self::SHARE_CANDIDATE_CAP));
    }

    /**
     * The direct pages of $collection the signed-in admin could share, for the folder-share confirmation: web pages
     * placed in exactly this collection (never a descendant) that the ordinary share seam shows them. Observational.
     * More than SHARE_CANDIDATE_CAP are cut off by name order and counted in `omitted`, so a partial set is never
     * presented as the whole folder.
     *
     * @return array{pages: Collection<int, Asset>, total: int, omitted: int}
     */
    public function shareCandidates(WebCollection $collection): array
    {
        $query = Asset::query()->where('file_type', 'web')->withDisplayName()
            ->whereExists(fn ($q) => $q->from('dam_web_collection_assets as p')->whereColumn('p.asset_id', 'dam_assets.id')->where('p.collection_id', $collection->id));
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_SHARE));

        $total = (clone $query)->toBase()->count();
        $pages = $query->orderByRaw(Asset::displayNameSql().' ASC')->orderBy('dam_assets.id')->limit($this->shareCandidateCap())->get();
        WebPageTelemetry::record('web_pages.folder_share_candidates', ['collection_id' => $collection->id, 'pages' => $pages->count(), 'state' => $total > $pages->count() ? 'capped' : 'complete']);

        return ['pages' => $pages, 'total' => $total, 'omitted' => max(0, $total - $pages->count())];
    }

    /**
     * The freeze stamp of a page for Share pages in folder: a digest of exactly what an ordinary link to it hands out,
     * the id and FULL checksum of every current stack member (the identity Task 2's member pin uses). A revised,
     * replaced, added or removed file changes it at once and at any resolution; a rename or thumbnail does not.
     */
    public function pageFingerprint(Asset $page): string
    {
        $members = $this->stackMembers->forAsset($page)
            ->map(fn ($file) => (int) $file->id.':'.strtolower((string) $file->fileObject?->sha256))
            ->sort()->values()->implode(',');

        return hash('sha256', 'web-page-members:'.(int) $page->id.':'.$members);
    }

    /**
     * Whether $page is, right now, still the page the folder-share confirmation listed: a web page placed directly in
     * $collectionId whose stack is the one that was listed. A page moved out (or into a sub-folder), revised or
     * turned into anything else answers false. Call it inside the share transaction: it takes a row lock on the
     * placement, so a concurrent move or folder delete waits until the link is written and the answer stays true
     * for the write it guards. Visibility is the caller's own check (the ordinary share seam).
     */
    public function holdsFrozenDirectPage(Asset $page, int $collectionId, string $fingerprint): bool
    {
        $placed = DB::table('dam_web_collection_assets')->where('asset_id', $page->id)->lockForUpdate()->value('collection_id');

        return $page->file_type === 'web'
            && $placed !== null && (int) $placed === $collectionId
            && hash_equals($this->pageFingerprint($page), $fingerprint);
    }

    /** @return array<int, array{id:int|null, name:string}> root entry first, then ancestors, then $collection */
    public function breadcrumb(?WebCollection $collection): array
    {
        $trail = [['id' => null, 'name' => trans('dam::app.admin.dam.web-pages.title')]];

        if ($collection !== null) {
            foreach ($collection->ancestors()->push($collection) as $node) {
                $trail[] = ['id' => $node->id, 'name' => $node->name];
            }
        }

        return $trail;
    }

    /** collection_id => number of placed pages this admin may see (one grouped query). */
    protected function visiblePageCounts(DirectoryPermissionService $permissions): array
    {
        $query = DB::table('dam_web_collection_assets')
            ->join('dam_assets', 'dam_assets.id', '=', 'dam_web_collection_assets.asset_id')
            ->where('dam_assets.file_type', 'web');
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::library(AccessContext::PURPOSE_LIST));

        return $query->groupBy('dam_web_collection_assets.collection_id')
            // The builder wraps (and table-prefixes) the column; a raw string here would skip DB_PREFIX.
            ->select('dam_web_collection_assets.collection_id')
            ->selectRaw('COUNT(*) as aggregate')
            ->pluck('aggregate', 'collection_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Per group, whether the signed-in admin may rename/move it and delete it, by the same own-items rules
     * assertMayChange()/assertMayDelete() apply on write. Grouped lookups only: no query per group, and
     * nothing about who created a group leaves the service.
     *
     * @return array<int, array{can_modify:bool, can_delete:bool}>
     */
    protected function abilities(Collection $all): array
    {
        $actor = $this->ownOnlyActor();
        $holds = $actor === null ? [] : $this->foreignHoldCounts(actor: $actor, ids: null);
        $abilities = [];

        foreach ($all as $node) {
            $mayChange = $this->mayChange(collection: $node, actor: $actor);
            $abilities[$node->id] = ['can_modify' => $mayChange, 'can_delete' => $mayChange && ($holds[$node->id] ?? 0) === 0];
        }

        return $abilities;
    }

    /** @return array<int, array{id:int, name:string, page_count:int, total_page_count:int, children_count:int, can_modify:bool, can_delete:bool, children?:array}> */
    protected function childNodes(?int $parentId, Collection $all, array $counts, array $abilities, bool $recursive, int $guard = 0): array
    {
        $nodes = [];

        foreach ($all->filter(fn (WebCollection $c) => $c->parent_id === $parentId) as $child) {
            $node = [
                'id'                => $child->id,
                'name'              => $child->name,
                'page_count'        => $counts[$child->id] ?? 0,
                'total_page_count'  => $this->subtreePageCount(id: $child->id, all: $all, counts: $counts),
                'children_count'    => $all->where('parent_id', $child->id)->count(),
                'can_modify'        => $abilities[$child->id]['can_modify'] ?? false,
                'can_delete'        => $abilities[$child->id]['can_delete'] ?? false,
            ];

            // The depth cap bounds a real tree; the guard stops a corrupted parent_id loop.
            if ($recursive && $guard < (int) config('dam.web_pages.max_collection_depth', 10)) {
                $node['children'] = $this->childNodes(parentId: $child->id, all: $all, counts: $counts, abilities: $abilities, recursive: true, guard: $guard + 1);
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /** Visible pages in a collection plus all its descendants; a page sits in one collection, so nothing double counts. */
    protected function subtreePageCount(int $id, Collection $all, array $counts, int $guard = 0): int
    {
        $total = $counts[$id] ?? 0;

        if ($guard >= (int) config('dam.web_pages.max_collection_depth', 10)) {
            return $total;
        }

        foreach ($all->where('parent_id', $id) as $child) {
            $total += $this->subtreePageCount(id: $child->id, all: $all, counts: $counts, guard: $guard + 1);
        }

        return $total;
    }

    protected function pathFromMap(Collection $all, int $id): ?string
    {
        $names = [];
        $seen = [];

        for ($node = $all->get($id); $node !== null && ! isset($seen[$node->id]); $node = $node->parent_id !== null ? $all->get($node->parent_id) : null) {
            $seen[$node->id] = true;
            array_unshift($names, $node->name);
        }

        return $names === [] ? null : implode(' / ', $names);
    }

    /** Whether the signed-in admin is limited to their own items (RoleBuilder::restrictedToOwn()), and who they are. */
    private function ownOnlyActor(): ?int
    {
        $roles = app(RoleBuilder::class);

        return $roles->restrictedToOwn() ? (int) $roles->actingAdmin()?->id : null;
    }

    /** Whether $actor (null = not limited to own items) may rename or move $collection. */
    protected function mayChange(WebCollection $collection, ?int $actor): bool
    {
        return $actor === null || (int) $collection->created_by === $actor;
    }

    /** Rename and move: a role limited to its own items changes only a page group it created. */
    protected function assertMayChange(WebCollection $collection): void
    {
        if (! $this->mayChange(collection: $collection, actor: $this->ownOnlyActor())) {
            throw new WebCollectionException('not-yours');
        }
    }

    /**
     * Delete re-parents the group's sub-groups and the pages filed in it, so a role limited to its own items deletes only
     * a group it created whose sub-groups and pages are all its own too (the count names what is not).
     */
    protected function assertMayDelete(WebCollection $collection): void
    {
        $actor = $this->ownOnlyActor();

        if ($actor === null) {
            return;
        }

        $this->assertMayChange($collection);

        $foreign = $this->foreignHoldCounts(actor: $actor, ids: [$collection->id])[$collection->id] ?? 0;

        if ($foreign > 0) {
            throw new WebCollectionException('holds-others', $foreign);
        }
    }

    /**
     * Per group, how many sub-groups and filed pages were not created by $actor (groups holding none are absent).
     * Two grouped queries whatever the number of groups; `$ids` null covers every group.
     *
     * @param  int[]|null  $ids
     * @return array<int, int> collection id => foreign sub-groups + foreign pages
     */
    protected function foreignHoldCounts(int $actor, ?array $ids): array
    {
        $children = WebCollection::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('parent_id', $ids), fn ($q) => $q->whereNotNull('parent_id'))
            ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $actor))
            ->select('parent_id')->selectRaw('COUNT(*) as aggregate')->groupBy('parent_id')
            ->pluck('aggregate', 'parent_id');
        $pages = DB::table('dam_web_collection_assets as p')
            ->join('dam_assets', 'dam_assets.id', '=', 'p.asset_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('p.collection_id', $ids))
            ->where(fn ($q) => $q->whereNull('dam_assets.created_by')->orWhere('dam_assets.created_by', '!=', $actor))
            ->select('p.collection_id')->selectRaw('COUNT(*) as aggregate')->groupBy('p.collection_id')
            ->pluck('aggregate', 'collection_id');

        $counts = [];

        foreach ([$children, $pages] as $grouped) {
            foreach ($grouped as $id => $count) {
                $counts[(int) $id] = ($counts[(int) $id] ?? 0) + (int) $count;
            }
        }

        return $counts;
    }

    /**
     * The ids among `$ids` that a role limited to its own items did not create, as a set; empty for everyone else.
     *
     * @param  int[]  $ids
     * @return array<int, true>
     */
    protected function foreignPageIds(array $ids): array
    {
        $actor = $this->ownOnlyActor();

        if ($actor === null || $ids === []) {
            return [];
        }

        return Asset::query()->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $actor))
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    public function collectionOrFail(int $id): WebCollection
    {
        return WebCollection::query()->find($id) ?? throw new WebCollectionException('missing-parent');
    }

    protected function validName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX || str_contains($name, '/') || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0) {
            throw new WebCollectionException('name-invalid');
        }

        return $name;
    }

    protected function nameTaken(string $name, ?int $parentId, ?int $exceptId): bool
    {
        return WebCollection::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    protected function assertUnique(string $name, ?int $parentId, ?int $exceptId): void
    {
        if ($this->nameTaken(name: $name, parentId: $parentId, exceptId: $exceptId)) {
            throw new WebCollectionException('name-taken');
        }
    }

    protected function freeName(string $name, ?int $parentId, int $exceptId): string
    {
        $candidate = $name;

        for ($n = 2; $this->nameTaken(name: $candidate, parentId: $parentId, exceptId: $exceptId); $n++) {
            $suffix = " ({$n})";
            $candidate = mb_substr($name, 0, self::NAME_MAX - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    protected function assertDepth(int $depth): void
    {
        $max = (int) config('dam.web_pages.max_collection_depth', 10);

        if ($depth > $max) {
            throw new WebCollectionException('too-deep', $max);
        }
    }

    /** 1 for a leaf; so a moved subtree cannot exceed the cap. Bounded by the cap itself. */
    protected function subtreeHeight(WebCollection $node): int
    {
        $height = 1;

        foreach ($node->children as $child) {
            $height = max($height, 1 + $this->subtreeHeight($child));
        }

        return $height;
    }
}
