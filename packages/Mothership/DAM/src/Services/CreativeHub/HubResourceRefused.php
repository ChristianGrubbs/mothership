<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\CreativeHub;

use RuntimeException;

/**
 * A font or brush the Hub resource library will not take, or an operation on one it will not do (ADR 0015). The
 * reason is a stable code the HTTP layer maps to a translated message and a status: 403 for NOT_OWNER, 422 for every
 * other code. The message is plain English for logs and tests, never shown as is.
 */
final class HubResourceRefused extends RuntimeException
{
    public const EMPTY = 'empty';

    public const TOO_LARGE = 'too_large';

    public const FORBIDDEN = 'forbidden';

    public const UNKNOWN_FORMAT = 'unknown_format';

    public const UNPARSED = 'unparsed';

    /** A font collection (.ttc / .otc, `ttcf`): refused in v1, whatever its face count. */
    public const COLLECTION = 'collection';

    public const BAD_BRUSH = 'bad_brush';

    public const QUOTA_BYTES = 'quota_bytes';

    public const LICENSE_REQUIRED = 'license_required';

    public const BAD_VISIBILITY = 'bad_visibility';

    public const NOT_OWNER = 'not_owner';

    /** @param  array<string, int|string>  $context  numbers and codes only (limits, counts), never names or bytes */
    public function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
