<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Automation;

use Mothership\DAM\Events\AssetValuesChanged;
use Mothership\DAM\Jobs\RunAutomationTrigger;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Models\AutomationRule;
use Mothership\DAM\Services\ContentStore;
use Mothership\DAM\Support\AutomationLog;

/**
 * Turns DAM events into queued rule evaluations (P5b, D19). Each triggering event gets the id that keys its runs
 * (roadmap §3.4):
 *
 * - ingest: `ingest:<asset>:<original sha256>`, so re-processing the same bytes is a no-op;
 * - ai_indexed: `ai_indexed:<asset>:<sha256>`, once per indexed content;
 * - field, tag and label changes: the change's own uuid, so two different changes on unchanged bytes evaluate twice.
 *
 * A change whose origin is automation (a mapping's or a rule's own write) is ignored: that is the loop guard.
 * Webhook deliveries are no trigger at all. Nothing is queued when no enabled rule listens.
 */
class AutomationTriggers
{
    public function valuesChanged(AssetValuesChanged $event): void
    {
        $trigger = match ($event->kind) {
            AssetValuesChanged::KIND_FIELD => AutomationRule::TRIGGER_FIELD_CHANGED,
            AssetValuesChanged::KIND_TAG   => AutomationRule::TRIGGER_TAG_CHANGED,
            default                        => AutomationRule::TRIGGER_LABEL_CHANGED,
        };

        if ($event->automation) {
            AutomationLog::write(event: 'trigger.loop_guard_skip', ctx: ['asset_id' => $event->assetId, 'trigger' => $trigger, 'source' => $event->source]);

            return;
        }

        $this->dispatch(trigger: $trigger, eventId: $event->kind.':'.$event->eventId, assetId: $event->assetId, fieldIds: $event->fieldIds);
    }

    public function ingest(Asset $asset): void
    {
        try {
            $sha = (string) app(ContentStore::class)->current($asset)->sha256;
        } catch (\Throwable) {
            $sha = 'none';
        }

        $this->dispatch(trigger: AutomationRule::TRIGGER_INGEST, eventId: 'ingest:'.$asset->id.':'.$sha, assetId: (int) $asset->id);
    }

    public function aiIndexed(int $assetId, ?string $sha256): void
    {
        $this->dispatch(trigger: AutomationRule::TRIGGER_AI_INDEXED, eventId: 'ai_indexed:'.$assetId.':'.($sha256 ?? 'none'), assetId: $assetId);
    }

    /**
     * The enabled rules one event evaluates.
     *
     * @param  list<int>  $fieldIds
     */
    public static function rulesFor(string $trigger, array $fieldIds = [])
    {
        return AutomationRule::query()->where('enabled', true)->where('trigger_type', $trigger)
            ->when($trigger === AutomationRule::TRIGGER_FIELD_CHANGED, fn ($query) => $query->where(
                fn ($field) => $field->whereNull('trigger_field_id')->orWhereIn('trigger_field_id', $fieldIds ?: [0])
            ))
            ->orderBy('id');
    }

    /** @param  list<int>  $fieldIds */
    private function dispatch(string $trigger, string $eventId, int $assetId, array $fieldIds = []): void
    {
        if (! self::rulesFor($trigger, $fieldIds)->exists()) {
            return;
        }

        AutomationLog::write(event: 'trigger.queued', ctx: ['asset_id' => $assetId, 'trigger' => $trigger]);
        RunAutomationTrigger::dispatch(trigger: $trigger, eventId: $eventId, assetId: $assetId, fieldIds: $fieldIds)->afterCommit();
    }
}
