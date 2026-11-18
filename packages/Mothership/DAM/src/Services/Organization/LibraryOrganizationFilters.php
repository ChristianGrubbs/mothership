<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * P4 (D4, D10, D11, D13, D3): the Library's organization filters and orders, kept in one helper so the explorer
 * (ExplorerDataController) and the REST listings (LibraryListing) apply them identically:
 *
 *  - `label_id` / `section_id`: browse a label, board or section (files only; manual position available).
 *  - `filter_labels[]` with `filter_labels_mode`: assets holding the labels. **AND (`all`) is the default**, as every
 *    other Library filter narrows; `any` widens to assets holding at least one of them.
 *  - `filter_favorites`: the viewer's starred assets. `filter_orientation`: landscape, portrait or square.
 *  - sort `position` (manual order inside a label, board or section) and, behind DAM_POPULARITY_SORT_ENABLED,
 *    `popularity` (PopularityScore: 0 for every asset until P1b analytics exist).
 *
 * Every filter only narrows a query the caller has already scoped through the access seam, so a hidden asset never
 * reaches a result or a count.
 */
final class LibraryOrganizationFilters
{
    public const MODE_ALL = 'all';

    public const MODE_ANY = 'any';

    public const SORT_POSITION = 'position';

    public const SORT_POPULARITY = 'popularity';

    /** The most labels one filter can name. */
    public const MAX_LABEL_FILTERS = 20;

    /** @return array<string, mixed> request rules for the organization parameters */
    public static function rules(): array
    {
        return [
            'label_id'           => 'nullable|integer|min:1',
            'section_id'         => 'nullable|integer|min:1|prohibits:label_id',
            'filter_labels'      => 'nullable|array|max:'.self::MAX_LABEL_FILTERS,
            'filter_labels.*'    => 'integer|min:1',
            'filter_labels_mode' => 'nullable|in:'.self::MODE_ALL.','.self::MODE_ANY,
            'filter_favorites'   => 'nullable|boolean',
            'filter_orientation' => 'nullable|in:'.implode(',', AssetOrientation::VALUES),
        ];
    }

    /** The extra sort keys, for a `sort_by` rule: `,position` and, when enabled, `,popularity`. */
    public static function sortRule(): string
    {
        return ','.implode(',', self::sortKeys());
    }

    /** @return array<int, string> */
    public static function sortKeys(): array
    {
        return config('dam.popularity_sort_enabled') ? [self::SORT_POSITION, self::SORT_POPULARITY] : [self::SORT_POSITION];
    }

