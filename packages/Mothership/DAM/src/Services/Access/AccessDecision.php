<?php

namespace Mothership\DAM\Services\Access;

use Mothership\DAM\Models\Asset;

/**
 * The seam's answer for one asset (P2 S2). `visible` decides between the asset and the 404 a missing id gets;
 * `allowed` decides whether a visible asset may be used for the context's purpose (403 when not). Availability,
 * the window (P2 S3) add `unavailable` and `expired`; trash (S4) adds `trashed`; merge (P3b-1) adds `merged`.
 */
final class AccessDecision
{
    public const REASON_MISSING = 'missing';

    public const REASON_HIDDEN = 'hidden';

    public const REASON_FORBIDDEN = 'forbidden';

    /** P2 S3: a draft, pending or archived asset, or one whose window has not opened, to a viewer it is not out for. */
    public const REASON_UNAVAILABLE = 'unavailable';

    /** P2 S3: an asset whose window has closed, to a viewer it is not out for. */
    public const REASON_EXPIRED = 'expired';

    /** P2 S4: an asset in the trash. Gone for every viewer, full admins included, until it is restored. */
    public const REASON_TRASHED = 'trashed';

    /** P3b-1: a merge source. Gone for every viewer, full admins included, exactly like a trashed asset, until a split. */
    public const REASON_MERGED = 'merged';

    private function __construct(
        public readonly bool $visible,
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?Asset $asset,
    ) {}

    public static function missing(): self
    {
        return new self(visible: false, allowed: false, reason: self::REASON_MISSING, asset: null);
    }

    public static function hidden(Asset $asset): self
    {
        return new self(visible: false, allowed: false, reason: self::REASON_HIDDEN, asset: $asset);
    }

    /** Not out for this viewer: answered like a missing asset, and the reason says why (unavailable or expired). */
    public static function unavailable(Asset $asset, string $reason): self
    {
        return new self(visible: false, allowed: false, reason: $reason, asset: $asset);
    }

    /** In the trash: answered like a missing asset on every Library and API route (a share link answers 410). */
    public static function trashed(Asset $asset): self
    {
        return new self(visible: false, allowed: false, reason: self::REASON_TRASHED, asset: $asset);
    }

    /** A merge source (P3b-1): answered like a missing asset on every Library and API route, as a trashed one is. */
    public static function merged(Asset $asset): self
    {
        return new self(visible: false, allowed: false, reason: self::REASON_MERGED, asset: $asset);
    }

    public static function forbidden(Asset $asset): self
    {
        return new self(visible: true, allowed: false, reason: self::REASON_FORBIDDEN, asset: $asset);
    }

    public static function allowed(Asset $asset): self
    {
        return new self(visible: true, allowed: true, reason: null, asset: $asset);
    }

    /** The asset, or the 404 a missing id gets: a hidden asset never reveals that it exists. */
    public function visibleOrFail(): Asset
    {
        abort_unless($this->visible && $this->asset !== null, 404);

        return $this->asset;
    }

    /** As visibleOrFail(), then 403 for a visible asset the purpose refuses. */
    public function allowedOrFail(): Asset
    {
        $asset = $this->visibleOrFail();

        abort_unless($this->allowed, 403, trans('dam::app.admin.permissions.unauthorized'));

        return $asset;
    }
}
