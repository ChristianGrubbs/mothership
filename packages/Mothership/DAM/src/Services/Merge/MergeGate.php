<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Merge;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\RoleBuilder;
use Mothership\DAM\Support\MergeLog;
use Mothership\User\Models\Admin;

/**
 * Who may merge, split and finalize (P3b-1, D7; audit §3.1 "Merge authorization"), for the admin UI and REST alike.
 *
 * - Edit rights on every participant, through the access seam: a missing, hidden, trashed or merged participant answers
 *   404, exactly like a missing one, before any 403 is given.
 * - A merge that would show a source's files to anyone who cannot see that source today widens access, so it also
 *   needs sharing authority on that source: its owner or a full admin (ADR 0010, the rule for access lists). "Anyone"
 *   is an active admin who opens the destination but not the source, and a public link that serves the destination
 *   (its own, or a folder link above it) but not the source: whoever holds that link would see the source's files.
 * - v1 refuses nesting: a destination with active merges is not merged into another asset until it is finalized.
 *
 * Every rule is asked twice: by the controller before anything is locked, and again by AssetMergeService on the locked,
 * freshly read rows and a freshly read actor (assertStillMayChange(), then assertMayMerge() again), so a grant revoked
 * or an audience widened in between refuses the write.
 */
class MergeGate
{
    /** How many sources one merge takes. */
    public const MAX_SOURCES = 50;

    /** The Library key every merge action needs. */
    public const LIBRARY_PERMISSION = 'dam.asset.edit';

    /** The REST key scope each action needs (api-acl.php). */
    public const API_SCOPES = [
        'merge'    => 'api.dam.assets.merges.store',
        'split'    => 'api.dam.assets.merges.split',
        'finalize' => 'api.dam.assets.merges.finalize',
        'revoke'   => 'api.dam.assets.merges.revoke_links',
    ];

    /** @var Collection<int, Admin>|null the active admins, read once per pass */
    private ?Collection $admins = null;

    public function __construct(protected AssetAccessDecision $access) {}

    /**
     * The destination and the sources, each one the viewer may change. Every participant is checked for visibility
     * before any is checked for the right to change it, so a hidden one always answers 404, never a 403 that names it.
     *
     * @param  list<int>  $sourceIds
     * @return array{0: Asset, 1: Collection<int, Asset>}
     */
    public function participants(int $destinationId, array $sourceIds): array
    {
        $context = AccessContext::library(AccessContext::PURPOSE_EDIT);
        $decisions = collect([$destinationId, ...$sourceIds])->mapWithKeys(fn (int $id) => [$id => $this->access->for($context, $id)]);

        $decisions->each->visibleOrFail();
        $decisions->each->allowedOrFail();

        return [$decisions->get($destinationId)->asset, collect($sourceIds)->map(fn (int $id) => $decisions->get($id)->asset)->values()];
    }

    /**
     * The `sources[]` of a merge or preview request: 1..MAX_SOURCES distinct ids, none the destination.
     *
     * @return list<int>
     */
    public static function sourceIds(mixed $input, int $destinationId): array
    {
        $ids = is_array($input) ? $input : [];
        $valid = $ids !== [] && count($ids) <= self::MAX_SOURCES
            && array_filter($ids, fn ($id) => ! is_numeric($id) || (int) $id < 1) === [];
        $ids = array_map('intval', $ids);

        if (! $valid || count(array_unique($ids)) !== count($ids) || in_array($destinationId, $ids, true)) {
            throw ValidationException::withMessages(['sources' => [trans('dam::app.admin.dam.asset.merge.bad-sources', ['max' => self::MAX_SOURCES])]]);
        }

        return array_values($ids);
    }

