<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\UploadRequests;

use Mothership\DAM\Support\GuestIntakeLog;

/**
 * P5a: the guest-intake funnel (portal opened → submission created → accepted | rejected), by ids only. Each step is
 * written to GuestIntakeLog today. The guest's actor key is the request id plus the submission id (§3.6.2); a visit
 * that has not sent a file yet has no submission, so `portal.opened` carries the request id alone. No cookie, no IP
 * and nothing the guest typed reaches an event (cookieless counting, §3.6.7, operator decision Q1).
 */
final class GuestIntakeFunnel
{
    public const PORTAL_OPENED = 'guest.portal.opened';

    public const SUBMISSION_CREATED = 'guest.submission.created';

    public const SUBMISSION_ACCEPTED = 'guest.submission.accepted';

    public const SUBMISSION_REJECTED = 'guest.submission.rejected';

    /**
     * @param  array{upload_request_id: int, submission_id?: int|null, asset_id?: int|null, files?: int, actor_admin_id?: int|null}  $ids
     */
    public static function emit(string $event, array $ids): void
    {
        GuestIntakeLog::write(event: $event, ctx: $ids, loc: 'guest-intake-funnel');

        // ---- P1b analytics hook: BEGIN -------------------------------------------------------------------------
        // P1b (claude/dam-p1b) owns the analytics collector. Wire the server emitter here and nowhere else: emit
        // $event with actor_type `guest_contributor` (or `admin` for accepted/rejected, from actor_admin_id) and
        // actor_key = upload_request_id ‖ submission_id, subject ids from $ids. Nothing else in P5a emits analytics.
        // ---- P1b analytics hook: END ---------------------------------------------------------------------------
    }
}
