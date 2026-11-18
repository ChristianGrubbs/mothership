<?php

namespace Mothership\DAM\Services\Access;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\DirectoryPermissionService;
use Mothership\User\Models\Admin;

/**
 * The one place that decides whether a viewer may see or use an asset (P2 S2). The trait helpers, FileController,
 * AssetController, both share controllers, AssetRelationshipService, the VisibleAsset rule and grant-authorised
 * page requests all ask it, and listings filter through scopeListable(), so a page the Library lists is a page that
 * opens. Availability, the window (S3) and trash (S4) are added here once, not at each caller; so is the merged state
 * of a merge source (P3b-1), which hides it from everyone exactly as trash does.
 *
 * P4 D2 (additive, the only collection code here): a `collection:<id>` context (AccessContext::collection(),
 * collectionLink()) is answered by CollectionGrant alone, at the top of visible(), for() and scopeListable(), and never
 * falls through to the Library rule. No other context reaches CollectionGrant, so the audience grant is bound to its
 * container. The Trash and out-for-use helpers serve no collection route and answer a collection context with nothing.
 */
class AssetAccessDecision
{
    /**
     * The visibility rule alone, for a caller that loads the asset itself: may the viewer open this id? For an
     * editor or full admin it does not check that the id exists: nothing is hidden from them, so a missing id gets
     * true and the caller's own lookup answers 404, exactly as before the seam. For anyone else a missing id answers
     * false, exactly like an unavailable one. Otherwise a folder-bypass role that is no editor would reach the
     * caller's own not-found answer for a missing id but the trait's generic 404 for an unavailable one, two
     * different bodies (P2 S3 re-review). A trashed asset (P2 S4) and a merge source (P3b-1) are false for everyone.
     */
    public function visible(AccessContext $context, int $assetId): bool
    {
        // P4 D2: a collection's own routes ask the collection grant, and only it.
        if ($context->isCollection()) {
            return $this->collectionGrant()->decide($context, $assetId)->visible;
        }

        $permissions = $this->permissionsFor($context);

        if ($permissions === null || ! $permissions->canAccessAsset($assetId)) {
            return false;
        }

        $viewer = $permissions->viewer();
        $asset = Asset::withTrashed()->whereKey($assetId)->first(['id', 'availability', 'available_from', 'available_until', 'created_by', 'deleted_at', 'merged_into_id', 'upload_submission_id']);

        if ($asset === null) {
            return $this->seesEveryUnavailable($viewer);
        }

        // P3b-1: a merge source is false for everyone, as a trashed asset is.
        return ! $asset->trashed() && ! $this->isMergeSource($asset) && $this->outFor(asset: $asset, viewer: $viewer);
    }

    /** Existence, visibility and the purpose, for one asset. A trashed asset answers `trashed` (P2 S4), not visible. */
    public function for(AccessContext $context, int|Asset $asset): AccessDecision
    {
        // P4 D2: a collection's own routes ask the collection grant, and only it.
        if ($context->isCollection()) {
            return $this->collectionGrant()->decide($context, $asset);
        }

        $model = $asset instanceof Asset ? $asset : Asset::withTrashed()->find($asset);

        if ($model === null) {
            return AccessDecision::missing();
        }

        if ($model->trashed()) {
            return AccessDecision::trashed($model);
        }

        // P3b-1: a merge source answers like a trashed asset, for every viewer and purpose.
        if ($this->isMergeSource($model)) {
            return AccessDecision::merged($model);
        }

        return $this->decide(context: $context, model: $model);
    }

    /**
     * The Trash page's question (P2 S4): the decision for() would give this trashed asset if it were not in the
     * trash, so a viewer acts only on what they could see and change before it was deleted. An asset that is not
     * in the trash answers missing here: the page acts on trashed assets only.
     */
    public function forTrashed(AccessContext $context, int|Asset $asset): AccessDecision
    {
        // P4 D2: nothing in the trash is reached through a collection.
        if ($context->isCollection()) {
            return AccessDecision::missing();
        }

        $model = $asset instanceof Asset ? $asset : Asset::onlyTrashed()->find($asset);

        if ($model === null || ! $model->trashed()) {
            return AccessDecision::missing();
        }

        // P3b-1: a finalized merge source waits in the trash for its purge, but it stays a merge source: never listed
        // on the Trash page, never restored.
        if ($this->isMergeSource($model)) {
            return AccessDecision::merged($model);
        }

        return $this->decide(context: $context, model: $model);
    }

