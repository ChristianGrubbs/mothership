<?php

declare(strict_types=1);

namespace Mothership\DAM\Http\Controllers\Explorer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\AiIndex\AiEligibility;
use Mothership\DAM\AiIndex\SmartSearch;
use Mothership\DAM\Analytics\DamEvent;
use Mothership\DAM\Analytics\DamEvents;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AccessListService;
use Mothership\DAM\Services\Access\AccessModel;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\AvailableVersions;
use Mothership\DAM\Services\CustomThumbnailService;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ExplorerSearchPredicate;
use Mothership\DAM\Services\LibraryDirectoryPresentation;
use Mothership\DAM\Services\LibraryMediaFilter;
use Mothership\DAM\Services\LibraryTelemetry;
use Mothership\DAM\Services\MetadataFields\FieldFilters;
use Mothership\DAM\Services\MetadataFields\FieldValues;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Services\Organization\LibraryListing;
use Mothership\DAM\Services\Organization\LibraryOrganizationFilters;
use Mothership\DAM\Services\Organization\SavedSearchService;
use Mothership\DAM\Services\Organization\VisibleAssets;
use Mothership\DAM\Services\VideoProxyStatus;
use Mothership\DAM\Support\AdobeFormats;
use Mothership\DAM\Support\SearchTerms;

class ExplorerDataController extends Controller
{
    public function __construct(
        protected DirectoryPermissionService $permissionService,
        protected AccessModel $accessModel,
        protected AccessListService $accessLists,
    ) {}

