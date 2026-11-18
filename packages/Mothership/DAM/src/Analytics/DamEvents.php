<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mothership\DAM\Support\AnalyticsLog;
use Mothership\DAM\Support\RequestId;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The server-side emitter (P1b, §3.6.3-§3.6.5): one DamEvents::emit() call at each code path that serves, creates
 * or settles something. It derives the actor, validates the props against the registry, and writes a raw row, the
 * one terminal row of an operation, or a count on the aggregate-only path. It never throws into its caller: a lost
 * analytics event is logged to analytics.jsonl and the request, job or download goes on.
 */
final class DamEvents
{
    /** The first time inside the window for this actor and subject: a counted view, visit or download. */
    public const COUNTED = 'counted';

    /** Recorded, but inside the window (a refresh, a retry): not counted again, here or on a link's own counter. */
    public const REPEAT = 'repeat';

    /** Recorded, by a suspected bot: kept out of every analytics count; a link's own counter is not analytics. */
    public const BOT = 'bot';

    /** Counted on the aggregate-only path: no raw row, no key. */
    public const AGGREGATE = 'aggregate';

    /** Not recorded: analytics off, or the write failed. */
    public const SKIPPED = 'skipped';

    /** Session id header the collector adds to the app's own requests, so server events join the client's session. */
    public const SESSION_HEADER = 'X-Dam-Sid';

    /** Capture request-origin correlation for asynchronous work without making analytics a business dependency. */
    public static function requestReplaySession(): ?string
    {
        try {
            return ReplaySession::forActor(actor: app(self::class)->actors->current(),
                value: app()->bound('request') ? request()->header(ReplaySession::HEADER) : null);
        } catch (Throwable $error) {
            AnalyticsLog::write(level: 'error', event: 'replay.context_failed', ctx: ['exception' => $error::class]);

            return null;
        }
    }

    /** Namespace of the derived event ids (attempts and terminal events, §3.6.5). */
    private const ID_NAMESPACE = '6f1e2b8c-4d3a-5e7f-9a0b-1c2d3e4f5a6b';

    public function __construct(
        private readonly ActorResolver $actors,
        private readonly EventStore $store,
    ) {}

    /**
     * @param  ?string  $subjectType  only for a `target` subject (a share's asset, folder or collection); otherwise the registry's
     * @param  ?string  $businessId  the operation an attempt or terminal event belongs to (an upload, a job)
     * @param  ?string  $attempt  which try an attempt is; a replay of the same try is the same event
     * @param  array<string, int|bool|string>  $props
     */
    public static function emit(
        DamEvent $event,
        ?int $subjectId = null,
        ?string $subjectType = null,
        array $props = [],
        ?string $surface = null,
        ?AnalyticsActor $actor = null,
        ?int $shareId = null,
        ?int $attachmentId = null,
        ?string $businessId = null,
        ?string $attempt = null,
        ?string $replaySessionId = null,
    ): string {
        try {
            return app(self::class)->record(
                event: $event, subjectId: $subjectId, subjectType: $subjectType, props: $props, surface: $surface,
                actor: $actor, shareId: $shareId, attachmentId: $attachmentId, businessId: $businessId, attempt: $attempt,
                replaySessionId: $replaySessionId,
            );
        } catch (Throwable $e) {
            AnalyticsLog::write(level: 'error', event: 'analytics.emit_failed', ctx: ['name' => $event->value, 'exception' => $e::class]);

            return self::SKIPPED;
        }
    }

    /** @param array<string, int|bool|string> $props */
    public function record(
        DamEvent $event,
        ?int $subjectId,
        ?string $subjectType,
        array $props,
        ?string $surface,
        ?AnalyticsActor $actor,
        ?int $shareId,
        ?int $attachmentId,
        ?string $businessId,
        ?string $attempt,
        ?string $replaySessionId = null,
    ): string {
        if (! config('dam_events.enabled')) {
            return self::SKIPPED;
        }

        $name = $event->value;
        $definition = $event->definition();
        $validated = EventRegistry::validateProps(name: $name, props: $props);

        if ($validated === null || $definition['emitter'] !== 'server') {
            AnalyticsLog::write(level: 'error', event: 'analytics.emit_rejected', ctx: ['name' => $name]);

            return self::SKIPPED;
        }

        $subjectType = match ($definition['subject']) {
            null     => null,
            'target' => in_array($subjectType, ['asset', 'directory', 'collection'], true) ? $subjectType : null,
            default  => $definition['subject'],
        };
        $subjectId = $subjectType === null ? null : $subjectId;
        $requestActor = $this->actors->current();
        $actor ??= $requestActor;
        $requestReplay = $actor->isAdmin() && $requestActor->isAdmin() && $actor->key === $requestActor->key
            && app()->bound('request') ? request()->header(ReplaySession::HEADER) : null;
        $replaySessionId = ReplaySession::forActor(actor: $actor, value: $replaySessionId ?? $requestReplay);
        if ($replaySessionId !== null) {
            $owner = $this->store->replaySessionOwners([$replaySessionId])[$replaySessionId] ?? null;
            $replaySessionId = $owner === null || $owner === $actor->key ? $replaySessionId : null;
        }
        $now = now();
        $outcome = $event->outcome();

        if ($actor->aggregateOnly || $actor->key === null) {
            $this->store->increment(slice: [
                'day'          => $now->toDateString(), 'name' => $name, 'outcome' => $outcome, 'actor_type' => $actor->type,
                'surface'      => (string) $surface, 'subject_type' => (string) $subjectType, 'subject_id' => (int) $subjectId,
                'share_id'     => (int) $shareId, 'detail' => self::detail(props: $validated[0]),
            ], bot: $actor->suspectedBot);

            return self::AGGREGATE;
        }

        $terminal = EventRegistry::terminalKey($name);
        $row = self::row(name: $name, actor: $actor, at: $now, fields: [
            'event_id'          => self::eventId(name: $name, terminal: $terminal, businessId: $businessId, attempt: $attempt),
            'session_id'        => $actor->isAdmin() ? $this->requestSession(actor: $actor) : null,
            'replay_session_id' => $replaySessionId,
            'surface'           => $surface,
            'subject_type'      => $subjectType,
            'subject_id'        => $subjectId,
            'attachment_id'     => $attachmentId,
            'share_id'          => $shareId,
            'outcome'           => $outcome,
            'props'             => $validated[0],
            'emitter'           => 'server',
        ]);

        $window = EventRegistry::window($name);
        $repeat = ($window !== null && $this->store->isRepeat(
            name: $name, actorKey: (string) $actor->key, subjectType: $subjectType, subjectId: $subjectId,
            shareId: $shareId, attachmentId: $attachmentId, at: $now, windowMinutes: $window,
        ));

        $terminal !== null ? $this->store->writeTerminal(row: $row) : $this->store->insertRaw(rows: [$row]);

        return $repeat ? self::REPEAT : ($actor->suspectedBot ? self::BOT : self::COUNTED);
    }

