<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Models\ChunkUpload;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\User\Models\Admin;

/**
 * Derives the actor of an event on the server (§3.6.2). Staff are keyed by admin id; a link visitor by the
 * daily-salted hash of the link, their IP's /24 and browser family, with no cookie. A browser sending Do Not
 * Track or Global Privacy Control, and an admin who opted out, are counted on the aggregate-only path.
 */
final class ActorResolver
{
    /** @var array<int, bool> link id (negative: upload request) => whether our own account created it */
    private array $internalLinks = [];

    public function __construct(
        private readonly OptOuts $optOuts,
        private readonly DailySalt $salt,
    ) {}

    /** The signed-in admin of the current request, or the system when there is none (a job, a command). */
    public function current(): AnalyticsActor
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin ? $this->forAdmin(admin: $admin, request: $this->request()) : AnalyticsActor::system();
    }

    /**
     * An admin named by id, as a job knows its owner; the system when the id is gone. A queue worker or command has
     * only Laravel's console request, whose "Symfony" user agent reads as a script: it carries no signal, so it is
     * not consulted, and the owner's opt-out alone decides.
     */
    public function forAdminId(?int $adminId): AnalyticsActor
    {
        $admin = $adminId === null ? null : Admin::query()->find($adminId);

        return $admin instanceof Admin
            ? $this->forAdmin(admin: $admin, request: app()->runningInConsole() ? null : $this->request())
            : AnalyticsActor::system();
    }

    /**
     * Who sent a chunked upload. A guest's portal upload is owned by the request's creator but sent by the guest: it
     * counts as a portal visitor in the request's own namespace (negative ids). Read with the completing request and carried by the finalize job, which has none. Never throws,
     * not even resolving the resolver: a failed lookup counts as the system rather than failing the upload.
     */
    public static function forChunkUpload(ChunkUpload $upload, ?Request $request): AnalyticsActor
    {
        try {
            $actors = app(self::class);

            if ($upload->isGuest()) {
                $requestId = UploadSubmission::query()->whereKey($upload->upload_submission_id)->value('upload_request_id');

                return $requestId === null ? AnalyticsActor::system() : $actors->forLinkVisitor(shareId: -(int) $requestId, request: $request);
            }

            $admin = $upload->user_id === null ? null : Admin::query()->find($upload->user_id);

            return $admin instanceof Admin ? $actors->forAdmin(admin: $admin, request: $request) : AnalyticsActor::system();
        } catch (\Throwable $error) {
            report($error);

            return AnalyticsActor::system();
        }
    }

    public function forAdmin(Admin $admin, ?Request $request): AnalyticsActor
    {
        $actor = new AnalyticsActor(
            type: AnalyticsActor::ADMIN,
            key: (string) $admin->id,
            roleId: $admin->role_id === null ? null : (int) $admin->role_id,
            suspectedBot: self::suspectedBot(request: $request),
        );
        $actor = $actor->asInternal(internal: $admin->isApiUser());

        if ($this->optOuts->has((int) $admin->id)) {
            return $actor->toAggregate(reason: AnalyticsActor::REASON_OPTED_OUT);
        }

        return self::privacySignal(request: $request) ? $actor->toAggregate(reason: AnalyticsActor::REASON_PRIVACY_SIGNAL) : $actor;
    }

    public function forLinkVisitor(int $shareId, ?Request $request): AnalyticsActor
    {
        $suspectedBot = self::suspectedBot(request: $request);
        $internal = $this->isInternalLink(shareId: $shareId);
        $anonymous = (new AnalyticsActor(type: AnalyticsActor::LINK_VISITOR, key: null, aggregateOnly: true, reason: AnalyticsActor::REASON_PRIVACY_SIGNAL, suspectedBot: $suspectedBot))
            ->asInternal(internal: $internal);

        if (self::privacySignal(request: $request)) {
            return $anonymous;
        }

        $key = $this->salt->visitorKey(
            shareId: $shareId,
            truncatedIp: SecurityEventRecorder::truncateIp(ip: $request?->ip()),
            browserFamily: SecurityEventRecorder::userAgentFamily(userAgent: $request?->userAgent()),
        );

        return $key === null
            ? $anonymous->toAggregate(reason: AnalyticsActor::REASON_NO_SALT)
            : (new AnalyticsActor(type: AnalyticsActor::LINK_VISITOR, key: $key, suspectedBot: $suspectedBot))->asInternal(internal: $internal);
    }

    /**
     * Whether one of our own accounts created the link (a negative id is an upload request): an API account, as the
     * agents' headless uploads use, or a deployment-listed QA account. Its visitors are our automation, not the
     * people the link was for.
     */
    private function isInternalLink(int $shareId): bool
    {
        if (isset($this->internalLinks[$shareId])) {
            return $this->internalLinks[$shareId];
        }

        $creator = $shareId < 0
            ? DB::table('dam_upload_requests')->where('id', -$shareId)->value('created_by')
            : DB::table('dam_shares')->where('id', $shareId)->value('created_by');
        $internal = $creator !== null && (
            in_array((int) $creator, (array) config('dam_events.internal_link_owner_ids', []), true)
            || Admin::query()->whereKey((int) $creator)->value('type') === 'api'
        );

        return $this->internalLinks[$shareId] = $internal;
    }

    /** DNT: 1 or Sec-GPC: 1 (§3.6.7). */
    public static function privacySignal(?Request $request): bool
    {
        return $request !== null && ($request->header('DNT') === '1' || $request->header('Sec-GPC') === '1');
    }

    /** A crawler or script user agent: kept raw, excluded from every count (§3.6.4). */
    public static function suspectedBot(?Request $request): bool
    {
        return in_array(SecurityEventRecorder::userAgentFamily(userAgent: $request?->userAgent()), ['Bot', 'Script'], true);
    }

    /** The current HTTP request. In a queue worker this is Laravel's synthetic console request (see forAdminId()). */
    private function request(): ?Request
    {
        return app()->bound('request') ? request() : null;
    }
}
