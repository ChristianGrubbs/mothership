<?php

declare(strict_types=1);

namespace Mothership\DAM\Listeners;

use Illuminate\Database\Eloquent\Model;
use Mothership\DAM\Services\CreativeHub\HubResourceStore;

/**
 * Removes a deleted admin's fonts and brushes before the admin row goes (ADR 0015), releasing each blob nothing else
 * holds. Without it the owner foreign key cascades the rows but leaves their FileObjects unreferenced (no tombstone,
 * so nothing reaps them), and on SQL Server (NO ACTION) the admin delete is refused.
 *
 * Registered on every driver, on the Admin model's `deleting` event, as DeleteAdminAccessRows is: every delete path
 * fires it, and AdminRepository::delete() runs it and the row delete in one transaction, so a vetoed or failed delete
 * keeps the resources. The blobs are unlinked only after that transaction commits.
 */
class ReleaseAdminHubResources
{
    public function __construct(private HubResourceStore $store) {}

    public function handle(Model $admin): void
    {
        $this->store->releaseOwnedBy(ownerId: (int) $admin->getKey());
    }
}