    /**
     * A dam_events row from the envelope fields (§3.6.1); shared with ingest so both emitters store one shape.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function row(string $name, AnalyticsActor $actor, Carbon $at, array $fields): array
    {
        $props = $fields['props'] ?? [];

        return [
            'event_id'          => $fields['event_id'],
            'name'              => $name,
            'v'                 => (int) EventRegistry::definition($name)['v'],
            'occurred_at'       => EventStore::timestamp($at),
            'received_at'       => EventStore::timestamp(now()),
            'session_id'        => $fields['session_id'] ?? null,
            'replay_session_id' => $fields['replay_session_id'] ?? null,
            'seq'               => $fields['seq'] ?? null,
            'actor_type'        => $actor->type,
            'actor_key'         => (string) $actor->key,
            'actor_role_id'     => $actor->roleId,
            'surface'           => $fields['surface'] ?? null,
            'subject_type'      => $fields['subject_type'] ?? null,
            'subject_id'        => $fields['subject_id'] ?? null,
            'attachment_id'     => $fields['attachment_id'] ?? null,
            'share_id'          => $fields['share_id'] ?? null,
            'outcome'           => $fields['outcome'],
            'request_id'        => RequestId::current(),
            'duration_ms'       => $fields['duration_ms'] ?? null,
            'props'             => $props === [] ? null : json_encode($props, JSON_THROW_ON_ERROR),
            'emitter'           => $fields['emitter'],
            'suspected_bot'     => $actor->suspectedBot,
            'internal_actor'    => $actor->isInternal(),
        ];
    }

    /**
     * uuidv5 of name ‖ operation ‖ try for an attempt, of operation key ‖ operation ‖ "terminal" for a terminal
     * event (§3.6.5); a random id for everything else, which is recorded once by the code path that serves it.
     */
    public static function eventId(string $name, ?string $terminal, ?string $businessId, ?string $attempt): string
    {
        if ($businessId !== null && $terminal !== null) {
            return Uuid::uuid5(self::ID_NAMESPACE, $terminal.'|'.$businessId.'|terminal')->toString();
        }

        if ($businessId !== null && $attempt !== null) {
            return Uuid::uuid5(self::ID_NAMESPACE, $name.'|'.$businessId.'|'.$attempt)->toString();
        }

        return (string) Str::uuid();
    }

    /**
     * Whether serving this request is a new download: not a HEAD, and not a ranged request that resumes part-way
     * through the file (a download manager's later chunks).
     */
    public static function isNewDownload(?Request $request): bool
    {
        if ($request === null) {
            return true;
        }

        $range = (string) $request->header('Range', '');

        return ! $request->isMethod('HEAD') && ($range === '' || preg_match('/^bytes=0-/', $range) === 1);
    }

    /** The short code a count-only slice keeps (§3.6.7): an error code or the download route, never free text. */
    public static function detail(array $props): string
    {
        $detail = $props['error_code'] ?? $props['via'] ?? $props['filter'] ?? $props['metric'] ?? '';

        return is_string($detail) ? substr($detail, 0, 32) : '';
    }

    /**
     * The collector's session id on this request, when it is a uuid that no other actor has used: a session first
     * seen with a different actor is never joined (§3.6.2), and the event is kept without it.
     */
    private function requestSession(AnalyticsActor $actor): ?string
    {
        $sessionId = app()->bound('request') ? request()->header(self::SESSION_HEADER) : null;

        if (! is_string($sessionId) || ! Str::isUuid($sessionId)) {
            return null;
        }

        $sessionId = strtolower($sessionId);
        $owner = $this->store->sessionOwners([$sessionId])[$sessionId] ?? null;

        return $owner === null || $owner === $actor->key ? $sessionId : null;
    }
}