    public function filterOptions(): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.asset.view'),
            403,
            trans('dam::app.admin.permissions.denied-browse')
        );

        $visibleAssets = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($visibleAssets, $this->listingContext());
        $properties = DB::table('dam_asset_properties')
            ->whereIn('dam_asset_id', $visibleAssets)
            ->where('is_filterable', true)
            // A value adopted by a typed field is filtered through that field (`fields` below), not twice.
            ->whereNull('field_id')
            ->distinct()
            ->orderBy('name')
            ->pluck('name');

        return response()->json([
            'file_types' => ['image', 'video', 'audio', 'document', 'spreadsheet', 'csv'],
            'properties' => $properties,
            // P4a (D10): field definitions only, never values, so nothing here depends on what the viewer can see.
            'fields'     => app(FieldFilters::class)->filterableFields()->map->toDefinition()->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.asset.view'),
            403,
            trans('dam::app.admin.permissions.denied-browse')
        );

        // P4 organization -- begin. A saved search re-runs its stored definition (never stored results) under the
        // viewer's access now; one the viewer cannot see answers 404 like a missing one.
        $savedSearch = null;
        if ($request->filled('saved_search_id')) {
            $request->validate(['saved_search_id' => 'integer|min:1']);
            $savedSearch = app(SavedSearchService::class)->findVisible(id: $request->integer('saved_search_id'), viewerId: VisibleAssets::viewerId());
            $request->replace(LibraryListing::explorerParams(definition: (array) $savedSearch->definition, request: $request->query()));

            // The stored folder may have been deleted, or hidden from this viewer, since the search was saved.
            if ($request->input('scope') !== 'all' && $request->filled('directory_id') && VisibleDirectory::find($request->input('directory_id')) === null) {
                return response()->json(['message' => trans('dam::app.admin.organize.saved-searches.folder-missing')], 422);
            }
        }
        // P4 organization -- end.

        $request->validate([
            'scope'               => 'sometimes|in:all,directory',
            'directory_id'        => ['required_unless:scope,all', 'nullable', 'integer', 'min:1', new VisibleDirectory],
            'media_type'          => 'nullable|in:all,images,videos,svgs,audio,models',
            'include_subfolders'  => 'sometimes|boolean',
            'search'              => 'nullable|string|max:255',
            'page'                => 'nullable|integer|min:1',
            'per_page'            => 'nullable|integer|min:1|max:250',
            'sort_by'             => 'nullable|in:created_at,name,size,updated_at,relevance'.LibraryOrganizationFilters::sortRule(),
            'sort_order'          => 'nullable|in:asc,desc',
            'similar_to'          => 'nullable|integer|min:1',
            'filter_file_name'    => 'nullable|string|max:255',
            'filter_extension'    => 'nullable|string|max:50',
            'filter_file_type'    => 'nullable|string|in:image,video,audio,document,spreadsheet,csv',
            'filter_tag'          => 'nullable|string|max:255',
            'filter_created_from' => 'nullable|date',
            'filter_created_to'   => 'nullable|date',
            'filter_updated_from' => 'nullable|date',
            'filter_updated_to'   => 'nullable|date',
            'filter_uploaded_by'  => 'nullable|in:me',
            'filter_stack'        => 'nullable|in:any,'.implode(',', Asset::STACK_BASES),
            'filter_stack_key'    => 'nullable|string|max:191',
            'group_by'            => 'nullable|in:stack',
            'filter_field'        => 'nullable|array',
            // P4 organization filters (labels, boards, sections, favorites, orientation).
            ...LibraryOrganizationFilters::rules(),
        ]);

        // P4 organization -- begin. A hidden label or board answers 404 like a missing one, before anything is read.
        // A stored definition naming a label or board the viewer cannot see matches nothing (never wider, never a 404).
        $organization = LibraryOrganizationFilters::resolve(params: $request->all(), viewerId: VisibleAssets::viewerId(), lenient: $savedSearch !== null);
        // P4 organization -- end.

        $allAssets = $request->input('scope') === 'all';
        $structuralIds = app(LibraryDirectoryPresentation::class)->structuralIds();
        $dir = $allAssets ? null : Directory::find($request->integer('directory_id'));

        if (! $allAssets && ! $dir) {
            return response()->json(['message' => trans('dam::app.admin.explorer.not-found')], 404);
        }

        if (! $allAssets && ! $this->permissionService->bypass() && ! $this->permissionService->canView($dir->id)) {
            return response()->json(['message' => trans('dam::app.admin.explorer.access-denied')], 403);
        }

        $includeSubfolders = $request->boolean('include_subfolders');
        $subtreeDirIds = $allAssets ? null : ($includeSubfolders ? Directory::query()
            ->whereBetween('_lft', [$dir->_lft, $dir->_rgt])
            ->pluck('id')
            ->all() : [$dir->id]);

        $search = $request->input('search');
        // Default matches the explorer's "Recently added" option; a caller naming only a column gets its natural order.
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', $sortBy === 'created_at' ? 'desc' : 'asc');
        // P4 organization -- begin. A section's default sort applies when the request names none.
        if (! $request->filled('sort_by') && ($sectionSort = $organization->defaultSort()) !== null) {
            [$sortBy, $sortOrder] = $sectionSort;
        }
        // P4 organization -- end.
        $perPage = $request->integer('per_page', 50);
        $page = $request->integer('page', 1);

        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $filterFileName = $request->input('filter_file_name');
        $filterExtension = $request->input('filter_extension');
        $filterFileType = $request->input('filter_file_type');
        $filterTag = $request->input('filter_tag');
        $filterCreatedFrom = $request->input('filter_created_from');
        $filterCreatedTo = $request->input('filter_created_to');
        $filterUpdatedFrom = $request->input('filter_updated_from');
        $filterUpdatedTo = $request->input('filter_updated_to');
        // An inverted range matches nothing; read it the way it was meant and say so in meta.
        [$filterCreatedFrom, $filterCreatedTo, $createdSwapped] = $this->orderedRange($filterCreatedFrom, $filterCreatedTo);
        [$filterUpdatedFrom, $filterUpdatedTo, $updatedSwapped] = $this->orderedRange($filterUpdatedFrom, $filterUpdatedTo);
        $rangeSwapped = $createdSwapped || $updatedSwapped;
        // "Uploaded by: Me" — only the signed-in user's own files; any other value means anyone.
        $filterMine = $request->input('filter_uploaded_by') === 'me';
        // "Any stack" is every asset with a basis; a basis value narrows to that one.
        $filterStack = $request->input('filter_stack');
        $filterStackKey = $request->input('filter_stack_key');

        $similarTo = $request->integer('similar_to') ?: null;
        $smart = $similarTo === null && config('ai-index.search_enabled') && $search !== null && trim((string) $search) !== '';
        // Smart search ranks and narrows the candidates itself; the plain LIKE predicate applies only without it.
        $likeSearch = ($smart || $similarTo !== null) ? null : $search;

        if ($similarTo !== null && (! config('ai-index.search_enabled')
            || ! app(AssetAccessDecision::class)->for(AccessContext::library(), $similarTo)->visible)) {
            // A hidden or unavailable seed, or the flag being off, answers exactly like a missing seed: no existence
            // oracle (spec §3), and an unavailable seed's stored vector never ranks anything (P2 S3 review).
            return response()->json(['message' => trans('dam::app.admin.explorer.not-found')], 404);
        }

        // A property filter arrives as `filter_prop_<name>` (the name survives only if its key is restored from the
        // raw query) or as a `filter_props[<name>]` map, which the Library itself sends so any name round-trips.
        $filterInput = LibraryListing::restorePropertyKeys(params: $request->all(), rawQuery: (string) $request->server->get('QUERY_STRING', ''));
        $filterProps = collect($filterInput)
            ->filter(fn ($v, $k) => str_starts_with((string) $k, 'filter_prop_') && is_string($v) && $v !== '')
            ->mapWithKeys(fn ($v, $k) => [substr((string) $k, strlen('filter_prop_')) => $v])
            ->union(collect(is_array($filterInput['filter_props'] ?? null) ? $filterInput['filter_props'] : [])
                ->filter(fn ($v, $k) => is_string($v) && $v !== '' && (string) $k !== '')
                ->mapWithKeys(fn ($v, $k) => [(string) $k => $v]));

        // P4a typed field filters (D10): parsed and checked once, applied inside the asset query below.
        $fieldFilters = app(FieldFilters::class)->parse($request->input('filter_field'));

        // Folder navigation remains available independently of upload filters.
        $dirQuery = Directory::query()->whereNotIn('id', $structuralIds);
        if ($allAssets) {
            $dirQuery->where(function ($query) use ($structuralIds) {
                $query->whereNull('parent_id')->orWhereIn('parent_id', $structuralIds);
            });
        } else {
            $parents = [$dir->id];
            // Flatten only a structural child of this exact directory.
            $parents = array_merge($parents, Directory::query()->whereIn('id', $structuralIds)->where('parent_id', $dir->id)->pluck('id')->all());
            $dirQuery->whereIn('parent_id', $parents);
        }

        if (! $this->permissionService->bypass()) {
            $dirQuery->whereIn('id', $this->permissionService->viewableIds());
        }

        // A search narrows the listed subfolders by name too, so SUBFOLDERS never shows every
        // folder beside a filtered file list (QA finding F11, 2026-09-27).
        if ($search !== null && $search !== '') {
            SearchTerms::apply($dirQuery, (string) $search, fn ($w, string $pattern) => $w->whereRaw(SearchTerms::likeSql('name'), [$pattern]));
        }

        $dirSortColumn = match ($sortBy) {
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            default      => 'name',
        };

        $directlyGrantedIds = $this->permissionService->bypass()
            ? null
            : $this->permissionService->directlyGrantedIds();

        $directories = $dirQuery->withCount(['assets' => function ($q) {
            $q->visibleCaptures();
            // The same per-file filter the file listing applies, so a folder never counts files the viewer cannot open.
            app(AssetAccessDecision::class)->scopeListable($q, $this->listingContext());
        }, 'children' => fn ($q) => $q->whereIn('id', $this->permissionService->viewableIds())])
            ->orderBy($dirSortColumn, $sortOrder)
            ->get(['id', 'name', 'parent_id', 'updated_at'])
            ->map(fn (Directory $d) => [
                'id'             => $d->id,
                'name'           => $d->name,
                'parent_id'      => $d->parent_id,
                'assets_count'   => $d->assets_count ?? 0,
                'children_count' => $d->children_count ?? 0,
                'updated_at'     => $d->updated_at,
                'can_access'     => $directlyGrantedIds === null || in_array($d->id, $directlyGrantedIds),
            ]);

        if ($similarTo !== null) {
            // The "Similar to" view lists files only.
            $directories = collect();
        }

        // P4 organization -- begin. A label, board or section holds files only.
        if ($organization->isContainer()) {
            $directories = collect();
        }
        // P4 organization -- end.

        // The sharing indicator on every card and row (ADR 0010, sharing slice 3). Batched over the page's
        // ids — two queries per item kind — never one query per card, and absent entirely under `grants`.
        if ($this->accessModel->lists()) {
            $folderModes = $this->accessLists->modesFor(type: 'directory', ids: $directories->pluck('id')->map(fn ($id) => (int) $id)->all());
            $directories = $directories->map(fn (array $item) => $item + ['access_mode' => $folderModes[$item['id']] ?? AccessListService::PRIVATE]);
        }

        $mediaType = $request->input('media_type');
        $buildAssetQuery = function () use (
            $mediaType,
            $likeSearch, $likeOperator, $subtreeDirIds,
            $filterFileName, $filterExtension, $filterFileType, $filterTag,
            $filterCreatedFrom, $filterCreatedTo,
            $filterUpdatedFrom, $filterUpdatedTo, $filterMine,
            $filterProps, $filterStack, $filterStackKey, $fieldFilters
        ) {
            $prefix = DB::getTablePrefix();

            $q = Asset::query()->visibleCaptures();
            app(AssetAccessDecision::class)->scopeListable($q, $this->listingContext());

            $q->whereExists(function ($sub) use ($subtreeDirIds) {
                $sub->select(DB::raw(1))
                    ->from('dam_asset_directory')
                    ->whereColumn('dam_asset_directory.asset_id', 'dam_assets.id');

                if ($subtreeDirIds !== null) {
                    $sub->whereIn('dam_asset_directory.directory_id', $subtreeDirIds);
                }

            });

            LibraryMediaFilter::apply($q, $mediaType);

            if ($likeSearch !== null && $likeSearch !== '') {
                ExplorerSearchPredicate::apply(query: $q, search: $likeSearch, likeOperator: $likeOperator);
            }

            if ($filterFileName) {
                $q->where('dam_assets.file_name', $likeOperator, '%'.strtolower($filterFileName).'%');
            }

            if ($filterExtension) {
                $q->where('dam_assets.extension', $likeOperator, '%'.strtolower($filterExtension).'%');
            }

            if ($filterFileType) {
                $q->where('dam_assets.file_type', $filterFileType);
            }

            if ($filterTag) {
                $lowerTag = '%'.strtolower($filterTag).'%';
                $q->whereExists(
                    DB::table('dam_tags')
                        ->join('dam_asset_tag', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
                        ->whereColumn('dam_asset_tag.asset_id', 'dam_assets.id')
                        ->where('dam_tags.name', $likeOperator, $lowerTag)
                        ->select(DB::raw(1))
                );
            }

            if ($filterCreatedFrom) {
                $q->where('dam_assets.created_at', '>=', $filterCreatedFrom.' 00:00:00');
            }

            if ($filterCreatedTo) {
                $q->where('dam_assets.created_at', '<=', $filterCreatedTo.' 23:59:59');
            }

            if ($filterUpdatedFrom) {
                $q->where('dam_assets.updated_at', '>=', $filterUpdatedFrom.' 00:00:00');
            }

            if ($filterUpdatedTo) {
                $q->where('dam_assets.updated_at', '<=', $filterUpdatedTo.' 23:59:59');
            }

            if ($filterMine) {
                $q->where('dam_assets.created_by', auth('admin')->id());
            }

            if ($filterStack === 'any') {
                $q->whereNotNull('dam_assets.stack_basis');
            } elseif ($filterStack) {
                $q->where('dam_assets.stack_basis', $filterStack);
            }

            if ($filterStackKey) {
                // Wrapped by the grammar so a table prefix (DB_PREFIX) reaches the raw expression.
                $q->whereRaw('LOWER('.DB::connection()->getQueryGrammar()->wrap('dam_assets.stack_key').') LIKE ?', ['%'.mb_strtolower($filterStackKey).'%']);
            }

            foreach ($filterProps as $propName => $propValue) {
                $q->whereExists(function ($sub) use ($prefix, $propName, $propValue, $likeOperator) {
                    $sub->select(DB::raw(1))
                        ->from('dam_asset_properties')
                        ->whereRaw("{$prefix}dam_asset_properties.dam_asset_id = {$prefix}dam_assets.id")
                        ->where('name', $propName)
                        ->where('value', $likeOperator, '%'.strtolower($propValue).'%');
                });
            }

            // P4a typed field filters: begin. ANDed onto the scoped query, so they only narrow what the viewer may list.
            app(FieldFilters::class)->apply(query: $q, filters: $fieldFilters);
            // P4a typed field filters: end.

            return $q;
        };

        $sortColumn = match ($sortBy) {
            'created_at' => 'dam_assets.created_at',
            'size'       => 'dam_assets.file_size',
            'updated_at' => 'dam_assets.updated_at',
            default      => DB::raw(Asset::displayNameSql()),
        };

        $assetQuery = $buildAssetQuery();
        // P4 organization -- begin. Narrows the access-scoped query, before any ranking or count.
        LibraryOrganizationFilters::apply($assetQuery, $organization);
        // P4 organization -- end.
        $ranked = null;

        if ($similarTo !== null) {
            $ranked = app(SmartSearch::class)->similar(viewer: auth('admin')->user(), candidates: $assetQuery, seedId: $similarTo);
        } elseif ($smart) {
            $ranked = app(SmartSearch::class)->rank(viewer: auth('admin')->user(), candidates: $assetQuery, query: (string) $search);
        }

        // Relevance order is the default for a search and the only order for Find similar; an explicit
        // sort keeps the ranked set but reorders it.
        // Grouping by stack is an order of its own (P3a S4): stacks by basis, then key, so a page never splits a
        // basis heading out of sequence. It applies to a ranked search too, which keeps its matches.
        $groupByStack = $request->input('group_by') === 'stack';
        $rankOrder = $ranked !== null && ! $groupByStack && ($similarTo !== null || $sortBy === 'relevance' || ! $request->filled('sort_by'));
        $matches = [];
        $positions = [];

        if ($ranked !== null) {
            $ids = array_column($ranked, 'id');
            $matches = array_combine($ids, array_column($ranked, 'match')) ?: [];
            $positions = array_flip($ids);
            // The ranked ids are the candidates that survived, so they are the whole result set either way.
            $totalAssets = count($ids);
            $assetQuery->whereIntegerInRaw('dam_assets.id', $rankOrder
                ? (array_slice($ids, ($page - 1) * $perPage, $perPage) ?: [0])
                : ($ids ?: [0]));
        } else {
            $totalAssets = (clone $assetQuery)->count('dam_assets.id');
        }

        // Phase 1 (page first): only the ids of the page, with the filters, ordering and pagination unchanged. The
        // heavy per-card columns (meta_data JSON, correlated subqueries) are then evaluated for the page's rows only,
        // not for every candidate row before the Sort/LIMIT. Access scoping stays on $assetQuery, so phase 2 can only
        // load ids phase 1 returned.
        // Phase 2 reuses a clone of the same scoped query (every access, listable, capture, organization and search
        // predicate), taken before phase 1 adds its select, ordering and page window. An asset that stops being listable
        // between the two statements is therefore dropped, as the old single statement would have done.
        $cardQuery = clone $assetQuery;
        $pageRows = $assetQuery
            ->select('dam_assets.id')
            ->when($groupByStack && ! $rankOrder, fn ($q) => $this->orderByStack($q))
            // P4 organization: the manual-position and popularity sorts come from LibraryOrganizationFilters.
            ->when(! $rankOrder, fn ($q) => (LibraryOrganizationFilters::orderBy(query: $q, scope: $organization, sortBy: $sortBy, sortOrder: $sortOrder) ? $q : $this->orderByColumn(query: $q, sortBy: $sortBy, column: $sortColumn, direction: $sortOrder))
                ->orderBy('dam_assets.id')->forPage($page, $perPage))
            ->get();
        $pageIds = $pageRows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pageOrder = array_flip($pageIds);
        // Columns an ordering added to phase 1 that the response carries (member_position), keyed by asset id.
        $orderingColumns = $pageRows->mapWithKeys(fn ($row) => [(int) $row->id => Arr::except($row->getAttributes(), ['id'])]);

        // Phase 2: the full card columns for those ids only (predicates re-checked), then phase 1's order restored.
        $assets = $cardQuery
            ->whereIntegerInRaw('dam_assets.id', $pageIds ?: [0])
            ->select([
                'dam_assets.id', 'dam_assets.file_name', 'dam_assets.file_type',
                'dam_assets.extension', 'dam_assets.file_size', 'dam_assets.path',
                'dam_assets.mime_type', 'dam_assets.asset_kind', 'dam_assets.updated_at', 'dam_assets.created_by',
                'dam_assets.stack_basis', 'dam_assets.stack_key',
                'dam_assets.availability', 'dam_assets.available_from', 'dam_assets.available_until',
                'dam_assets.meta_data->custom_thumbnail as custom_thumbnail',
                'dam_assets.meta_data->thumbnail_path as generated_thumbnail',
                'dam_assets.meta_data->thumbnail_revision as thumbnail_revision',
            ])
            // One correlated subquery instead of a query per card: the 3D
            // capture flow binds every upload to the sha256 of the asset's
            // original, so a card has to carry it (ADR 0006).
            ->addSelect(['checksum' => ModelThumbnailService::checksumSubquery()])
            // The uploader's name for the card and row; a file with no owner shows none.
            ->addSelect(['owner_name' => DB::table('admins')->select('name')->whereColumn('admins.id', 'dam_assets.created_by')->limit(1)])
            // The card's "N files in this stack" badge, counted in the same query.
            ->addSelect(['attachment_count' => Asset::attachmentCountSubquery()])
            ->addSelect(['attachment_size' => Asset::attachmentSizeSubquery()])
            ->withDisplayName()
            ->get()
            ->sortBy(fn ($asset) => $pageOrder[(int) $asset->id])
            ->values()
            ->each(function ($asset) use ($orderingColumns) {
                foreach ($orderingColumns[(int) $asset->id] ?? [] as $column => $value) {
                    $asset->setAttribute($column, $value);
                }
            })
            ->map(function ($asset) {
                // P4 organization: a section's unpinned file has no manual position.
                LibraryOrganizationFilters::presentPosition($asset);
                $asset->attachment_count = (int) $asset->attachment_count;
                $asset->attachment_size = (int) $asset->attachment_size;
                // P2 S3: the card's badge for a file the owner, an editor or a full admin sees though it is not out for use.
                $asset->unavailable = $asset->unavailableBecause();
                $asset->unavailable_label = $asset->unavailable ? trans('dam::app.admin.dam.asset.availability.badges.'.$asset->unavailable) : null;
                $custom = json_decode($asset->custom_thumbnail ?? 'null', true);
                $asset->has_custom_thumbnail = is_array($custom) && ! empty($custom['revision']);
                unset($asset->custom_thumbnail);
                // The JSON path selector is unquoted on every supported driver,
                // but trim defensively so a quoted scalar never becomes a path.
                $generated = is_string($asset->generated_thumbnail) ? trim($asset->generated_thumbnail, '"') : '';
                unset($asset->generated_thumbnail);
                // A 3D capture carries the sha of its stored JPEG (ModelThumbnailService::store()):
                // a forced re-capture overwrites the same file, so only that revision moves the URL.
                // It counts only while the path is still a capture: a poster job rewrites
                // thumbnail_path and leaves the rest of meta_data, revision included, behind.
                $capturedRevision = is_string($asset->thumbnail_revision) && str_starts_with($generated, ModelThumbnailService::DIRECTORY)
                    ? trim($asset->thumbnail_revision, '"') : '';
                unset($asset->thumbnail_revision);
                $asset->has_thumbnail = $asset->has_custom_thumbnail || $generated !== '';
                // Otherwise a generated thumbnail is keyed by its source blob's sha (ADR 0009):
                // derivatives/<sha[0:2]>/<sha>/poster.jpg — the directory names the revision, not
                // the constant "poster" filename a PATHINFO_FILENAME extraction would give.
                $asset->path = $asset->path
                    ? route('admin.dam.file.thumbnail', [
                        'path' => urlencode($asset->path),
                        'v'    => (is_array($custom) ? CustomThumbnailService::servedVersion($custom) : null) ?? match (true) {
                            $capturedRevision !== '' => $capturedRevision,
                            $generated !== ''        => basename(dirname($generated)),
                            // A plain raster has no recorded thumbnail: its derivative is keyed by the original's
                            // sha, so that sha names the bytes and lets the thumbnail route cache them for good.
                            default                  => is_string($asset->checksum) && $asset->checksum !== '' ? $asset->checksum : null,
                        },
                    ])
                    : '';

                return $asset;
            });

        if ($ranked !== null) {
            $assets->each(function ($asset) use ($matches) {
                $asset->match = $matches[(int) $asset->id] ?? null;
            });

            if ($rankOrder) {
                $assets = $assets->sortBy(fn ($asset) => $positions[(int) $asset->id] ?? PHP_INT_MAX)->values();
            }
        }

        // One query for the page: the OPT / SVG / other-format chips beside each card's type badge.
        $versions = app(AvailableVersions::class)->forAssets(
            $assets->mapWithKeys(fn ($asset) => [(int) $asset->id => $asset->extension])->all()
        );
        $assets->each(function ($asset) use ($versions) {
            $asset->versions = $versions[(int) $asset->id] ?? [];
            // Storage-free: false for a type the AI can never describe, so bulk AI actions can leave it out up front.
            $asset->ai_possible = AiEligibility::typeMayBeDescribed($asset->extension, $asset->file_type);
            // Which Adobe application the file belongs to (presentation only; the stored MIME is untouched).
            $asset->adobe = AdobeFormats::present(extension: $asset->extension, mime: $asset->mime_type);
        });

        // P4a (D9): up to five "Show on cards" fields per card, in priority order, read for the whole page at once.
        $cardFields = app(FieldValues::class)->cardFields($assets->pluck('id')->map(fn ($id) => (int) $id)->all());
        $assets->each(function ($asset) use ($cardFields) {
            $asset->card_fields = $cardFields[(int) $asset->id] ?? [];
        });

        // P6 D26: one query for the page, a badge on a video whose preview proxy is queued, processing or failed.
        $proxyBadges = app(VideoProxyStatus::class)->badges(
            $assets->filter(fn ($asset) => $asset->file_type === 'video')->map(fn ($asset) => (int) $asset->id)->values()->all()
        );
        $assets->each(function ($asset) use ($proxyBadges) {
            $status = $proxyBadges[(int) $asset->id] ?? null;
            $asset->proxy_status = $status;
            $asset->proxy_status_label = $status === null ? null : VideoProxyStatus::badgeLabel($status);
        });

        if ($this->accessModel->lists()) {
            $assetModes = $this->accessLists->modesFor(type: 'asset', ids: $assets->pluck('id')->map(fn ($id) => (int) $id)->all());
            $assets->each(function ($asset) use ($assetModes) {
                $asset->access_mode = $assetModes[(int) $asset->id] ?? AccessListService::PRIVATE;
            });
        }

        // getQuery(): the ancestors relation already carries its constraints, and Larastan rejects
        // the __call forwarding of whereIn() on Relation as a private-method call.
        $breadcrumb = $dir ? $dir->ancestors()->getQuery()
            ->whereIn('id', $this->permissionService->viewableIds())
            ->defaultOrder()
            ->get(['id', 'name'])
            ->map(fn (Directory $a) => ['id' => $a->id, 'name' => $a->name])
            ->push(['id' => $dir->id, 'name' => $dir->name])
            ->reject(fn ($crumb) => in_array((int) $crumb['id'], $structuralIds, true))
            ->values()
            ->toArray() : [];

        LibraryTelemetry::record(event: 'explorer.result', ctx: [
            'scope'        => $allAssets ? 'all' : 'directory', 'media_type' => $mediaType ?? 'all',
            'directory_id' => $dir?->id, 'page' => $page, 'total_assets' => $totalAssets,
            'has_search'   => $search !== null && $search !== '',
            'smart'        => $smart, 'similar' => $similarTo !== null,
        ]);

        // P1b: a search served is its first page; paging and infinite scroll are the same search.
        if ($page === 1 && (($search !== null && trim((string) $search) !== '') || $similarTo !== null)) {
            DamEvents::emit(event: DamEvent::SearchServed, surface: 'library', props: [
                'result_count' => min($totalAssets, 1_000_000_000), 'smart' => $smart, 'similar' => $similarTo !== null,
                'scope'        => $allAssets ? 'all' : 'directory',
                'filtered'     => collect($request->only(['filter_file_name', 'filter_extension', 'filter_file_type', 'filter_tag', 'filter_created_from', 'filter_created_to', 'filter_updated_from', 'filter_updated_to', 'filter_uploaded_by', 'filter_stack', 'filter_stack_key', 'media_type']))->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty(),
            ]);
        }

        return response()->json([
            'directories' => $directories,
            'assets'      => $assets,
            'breadcrumb'  => $breadcrumb,
            'meta'        => [
                'filter_conflict'    => LibraryMediaFilter::conflict(mediaType: $mediaType, extension: $filterExtension),
                'date_range_swapped' => $rangeSwapped,
                'directory_id'       => $dir?->id,
                'scope'              => $allAssets ? 'all' : 'directory',
                'total_assets'       => $totalAssets,
                'total_directories'  => $directories->count(),
                'current_page'       => $page,
                'last_page'          => max(1, (int) ceil($totalAssets / max($perPage, 1))),
                'per_page'           => $perPage,
                'can_access_current' => ! $allAssets && ($this->permissionService->bypass() || $this->permissionService->canAccess($dir->id)),
                // P4 organization: the label, board or section being browsed, for the Library header.
                'organization'       => $organization->meta(),
                'sort'               => $sortBy.':'.$sortOrder,
            ],
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function countItems(Request $request): JsonResponse
    {
        abort_unless(
            bouncer()->hasPermission('dam.asset.view'),
            403,
            trans('dam::app.admin.permissions.denied-browse')
        );

        $request->validate([
            'asset_ids'       => 'nullable|array',
            'asset_ids.*'     => 'integer|min:1',
            'directory_ids'   => 'nullable|array',
            'directory_ids.*' => 'integer|min:1',
        ]);

        $bypass = $this->permissionService->bypass();
        $grantedIds = $bypass ? null : $this->permissionService->directlyGrantedIds();

        $assetIds = array_unique(array_map('intval', $request->input('asset_ids', [])));
        $dirIds = array_values(array_filter(
            array_unique(array_map('intval', $request->input('directory_ids', []))),
            fn (int $id) => $bypass || in_array($id, $grantedIds, true)
        ));

        $accessible = Asset::query()->whereKey($assetIds);
        app(AssetAccessDecision::class)->scopeListable($accessible, $this->listingContext());
        $assetIds = $accessible->pluck('dam_assets.id')->all();
        $fileCount = count($assetIds);

        if (! empty($dirIds)) {
            $roots = Directory::whereIn('id', $dirIds)->get(['id', '_lft', '_rgt']);
            if ($roots->isEmpty()) {
                return response()->json(['file_count' => $fileCount]);
            }

            // The per-root subtree predicates are ORed, so they need their own group:
            // left flat, SQL's tighter AND binds the grant filter to the last root
            // alone and the first root's whole subtree escapes it.
            $subtreeQuery = Directory::query()->where(function ($group) use ($roots) {
                foreach ($roots as $i => $root) {
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $group->{$method}(function ($q) use ($root) {
                        $q->where('_lft', '>=', $root->_lft)
                            ->where('_rgt', '<=', $root->_rgt);
                    });
                }
            });

            if ($grantedIds !== null) {
                $subtreeQuery->whereIn('id', $grantedIds);
            }

            $allDirIds = $subtreeQuery->pluck('id');

            // Count the assets the listing would actually hand back for these
            // directories: index() gates on both of these (visibleCaptures hides
            // non-primary capture derivatives), so without them a selection count
            // promises rows the Library never shows.
            $countableAssets = Asset::query()->visibleCaptures()->select('dam_assets.id');
            app(AssetAccessDecision::class)->scopeListable($countableAssets, $this->listingContext());

            $fileCount += DB::table('dam_asset_directory')
                ->whereIn('asset_id', $countableAssets)
                ->whereIn('directory_id', $allDirIds)
                ->distinct()
                ->count('asset_id');
        }

        return response()->json(['file_count' => $fileCount]);
    }

    /** Stacks first, in Asset::STACK_BASES order, then by key (case-insensitive); plain assets last. */
    /** The plain sorts; Size orders by the size the list shows, a stack's files together (VISR-09). */
    private function orderByColumn($query, string $sortBy, $column, string $direction)
    {
        if ($sortBy === 'size') {
            Asset::orderByListSize(query: $query, direction: $direction);

            return $query;
        }

        return $query->orderBy($column, $direction);
    }

    private function orderByStack($query)
    {
        $grammar = $query->getQuery()->getGrammar();
        $basis = $grammar->wrap('dam_assets.stack_basis');
        $cases = collect(Asset::STACK_BASES)->map(fn (string $value, int $index) => "WHEN '{$value}' THEN {$index}")->implode(' ');

        return $query
            ->orderByRaw("CASE {$basis} {$cases} ELSE ".count(Asset::STACK_BASES).' END')
            ->orderByRaw('LOWER('.$grammar->wrap('dam_assets.stack_key').')');
    }

    /**
     * The Library's listing context (P2 S3). The "Include unavailable" filter (`include_unavailable=0`) leaves out,
     * for everyone, the files that are not out for use; by default the owner, editors and full admins see them.
     */
    protected function listingContext(): AccessContext
    {
        $context = AccessContext::library(AccessContext::PURPOSE_LIST);

        return request()->boolean('include_unavailable', true) ? $context : $context->withoutUnavailable();
    }

    /** @return array{0: mixed, 1: mixed, 2: bool} the range with its ends in order, and whether they were swapped */
    private function orderedRange(mixed $from, mixed $to): array
    {
        return $from && $to && $from > $to ? [$to, $from, true] : [$from, $to, false];
    }
}
