<?php

declare(strict_types=1);

namespace Mothership\DAM\Events;

use Illuminate\Support\Str;
use Mothership\DAM\Services\Automation\AutomationOrigin;

/**
 * P5b: an asset's fields, tags or labels changed (roadmap §3.4). Raised once per change by FieldValues,
 * AssetTagWriter and LabelService, after the write. Two listeners read it: the automation triggers (field_changed,
 * tag_changed, label_changed), which ignore a change whose origin is automation, and the `dam.asset.updated`
 * webhook.
 *
 * `eventId` is new for every change: it is the trigger event of the run key, so a redelivered job for this change
 * evaluates once while two different changes on unchanged bytes evaluate twice.
 */
final class AssetValuesChanged
{
    public const KIND_FIELD = 'field';

    public const KIND_TAG = 'tag';

    public const KIND_LABEL = 'label';

    public readonly string $eventId;

    public readonly bool $automation;

    /**
     * @param  list<string>  $keys  what changed: `fields.<code>`, `tags` or `labels`
     * @param  list<int>  $fieldIds  for a field change, the fields whose value changed
     */
    public function __construct(
        public readonly int $assetId,
        public readonly string $kind,
        public readonly array $keys,
        public readonly string $source,
        public readonly array $fieldIds = [],
        ?bool $automation = null,
        ?string $eventId = null,
    ) {
        $this->automation = $automation ?? AutomationOrigin::active();
        $this->eventId = $eventId ?? (string) Str::uuid();
    }
}