    /**
     * Resolves validated parameters for one viewer. A label or board they cannot see, or a section that does not
     * exist, answers the same 404 a missing id does. `lenient` is for a stored definition (a saved search someone
     * shared): there a label the viewer cannot see matches nothing, rather than refusing the run or being dropped,
     * which would widen it.
     *
     * @param  array<string, mixed>  $params
     */
    public static function resolve(array $params, ?int $viewerId, bool $lenient = false): OrganizationScope
    {
        $labels = app(LabelService::class);
        $sections = app(SectionService::class);
        $labelIds = array_values(array_unique(array_map('intval', (array) ($params['filter_labels'] ?? []))));
        $matchesNothing = false;
        $find = function (callable $lookup) use ($lenient, &$matchesNothing) {
            try {
                return $lookup();
            } catch (HttpException $missing) {
                if (! $lenient || $missing->getStatusCode() !== 404) {
                    throw $missing;
                }

                $matchesNothing = true;

                return null;
            }
        };

        foreach ($labelIds as $labelId) {
            $find(fn () => $labels->findVisible(id: $labelId, viewerId: $viewerId));
        }

        // In `any` mode one unseen label narrows nothing on its own: it is simply left out of the alternatives.
        if ($matchesNothing && ($params['filter_labels_mode'] ?? null) === self::MODE_ANY) {
            $visibleIds = $labels->visibleQuery(viewerId: $viewerId)->whereIn('id', $labelIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $labelIds = array_values(array_intersect($labelIds, $visibleIds));
            $matchesNothing = $labelIds === [];
        }

        $orientation = $params['filter_orientation'] ?? null;
        $label = ! empty($params['label_id']) ? $find(fn () => $labels->findVisible(id: (int) $params['label_id'], viewerId: $viewerId)) : null;
        $section = ! empty($params['section_id']) ? $find(fn () => $sections->find((int) $params['section_id'])) : null;

        return new OrganizationScope(
            viewerId: $viewerId,
            labelIds: $labelIds,
            labelsMode: ($params['filter_labels_mode'] ?? null) === self::MODE_ANY ? self::MODE_ANY : self::MODE_ALL,
            favorites: filter_var($params['filter_favorites'] ?? false, FILTER_VALIDATE_BOOLEAN),
            orientation: in_array($orientation, AssetOrientation::VALUES, true) ? $orientation : null,
            label: $label,
            section: $section,
            matchesNothing: $matchesNothing,
        );
    }

    /** Narrows an asset query (over dam_assets) to the scope. */
    public static function apply(Builder $query, OrganizationScope $scope): void
    {
        if ($scope->matchesNothing) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($scope->label !== null) {
            $query->whereExists(fn ($member) => $member->select(DB::raw(1))->from('dam_label_assets')
                ->whereColumn('dam_label_assets.asset_id', 'dam_assets.id')
                ->where('dam_label_assets.label_id', $scope->label->id));
        }

        if ($scope->section !== null) {
            app(SectionService::class)->applyMembership(query: $query, section: $scope->section);
        }

        if ($scope->labelIds !== []) {
            $holding = fn (array $labelIds) => fn ($member) => $member->select(DB::raw(1))->from('dam_label_assets')
                ->whereColumn('dam_label_assets.asset_id', 'dam_assets.id')
                ->whereIn('dam_label_assets.label_id', $labelIds);

            if ($scope->labelsMode === self::MODE_ANY) {
                $query->whereExists($holding($scope->labelIds));
            } else {
                foreach ($scope->labelIds as $labelId) {
                    $query->whereExists($holding([$labelId]));
                }
            }
        }

        if ($scope->favorites) {
            // Nobody signed in has no favorites.
            $query->whereExists(fn ($favorite) => $favorite->select(DB::raw(1))->from('dam_asset_favorites')
                ->whereColumn('dam_asset_favorites.dam_asset_id', 'dam_assets.id')
                ->where('dam_asset_favorites.admin_id', $scope->viewerId ?? 0));
        }

        if ($scope->orientation !== null) {
            $query->where('dam_assets.orientation', $scope->orientation);
        }
    }

    /**
     * Orders by `position` or `popularity` when that is the sort, and reports whether it did; any other sort is the
     * caller's column. Position inside a label or board is the manual order; inside a section the pinned assets come
     * first in pin order, then the rest newest first. Outside a container there is no manual order, so it falls back
     * to newest first. The listing gets `member_position` for the manual order's move up/down.
     */
    public static function orderBy(Builder $query, OrganizationScope $scope, string $sortBy, string $sortOrder): bool
    {
        if ($sortBy === self::SORT_POPULARITY) {
            PopularityScore::orderBy(query: $query, direction: $sortOrder);

            return true;
        }

        if ($sortBy !== self::SORT_POSITION) {
            return false;
        }

        if ($scope->label !== null) {
            $query->addSelect(['member_position' => DB::table('dam_label_assets')->select('dam_label_assets.position')
                ->whereColumn('dam_label_assets.asset_id', 'dam_assets.id')
                ->where('dam_label_assets.label_id', $scope->label->id)])
                ->orderBy('member_position', $sortOrder);
        } elseif ($scope->section !== null) {
            $pin = DB::table('dam_section_pins')->select('dam_section_pins.position')
                ->whereColumn('dam_section_pins.asset_id', 'dam_assets.id')
                ->where('dam_section_pins.section_id', $scope->section->id);
            // An unpinned asset sorts after every pin, whichever way the pins run.
            $query->selectRaw('COALESCE(('.$pin->toSql().'), '.SectionService::UNPINNED_POSITION.') AS member_position', $pin->getBindings())
                ->orderBy('member_position', 'asc');
        }

        $query->orderBy('dam_assets.created_at', 'desc');

        return true;
    }

    /** Casts a listed asset's `member_position` (present only under the position sort); an unpinned section file has none. */
    public static function presentPosition(object $asset): void
    {
        if (! isset($asset->member_position)) {
            return;
        }

        $position = (int) $asset->member_position;
        $asset->member_position = $position === SectionService::UNPINNED_POSITION ? null : $position;
    }
}
