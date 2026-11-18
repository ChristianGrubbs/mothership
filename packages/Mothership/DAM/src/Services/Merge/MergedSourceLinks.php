<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Merge;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\AssetMerge;
use Mothership\DAM\Models\FileObject;

/**
 * A merge source's own share links while the merge is active (P3b-1; operator decision Q4). The links stay on the
 * source and keep working until someone revokes them, but they resolve only to the slots that came from that source,
 * now on the destination: never to the destination's own files or another source's. The public viewer asks this
 * service and changes nothing else, so the link logic lives here, not in the controller.
 *
 * - The link's default file is the slot that came from the source's representative, at its current revision.
 * - Its other files (OD5) are the current revisions of the other slots that came from it, never a generated one (files()).
 * - A destination in the trash, or not out for use, closes the link as the destination's own link would be closed.
 * - Everything derived from the bytes (DNG preview, model capture, web page bundle) reads them through contentView().
 *
 * Every method answers null for an asset that is not a merge source, so the caller keeps its own behaviour.
 */
class MergedSourceLinks
{
    /** The status a merge source's link answers with when its destination is closed (410/404), or null when it is open. */
    public function status(Asset $asset): ?int
    {
        $merge = $this->activeMerge($asset);

        if ($merge === null) {
            return $asset->isMerged() ? 410 : null;
        }

        $destination = $merge->destination;

        if ($destination === null || $destination->trashed()) {
            return 410;
        }

        return match ($destination->unavailableBecause()) {
            null                                                          => null,
            Asset::AVAILABILITY_DRAFT, Asset::AVAILABILITY_PENDING_REVIEW => 404,
            default                                                       => 410,
        };
    }

    /**
     * The bytes a merge source's link serves as its default file: the current revision of the destination slot that
     * came from the source's representative. A FileObject, false when the source is merged but that slot is gone or is
     * no longer one the link hands out (re-roled to a generated proxy or thumbnail: handedOut()), so the link serves
     * nothing, or null when the asset is not a merge source.
     */
    public function defaultObject(Asset $asset): FileObject|false|null
    {
        if (! $asset->isMerged()) {
            return null;
        }

        $representative = $this->representativeSlotId($asset);
        $file = $this->handedOut($asset)->first(fn (AssetFile $file) => $file->attachment->origin_attachment_id === $representative);

        return $file?->fileObject ?? false;
    }

    /**
     * The asset as a merge source's link must read it, for everything derived from its bytes (a DNG preview, a model
     * capture, a web page bundle, cover art): a copy of the source whose original is the current revision of the slot
     * that came from it. When that revision is not the source's own original any more (the destination revised it
     * after the merge), the copy drops what was derived from the old bytes and recorded on the asset (its web page
     * bundle, cover art and captured thumbnail), so nothing stale is served; sha-keyed derivatives follow the new bytes
     * on their own. False when the slot is gone (the link serves nothing: 404), null when the asset is not a merge
     * source.
     */
    public function contentView(Asset $asset): Asset|false|null
    {
        $object = $this->defaultObject($asset);

        if ($object === null || $object === false) {
            return $object;
        }

        $view = clone $asset;
        $original = new AssetFile(['dam_asset_id' => $asset->id, 'file_object_id' => $object->id, 'role' => AssetFile::ROLE_ORIGINAL, 'is_current' => true]);
        $original->setRelation('fileObject', $object);
        $view->setRelation('files', new EloquentCollection([$original]));

        if ($this->ownOriginalSha($asset) !== $object->sha256) {
            $meta = is_array($asset->meta_data) ? $asset->meta_data : [];
            unset($meta['web_bundle'], $meta['cover_art_path'], $meta['thumbnail_path']);
            $view->setAttribute('meta_data', $meta);
        }

        return $view;
    }

    /**
     * The checksum a model capture of a merge source's link must be bound to: the slot's current bytes, so a capture of
     * the source's own original is served only while the slot still holds those bytes. Null when not a merge source.
     */
    public function modelChecksum(Asset $asset): ?string
    {
        $object = $this->defaultObject($asset);

        return $object instanceof FileObject ? (string) $object->sha256 : null;
    }

    /** The checksum of the source's own original, as ModelThumbnailService binds a capture: its first original row. */
    private function ownOriginalSha(Asset $asset): ?string
    {
        $sha = AssetFile::query()->join('dam_file_objects', 'dam_file_objects.id', '=', 'dam_asset_files.file_object_id')
            ->where('dam_asset_files.dam_asset_id', $asset->id)->where('dam_asset_files.role', AssetFile::ROLE_ORIGINAL)
            ->orderBy('dam_asset_files.id')->value('dam_file_objects.sha256');

        return is_string($sha) ? $sha : null;
    }

    /**
     * OD5: every file a merge source's link hands out: the current revision of each destination slot that came from the
     * source, never a generated proxy or thumbnail, main slot first, then the destination's slot order. Null when the
     * asset is not a merge source.
     *
     * @return Collection<int, AssetFile>|null
     */
    public function files(Asset $asset): ?Collection
    {
        if (! $asset->isMerged()) {
            return null;
        }

        $main = $this->mainSlotId($asset);

        return $this->handedOut($asset)
            ->sortBy(fn (AssetFile $file) => [(int) $file->attachment_id === $main ? 0 : 1, (int) $file->attachment->position, (int) $file->id])
            ->values();
    }

    /** The destination slot that came from the source's representative (its link's main file), or null. */
    public function mainSlotId(Asset $asset): ?int
    {
        if (! $asset->isMerged()) {
            return null;
        }

        $representative = $this->representativeSlotId($asset);
        $file = $this->handedOut($asset)->first(fn (AssetFile $file) => $file->attachment->origin_attachment_id === $representative);

        return $file === null ? null : (int) $file->attachment_id;
    }

    /**
     * The one set a merge source's link may serve from (OD5): the slots it contributed, minus any later re-roled to a
     * generated proxy, thumbnail or version. The default file, its derivatives (contentView()) and every listed file all
     * resolve through it, so a slot SharedFiles excludes is never served under another route.
     *
     * @return Collection<int, AssetFile>
     */
    private function handedOut(Asset $asset): Collection
    {
        return $this->contributed($asset)
            ->reject(fn (AssetFile $file) => in_array($file->role, Asset::UNSTACKED_ROLES, true)
                || in_array($file->attachment->role, Asset::UNSTACKED_ROLES, true))
            ->values();
    }

    /** @return Collection<int, AssetFile> the current revisions of the slots the source gave, in slot order */
    private function contributed(Asset $asset): Collection
    {
        $merge = $this->activeMerge($asset);

        if ($merge === null) {
            return collect();
        }

        return AssetFile::query()
            ->where('dam_asset_id', $merge->dest_id)
            ->where('is_current', true)
            ->whereHas('attachment', fn ($q) => $q->where('dam_asset_id', $merge->dest_id)->where('merge_id', $merge->id))
            ->with(['attachment', 'fileObject'])
            ->get()
            ->filter(fn (AssetFile $file) => $file->fileObject !== null)
            ->sortBy(fn (AssetFile $file) => [$file->attachment->position, $file->id])
            ->values();
    }

    private function activeMerge(Asset $asset): ?AssetMerge
    {
        if (! $asset->isMerged()) {
            return null;
        }

        return AssetMerge::query()->active()->where('source_id', $asset->id)->where('dest_id', $asset->merged_into_id)->with('destination')->first();
    }

    private function representativeSlotId(Asset $asset): ?int
    {
        $id = $asset->attachments()->where('is_representative', true)->value('id');

        return $id === null ? null : (int) $id;
    }
}
