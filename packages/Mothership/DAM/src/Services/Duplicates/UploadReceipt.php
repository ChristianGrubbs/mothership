<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Duplicates;

/**
 * What one upload published (P3b-2): the checksum of its bytes, and whether it made a new entry, a new version of a
 * same-name entry, or changed nothing because that entry already held these bytes.
 */
final class UploadReceipt
{
    public const CREATED = 'created';

    public const VERSIONED = 'versioned';

    public const UNCHANGED = 'unchanged';

    public const OUTCOMES = [self::CREATED, self::VERSIONED, self::UNCHANGED];

    public function __construct(
        public readonly int $assetId,
        public readonly string $sha256,
        public readonly string $outcome,
    ) {}
}
