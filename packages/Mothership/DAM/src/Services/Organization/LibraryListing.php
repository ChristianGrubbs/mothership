<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\AiIndex\SmartSearch;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Rules\VisibleDirectory;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\DAM\Services\ExplorerSearchPredicate;
use Mothership\DAM\Services\LibraryMediaFilter;
use Mothership\DAM\Support\AdobeFormats;

/**
 * P4 (D12, REST parity): one Library listing from the Library's own filter parameters, the query definition a saved
 * search stores and every REST label, board, section and saved-search listing reads. It applies the explorer's
 * filters with the explorer's semantics (scope, media type, word or smart search, the filter drawer, the organization
 * filters and sorts), always through the access seam for the viewer asking now, so a definition saved last month
 * returns only what its viewer may list today. It stores and returns no result ids of its own.
 */
class LibraryListing
{
    public const MAX_PER_PAGE = 250;

    /** The query definition keys, flat as the explorer takes them; `filter_props` holds the custom-property filters. */
    public static function rules(): array
    {
        return [
            'search'              => 'nullable|string|max:255',
            'media_type'          => 'nullable|in:all,images,videos,svgs,audio,models',
            'scope'               => 'nullable|in:all,directory',
            'directory_id'        => 'nullable|required_if:scope,directory|integer|min:1',
            'include_subfolders'  => 'nullable|boolean',
            'include_unavailable' => 'nullable|boolean',
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
            'filter_props'        => 'nullable|array|max:20',
            'filter_props.*'      => 'nullable|string|max:255',
            ...LibraryOrganizationFilters::rules(),
            'sort_by'             => 'nullable|in:created_at,name,size,updated_at,relevance'.LibraryOrganizationFilters::sortRule(),
            'sort_order'          => 'nullable|in:asc,desc',
        ];
    }

    /**
     * A definition to store: only known keys, validated, empty values dropped. An unknown key is refused rather than
     * kept, so a saved search never silently means something other than what re-running it does.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function normalizeDefinition(array $definition): array
    {
        $definition = self::foldPropertyFilters($definition);
        $rules = self::rules();
        $unknown = array_diff(array_keys($definition), array_filter(array_keys($rules), fn (string $key) => ! str_contains($key, '.')));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['definition' => trans('dam::app.admin.organize.saved-searches.unknown-keys', ['keys' => implode(', ', array_slice($unknown, 0, 5))])]);
        }

        // A stored folder must exist and be one the saver can open now (a folder they cannot see reads as missing).
        $rules['directory_id'] = [...explode('|', $rules['directory_id']), new VisibleDirectory];
        $validated = Validator::make($definition, $rules)->validate();

        return array_filter($validated, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * The Library request that re-runs a stored definition: every definition key the request carried is replaced by
     * the definition's (so the run means exactly what was saved), and paging, grouping and an explicit sort the
     * viewer picked stay. Custom-property filters go back to the explorer's `filter_prop_<name>` keys.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public static function explorerParams(array $definition, array $request): array
    {
        $keep = isset($request['sort_by']) && $request['sort_by'] !== '' ? ['sort_by', 'sort_order'] : [];
        $definitionKeys = array_diff(array_filter(array_keys(self::rules()), fn (string $key) => ! str_contains($key, '.')), $keep);
        $params = array_filter($request, fn ($value, $key) => ! in_array($key, $definitionKeys, true) && ! str_starts_with((string) $key, 'filter_prop_'), ARRAY_FILTER_USE_BOTH);

        foreach ($definition as $key => $value) {
            if ($key === 'filter_props' && is_array($value)) {
                foreach ($value as $name => $propValue) {
                    $params['filter_prop_'.$name] = $propValue;
                }
            } elseif (! in_array($key, $keep, true)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * PHP turns a space or a dot in a query KEY into an underscore, so `filter_prop_Date Taken=x` reaches the app as
     * `filter_prop_Date_Taken` and matches no property. The raw query string still has the real key: put it back, and
     * only where the request still carries the mangled one (a saved search that replaced the parameters keeps its own).
     *
     * @param  array<string, mixed>  $params  the parsed parameters
     * @return array<string, mixed>
     */
    public static function restorePropertyKeys(array $params, string $rawQuery): array
    {
        if ($rawQuery === '' || ! str_contains($rawQuery, 'filter_prop_')) {
            return $params;
        }

        foreach (explode('&', $rawQuery) as $pair) {
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($rawKey);
            $mangled = str_replace([' ', '.'], '_', $key);

            if (! str_starts_with($key, 'filter_prop_') || $mangled === $key || ! array_key_exists($mangled, $params)) {
                continue;
            }

            unset($params[$mangled]);
            $params[$key] = urldecode($rawValue);
        }

        return $params;
    }