    /**
     * Refuses what the participants' state forbids, once they are known to be visible and changeable: a nested merge
     * (409), and widening without sharing authority on that source (403). Reads the admins and links afresh.
     *
     * @param  Collection<int, Asset>  $sources
     */
    public function assertMayMerge(Admin $actor, Asset $destination, Collection $sources): void
    {
        $this->admins = null;

        foreach ($sources as $source) {
            if (AssetMerge::query()->active()->where('dest_id', $source->id)->exists()) {
                MergeLog::write(event: 'merge.refused', ctx: ['dest' => (int) $destination->id, 'source' => (int) $source->id, 'reason' => 'nested']);

                self::refuse(status: 409, reason: 'nested', message: self::nestedMessage($destination));
            }
        }

        foreach ($sources as $source) {
            $impact = $this->impact(destination: $destination, source: $source);

            if (self::widens($impact) && ! $this->canShare(actor: $actor, source: $source)) {
                MergeLog::write(event: 'merge.refused', ctx: [
                    'dest'   => (int) $destination->id,
                    'source' => (int) $source->id,
                    'reason' => 'widening',
                    'admins' => count($impact['gains']),
                    'links'  => count($impact['links']),
                ]);

                self::refuse(status: 403, reason: 'widening');
            }
        }
    }

    /**
     * The second pass, under the participants' row locks: the actor as stored now is still enabled and still holds the
     * surface's key for this action (the role's in the Library, the API key's over REST), and may still change every
     * asset, each answered by the access seam with a fresh permission reader. A refusal answers as the first pass
     * would have: 403 for the key, 404 for an asset the actor can no longer open, 403 for one they can no longer change.
     *
     * @param  iterable<Asset>  $assets  the locked rows
     */
    public function assertStillMayChange(Admin $actor, string $via, string $action, iterable $assets): void
    {
        if (! $this->actorStillMay(actor: $actor, via: $via, action: $action)) {
            MergeLog::write(event: 'merge.refused', ctx: ['actor' => (int) $actor->id, 'action' => $action, 'reason' => 'actor_changed']);

            abort(403, trans('dam::app.admin.permissions.denied-merge'));
        }

        // A fresh context per pass: forAdmin() builds its own permission service, which reads the grants again.
        $context = AccessContext::forAdmin($actor, AccessContext::PURPOSE_EDIT, $via);
        $decisions = collect($assets)->map(fn (Asset $asset) => $this->access->for($context, $asset));

        $decisions->each->visibleOrFail();
        $decisions->each->allowedOrFail();
    }

    /**
     * A role limited to its own items merges, splits and finalizes only what it created: a merge changes the destination
     * (it gains the source's files, tags and properties), hides the source behind it, and a finalize sends the source to
     * the trash. Every participant must be the actor's own (a NULL owner is nobody's), else 403 `own-only` and nothing is
     * written. Asked on the locked rows of every action, so a pre-existing merge, a REST call and a race all meet it.
     *
     * @param  iterable<Asset>  $assets  the locked rows
     */
    public function assertOwnsEvery(Admin $actor, iterable $assets): void
    {
        $roles = app(RoleBuilder::class);

        if (! $roles->restrictedToOwn($actor)) {
            return;
        }

        foreach ($assets as $asset) {
            if (! $roles->canUserDelete(model: $asset, admin: $actor)) {
                MergeLog::write(event: 'merge.refused', ctx: ['actor' => (int) $actor->id, 'asset' => (int) $asset->id, 'reason' => 'own_only']);

                self::refuse(status: 403, reason: 'own-only');
            }
        }
    }

    /** The actor read again with its role and API key, or null when it no longer exists. */
    public static function freshActor(Admin $actor): ?Admin
    {
        return Admin::query()->with(['role', 'apiKey'])->find($actor->id);
    }

    /** The actor is still enabled and still holds the keys of the surface for this action. */
    private function actorStillMay(Admin $actor, string $via, string $action): bool
    {
        if (! (bool) $actor->status) {
            return false;
        }

        if ($via === AccessContext::VIA_API) {
            $key = $actor->apiKey;

            return $key !== null && ! $key->revoked
                && ($key->permission_type === 'all' || (bool) $key->hasPermission(self::API_SCOPES[$action] ?? ''));
        }

        return $actor->role !== null
            && ($actor->role->permission_type === 'all' || (bool) $actor->hasPermission(self::LIBRARY_PERMISSION));
    }

