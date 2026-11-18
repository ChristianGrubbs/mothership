<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Merge;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mothership\DAM\AiIndex\AiIndexDispatcher;
use Mothership\DAM\Jobs\GenerateVideoPreviewProxy;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetComments;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\AssetProperty;
use Mothership\DAM\Models\Attachment;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Services\AssetDeletionService;
use Mothership\DAM\Services\AttachmentLedger;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\ModelFileThumbnailService;
use Mothership\DAM\Services\ModelThumbnailService;
use Mothership\DAM\Support\MergeLog;
use Mothership\User\Models\Admin;

/**
 * The one writer of merges (P3b-1, D7; audit §3.1 "Merge: link, don't move"), shared by the admin UI and REST.
 * Callers authorize first (MergeGate). Every write then fires EVENT_LOCKING, locks its rows, reads the actor again and
 * asks every rule a second time on the locked rows (MergeGate::assertStillMayChange(), and for a merge
 * assertMayMerge()), so a grant revoked or an audience widened between the two passes refuses it and changes nothing.
 *
 * - **Merge** links. Each source slot becomes a new destination slot whose revisions name the same FileObjects; the
 *   source's own rows, versions, renditions and keys are untouched, so no blob loses a reference and no owner key is
 *   crossed. The source is then hidden by `merged_into_id` (AssetAccessDecision answers it like a trashed asset).
 *   The destination's metadata and access win: tags take the union (unless asked not to), a property the destination
 *   lacks is added. Each merge records every tag and property its source contributes, including one another merge
 *   already brought, so a split takes back only what no remaining (active or finalized) merge still contributes.
 * - **Split** reverses one merge: the source comes back whole from its own rows, the destination slots it gave are
 *   removed, and what the merge added to the destination is taken back. A slot that gained revisions after the merge
 *   is kept on the destination as a forked slot (the default) or has those revisions copied back to the source slot.
 * - **Finalize** is irreversible: split ends, the slots become the destination's own, the source's comment threads are
 *   copied onto the destination with their authors, times and replies and a "From <source>" attribution, and the
 *   source goes to the trash, still a merge source (never listed, never restored), so the normal 30-day purge removes
 *   it.
 *
 * Lock order is ADR 0009's: asset rows (in id order), then slots, then revisions, then blobs (in sha order). No query
 * binds more than ID_CHUNK ids, so none comes near SQL Server's 2,100 parameters.
 */
class AssetMergeService
{
    /** Derived previews of the source's representative: they stay on the source and are never linked. */
    public const UNLINKED_ROLES = [AssetFile::ROLE_PROXY, AssetFile::ROLE_THUMBNAIL];

    /** The per-slot split choices for a slot that gained revisions after the merge (audit §3.1). */
    public const KEEP_ON_DESTINATION = 'destination';

    public const COPY_TO_SOURCE = 'source';

    /** Fired after the caller's checks and before the lock, with [action, destinationId, sourceIds or mergeId]. */
    public const EVENT_LOCKING = 'dam.merge.locking';

    /** Ids per IN list, well under SQL Server's 2,100 bound parameters. */
    public const ID_CHUNK = 500;

    public function __construct(
        protected AttachmentLedger $ledger,
        protected ContentStore $store,
        protected MergeGate $gate,
    ) {}

