<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Stack;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\Attachment;

/**
 * The stack-member set of one asset: the current revision of every file slot except the roles in
 * `Asset::UNSTACKED_ROLES` (version, proxy, thumbnail), main (representative) slot first, then attachment position,
 * then file id. A superseded revision, a slot of another asset and a slot whose bytes are gone are never members.
 *
 * Share links (SharedFiles) and the Creative Hub (open/save every file of a stack) both read this one query, so the two
 * can never disagree about what a stack holds. This class holds no access policy: the caller decides who may see the
 * members.
 *
 * Merge sources are NOT handled here. A merge source's slots live on its destination, and which of them the source
 * hands out is the filtered set owned by MergedSourceLinks (commit 68632ef4c). Callers that may meet a merge source
 * go through SharedFiles::forAsset(), which asks MergedSourceLinks first. For a merged asset this service fails closed:
 * it answers an empty set and null, never the source's own (moved) slots.
 */
class StackMembers
{
    /** @return Collection<int, AssetFile> */
    public function forAsset(Asset $asset): Collection
    {
        if ($asset->isMerged()) {
            return new Collection;
        }

        $main = $this->mainAttachmentId($asset);

        return $this->memberQuery($asset)
            ->get()
            ->filter(fn (AssetFile $file) => $file->fileObject !== null)
            ->sortBy(fn (AssetFile $file) => [(int) $file->attachment_id === $main ? 0 : 1, (int) $file->attachment->position, (int) $file->id])
            ->values();
    }

    /**
     * The member whose current revision is this AssetFile row, or null (never another revision of its slot): a
     * superseded revision, a row of another asset, a generated role and a merged asset all answer null. It reads
     * memberQuery(), the query forAsset() reads, so the two cannot disagree about what a member is.
     */
    public function findFile(Asset $asset, int $fileId): ?AssetFile
    {
        if ($asset->isMerged() || $fileId < 1) {
            return null;
        }

        $file = $this->memberQuery($asset)->whereKey($fileId)->first();

        return $file !== null && $file->fileObject !== null ? $file : null;
    }

    /** The member in this slot, or null (never the main file in its place). */
    public function find(Asset $asset, int $attachmentId): ?AssetFile
    {
        return $this->forAsset($asset)->first(fn (AssetFile $file) => (int) $file->attachment_id === $attachmentId);
    }

    /** @return Builder<AssetFile> */
    private function memberQuery(Asset $asset): Builder
    {
        return AssetFile::query()
            ->where('dam_asset_id', $asset->id)
            ->where('is_current', true)
            ->whereNotIn('role', Asset::UNSTACKED_ROLES)
            ->whereHas('attachment', fn ($q) => $q
                ->where('dam_asset_id', $asset->id)
                ->whereNotIn('role', Asset::UNSTACKED_ROLES))
            ->with(['attachment', 'fileObject']);
    }

    /** The main (representative) slot of a non-merged asset, or null when none is left. */
    public function mainAttachmentId(Asset $asset): ?int
    {
        if ($asset->isMerged()) {
            return null;
        }

        $id = Attachment::query()->where('dam_asset_id', $asset->id)->where('is_representative', true)->value('id');

        return $id === null ? null : (int) $id;
    }
}
