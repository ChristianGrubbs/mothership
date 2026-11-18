<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

/**
 * What one machine write did, or in a dry run would do, to one asset: written, skipped (already so, or nothing to
 * do) or refused, with a short reason code. `before` and `after` are display text for the run log and the dry-run
 * table, never sent to a log sink.
 */
final class WriteOutcome
{
    public const WRITTEN = 'written';

    public const SKIPPED = 'skipped';

    public const REFUSED = 'refused';

    /** A person's value: manual wins (§3.4). */
    public const REASON_MANUAL = 'manual';

    /** A person removed this value; no machine writer puts it back. */
    public const REASON_REMOVED = 'removed';

    /** A machine value of higher precedence is there (an AI writer never replaces a rule or mapping value). */
    public const REASON_PRECEDENCE = 'higher_precedence';

    public const REASON_PRESENT = 'present';

    public const REASON_UNCHANGED = 'unchanged';

    /** Fill-empty mode, and the value is already set by another machine writer. */
    public const REASON_NOT_EMPTY = 'not_empty';

    public const REASON_INVALID = 'invalid_value';

    public const REASON_TAG_NAME = 'tag_name_refused';

    public const REASON_TARGET_MISSING = 'target_missing';

    /** The rule's owner may not edit this asset, or lacks the action's permission. */
    public const REASON_NO_ACCESS = 'no_access';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $reason,
        public readonly ?string $before,
        public readonly ?string $after,
    ) {}

    public static function written(?string $before, ?string $after): self
    {
        return new self(outcome: self::WRITTEN, reason: null, before: $before, after: $after);
    }

    public static function skipped(string $reason, ?string $before, ?string $after = null): self
    {
        return new self(outcome: self::SKIPPED, reason: $reason, before: $before, after: $after);
    }

    public static function refused(string $reason, ?string $before = null, ?string $after = null): self
    {
        return new self(outcome: self::REFUSED, reason: $reason, before: $before, after: $after);
    }

    /** @return array{outcome: string, reason: ?string, before: ?string, after: ?string} */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'reason' => $this->reason, 'before' => $this->before, 'after' => $this->after];
    }
}