    /**
     * Merges every source into the destination, one merge record per source, in one transaction. `$via` names the
     * surface (library or api), whose key the second pass checks again.
     *
     * `$revalidate`, when given, runs once the rows are locked, before anything is written, and aborts (it throws) when what the
     * caller reviewed no longer holds: the Similar images groups' confirmation uses it, so a group that changed between the
     * preview and the confirm merges nothing.
     *
     * @param  list<int>  $sourceIds
     * @return Collection<int, AssetMerge>
     */
    public function merge(Asset $destination, array $sourceIds, bool $unionTags, Admin $actor, string $via, ?\Closure $revalidate = null): Collection
    {
        $tagsChanged = false;

        Event::dispatch(self::EVENT_LOCKING, ['merge', (int) $destination->id, $sourceIds]);

        $merges = DB::transaction(function () use ($destination, $sourceIds, $unionTags, $actor, $via, $revalidate, &$tagsChanged): Collection {
            $locked = $this->lockAssets([(int) $destination->id, ...$sourceIds]);
            $dest = $locked->get((int) $destination->id);
            $sources = collect($sourceIds)->map(fn (int $id) => $locked->get($id));
            abort_if($dest === null || $sources->contains(null), 404);

            if ($revalidate !== null) {
                $revalidate();
            }

            // Second pass: every rule again, on the locked rows and the actor as stored now.
            $fresh = MergeGate::freshActor($actor);
            abort_if($fresh === null, 403, trans('dam::app.admin.permissions.denied-merge'));
            $this->gate->assertStillMayChange(actor: $fresh, via: $via, action: 'merge', assets: [$dest, ...$sources->all()]);
            $this->gate->assertOwnsEvery(actor: $fresh, assets: [$dest, ...$sources->all()]);
            $this->gate->assertMayMerge(actor: $fresh, destination: $dest, sources: $sources);

            $position = (int) Attachment::query()->where('dam_asset_id', $dest->id)->max('position') + 1;
            // Sets (value => true), so a source with thousands of tags or properties is reconciled in linear time.
            $destTags = DB::table('dam_asset_tag')->where('asset_id', $dest->id)->pluck('tag_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
            $destKeys = AssetProperty::query()->where('dam_asset_id', $dest->id)->get()->mapWithKeys(fn (AssetProperty $property) => [self::propertyKey($property) => true])->all();
            // What the destination's earlier, unsplit merges contribute: a tag or property they brought is not the
            // destination's own, so a source holding it too contributes it as well.
            $earlier = $this->contributingMerges(destId: (int) $dest->id, exceptId: null);
            $contributedTags = array_fill_keys($earlier->flatMap(fn (AssetMerge $merge) => self::tagContributions($merge))->all(), true);
            $contributedKeys = array_fill_keys($earlier->flatMap(fn (AssetMerge $merge) => array_column(self::propertyContributions($merge), 'key'))->all(), true);
            $merges = collect();

            foreach ($sources as $source) {
                $merge = AssetMerge::create([
                    'dest_id'   => $dest->id,
                    'source_id' => $source->id,
                    'merged_by' => $fresh->id,
                    'merged_at' => now(),
                ]);

                [$slots, $position] = $this->linkSlots(dest: $dest, source: $source, merge: $merge, position: $position, actorId: (int) $fresh->id);

                $tagsAdded = [];
                $tagsContributed = [];

                if ($unionTags) {
                    foreach (DB::table('dam_asset_tag')->where('asset_id', $source->id)->orderBy('tag_id')->pluck('tag_id') as $tagId) {
                        $tagId = (int) $tagId;

                        if (! isset($destTags[$tagId])) {
                            DB::table('dam_asset_tag')->insert(['asset_id' => $dest->id, 'tag_id' => $tagId]);
                            $destTags[$tagId] = true;
                            $tagsAdded[] = $tagId;
                            $tagsContributed[] = $tagId;
                        } elseif (isset($contributedTags[$tagId])) {
                            $tagsContributed[] = $tagId;
                        }
                    }
                }

                $propertiesContributed = [];
                $seenKeys = [];

                // The destination's value wins a conflict; a property it lacks comes from the first source that has it.
                // A source holding a property an earlier merge brought contributes it too, with its own value.
                foreach (AssetProperty::query()->where('dam_asset_id', $source->id)->orderBy('id')->get() as $property) {
                    $key = self::propertyKey($property);

                    if (isset($seenKeys[$key])) {
                        continue;
                    }

                    $seenKeys[$key] = true;
                    $inserted = ! isset($destKeys[$key]);

                    if (! $inserted && ! isset($contributedKeys[$key])) {
                        continue;
                    }

                    if ($inserted) {
                        AssetProperty::create([
                            'dam_asset_id'  => $dest->id,
                            'name'          => $property->name,
                            'type'          => $property->type,
                            'language'      => $property->language,
                            'value'         => $property->value,
                            'is_filterable' => $property->is_filterable,
                            'sort_order'    => $property->sort_order,
                        ]);
                        $destKeys[$key] = true;
                    }

                    $propertiesContributed[] = ['key' => $key, 'name' => (string) $property->name, 'value' => (string) $property->value, 'inserted' => $inserted];
                }

                // A plain column write: the source row is otherwise left exactly as it was, updated_at included.
                DB::table('dam_assets')->where('id', $source->id)->update(['merged_into_id' => $dest->id]);

                $merge->snapshot = [
                    'source' => [
                        'id'           => (int) $source->id,
                        'file_name'    => (string) $source->file_name,
                        'display_name' => (string) $source->display_name,
                        'created_by'   => $source->created_by === null ? null : (int) $source->created_by,
                    ],
                    'union_tags'             => $unionTags,
                    'slots'                  => $slots,
                    'tags_added'             => $tagsAdded,
                    'tags_contributed'       => $tagsContributed,
                    'properties_contributed' => $propertiesContributed,
                    'sha256'                 => self::sha256Set(assetId: (int) $source->id),
                ];
                $merge->save();
                $tagsChanged = $tagsChanged || $tagsAdded !== [];
                $contributedTags += array_fill_keys($tagsContributed, true);
                $contributedKeys += array_fill_keys(array_column($propertiesContributed, 'key'), true);

                MergeLog::write(event: 'merge.linked', ctx: [
                    'merge'      => (int) $merge->id,
                    'dest'       => (int) $dest->id,
                    'source'     => (int) $source->id,
                    'slots'      => count($slots),
                    'tags'       => count($tagsContributed),
                    'properties' => count($propertiesContributed),
                ]);
                $merges->push($merge);
            }

            return $merges;
        });

        $this->afterMetadataChange(assetId: (int) $destination->id, tagsChanged: $tagsChanged);

        return $merges;
    }

    /**
     * One new destination slot per source slot (but the derived previews), its revisions sharing the source's blobs.
     * The source's representative becomes an `other` slot labelled with the source's Library name, so it keeps reading
     * as itself and never takes the destination's one `original`.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function linkSlots(Asset $dest, Asset $source, AssetMerge $merge, int $position, int $actorId): array
    {
        $sourceSlots = Attachment::query()->where('dam_asset_id', $source->id)
            ->whereNotIn('role', self::UNLINKED_ROLES)
            ->orderByDesc('is_representative')->orderBy('position')->orderBy('id')
            ->lockForUpdate()->get();
        // Revisions after their slots, in slot-id order, ID_CHUNK slots per query.
        $revisionsBySlot = self::inChunks(
            ids: $sourceSlots->pluck('id')->all(),
            read: fn (array $chunk) => AssetFile::query()->whereIn('attachment_id', $chunk)->with('fileObject')
                ->orderBy('attachment_id')->orderBy('revision_number')->lockForUpdate()->get(),
        )->groupBy('attachment_id');

        // Every blob this merge will name, locked in sha order so two merges sharing blobs never deadlock.
        $objects = $revisionsBySlot->flatten()->pluck('fileObject')->filter()->unique('id')->sortBy('sha256')
            ->mapWithKeys(fn (FileObject $object) => [(int) $object->id => $this->store->retain($object)]);

        $slots = [];

        foreach ($sourceSlots as $sourceSlot) {
            $revisions = $revisionsBySlot->get($sourceSlot->id, collect());

            if ($revisions->isEmpty()) {
                continue;
            }

            $current = $revisions->firstWhere('is_current', true) ?? $revisions->last();
            $role = $sourceSlot->is_representative ? AssetFile::ROLE_OTHER : $sourceSlot->role;
            $label = $sourceSlot->is_representative
                ? ($sourceSlot->label ?: (string) $source->file_name)
                : ($sourceSlot->label ?: $current->label);
            $slot = Attachment::create([
                'dam_asset_id'         => $dest->id,
                'role'                 => $role,
                'label'                => $label,
                'position'             => $position,
                'is_representative'    => false,
                'created_by'           => $actorId,
                'merge_id'             => $merge->id,
                'origin_attachment_id' => $sourceSlot->id,
            ]);
            $linked = [];

            foreach ($revisions as $revision) {
                $object = $objects->get((int) $revision->file_object_id);
                $row = new AssetFile([
                    'dam_asset_id'    => $dest->id,
                    'file_object_id'  => $object?->id ?? $revision->file_object_id,
                    'role'            => $revision->is_current ? $role : AssetFile::ROLE_VERSION,
                    'label'           => $revision->is_current && $sourceSlot->is_representative ? $label : $revision->label,
                    'sort'            => $position,
                    'attachment_id'   => $slot->id,
                    'revision_number' => $revision->revision_number,
                    'is_current'      => (bool) $revision->is_current,
                    'created_by'      => $revision->created_by,
                    'note'            => $revision->note,
                ]);
                // When the revision was made, not when it was linked: the slot's history reads as the source's did.
                $row->created_at = $revision->created_at;
                $row->save();

                $linked[] = [
                    'id'              => (int) $row->id,
                    'origin_id'       => (int) $revision->id,
                    'revision_number' => (int) $revision->revision_number,
                    'sha256'          => (string) $revision->fileObject?->sha256,
                ];
            }

            if ($sourceSlot->is_representative && $current->fileObject !== null) {
                $this->carryModelCapture(dest: $dest, source: $source, sha256: (string) $current->fileObject->sha256);
            }

            $slots[] = [
                'attachment_id'        => (int) $slot->id,
                'origin_attachment_id' => (int) $sourceSlot->id,
                'origin_role'          => (string) $sourceSlot->role,
                'role'                 => $role,
                'label'                => $label,
                'revisions'            => $linked,
            ];
            $position++;
        }

        return [$slots, $position];
    }

    /**
     * A 3D source's browser capture (ADR 0006, `thumbnails/models/<source>-<sha12>.jpg`) is the only picture of its
     * file, and a stack reads a file's picture beside its blob. So once the merge has committed the capture is copied
     * there for the destination (`model-capture-a<dest>.jpg`): a copy, so a split leaves the source whole and the picture
     * goes with the blob. A capture the destination already has is never overwritten, a rolled-back merge leaves nothing
     * behind, and a copy that fails never fails the merge.
     */
    private function carryModelCapture(Asset $dest, Asset $source, string $sha256): void
    {
        if (! ModelThumbnailService::supports($source) || preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            return;
        }

        DB::afterCommit(function () use ($dest, $source, $sha256): void {
            try {
                ModelFileThumbnailService::copyIfAbsent(
                    from: ModelThumbnailService::generatedPath($source, $sha256),
                    sha256: $sha256,
                    assetId: (int) $dest->id,
                );
            } catch (\Throwable $e) {
                MergeLog::write(event: 'merge.capture_copy_failed', ctx: ['dest' => (int) $dest->id, 'source' => (int) $source->id, 'error' => $e->getMessage()], level: 'warning');
            }
        });
    }

    /**
     * What a split of this merge would do, slot by slot: the slots it gave, which gained revisions since (and so ask
     * where those go), and which must stay on the destination because the destination now depends on them.
     *
     * @return list<array<string, mixed>>
     */
    public function splitPlan(AssetMerge $merge): array
    {
        $linked = collect($merge->snapshot['slots'] ?? [])->keyBy('attachment_id');
        $slots = Attachment::query()->where('dam_asset_id', $merge->dest_id)->where('merge_id', $merge->id)
            ->orderBy('position')->orderBy('id')->get();
        $revisions = self::inChunks(
            ids: $slots->pluck('id')->all(),
            read: fn (array $chunk) => AssetFile::query()->whereIn('attachment_id', $chunk)->with('fileObject')->orderBy('revision_number')->get(),
        )->groupBy('attachment_id');
        $historyPinned = array_flip(self::historyPinned($slots->pluck('id')->all()));

        return $slots->map(function (Attachment $slot) use ($linked, $revisions, $historyPinned): array {
            $rows = $revisions->get($slot->id, collect());
            $linkedIds = collect($linked->get($slot->id)['revisions'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
            $newer = $rows->reject(fn (AssetFile $row) => in_array((int) $row->id, $linkedIds, true))->values();
            $current = $rows->firstWhere('is_current', true);

            return [
                'attachment_id'        => (int) $slot->id,
                'origin_attachment_id' => $slot->origin_attachment_id,
                'role'                 => $slot->role,
                'label'                => $slot->label,
                'sha256'               => $current?->fileObject?->sha256,
                'size'                 => $current?->fileObject?->size,
                'new_revisions'        => $newer->count(),
                'new_revision_ids'     => $newer->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'pinned'               => $slot->is_representative ? 'representative' : (isset($historyPinned[$slot->id]) ? 'history' : null),
            ];
        })->values()->all();
    }

    /**
     * Which slots the destination's own history pins (a slot standing for the entry is pinned as `representative` by
     * the caller): an asset version, or a rendition made from it, names one of their revisions, and those records'
     * owner keys refuse its removal. Asked ID_CHUNK slots at a time with EXISTS on each revision, so no list of
     * revision ids is bound.
     *
     * @param  list<int>  $slotIds
     * @return list<int> the pinned slot ids
     */
    private static function historyPinned(array $slotIds): array
    {
        return self::inChunks(
            ids: $slotIds,
            read: fn (array $chunk) => AssetFile::query()->whereIn('dam_asset_files.attachment_id', $chunk)
                ->where(fn ($pinned) => $pinned
                    ->whereExists(fn ($version) => $version->selectRaw('1')->from('dam_asset_versions')->whereColumn('dam_asset_versions.primary_asset_file_id', 'dam_asset_files.id'))
                    ->orWhereExists(fn ($rendition) => $rendition->selectRaw('1')->from('dam_renditions')->whereColumn('dam_renditions.source_asset_file_id', 'dam_asset_files.id')))
                ->distinct()->pluck('dam_asset_files.attachment_id'),
        )->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Splits one active merge. $choices maps a destination slot id to KEEP_ON_DESTINATION or COPY_TO_SOURCE; it is
     * read only for slots that gained revisions after the merge, and a slot it does not name keeps them on the
     * destination. A revoked link stays revoked: shares are never touched.
     *
     * @param  array<int, string>  $choices
     */
    public function split(AssetMerge $merge, array $choices, Admin $actor, string $via): AssetMerge
    {
        $refreshSource = false;
        $tombstones = [];

        Event::dispatch(self::EVENT_LOCKING, ['split', (int) $merge->dest_id, (int) $merge->id]);

        $split = DB::transaction(function () use ($merge, $choices, $actor, $via, &$refreshSource, &$tombstones): AssetMerge {
            $locked = $this->lockAssets([(int) $merge->dest_id, (int) $merge->source_id]);
            $merge = AssetMerge::query()->lockForUpdate()->findOrFail($merge->id);
            if (! $merge->isActive() || ! $locked->has($merge->source_id) || ! $locked->has($merge->dest_id)) {
                MergeGate::refuse(status: 409, reason: 'not-active');
            }
            $source = $locked->get($merge->source_id);
            $dest = $locked->get($merge->dest_id);
            $fresh = $this->assertStillMayChangeDestination(actor: $actor, via: $via, action: 'split', dest: $dest);
            $this->gate->assertOwnsEvery(actor: $fresh, assets: [$dest, $source]);

            $kept = [];
            $copied = [];
            $removed = [];
            $releasedObjects = [];

            foreach ($this->splitPlan($merge) as $plan) {
                $slot = Attachment::query()->lockForUpdate()->findOrFail($plan['attachment_id']);
                $choice = $choices[$plan['attachment_id']] ?? self::KEEP_ON_DESTINATION;

                if ($plan['pinned'] !== null || ($plan['new_revisions'] > 0 && $choice === self::KEEP_ON_DESTINATION)) {
                    // A forked slot: the destination's own from now on. origin_attachment_id keeps where it came from.
                    $slot->update(['merge_id' => null]);
                    $kept[] = (int) $slot->id;

                    continue;
                }

                if ($plan['new_revisions'] > 0) {
                    $copied[] = (int) $slot->id;
                    $refreshSource = $this->copyBack(source: $source, dest: $dest, slot: $slot, revisionIds: $plan['new_revision_ids']) || $refreshSource;
                }

                foreach (AssetFile::query()->where('attachment_id', $slot->id)->orderByDesc('revision_number')->lockForUpdate()->get() as $row) {
                    $releasedObjects[] = (int) $row->file_object_id;
                    // One row at a time, so AssetFile's deleted hook clears lineage pointers and drops the empty slot.
                    $row->delete();
                }

                $removed[] = (int) $slot->id;
            }

            $tombstones = $this->releaseUnreferenced(objectIds: $releasedObjects);
            $takeBack = $this->takeBackPlan(merge: $merge);
            $this->takeBack(merge: $merge, plan: $takeBack);

            $this->compactPositions(assetId: (int) $dest->id);

            DB::table('dam_assets')->where('id', $source->id)->update(['merged_into_id' => null]);
            $this->refileIfFolderless(source: $source);

            $merge->forceFill([
                'split_at' => now(),
                'split_by' => $actor->id,
                'snapshot' => array_merge($merge->snapshot ?? [], ['split' => [
                    'kept_on_destination' => $kept,
                    'copied_to_source'    => $copied,
                    'removed'             => $removed,
                    'tags_removed'        => $takeBack['tags'],
                    'properties'          => $takeBack['properties'],
                ]]),
            ])->save();

            MergeLog::write(event: 'merge.split', ctx: [
                'merge'   => (int) $merge->id,
                'dest'    => (int) $dest->id,
                'source'  => (int) $source->id,
                'kept'    => $kept,
                'copied'  => $copied,
                'removed' => count($removed),
                'tags'    => count($takeBack['tags']),
            ]);

            return $merge;
        });

        foreach (array_chunk($tombstones, self::ID_CHUNK) as $chunk) {
            try {
                app(AssetDeletionService::class)->processPendingFileDeletions($chunk);
            } catch (\Throwable) {
                // Already logged with its tombstone kept for retry (dam:storage:reap).
            }
        }

        if ($refreshSource) {
            $this->ledger->refreshDerived($split->source_id, bytesChanged: true);
        }

        $this->afterMetadataChange(assetId: $split->dest_id, tagsChanged: true);

        return $split;
    }

    /**
     * Appends a destination slot's post-merge revisions to the source slot it came from, oldest first, each sharing its
     * blob. Returns whether the source's representative changed, so the caller refreshes its derived files.
     *
     * @param  list<int>  $revisionIds
     */
    private function copyBack(Asset $source, Asset $dest, Attachment $slot, array $revisionIds): bool
    {
        $origin = Attachment::query()->where('dam_asset_id', $source->id)->find($slot->origin_attachment_id);

        if ($origin === null) {
            return false;
        }

        $note = trans('dam::app.admin.dam.asset.merge.copied-back-note', ['name' => (string) $dest->file_name]);
        $changed = false;
        $revisions = self::inChunks(
            ids: $revisionIds,
            read: fn (array $chunk) => AssetFile::query()->whereIn('id', $chunk)->with('fileObject')->get(),
        )->sortBy('revision_number');

        foreach ($revisions as $revision) {
            if ($revision->fileObject !== null && $this->ledger->appendRevision(asset: $source, attachmentId: (int) $origin->id, object: $revision->fileObject, note: $note) !== null) {
                $changed = true;
            }
        }

        return $changed && $origin->is_representative;
    }

    /**
     * What a split of this merge takes back from the destination, reconciled against every merge into it that remains
     * (active or finalized): a tag no remaining merge contributes is removed; a property no remaining merge contributes
     * is removed, and one another merge still contributes stays, taking that merge's value if it still holds this one's.
     *
     * @return array{tags: list<int>, properties: list<array{key: string, name: string, action: string, value: ?string, from: string}>}
     */
    public function takeBackPlan(AssetMerge $merge): array
    {
        $remaining = $this->contributingMerges(destId: (int) $merge->dest_id, exceptId: (int) $merge->id);
        $stillTagged = $remaining->flatMap(fn (AssetMerge $other) => self::tagContributions($other))->unique()->all();
        // Each key's next contributor is the earliest remaining merge that contributes it, read once per merge.
        $nextByKey = [];

        foreach ($remaining as $other) {
            foreach (self::propertyContributions($other) as $contribution) {
                $nextByKey[$contribution['key']] ??= $contribution;
            }
        }

        $properties = [];

        foreach (self::propertyContributions($merge) as $contribution) {
            $next = $nextByKey[$contribution['key']] ?? null;

            $properties[] = [
                'key'    => $contribution['key'],
                'name'   => $contribution['name'],
                'action' => $next === null ? 'remove' : 'keep',
                'value'  => $next['value'] ?? null,
                'from'   => $contribution['value'],
            ];
        }

        return [
            'tags'       => array_values(array_diff(self::tagContributions($merge), $stillTagged)),
            'properties' => $properties,
        ];
    }

    /** @param  array{tags: list<int>, properties: list<array<string, mixed>>}  $plan */
    private function takeBack(AssetMerge $merge, array $plan): void
    {
        foreach (array_chunk($plan['tags'], self::ID_CHUNK) as $chunk) {
            DB::table('dam_asset_tag')->where('asset_id', $merge->dest_id)->whereIn('tag_id', $chunk)->delete();
        }

        if ($plan['properties'] === []) {
            return;
        }

        $rows = AssetProperty::query()->where('dam_asset_id', $merge->dest_id)->orderBy('id')->get()
            ->groupBy(fn (AssetProperty $row) => self::propertyKey($row));

        foreach ($plan['properties'] as $property) {
            $row = $rows->get($property['key'])?->first();

            if ($row === null) {
                continue;
            }

            if ($property['action'] === 'remove') {
                $row->delete();
            } elseif ((string) $row->value === $property['from'] && $property['value'] !== $property['from']) {
                // Still this merge's value, untouched since: the next contributor's value replaces it.
                $row->update(['value' => $property['value']]);
            }
        }
    }

    /**
     * The destination's merges that still contribute tags and properties: every one not split (active or finalized),
     * oldest first, except `$exceptId`.
     *
     * @return Collection<int, AssetMerge>
     */
    private function contributingMerges(int $destId, ?int $exceptId): Collection
    {
        return AssetMerge::query()->where('dest_id', $destId)->whereNull('split_at')
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('id')->get();
    }

    /** @return list<int> */
    private static function tagContributions(AssetMerge $merge): array
    {
        return array_map('intval', $merge->snapshot['tags_contributed'] ?? []);
    }

    /** @return list<array{key: string, name: string, value: string, inserted: bool}> */
    private static function propertyContributions(AssetMerge $merge): array
    {
        return array_values($merge->snapshot['properties_contributed'] ?? []);
    }

    /**
     * Finalizes one active merge. Irreversible: split ends, the destination keeps the linked slots as its own and gets
     * a copy of the source's comment threads, and the source goes to the trash, still a merge source, so the normal
     * purge reclaims it after the retention period. Its blobs stay while the destination's slots name them.
     */
    public function finalize(AssetMerge $merge, Admin $actor, string $via): AssetMerge
    {
        Event::dispatch(self::EVENT_LOCKING, ['finalize', (int) $merge->dest_id, (int) $merge->id]);

        return DB::transaction(function () use ($merge, $actor, $via): AssetMerge {
            $locked = $this->lockAssets([(int) $merge->dest_id, (int) $merge->source_id]);
            $merge = AssetMerge::query()->lockForUpdate()->findOrFail($merge->id);
            if (! $merge->isActive() || ! $locked->has($merge->source_id) || ! $locked->has($merge->dest_id)) {
                MergeGate::refuse(status: 409, reason: 'not-active');
            }
            $source = $locked->get($merge->source_id);
            $dest = $locked->get($merge->dest_id);
            $fresh = $this->assertStillMayChangeDestination(actor: $actor, via: $via, action: 'finalize', dest: $dest);
            $this->gate->assertOwnsEvery(actor: $fresh, assets: [$dest, $source]);

            $linkedSlots = Attachment::query()->where('dam_asset_id', $merge->dest_id)->where('merge_id', $merge->id)->pluck('id');
            Attachment::query()->where('dam_asset_id', $merge->dest_id)->where('merge_id', $merge->id)->update(['merge_id' => null]);
            $comments = $this->copyComments(merge: $merge, source: $source);

            // The source's own preview proxy is never linked (UNLINKED_ROLES), so a linked video makes its own. Only
            // once the merge can no longer be split: a rendition naming a linked revision would pin the slot to the
            // destination (historyPinned()).
            self::inChunks(
                ids: $linkedSlots->all(),
                read: fn (array $chunk) => AssetFile::query()->whereIn('attachment_id', $chunk)->where('is_current', true)->get(),
            )->each(fn (AssetFile $file) => GenerateVideoPreviewProxy::dispatchForMember($file));
            $merge->forceFill([
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
                'snapshot'     => array_merge($merge->snapshot ?? [], ['finalized' => ['comments_copied' => $comments]]),
            ])->save();

            if (! $source->trashed()) {
                $source->trashedBy((int) $actor->id)->delete();
            }

            MergeLog::write(event: 'merge.finalized', ctx: ['merge' => (int) $merge->id, 'dest' => (int) $merge->dest_id, 'source' => (int) $merge->source_id, 'comments' => $comments]);

            return $merge;
        });
    }

    /**
     * Copies the source's comment threads onto the destination before the source can be purged (P3b-1 review, lead
     * decision): each comment keeps its author, text, timestamps and place in its thread (a reply stays a reply to the
     * copy of its parent), and records the merge, the comment it copies and the source's name, which the comments tab
     * shows as "From <name>". Written as rows, not models: these are not new comments, so they add no history entries.
     * Returns how many were copied.
     */
    private function copyComments(AssetMerge $merge, Asset $source): int
    {
        $name = (string) ($source->display_name ?: ($merge->snapshot['source']['display_name'] ?? $source->file_name));
        $copies = [];

        // Oldest first: a reply is always newer than its parent, so its parent's copy already exists.
        foreach (AssetComments::query()->where('dam_asset_id', $source->id)->orderBy('id')->get() as $comment) {
            $copies[(int) $comment->id] = (int) DB::table('dam_asset_comments')->insertGetId([
                'dam_asset_id'      => $merge->dest_id,
                'admin_id'          => $comment->admin_id,
                'parent_id'         => $comment->parent_id === null ? null : ($copies[(int) $comment->parent_id] ?? null),
                'comments'          => $comment->comments,
                'merge_id'          => $merge->id,
                'origin_comment_id' => $comment->id,
                'merged_from_name'  => mb_substr($name, 0, 255),
                'created_at'        => $comment->getRawOriginal('created_at'),
                'updated_at'        => $comment->getRawOriginal('updated_at'),
            ]);
        }

        return count($copies);
    }

    /**
     * Revokes every active link whose target is the merged source. The links stay on the source and keep resolving
     * to its own slots on the destination until someone revokes them (operator decision Q4); a revoked link stays
     * revoked through a split. Needs sharing authority on the source, asked again under the lock. Returns how many
     * were revoked.
     */
    public function revokeSourceLinks(AssetMerge $merge, Admin $actor, string $via): int
    {
        Event::dispatch(self::EVENT_LOCKING, ['revoke', (int) $merge->dest_id, (int) $merge->id]);

        $count = DB::transaction(function () use ($merge, $actor, $via): int {
            $locked = $this->lockAssets([(int) $merge->dest_id, (int) $merge->source_id]);
            $merge = AssetMerge::query()->lockForUpdate()->findOrFail($merge->id);
            if (! $merge->isActive() || ! $locked->has($merge->source_id) || ! $locked->has($merge->dest_id)) {
                MergeGate::refuse(status: 409, reason: 'not-active');
            }
            $fresh = $this->assertStillMayChangeDestination(actor: $actor, via: $via, action: 'revoke', dest: $locked->get($merge->dest_id));

            if (! $this->gate->canShare(actor: $fresh, source: $locked->get($merge->source_id))) {
                abort(response()->json(['success' => false, 'message' => trans('dam::app.admin.dam.access.not-owner')], 403));
            }

            return Share::query()->active()
                ->where('share_type', Share::TYPE_ASSET)
                ->where('target_id', $merge->source_id)
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        });

        MergeLog::write(event: 'merge.links_revoked', ctx: ['merge' => (int) $merge->id, 'source' => (int) $merge->source_id, 'count' => $count, 'actor' => (int) $actor->id]);

        return $count;
    }

    /** The second pass of split, finalize and revoke: the actor as stored now may still change the destination. */
    private function assertStillMayChangeDestination(Admin $actor, string $via, string $action, Asset $dest): Admin
    {
        $fresh = MergeGate::freshActor($actor);
        abort_if($fresh === null, 403, trans('dam::app.admin.permissions.denied-merge'));
        $this->gate->assertStillMayChange(actor: $fresh, via: $via, action: $action, assets: [$dest]);

        return $fresh;
    }

    /**
     * Reads `$ids` ID_CHUNK at a time, in ascending id order, so no query binds more than ID_CHUNK of them.
     *
     * @param  array<int, int|string>  $ids
     * @param  callable(list<int>): Collection<int, mixed>  $read
     * @return Collection<int, mixed>
     */
    private static function inChunks(array $ids, callable $read): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $rows = collect();

        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            foreach ($read($chunk) as $row) {
                $rows->push($row);
            }
        }

        return $rows;
    }

    /**
     * The assets, locked in id order (ADR 0009: asset rows first), trashed ones included so the caller can refuse them.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Asset>
     */
    private function lockAssets(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return Asset::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockedForGraph()->get()->keyBy(fn (Asset $asset) => (int) $asset->id);
    }

    /**
     * Blobs a split stopped naming that nothing else names any more. A split only removes rows whose bytes the source
     * still holds, or that were copied back to it, so this is normally empty; it is the safety net.
     *
     * @param  list<int>  $objectIds
     * @return list<int> tombstone ids
     */
    private function releaseUnreferenced(array $objectIds): array
    {
        // Sha order (ADR 0009) across every chunk: read the shas first, then lock ID_CHUNK rows at a time in that order.
        $ordered = self::inChunks(ids: $objectIds, read: fn (array $chunk) => FileObject::query()->whereIn('id', $chunk)->get(['id', 'sha256']))
            ->sortBy('sha256')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $objects = collect();

        foreach (array_chunk($ordered, self::ID_CHUNK) as $chunk) {
            foreach (FileObject::query()->whereIn('id', $chunk)->orderBy('sha256')->lockForUpdate()->get() as $object) {
                $objects->push($object);
            }
        }

        $locations = [];

        foreach ($objects as $object) {
            if ($object->isReferenced()) {
                continue;
            }

            $object->delete();
            $locations[] = ['disk' => $object->disk, 'path' => $object->path];
            $locations[] = ['disk' => $object->disk, 'path' => $this->store->objectPath($object->sha256)];
        }

        // Queued 150 locations at a time: each binds six values in the upsert and two in the read back.
        $tombstones = [];

        foreach (collect($locations)->unique(fn (array $location) => $location['disk']."\0".$location['path'])->values()->chunk(150) as $chunk) {
            array_push($tombstones, ...app(AssetDeletionService::class)->queueFileDeletions($chunk->values()->all()));
        }

        return $tombstones;
    }

    /** Slot positions 0..n-1 in their current order, with every revision's `sort` following its slot. */
    private function compactPositions(int $assetId): void
    {
        $slots = Attachment::query()->where('dam_asset_id', $assetId)->orderBy('position')->orderBy('id')->get(['id', 'position']);

        // Only a slot that moves is written, so a slot the split did not shift keeps its row exactly, updated_at included.
        foreach ($slots->values() as $position => $slot) {
            if ((int) $slot->position !== $position) {
                Attachment::query()->where('id', $slot->id)->update(['position' => $position]);
                AssetFile::query()->where('attachment_id', $slot->id)->update(['sort' => $position]);
            }
        }
    }

    /**
     * A folder deleted while the source was merged took the source's placement with it. The source comes back in the
     * Library root then, as a Restore from the trash does, so it is never a file no folder holds.
     */
    private function refileIfFolderless(Asset $source): void
    {
        if ($source->trashed() || DB::table('dam_asset_directory')->where('asset_id', $source->id)->exists()) {
            return;
        }

        $root = Directory::query()->whereNull('parent_id')->orderBy('id')->first();

        if ($root !== null) {
            $source->directories()->attach($root->id);
        }
    }

    /** After the destination's tags or properties change: audit the tag change and refresh its search words. */
    private function afterMetadataChange(int $assetId, bool $tagsChanged): void
    {
        if ($tagsChanged && ($asset = Asset::query()->find($assetId)) !== null) {
            Event::dispatch('core.model.proxy.sync.tag', [
                'old_values' => [],
                'new_values' => $asset->tags()->pluck('name')->all(),
                'model'      => $asset,
            ]);
        }

        AiIndexDispatcher::lexical([$assetId]);
    }

    /**
     * The checksums of every file the asset holds, current and past, sorted: what a merge and split must leave
     * identical.
     *
     * @return list<string>
     */
    public static function sha256Set(int $assetId): array
    {
        return DB::table('dam_asset_files')
            ->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
            ->where('dam_asset_files.dam_asset_id', $assetId)
            ->orderBy('dam_file_objects.sha256')
            ->pluck('dam_file_objects.sha256')
            ->map(fn ($sha) => (string) $sha)
            ->all();
    }

    /** A property's identity for reconciliation: its name, case-insensitively, in its language. */
    public static function propertyKey(AssetProperty $property): string
    {
        return mb_strtolower(trim((string) $property->name))."\0".(string) $property->language;
    }

    /**
     * Validates a split's choices: a map of destination slot id to `destination` or `source`.
     *
     * @return array<int, string>
     */
    public static function choices(mixed $input): array
    {
        if ($input === null) {
            return [];
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages(['choices' => [trans('dam::app.admin.dam.asset.merge.bad-choices')]]);
        }

        $choices = [];

        foreach ($input as $slotId => $choice) {
            if (! is_numeric($slotId) || ! in_array($choice, [self::KEEP_ON_DESTINATION, self::COPY_TO_SOURCE], true)) {
                throw ValidationException::withMessages(['choices' => [trans('dam::app.admin.dam.asset.merge.bad-choices')]]);
            }

            $choices[(int) $slotId] = $choice;
        }

        return $choices;
    }
}
