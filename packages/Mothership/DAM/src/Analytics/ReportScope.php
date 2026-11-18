<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Access\CollectionGrant;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/**
 * Access before aggregation (§3.6.8): every analytics report restricts its rows to subjects the viewer can see now,
 * in SQL, before anything is summed, grouped or named. An asset counts when the viewer's Library lists it (the
 * AssetAccessDecision listing rule, so trashed, deleted and unavailable-to-them assets drop out); a folder when the
 * viewer can see it; a collection (its link's visits) when the viewer manages it, as only they see its link. An event
 * with no subject (a search, a dialog) carries no asset and always counts. There is no "restricted" bucket: what the
 * viewer cannot see is absent.
 */
final class ReportScope
{
    private ?array $directoryIds = null;

    private bool $directoriesLoaded = false;

    public function __construct(
        public readonly Admin $viewer,
    ) {}

    /** The ids of the assets the viewer's Library lists, as a subquery. */
    public function visibleAssets(): Builder
    {
        $query = Asset::query()->select('dam_assets.id');
        app(AssetAccessDecision::class)->scopeListable($query, AccessContext::forAdmin(admin: $this->viewer, purpose: AccessContext::PURPOSE_LIST));

        return $query->toBase();
    }

    /** The folder ids the viewer can see; null when they see every folder. */
    public function visibleDirectories(): ?array
    {
        if (! $this->directoriesLoaded) {
            $permissions = (new DirectoryPermissionService)->forAdmin($this->viewer);
            $this->directoryIds = $permissions->bypass() ? null : array_map('intval', $permissions->viewableIds());
            $this->directoriesLoaded = true;
        }

        return $this->directoryIds;
    }

    /**
     * Restricts a dam_events or dam_event_counts_daily query to rows whose subject the viewer can see, or that have
     * none. $none is how the table writes "no subject": null on raw events, '' on daily counts.
     */
    public function apply(Builder $query, string $table, ?string $none): Builder
    {
        $directories = $this->visibleDirectories();

        return $query->where(function ($visible) use ($table, $none, $directories): void {
            $none === null ? $visible->whereNull($table.'.subject_type') : $visible->where($table.'.subject_type', $none);

            $visible->orWhere(fn ($asset) => $asset->where($table.'.subject_type', 'asset')->whereIn($table.'.subject_id', $this->visibleAssets()));

            $visible->orWhere(function ($folder) use ($table, $directories): void {
                $folder->where($table.'.subject_type', 'directory');

                // Inlined integers, not bindings: a role can see more folders than SQL Server takes parameters.
                if ($directories !== null) {
                    $folder->whereIntegerInRaw($table.'.subject_id', $directories === [] ? [0] : $directories);
                }
            });

            $visible->orWhere(fn ($collection) => $collection->where($table.'.subject_type', 'collection')
                ->whereIn($table.'.subject_id', $this->managedCollections()));
        });
    }

    /** The ids of the existing collections the viewer manages (CollectionGrant::manages), as a subquery. */
    public function managedCollections(): Builder
    {
        return AssetCollection::query()->select('id')
            ->when(! CollectionGrant::fullAdmin($this->viewer), fn ($query) => $query->where('owner_id', $this->viewer->id))
            ->toBase();
    }

    /**
     * Keeps rows made through a link the viewer may see: one that still exists and is not a collection link, or is one
     * of a collection they manage. A member's download through another owner's collection link has the member as its
     * subject, which apply() may let through, but the link and its collection stay hidden, as does a deleted link.
     */
    public function applyShares(Builder $query, string $table): Builder
    {
        return $query->whereIn($table.'.share_id', $this->visibleShares());
    }

    /** The ids of the existing links the viewer may see named, as a subquery. */
    public function visibleShares(): Builder
    {
        return DB::table('dam_shares')->select('id')->where(fn ($share) => $share->where('share_type', '!=', Share::TYPE_COLLECTION)
            ->orWhereIn('target_id', $this->managedCollections()));
    }
}
