<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Database\Eloquent\Builder;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\DirectoryPermissionService;

/**
 * P4: what the viewer may list, as the Library lists it (the access seam's listing rule plus hidden capture
 * derivatives), for every label, board, section, favorites and saved-search read. Membership never grants: a member
 * outside this set is left out of results and counts, and is back as soon as access returns.
 */
final class VisibleAssets
{
    /** The listable asset ids, as a subquery. */
    public static function query(AccessContext $context): Builder
    {
        $query = Asset::query()->visibleCaptures()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($query, $context);

        return $query;
    }

    /**
     * The given ids the viewer may list, in the order given; a hidden id drops out exactly like a missing one.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    public static function filter(array $ids, AccessContext $context): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $visible = self::query($context)->whereIn('dam_assets.id', $ids)->pluck('dam_assets.id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $visible, true)));
    }

    /** The admin the reads are for: the session's (admin, then api guard). */
    public static function viewerId(): ?int
    {
        $viewer = app(DirectoryPermissionService::class)->viewer();

        return $viewer ? (int) $viewer->id : null;
    }
}