    protected function decide(AccessContext $context, Asset $model): AccessDecision
    {
        $permissions = $this->permissionsFor($context);

        if ($permissions === null) {
            return AccessDecision::hidden($model);
        }

        $firstFolder = $context->purpose === AccessContext::PURPOSE_EDIT ? $this->firstFolderAllows(permissions: $permissions, asset: $model) : null;

        // The first folder allowing a change already shows the asset, so only a refusal asks the wider rule,
        // to tell a visible asset (403) from a hidden one (404).
        if ($firstFolder === true) {
            $unavailable = $this->unavailableReason(asset: $model, viewer: $permissions->viewer());

            return $unavailable ? AccessDecision::unavailable($model, $unavailable) : AccessDecision::allowed($model);
        }

        if (! $permissions->canAccessAsset($model->id)) {
            return AccessDecision::hidden($model);
        }

        if ($unavailable = $this->unavailableReason(asset: $model, viewer: $permissions->viewer())) {
            return AccessDecision::unavailable($model, $unavailable);
        }

        return $firstFolder === false ? AccessDecision::forbidden($model) : AccessDecision::allowed($model);
    }

    /**
     * For something kept apart from the Library that still names the asset it came from (a StylePress reference,
     * a vectorization result): readable while that source is one the viewer may open, or once the source is gone,
     * when the kept copy stands alone. A hidden or unavailable source closes it, as if it did not exist (P2 S3
     * review). Null means it never had a source: an upload of the viewer's own.
     */
    public function sourceOpen(AccessContext $context, ?int $sourceAssetId): bool
    {
        if ($sourceAssetId === null) {
            return true;
        }

        $decision = $this->for($context, $sourceAssetId);

        return $decision->visible || $decision->reason === AccessDecision::REASON_MISSING;
    }

    /**
     * A public surface's question (P6 D26, embeds): the asset, when anyone at all may receive it now, else null. The
     * requester is nobody in particular, so no owner, editor or full-admin exemption applies and no guard is asked
     * who is signed in: only an asset that is out for use for everyone opens, never one in the trash or one that is
     * not available now. These are the two rules scopeOutForUse() applies to a listing that leaves every unavailable
     * asset out; a state that closes an asset for everyone (P3b's merged) must close it here too.
     */
    public function openToPublic(int $assetId): ?Asset
    {
        $query = Asset::query()->where('dam_assets.id', $assetId);

        $this->scopeNotTrashed(query: $query, column: 'dam_assets.id');
        // P3b-1: a merge source is closed to the public as a trashed asset is (P6b embeds).
        $this->scopeNotMerged(query: $query, column: 'dam_assets.id');
        $query->where(fn ($available) => Asset::whereAvailableNow($available));

        return $query->first();
    }

    /**
     * Restricts an asset query to what the viewer may see: the listing half of the same rule. $column names the
     * asset id column when the query is not over dam_assets itself. Trashed assets (P2 S4) are left out for everyone,
     * whether or not the query is an Eloquent one that already carries the SoftDeletes scope.
     */
    public function scopeListable(Builder $query, AccessContext $context, string $column = 'dam_assets.id'): void
    {
        // P4 D2: a collection lists its servable members, and only through its own routes.
        if ($context->isCollection()) {
            $this->collectionGrant()->scope(query: $query, context: $context, column: $column);

            return;
        }

        $this->scopeNotTrashed(query: $query, column: $column);
        $this->scopeNotMerged(query: $query, column: $column);
        $this->scopeVisibleIgnoringTrash(query: $query, context: $context, column: $column);
    }

