<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

/** The user-safe reasons an item is skipped or fails (dam::app.admin.batch.errors.*). */
final class ItemError
{
    /** Missing, hidden, trashed, merged or unavailable to the requester: one reason, so none of them leaks. */
    public const NOT_FOUND = 'not_found';

    /** Visible, but the requester may not change it (attach needs edit access to the source entry). */
    public const NOT_ALLOWED = 'not_allowed';

    public const NOT_APPLICABLE = 'not_applicable';

    public const SOURCE_UNAVAILABLE = 'source_unavailable';

    public const OVERLAY_UNAVAILABLE = 'overlay_unavailable';

    public const PROCESSING_FAILED = 'processing_failed';

    public const WORKER_FAILED = 'worker_failed';
}
