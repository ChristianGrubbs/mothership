<?php

declare(strict_types=1);

namespace Mothership\DAM\Optimization;

use Mothership\DAM\Exceptions\ContentUnavailable;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetFile;
use Mothership\DAM\Models\FileObject;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Services\Stack\StackMembers;
use Mothership\DAM\Support\StackFileName;

/**
 * The file one optimization reads: the asset's main file (no slot), or one stacked file named by its slot. The
 * request, the job and the publication all resolve it here, so the three can never read different bytes.
 */
final class OptimizationSource
{
    private function __construct(
        public readonly FileObject $object,
        public readonly string $extension,
        public readonly string $fileName,
        public readonly ?AssetFile $member,
    ) {}

    /**
     * A slot that is the main file is the main file. A slot that is no longer a member of this asset (removed, or
     * now another asset's) throws ContentUnavailable, as a missing original does.
     */
    public static function resolve(Asset $asset, ?int $attachmentId): self
    {
        $members = app(StackMembers::class);

        if ($attachmentId === null || $attachmentId <= 0 || $attachmentId === $members->mainAttachmentId($asset)) {
            $object = app(ContentStore::class)->current($asset);

            return new self(object: $object, extension: strtolower((string) $asset->extension), fileName: (string) ($asset->file_name ?: 'source.'.$asset->extension), member: null);
        }

        $member = $members->find(asset: $asset, attachmentId: $attachmentId);
        $object = $member?->fileObject;

        if ($member === null || ! $object instanceof FileObject) {
            throw new ContentUnavailable(reason: ContentUnavailable::NO_ORIGINAL, assetId: (int) $asset->id);
        }

        $extension = StackFileName::extension($member, $asset);

        return new self(object: $object, extension: $extension, fileName: (string) ($member->label ?: 'source.'.$extension), member: $member);
    }

    /** The slot to store on the row: null for the main file. */
    public function attachmentId(): ?int
    {
        return $this->member === null ? null : (int) $this->member->attachment_id;
    }
}