    /**
     * The Trash page's listing (P2 S4): over trashed assets, the folder and availability rule of scopeListable()
     * without the trash filter, so the page lists what the viewer could see before it was deleted.
     */
    public function scopeTrashListable(Builder $query, AccessContext $context): void
    {
        if ($context->isCollection()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->scopeNotMerged(query: $query, column: 'dam_assets.id');
        $this->scopeVisibleIgnoringTrash(query: $query, context: $context, column: 'dam_assets.id');
    }

    protected function scopeVisibleIgnoringTrash(Builder $query, AccessContext $context, string $column): void
    {
        $permissions = $this->permissionsFor($context);

        if ($permissions === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $permissions->applyAssetDirectoryGrantFilter($query, $column);

        $this->availabilityFilter(query: $query, context: $context, column: $column, permissions: $permissions);
    }

    /**
     * The availability half of scopeListable() alone (P2 S3): leaves out what is not out for this viewer, whatever
     * folder rule the caller applies itself (a `grants` folder zip trusts the folder check and zips the subtree).
     * Trashed assets are always out (P2 S4).
     */
    public function scopeOutForUse(Builder $query, AccessContext $context, string $column = 'dam_assets.id', ?DirectoryPermissionService $permissions = null): void
    {
        if ($context->isCollection()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->scopeNotTrashed(query: $query, column: $column);
        $this->scopeNotMerged(query: $query, column: $column);
        $this->availabilityFilter(query: $query, context: $context, column: $column, permissions: $permissions ?? $this->permissionsFor($context));
    }

    /** Leaves trashed assets out (P2 S4): over dam_assets by its own column, otherwise by id. */
    protected function scopeNotTrashed(Builder $query, string $column): void
    {
        if ($column === 'dam_assets.id') {
            $query->whereNull('dam_assets.deleted_at');

            return;
        }

        $query->whereNotIn($column, fn ($ids) => $ids->select('dam_assets.id')->from('dam_assets')->whereNotNull('dam_assets.deleted_at'));
    }

    /**
     * P3b-1: leaves merge sources out, for everyone, exactly as scopeNotTrashed() leaves trashed assets out. A merge
     * source's files are listed as slots of its destination; the source itself returns only when it is split.
     */
    protected function scopeNotMerged(Builder $query, string $column): void
    {
        if ($column === 'dam_assets.id') {
            $query->whereNull('dam_assets.merged_into_id');

            return;
        }

        $query->whereNotIn($column, fn ($ids) => $ids->select('dam_assets.id')->from('dam_assets')->whereNotNull('dam_assets.merged_into_id'));
    }

    /**
     * P3b-1: whether the asset is a merge source. A model loaded without the column (a narrow select) is asked about by
     * id rather than read as "not merged", so a partial model never opens a merge source.
     */
    protected function isMergeSource(Asset $asset): bool
    {
        if (array_key_exists('merged_into_id', $asset->getAttributes())) {
            return $asset->merged_into_id !== null;
        }

        return Asset::withTrashed()->where('id', $asset->getKey())->whereNotNull('merged_into_id')->exists();
    }

    protected function availabilityFilter(Builder $query, AccessContext $context, string $column, ?DirectoryPermissionService $permissions): void
    {
        $viewer = $permissions?->viewer();

        $this->guestIntakeFilter(query: $query, column: $column, viewer: $viewer);

        if ($context->includeUnavailable && $this->seesEveryUnavailable($viewer)) {
            return;
        }

        // The owner still lists their own drafts, unless the listing leaves every unavailable asset out.
        $ownerId = $context->includeUnavailable ? $viewer?->id : null;
        $outForUse = function ($q) use ($ownerId): void {
            $q->where(fn ($available) => Asset::whereAvailableNow($available))
                ->when($ownerId !== null, fn ($owned) => $owned->orWhere('dam_assets.created_by', $ownerId));
        };

        // Over dam_assets itself the columns are right there; otherwise ask the assets table by id.
        if ($column === 'dam_assets.id') {
            $query->where($outForUse);

            return;
        }

        $query->whereIn($column, fn ($ids) => $ids->select('dam_assets.id')->from('dam_assets')->where($outForUse));
    }

    /**
     * P5a: guest intake still `pending_review` is listed only to its owner (the upload request's creator) and full
     * admins, whatever the folder grants or the editor right say, under either access model. Under `lists` its empty
     * access list already hides it from everyone else; this keeps `grants`, where folders decide, just as private.
     * A viewer-less listing needs nothing here: availabilityFilter() already leaves every pending asset out of it.
     */
    protected function guestIntakeFilter(Builder $query, string $column, ?Admin $viewer): void
    {
        if ($viewer === null || $this->isFullAdmin($viewer)) {
            return;
        }

        $private = function ($q) use ($viewer): void {
            $q->whereNotNull('dam_assets.upload_submission_id')
                ->where('dam_assets.availability', Asset::AVAILABILITY_PENDING_REVIEW)
                ->where(fn ($owner) => $owner->whereNull('dam_assets.created_by')->orWhere('dam_assets.created_by', '!=', $viewer->id));
        };

        if ($column === 'dam_assets.id') {
            $query->whereNot($private);

            return;
        }

        $query->whereNotIn($column, fn ($ids) => $ids->select('dam_assets.id')->from('dam_assets')->where($private));
    }

    /** P5a: whether the asset is guest intake awaiting a decision; a narrow model is asked about by id, as merges are. */
    protected function isPendingGuestIntake(Asset $asset): bool
    {
        $attributes = $asset->getAttributes();

        if (array_key_exists('upload_submission_id', $attributes) && array_key_exists('availability', $attributes)) {
            return $asset->isPendingGuestIntake();
        }

        return Asset::withTrashed()->where('id', $asset->getKey())->whereNotNull('upload_submission_id')
            ->where('availability', Asset::AVAILABILITY_PENDING_REVIEW)->exists();
    }

    /** A full admin: no role (the parity case) or a role whose permission type is `all`. */
    protected function isFullAdmin(?Admin $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $role = $viewer->role;

        return $role === null || $role->permission_type === 'all';
    }

    /**
     * Whether the context's viewer may manage availability, so sees every unavailable asset it can open: an
     * editor or a full admin. A folder bypass is not this: a custom role that reaches every folder still has
     * drafts and closed windows left out of what it lists (P2 S3 review).
     */
    public function seesEveryUnavailableTo(AccessContext $context): bool
    {
        return $this->seesEveryUnavailable($this->permissionsFor($context)?->viewer());
    }

    /**
     * Owner, editors and full admins see an asset that is not out for use (P2 S3). Editors and full admins see
     * every one they can open; this answers for them without loading the asset.
     */
    protected function seesEveryUnavailable(?Admin $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $role = $viewer->role;

        // As DirectoryPermissionService: an admin with no role is a full admin.
        if ($role === null || $role->permission_type === 'all') {
            return true;
        }

        return in_array('dam.asset.update', (array) $role->permissions, true);
    }

    /** Whether the asset is out for this viewer: available now, or theirs, or they see every unavailable one. */
    protected function outFor(Asset $asset, ?Admin $viewer): bool
    {
        return $this->unavailableReason(asset: $asset, viewer: $viewer) === null;
    }

    /** Null when the asset is out for the viewer; otherwise the decision's reason, `unavailable` or `expired`. */
    protected function unavailableReason(Asset $asset, ?Admin $viewer): ?string
    {
        // P5a: guest intake awaiting a decision is out only for its owner and full admins (see guestIntakeFilter()).
        if ($this->isPendingGuestIntake($asset) && ! $this->isFullAdmin($viewer)
            && ($viewer === null || (int) $asset->created_by !== (int) $viewer->id)) {
            return AccessDecision::REASON_UNAVAILABLE;
        }

        $because = $asset->unavailableBecause();

        if ($because === null || $this->seesEveryUnavailable($viewer)
            || ($viewer !== null && (int) $asset->created_by === (int) $viewer->id)) {
            return null;
        }

        return $because === Asset::AVAILABILITY_EXPIRED ? AccessDecision::REASON_EXPIRED : AccessDecision::REASON_UNAVAILABLE;
    }

    /** P4 D2: the collection-audience grant, asked only for a `collection:<id>` context. */
    protected function collectionGrant(): CollectionGrant
    {
        return app(CollectionGrant::class);
    }

    /** The session's rules, or a named admin's; null for a disabled admin, who sees nothing. */
    protected function permissionsFor(AccessContext $context): ?DirectoryPermissionService
    {
        if ($context->viewer === null) {
            return app(DirectoryPermissionService::class);
        }

        // The same rule the Bouncer middleware applies to a session: a disabled admin has no access.
        if (! (bool) $context->viewer->status) {
            return null;
        }

        return (new DirectoryPermissionService)->forAdmin($context->viewer);
    }

    /**
     * Under `grants`, changing an asset has always asked the folder it was first filed in, not any folder that
     * shows it. Null when there is nothing more to ask: under `lists` the file's own list decides everything
     * (ADR 0010), and a role that sees everything may change anything.
     */
    protected function firstFolderAllows(DirectoryPermissionService $permissions, Asset $asset): ?bool
    {
        if (app(AccessModel::class)->lists() || $permissions->bypass()) {
            return null;
        }

        $directoryId = DB::table('dam_asset_directory')->where('asset_id', $asset->id)->orderBy('id')->value('directory_id');

        return $directoryId !== null && $permissions->canAccess((int) $directoryId);
    }
}
