<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AssetCollection;
use Mothership\DAM\Models\CollectionMember;
use Mothership\DAM\Services\Collections\CollectionService;
use Mothership\User\Models\Admin;

/**
 * A rule's "add to collection" (P5b D19) through the P4c collection service, with the rights a person adding the
 * same asset by hand would need: the rule owner manages the collection and may redistribute the asset (roadmap
 * §3.3). A member a person removed stays removed; an existing member is left as it is.
 */
final class CollectionsTarget implements CollectionTarget
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly ValueDecisions $decisions,
    ) {}

    public function add(Asset $asset, int $collectionId, Admin $owner, string $source, bool $dryRun): WriteOutcome
    {
        $collection = AssetCollection::query()->find($collectionId);

        if ($collection === null) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_TARGET_MISSING);
        }

        if (! $this->collections->manages($collection, $owner) || $this->collections->redistributeRefusal(actor: $owner, assetId: (int) $asset->id) !== null) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_NO_ACCESS);
        }

        if (CollectionMember::query()->where('collection_id', $collectionId)->where('asset_id', $asset->id)->exists()) {
            return WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $collection->name, after: $collection->name);
        }

        if ($this->decisions->isRemoved(assetId: (int) $asset->id, kind: ValueDecisions::KIND_COLLECTION, targetId: $collectionId)) {
            return WriteOutcome::refused(reason: WriteOutcome::REASON_REMOVED);
        }

        if (! $dryRun) {
            $result = $this->collections->addMembers(collection: $collection, actor: $owner, assetIds: [(int) $asset->id], source: $source);

            if ($result['added'] === []) {
                return WriteOutcome::skipped(reason: WriteOutcome::REASON_PRESENT, before: $collection->name, after: $collection->name);
            }
        }

        return WriteOutcome::written(before: null, after: $collection->name);
    }
}
