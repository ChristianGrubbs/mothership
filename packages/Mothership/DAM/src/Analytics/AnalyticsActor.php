<?php

declare(strict_types=1);

namespace Mothership\DAM\Analytics;

/**
 * Who an event is about, always derived on the server (§3.6.2): from the admin session, from a share page's ingest
 * capability, or from the job's owner. A client never asserts any of it.
 *
 * `aggregateOnly` sends the event to the count-only path (DNT/GPC, an opted-out admin, no salt to key a visitor
 * with): no raw row, no key, no session, so nothing can be joined into a journey (§3.6.7).
 */
final class AnalyticsActor
{
    public const ADMIN = 'admin';

    public const LINK_VISITOR = 'link_visitor';

    public const SYSTEM = 'system';

    public const REASON_OPTED_OUT = 'opted_out';

    public const REASON_PRIVACY_SIGNAL = 'privacy_signal';

    public const REASON_NO_SALT = 'no_salt';

    public function __construct(
        public readonly string $type,
        public readonly ?string $key,
        public readonly ?int $roleId = null,
        public readonly bool $aggregateOnly = false,
        public readonly ?string $reason = null,
        public readonly bool $suspectedBot = false,
    ) {}

    /**
     * Our own automation or QA, exported as internal_actor so PostHog's test-account filter drops it: an API account,
     * or a visitor of a link one created. Not promoted, so a job serialized before it existed still unserializes.
     */
    private bool $internal = false;

    public static function system(): self
    {
        return new self(type: self::SYSTEM, key: 'system');
    }

    /** The same actor on the count-only path, with nothing that could identify or join it. */
    public function toAggregate(string $reason): self
    {
        return (new self(type: $this->type, key: null, roleId: null, aggregateOnly: true, reason: $reason, suspectedBot: $this->suspectedBot))
            ->asInternal(internal: $this->internal);
    }

    /**
     * The same actor with the signals its browser sent when the work was asked for, as a job carries them: a privacy
     * signal sends it to the count-only path, a script or crawler marks it a suspected bot.
     */
    public function withClientSignals(bool $privacySignal, bool $suspectedBot): self
    {
        $actor = $suspectedBot && ! $this->suspectedBot
            ? (new self(type: $this->type, key: $this->key, roleId: $this->roleId, aggregateOnly: $this->aggregateOnly, reason: $this->reason, suspectedBot: true))
                ->asInternal(internal: $this->internal)
            : $this;

        return $privacySignal && ! $actor->aggregateOnly ? $actor->toAggregate(reason: self::REASON_PRIVACY_SIGNAL) : $actor;
    }

    public function asInternal(bool $internal = true): self
    {
        if ($internal === $this->internal) {
            return $this;
        }

        $actor = clone $this;
        $actor->internal = $internal;

        return $actor;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function isAdmin(): bool
    {
        return $this->type === self::ADMIN && ! $this->aggregateOnly;
    }
}
