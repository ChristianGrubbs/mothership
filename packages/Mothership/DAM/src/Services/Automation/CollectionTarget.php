<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Mothership\DAM\Models\Asset;
use Mothership\User\Models\Admin;

/**
 * Where a rule's "add to collection" action lands (P5b, D19). The bound implementation is CollectionsTarget, through
 * the P4c collection service.
 *
 * An implementation must check `redistribute` for the rule's owner per asset (roadmap §3.3: adding to a
 * collection with an audience needs the asset's owner, a full admin, or dam.asset.share plus sight of the asset),
 * add if absent with `$source`, record a person's removal as a tombstone it honours, and decide without writing
 * when `$dryRun`.
 */
interface CollectionTarget
{
    public function add(Asset $asset, int $collectionId, Admin $owner, string $source, bool $dryRun): WriteOutcome;
}