    /**
     * What to tell someone whose source is already a stack: add it as the representative, or, when the destination is a
     * stack too, split one of them first, since two stacks cannot be folded together in one step.
     */
    public static function nestedMessage(Asset $destination): string
    {
        $both = AssetMerge::query()->active()->where('dest_id', $destination->id)->exists();

        return trans('dam::app.admin.dam.asset.merge.'.($both ? 'nested-both' : 'nested'));
    }

    /**
     * Refuses with the reason in the body, `{message, reason}`: the exception handler gives a 403 or 409 abort only
     * generic copy, and the merge dialog shows why. Reasons: `nested`, `widening`, `not-active`.
     */
    public static function refuse(int $status, string $reason, ?string $message = null): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message ?? trans('dam::app.admin.dam.asset.merge.'.$reason),
            'reason'  => $reason,
        ], $status));
    }

    /**
     * Sharing authority on a source (ADR 0010): its owner, or a full admin. An ownerless item is managed by full admins.
     */
    public function canShare(Admin $actor, Asset $source): bool
    {
        return ($source->created_by !== null && (int) $source->created_by === (int) $actor->id)
            || $actor->role?->permission_type === 'all';
    }

    /**
     * Who gains and who loses sight of a source's files when it merges into the destination. The destination's access
     * wins, so an active admin who opens the destination but not the source gains them, and one who opens the source but
     * not the destination loses them; each answer is the access seam's own, availability included. `links` are the
     * active public links that serve the destination but not the source: their holders gain the files too.
     *
     * @return array{gains: list<Admin>, losses: list<Admin>, links: list<int>}
     */
    public function impact(Asset $destination, Asset $source): array
    {
        $gains = [];
        $losses = [];

        foreach ($this->activeAdmins() as $admin) {
            $context = AccessContext::forAdmin(admin: $admin);
            $seesDestination = $this->access->visible($context, (int) $destination->id);
            $seesSource = $this->access->visible($context, (int) $source->id);

            if ($seesDestination && ! $seesSource) {
                $gains[] = $admin;
            } elseif ($seesSource && ! $seesDestination) {
                $losses[] = $admin;
            }
        }

        $links = array_values(array_diff(self::reachingLinks(assetId: (int) $destination->id), self::reachingLinks(assetId: (int) $source->id)));

        return ['gains' => $gains, 'losses' => $losses, 'links' => $links];
    }

    /** @param  array{gains: list<Admin>, links: list<int>}  $impact */
    public static function widens(array $impact): bool
    {
        return $impact['gains'] !== [] || $impact['links'] !== [];
    }

    /**
     * The active public links that serve an asset: its own, and every folder link on a folder that holds it or holds
     * one of its folders (a folder link serves the whole subtree). Both are bound by the asset id alone.
     *
     * @return list<int> share ids
     */
    public static function reachingLinks(int $assetId): array
    {
        $direct = Share::query()->active()->where('share_type', Share::TYPE_ASSET)->where('target_id', $assetId)->pluck('id');
        $inherited = Share::query()->active()->where('share_type', Share::TYPE_DIRECTORY)
            ->whereIn('target_id', fn ($folders) => $folders->select('folder.id')->from('dam_directories as folder')
                ->join('dam_directories as item', fn ($join) => $join->on('folder._lft', '<=', 'item._lft')->on('folder._rgt', '>=', 'item._rgt'))
                ->join('dam_asset_directory as placement', 'placement.directory_id', '=', 'item.id')
                ->where('placement.asset_id', $assetId))
            ->pluck('id');

        return $direct->merge($inherited)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /** @return Collection<int, Admin> */
    private function activeAdmins(): Collection
    {
        return $this->admins ??= Admin::query()->with('role')->where('status', true)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The destination's active merge by id, for split, finalize and link revoke: a merge of another destination, or one
     * already split or finalized, answers 404 like a merge that does not exist.
     */
    public function activeMerge(Asset $destination, int $mergeId): AssetMerge
    {
        $merge = AssetMerge::query()->active()->where('dest_id', $destination->id)->find($mergeId);

        abort_if($merge === null, 404);

        return $merge;
    }
}
