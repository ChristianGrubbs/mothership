<?php

declare(strict_types=1);

namespace Mothership\DAM\Processing;

use Mothership\User\Models\Admin;

/** What a person asked a batch to do: one value, since several of its fields are strings (P6a). */
final class BatchRequest
{
    /**
     * @param  string  $via  AccessContext::VIA_LIBRARY or VIA_API
     * @param  list<int>  $assetIds
     */
    public function __construct(
        public readonly Admin $requester,
        public readonly string $via,
        public readonly string $recipe,
        public readonly mixed $params,
        public readonly array $assetIds,
        public readonly string $saveAs,
    ) {}
}
