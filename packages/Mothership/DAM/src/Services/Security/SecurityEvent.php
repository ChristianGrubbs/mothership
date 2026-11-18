<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Security;

/**
 * One row for dam_security_events, before the recorder adds what the request knows (IP, user agent family,
 * session, request id). `identifier` is the submitted login as typed; the recorder stores only its HMAC.
 */
final class SecurityEvent
{
    public const LOGIN_SUCCEEDED = 'auth.login.succeeded.v1';

    public const LOGIN_FAILED = 'auth.login.failed.v1';

    public const LOGIN_LOCKED_OUT = 'auth.login.locked_out.v1';

    public const LOGOUT_SUCCEEDED = 'auth.logout.succeeded.v1';

    public const PASSWORD_RESET = 'auth.password.reset.v1';

    /** An admin invited someone to set a first password, sent the invite again, or the invitee accepted it. */
    public const INVITE_SENT = 'auth.invite.sent.v1';

    public const INVITE_RESENT = 'auth.invite.resent.v1';

    public const INVITE_ACCEPTED = 'auth.invite.accepted.v1';

    public const REPORT_VIEWED = 'report.viewed.v1';

    /** folder-access.jsonl events that change who can reach what, and the security event each one becomes. */
    public const ACCESS_CHANGES = [
        'group_saved'           => 'access.group.saved.v1',
        'group_deleted'         => 'access.group.deleted.v1',
        'grants_saved'          => 'access.grants.saved.v1',
        'access_set'            => 'access.list.set.v1',
        'access_cascaded'       => 'access.list.cascaded.v1',
        'access_model_switched' => 'access.model.switched.v1',
    ];

    /**
     * A moved file or folder takes its destination's list in place of its own, which can widen who reaches it:
     * AccessListWriter records one of these per moved file, and one per moved folder for its subtree. The same
     * inheritance on a first placement (an upload, a new folder) is not a change of access and is not recorded.
     */
    public const ACCESS_ADOPTED_ON_MOVE = 'access.list.adopted_on_move.v1';

    /** P5a: a guest visit sent its first file to an upload request (actor: none; ctx: request and submission ids). */
    public const GUEST_SUBMISSION_CREATED = 'guest.submission.created.v1';

    /** P5a: staff accepted guest intake into the Library, or rejected it to the trash (ctx: request id, files). */
    public const GUEST_SUBMISSION_ACCEPTED = 'guest.submission.accepted.v1';

    public const GUEST_SUBMISSION_REJECTED = 'guest.submission.rejected.v1';

    /**
     * P5a: an upload-request token used against its rules (ctx `reason`): a password lockout, a forged or foreign
     * visit, a client-chosen destination, or a file sent to a request that is closed or expired.
     */
    public const GUEST_TOKEN_MISUSE = 'guest.portal.token_misuse.v1';

    /**
     * @param  array<string, mixed>  $ctx  filtered against SecurityEventRecorder::CTX_KEYS before it is stored
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $guard = null,
        public readonly ?int $actorAdminId = null,
        public readonly ?string $identifier = null,
        public readonly array $ctx = [],
    ) {}
}