    /**
     * The explorer sends each custom-property filter as its own `filter_prop_<name>` key; a definition holds them in
     * one `filter_props` map.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function foldPropertyFilters(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'filter_prop_')) {
                unset($params[$key]);

                if (is_string($value) && $value !== '' && strlen($key) > strlen('filter_prop_')) {
                    $params['filter_props'][substr($key, strlen('filter_prop_'))] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Runs the parameters for whoever is asking (the session's admin or the API key's), through the access seam.
     *
     * @param  array<string, mixed>  $params  validated against rules()
     * @param  bool  $stored  a saved definition: a label the viewer cannot see matches nothing instead of a 404
     * @return array{assets: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function run(array $params, AccessContext $context, int $page = 1, int $perPage = 50, bool $stored = false): array
    {
        $viewerId = VisibleAssets::viewerId();
        $scope = LibraryOrganizationFilters::resolve(params: $params, viewerId: $viewerId, lenient: $stored);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        if (array_key_exists('include_unavailable', $params) && ! filter_var($params['include_unavailable'], FILTER_VALIDATE_BOOLEAN)) {
            $context = $context->withoutUnavailable();
        }

        $query = Asset::query()->visibleCaptures();
        app(AssetAccessDecision::class)->scopeListable($query, $context);
        $this->scopeDirectory(query: $query, params: $params);
        LibraryMediaFilter::apply($query, $params['media_type'] ?? null);

        $search = trim((string) ($params['search'] ?? ''));
        $smart = $search !== '' && (bool) config('ai-index.search_enabled');
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        if ($search !== '' && ! $smart) {
            ExplorerSearchPredicate::apply(query: $query, search: $search, likeOperator: $likeOperator);
        }

        $this->applyFilters(query: $query, params: $params, likeOperator: $likeOperator, viewerId: $viewerId);
        LibraryOrganizationFilters::apply($query, $scope);

        $rankedIds = null;

        if ($smart) {
            // Ranked over the narrowed candidates, as the explorer does; the ranked ids are the whole result set.
            $ranked = app(SmartSearch::class)->rank(viewer: app(DirectoryPermissionService::class)->viewer(), candidates: clone $query, query: $search);
            $rankedIds = array_map('intval', array_column($ranked, 'id'));
            $query->whereIntegerInRaw('dam_assets.id', $rankedIds ?: [0]);
        }

        [$sortBy, $sortOrder] = $this->sort(params: $params, scope: $scope);
        $query->select([
            'dam_assets.id', 'dam_assets.file_name', 'dam_assets.file_type', 'dam_assets.extension', 'dam_assets.mime_type',
            'dam_assets.file_size', 'dam_assets.orientation', 'dam_assets.created_by', 'dam_assets.created_at', 'dam_assets.updated_at',
        ])->withDisplayName();

        $total = (clone $query)->count('dam_assets.id');

        if ($rankedIds !== null && ($sortBy === 'relevance' || empty($params['sort_by']))) {
            // Relevance order: the ranker's order, paged here.
            $pageIds = array_slice($rankedIds, ($page - 1) * $perPage, $perPage);
            $rows = $query->whereIntegerInRaw('dam_assets.id', $pageIds ?: [0])->get()
                ->sortBy(fn (Asset $asset) => array_search((int) $asset->id, $pageIds, true))->values();
        } else {
            if (! LibraryOrganizationFilters::orderBy(query: $query, scope: $scope, sortBy: $sortBy, sortOrder: $sortOrder)) {
                $query->orderBy(match ($sortBy) {
                    'size'       => 'dam_assets.file_size',
                    'updated_at' => 'dam_assets.updated_at',
                    'name'       => DB::raw(Asset::displayNameSql()),
                    default      => 'dam_assets.created_at',
                }, $sortOrder);
            }

            $rows = $query->orderBy('dam_assets.id')->forPage($page, $perPage)->get();
        }

        return [
            'assets' => $rows->each(fn (Asset $asset) => LibraryOrganizationFilters::presentPosition($asset))->map(fn (Asset $asset) => [
                'id'              => (int) $asset->id,
                'file_name'       => $asset->file_name,
                'display_name'    => $asset->display_name,
                'file_type'       => $asset->file_type,
                'extension'       => $asset->extension,
                'adobe'           => AdobeFormats::present(extension: $asset->extension, mime: $asset->mime_type),
                'mime_type'       => $asset->mime_type,
                'file_size'       => (int) $asset->file_size,
                'orientation'     => $asset->orientation,
                'created_at'      => $asset->created_at?->toIso8601String(),
                'updated_at'      => $asset->updated_at?->toIso8601String(),
                'member_position' => $asset->member_position ?? null,
            ])->values()->all(),
            'meta' => [
                'total'        => $total,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'sort'         => $sortBy.':'.$sortOrder,
                'organization' => $scope->meta(),
            ],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function sort(array $params, OrganizationScope $scope): array
    {
        if (empty($params['sort_by']) && ($default = $scope->defaultSort()) !== null) {
            return $default;
        }

        $sortBy = $params['sort_by'] ?? 'created_at';

        return [$sortBy, $params['sort_order'] ?? ($sortBy === 'created_at' || $sortBy === 'relevance' ? 'desc' : 'asc')];
    }

    /** As the explorer: one folder (or its subtree), or every filed asset; a folder the viewer cannot see is a 404. */
    private function scopeDirectory($query, array $params): void
    {
        $directoryIds = null;

        if (($params['scope'] ?? 'all') === 'directory' && ! empty($params['directory_id'])) {
            $permissions = app(DirectoryPermissionService::class);
            $directory = Directory::query()->where('id', (int) $params['directory_id'])->first();
            abort_if($directory === null || (! $permissions->bypass() && ! $permissions->canView((int) $directory->id)), 404, trans('dam::app.admin.explorer.not-found'));

            $directoryIds = filter_var($params['include_subfolders'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? Directory::query()->whereBetween('_lft', [$directory->_lft, $directory->_rgt])->pluck('id')->all()
                : [(int) $directory->id];
        }

        $query->whereExists(function ($filed) use ($directoryIds): void {
            $filed->select(DB::raw(1))->from('dam_asset_directory')->whereColumn('dam_asset_directory.asset_id', 'dam_assets.id');

            if ($directoryIds !== null) {
                $filed->whereIn('dam_asset_directory.directory_id', $directoryIds);
            }
        });
    }

    /** The filter drawer, with the explorer's semantics (ExplorerDataController::index). */
    private function applyFilters($query, array $params, string $likeOperator, ?int $viewerId): void
    {
        $contains = fn (string $value) => '%'.mb_strtolower($value).'%';

        if (! empty($params['filter_file_name'])) {
            $query->where('dam_assets.file_name', $likeOperator, $contains($params['filter_file_name']));
        }

        if (! empty($params['filter_extension'])) {
            $query->where('dam_assets.extension', $likeOperator, $contains($params['filter_extension']));
        }

        if (! empty($params['filter_file_type'])) {
            $query->where('dam_assets.file_type', $params['filter_file_type']);
        }

        if (! empty($params['filter_tag'])) {
            $query->whereExists(DB::table('dam_tags')->join('dam_asset_tag', 'dam_tags.id', '=', 'dam_asset_tag.tag_id')
                ->whereColumn('dam_asset_tag.asset_id', 'dam_assets.id')
                ->where('dam_tags.name', $likeOperator, $contains($params['filter_tag']))->select(DB::raw(1)));
        }

        foreach (['created' => 'dam_assets.created_at', 'updated' => 'dam_assets.updated_at'] as $key => $column) {
            if (! empty($params["filter_{$key}_from"])) {
                $query->where($column, '>=', $params["filter_{$key}_from"].' 00:00:00');
            }

            if (! empty($params["filter_{$key}_to"])) {
                $query->where($column, '<=', $params["filter_{$key}_to"].' 23:59:59');
            }
        }

        if (($params['filter_uploaded_by'] ?? null) === 'me') {
            $query->where('dam_assets.created_by', $viewerId ?? 0);
        }

        if (($params['filter_stack'] ?? null) === 'any') {
            $query->whereNotNull('dam_assets.stack_basis');
        } elseif (! empty($params['filter_stack'])) {
            $query->where('dam_assets.stack_basis', $params['filter_stack']);
        }

        if (! empty($params['filter_stack_key'])) {
            $query->whereRaw('LOWER('.DB::connection()->getQueryGrammar()->wrap('dam_assets.stack_key').') LIKE ?', [$contains($params['filter_stack_key'])]);
        }

        foreach ((array) ($params['filter_props'] ?? []) as $name => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $query->whereExists(fn ($property) => $property->select(DB::raw(1))->from('dam_asset_properties')
                ->whereColumn('dam_asset_properties.dam_asset_id', 'dam_assets.id')
                ->where('name', (string) $name)
                ->where('value', $likeOperator, $contains($value)));
        }
    }
}
