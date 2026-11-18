<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Organization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\Events\AssetValuesChanged;
use Mothership\DAM\Models\Label;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Automation\ValueDecisions;
use Mothership\DAM\Services\Automation\ValueSource;
use Mothership\DAM\Support\OrganizationLog;
use Mothership\DAM\Support\OrganizationNameLock;

/**
 * P4 (D4, D13): shared labels and personal boards, one tree and one membership table. A shared label is seen by
 * everyone who uses the Library; a board only by its owner, on every path (a board id anyone else names answers
 * exactly like a missing one). Members are read through the access seam (VisibleAssets): removing a label never
 * removes an asset, and a member the viewer cannot open is left out without losing its membership.
 *
 * Who may change a shared label, or what it holds, is the caller's check (dam.labels.manage and dam.labels.assign
 * on the web, the route's API permission on REST); a board is its owner's to change.
 */
class LabelService
{
    /** Labels this viewer can see: every shared one, and their own boards. */
    public function visibleQuery(?int $viewerId, ?string $kind = null): Builder
    {
        return Label::query()
            ->where(function ($query) use ($viewerId): void {
                $query->where('kind', Label::KIND_SHARED)
                    ->orWhere(fn ($board) => $board->where('kind', Label::KIND_BOARD)->where('owner_id', $viewerId ?? 0));
            })
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind));
    }

    /** The label of this kind when the viewer can see it, else the same 404 a missing id gets. */
    public function findVisible(int $id, ?int $viewerId, ?string $kind = null): Label
    {
        $label = $this->visibleQuery(viewerId: $viewerId, kind: $kind)->where('id', $id)->first();

        abort_if($label === null, 404, trans('dam::app.admin.organize.labels.not-found'));

        return $label;
    }

    /**
     * The viewer's labels of one kind as a tree, each with how many members they may list and, when asset ids are
     * given, how many of those (the visible ones only) it already holds.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, array<string, mixed>>
     */
    public function tree(?int $viewerId, string $kind, AccessContext $context, array $assetIds = []): array
    {
        $labels = $this->visibleQuery(viewerId: $viewerId, kind: $kind)->orderBy('position')->orderBy('id')->get();
        $ids = $labels->pluck('id')->all();
        $counts = $this->memberCounts(labelIds: $ids, context: $context);
        $visibleAssetIds = VisibleAssets::filter($assetIds, $context);
        $holds = $visibleAssetIds === [] ? [] : DB::table('dam_label_assets')->whereIn('label_id', $ids ?: [0])
            ->whereIn('asset_id', $visibleAssetIds)->groupBy('label_id')
            ->selectRaw('label_id, COUNT(*) AS held')->pluck('held', 'label_id')->all();

        $nodes = $labels->map(fn (Label $label) => [
            'id'        => (int) $label->id,
            'parent_id' => $label->parent_id === null ? null : (int) $label->parent_id,
            'name'      => $label->name,
            'kind'      => $label->kind,
            'position'  => (int) $label->position,
            'count'     => (int) ($counts[$label->id] ?? 0),
            'holds'     => (int) ($holds[$label->id] ?? 0),
        ]);

        return $this->nest($nodes, null);
    }

    /** @return array<int, array<string, mixed>> */
    private function nest(Collection $nodes, ?int $parentId): array
    {
        return $nodes->filter(fn (array $node) => $node['parent_id'] === $parentId)
            ->map(fn (array $node) => $node + ['children' => $this->nest($nodes, $node['id'])])
            ->values()->all();
    }

    /**
     * How many members of each label the viewer may list: hidden members are never counted.
     *
     * @param  array<int, int>  $labelIds
     * @return array<int, int>
     */
    public function memberCounts(array $labelIds, AccessContext $context): array
    {
        if ($labelIds === []) {
            return [];
        }

        return DB::table('dam_label_assets')->whereIn('label_id', $labelIds)
            ->whereIn('asset_id', VisibleAssets::query($context))
            ->groupBy('label_id')->selectRaw('label_id, COUNT(*) AS members')
            ->pluck('members', 'label_id')->map(fn ($count) => (int) $count)->all();
    }

    /** @param  array{name: string, parent_id?: int|null}  $attributes */
    public function create(string $kind, array $attributes, ?int $viewerId): Label
    {
        // Check and insert run together, so two simultaneous creates of one name cannot both pass the check.
        return OrganizationNameLock::run(
            scope: $this->lockScope(kind: $kind, ownerId: $kind === Label::KIND_BOARD ? $viewerId : null),
            callback: fn (): Label => $this->createUnlocked(kind: $kind, attributes: $attributes, viewerId: $viewerId),
        );
    }

    /** @param  array{name: string, parent_id?: int|null}  $attributes */
    private function createUnlocked(string $kind, array $attributes, ?int $viewerId): Label
    {
        $parent = isset($attributes['parent_id']) ? $this->findVisible(id: (int) $attributes['parent_id'], viewerId: $viewerId, kind: $kind) : null;
        $this->assertDepth(parent: $parent, subtreeHeight: 1);
        $name = trim($attributes['name']);
        $this->assertUniqueName(kind: $kind, ownerId: $kind === Label::KIND_BOARD ? $viewerId : null, parentId: $parent?->id, name: $name);

        $label = Label::create([
            'name'      => $name,
            'kind'      => $kind,
            'owner_id'  => $kind === Label::KIND_BOARD ? $viewerId : null,
            'parent_id' => $parent?->id,
            'position'  => $this->nextPosition(kind: $kind, ownerId: $kind === Label::KIND_BOARD ? $viewerId : null, parentId: $parent?->id),
        ]);

        OrganizationLog::write(event: 'label.created', ctx: ['label_id' => (int) $label->id, 'kind' => $kind, 'nested' => $parent !== null, 'viewer_id' => $viewerId]);

        return $label;
    }

    /**
     * Renames, moves under another label of the same kind (or to the root with parent_id null) and/or moves to a
     * 0-based position among its siblings; the siblings keep their order around it.
     *
     * @param  array{name?: string, parent_id?: int|null, position?: int}  $attributes
     */
    public function update(Label $label, array $attributes, ?int $viewerId): Label
    {
        // A rename or move can make a name clash with a create in flight, so it takes the scope lock create takes.
        return OrganizationNameLock::run(
            scope: $this->lockScope(kind: $label->kind, ownerId: $label->owner_id),
            callback: fn (): Label => $this->updateUnlocked(label: $label, attributes: $attributes, viewerId: $viewerId),
        );
    }

    /** @param  array{name?: string, parent_id?: int|null, position?: int}  $attributes */
    private function updateUnlocked(Label $label, array $attributes, ?int $viewerId): Label
    {
        // The row as it is now, not as it was when the request loaded it: another change may have landed while waiting.
        $label = Label::query()->lockForUpdate()->find($label->id) ?? abort(404, trans('dam::app.admin.organize.labels.not-found'));

        return DB::transaction(function () use ($label, $attributes, $viewerId): Label {
            $parentId = $label->parent_id;

            if (array_key_exists('parent_id', $attributes) && $attributes['parent_id'] !== $label->parent_id) {
                $parent = $attributes['parent_id'] === null ? null : $this->findVisible(id: (int) $attributes['parent_id'], viewerId: $viewerId, kind: $label->kind);

                if ($parent !== null && in_array($parent->id, $this->subtreeIds($label), true)) {
                    throw ValidationException::withMessages(['parent_id' => trans('dam::app.admin.organize.labels.cycle')]);
                }

                $this->assertDepth(parent: $parent, subtreeHeight: $this->height($label));
                $parentId = $parent?->id;
            }

            $name = array_key_exists('name', $attributes) ? trim((string) $attributes['name']) : $label->name;

            if ($name !== $label->name || $parentId !== $label->parent_id) {
                $this->assertUniqueName(kind: $label->kind, ownerId: $label->owner_id, parentId: $parentId, name: $name, exceptId: (int) $label->id);
            }

            $moved = $parentId !== $label->parent_id;
            $label->fill(['name' => $name, 'parent_id' => $parentId])->save();

            if (array_key_exists('position', $attributes) || $moved) {
                $this->placeAmongSiblings(label: $label, position: $attributes['position'] ?? PHP_INT_MAX);
            }

            OrganizationLog::write(event: 'label.updated', ctx: ['label_id' => (int) $label->id, 'kind' => $label->kind, 'moved' => $moved, 'reordered' => array_key_exists('position', $attributes), 'viewer_id' => $viewerId]);

            return $label->refresh();
        });
    }

    /** Deletes the label and every label nested under it; their assets stay exactly where they are. */
    public function delete(Label $label, ?int $viewerId): int
    {
        $ids = $this->subtreeIds($label);

        DB::transaction(function () use ($ids): void {
            DB::table('dam_label_assets')->whereIn('label_id', $ids)->delete();
            Label::query()->whereIn('id', $ids)->delete();
            app(ValueDecisions::class)->forgetTarget(kind: ValueDecisions::KIND_LABEL, targetIds: $ids);
        });

        OrganizationLog::write(event: 'label.deleted', ctx: ['label_id' => (int) $label->id, 'kind' => $label->kind, 'labels_removed' => count($ids), 'viewer_id' => $viewerId]);

        return count($ids);
    }

    /**
     * Adds assets the viewer may list to the end of the label; one already there keeps its place. Any id the viewer
     * cannot list answers the same 404 a missing one does, and nothing is added.
     *
     * @param  array<int, int>  $assetIds
     * @return array<int, int> the ids added
     */
    public function addAssets(Label $label, array $assetIds, AccessContext $context, ?int $viewerId): array
    {
        $assetIds = array_values(array_unique(array_map('intval', $assetIds)));
        $visible = VisibleAssets::filter($assetIds, $context);
        abort_if(count($visible) !== count($assetIds), 404, trans('dam::app.admin.organize.assets-not-found'));

        $added = MemberOrder::label((int) $label->id)->append(assetIds: $visible, addedBy: $viewerId);

        // P5b (§3.4): a person adding a shared label lifts their earlier removal of it.
        if ($label->kind === Label::KIND_SHARED) {
            app(ValueDecisions::class)->clear(assetIds: $visible, kind: ValueDecisions::KIND_LABEL, targetId: (int) $label->id);
            $this->changed(assetIds: $added, source: ValueSource::MANUAL);
        }

        OrganizationLog::write(event: 'label.assets_added', ctx: ['label_id' => (int) $label->id, 'kind' => $label->kind, 'requested' => count($assetIds), 'added' => count($added), 'viewer_id' => $viewerId]);

        return $added;
    }

    /**
     * Takes assets out of the label; the assets themselves are untouched. Only members the viewer may list are taken
     * out: a hidden id is a no-op, exactly like a missing one.
     *
     * @param  array<int, int>  $assetIds
     */
    public function removeAssets(Label $label, array $assetIds, AccessContext $context, ?int $viewerId): int
    {
        $visible = VisibleAssets::filter($assetIds, $context);
        $order = MemberOrder::label((int) $label->id);
        $members = array_values(array_intersect($visible, $order->ids()));
        $removed = $order->remove($visible);

        // P5b (§3.4): a person taking a shared label off records the removal, so no mapping or rule puts it back.
        if ($label->kind === Label::KIND_SHARED && $members !== []) {
            app(ValueDecisions::class)->remove(assetIds: $members, kind: ValueDecisions::KIND_LABEL, targetId: (int) $label->id, actorId: $viewerId);
            $this->changed(assetIds: $members, source: ValueSource::MANUAL);
        }

        OrganizationLog::write(event: 'label.assets_removed', ctx: ['label_id' => (int) $label->id, 'kind' => $label->kind, 'requested' => count($assetIds), 'removed' => $removed, 'viewer_id' => $viewerId]);

        return $removed;
    }

    /** Moves one member to a 0-based position among the members the viewer can see (drag, or move up/down). */
    public function moveAsset(Label $label, int $assetId, int $position, AccessContext $context, ?int $viewerId): void
    {
        $order = MemberOrder::label((int) $label->id);
        $visible = VisibleAssets::filter($order->ids(), $context);
        abort_unless(in_array($assetId, $visible, true), 404, trans('dam::app.admin.organize.assets-not-found'));

        $order->move(assetId: $assetId, position: $position, visibleIds: $visible);
        OrganizationLog::write(event: 'label.asset_moved', ctx: ['label_id' => (int) $label->id, 'kind' => $label->kind, 'asset_id' => $assetId, 'position' => $position, 'viewer_id' => $viewerId]);
    }

    /**
     * Adds a shared label to one asset for a mapping or a rule (P5b ValueWriter, which decided it may): the
     * membership keeps `$source`. False when the asset already held it.
     */
    public function addByMachine(Label $label, int $assetId, string $source): bool
    {
        $added = MemberOrder::label((int) $label->id)->append(assetIds: [$assetId], addedBy: null, source: $source) !== [];

        if ($added) {
            OrganizationLog::write(event: 'label.assets_added', ctx: ['label_id' => (int) $label->id, 'kind' => $label->kind, 'requested' => 1, 'added' => 1, 'viewer_id' => null]);
            $this->changed(assetIds: [$assetId], source: $source);
        }

        return $added;
    }

    /** @param  array<int, int>  $assetIds */
    private function changed(array $assetIds, string $source): void
    {
        foreach ($assetIds as $assetId) {
            Event::dispatch(new AssetValuesChanged(assetId: (int) $assetId, kind: AssetValuesChanged::KIND_LABEL, keys: ['labels'], source: $source));
        }
    }

    /** @return array<int, int> this label and every label nested under it */
    public function subtreeIds(Label $label): array
    {
        $ids = [(int) $label->id];
        $frontier = $ids;

        while ($frontier !== []) {
            $frontier = Label::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->diff($ids)->values()->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function height(Label $label): int
    {
        $height = 1;
        $frontier = [(int) $label->id];

        while (($frontier = Label::query()->whereIn('parent_id', $frontier)->pluck('id')->all()) !== []) {
            $height++;
        }

        return $height;
    }

    private function depth(?Label $label): int
    {
        $depth = 0;

        while ($label !== null && $depth <= Label::MAX_DEPTH) {
            $depth++;
            $label = $label->parent_id === null ? null : Label::query()->where('id', $label->parent_id)->first();
        }

        return $depth;
    }

    private function assertDepth(?Label $parent, int $subtreeHeight): void
    {
        if ($this->depth($parent) + $subtreeHeight > Label::MAX_DEPTH) {
            throw ValidationException::withMessages(['parent_id' => trans('dam::app.admin.organize.labels.too-deep', ['max' => Label::MAX_DEPTH])]);
        }
    }

    private function siblings(string $kind, ?int $ownerId, ?int $parentId): Builder
    {
        return Label::query()->where('kind', $kind)
            ->when($ownerId === null, fn ($query) => $query->whereNull('owner_id'), fn ($query) => $query->where('owner_id', $ownerId))
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId));
    }

    /** One lock per set of siblings that must stay unique: a person's boards, or the shared labels. */
    private function lockScope(string $kind, ?int $ownerId): string
    {
        return 'label.'.$kind.'.'.($kind === Label::KIND_BOARD ? (int) $ownerId : 'shared');
    }

    private function assertUniqueName(string $kind, ?int $ownerId, ?int $parentId, string $name, ?int $exceptId = null): void
    {
        // A locking read: on MySQL REPEATABLE READ a plain read answers from the snapshot taken before this waited for the lock.
        $taken = $this->siblings(kind: $kind, ownerId: $ownerId, parentId: $parentId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->lockForUpdate()->value('id') !== null;

        if ($taken) {
            throw ValidationException::withMessages(['name' => trans($kind === Label::KIND_BOARD ? 'dam::app.admin.organize.labels.name-taken-board' : 'dam::app.admin.organize.labels.name-taken')]);
        }
    }

    private function nextPosition(string $kind, ?int $ownerId, ?int $parentId): int
    {
        $max = $this->siblings(kind: $kind, ownerId: $ownerId, parentId: $parentId)->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }

    private function placeAmongSiblings(Label $label, int $position): void
    {
        $siblings = $this->siblings(kind: $label->kind, ownerId: $label->owner_id, parentId: $label->parent_id)
            ->where('id', '<>', $label->id)->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        array_splice($siblings, max(0, min($position, count($siblings))), 0, [(int) $label->id]);

        foreach ($siblings as $index => $id) {
            Label::query()->where('id', $id)->update(['position' => $index]);
        }
    }
}
