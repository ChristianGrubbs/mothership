<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Webhooks;

use Mothership\DAM\Events\AssetValuesChanged;
use Mothership\DAM\Jobs\SendDamWebhook;
use Mothership\DAM\Models\AutomationRun;
use Mothership\DAM\Support\AutomationLog;
use Mothership\Webhook\Repositories\WebhookRepository;

/**
 * The DAM's webhook events (P5b, D19), delivered through the Webhook package's own signing, SSRF guard and
 * delivery log. Every payload carries ids and changed keys only: no file URL, share token, title or file name.
 *
 * Payloads (`data`):
 * - dam.asset.created / .trashed / .restored: {asset_id}
 * - dam.asset.updated: {asset_id, changed: ["fields.<code>" | "tags" | "labels"], source, origin: user|automation}
 * - dam.automation.run.completed: {run_id, rule_id, rule_version, trigger, status, counts: {matched, written,
 *   skipped, refused}}
 *
 * Each is queued once per change and delivered per subscribed webhook only when that webhook's owner may see the
 * asset (SendDamWebhook). A delivery is never a rule trigger.
 */
class DamWebhookEvents
{
    public const ASSET_CREATED = 'dam.asset.created';

    public const ASSET_UPDATED = 'dam.asset.updated';

    public const ASSET_TRASHED = 'dam.asset.trashed';

    public const ASSET_RESTORED = 'dam.asset.restored';

    public const RUN_COMPLETED = 'dam.automation.run.completed';

    /** event key => translation key, registered with the Webhook package's EventRegistry. */
    public const EVENTS = [
        self::ASSET_CREATED  => 'dam::app.admin.automation.webhook-events.asset-created',
        self::ASSET_UPDATED  => 'dam::app.admin.automation.webhook-events.asset-updated',
        self::ASSET_TRASHED  => 'dam::app.admin.automation.webhook-events.asset-trashed',
        self::ASSET_RESTORED => 'dam::app.admin.automation.webhook-events.asset-restored',
        self::RUN_COMPLETED  => 'dam::app.admin.automation.webhook-events.run-completed',
    ];

    public function assetCreated(int $assetId): void
    {
        $this->queue(event: self::ASSET_CREATED, data: ['asset_id' => $assetId], assetId: $assetId);
    }

    public function assetUpdated(AssetValuesChanged $change): void
    {
        $this->queue(event: self::ASSET_UPDATED, data: [
            'asset_id' => $change->assetId,
            'changed'  => $change->keys,
            'source'   => $change->source,
            'origin'   => $change->automation ? 'automation' : 'user',
        ], assetId: $change->assetId);
    }

    public function assetTrashed(int $assetId): void
    {
        $this->queue(event: self::ASSET_TRASHED, data: ['asset_id' => $assetId], assetId: $assetId);
    }

    public function assetRestored(int $assetId): void
    {
        $this->queue(event: self::ASSET_RESTORED, data: ['asset_id' => $assetId], assetId: $assetId);
    }

    public function runCompleted(AutomationRun $run): void
    {
        $this->queue(event: self::RUN_COMPLETED, data: [
            'run_id'       => (int) $run->id,
            'rule_id'      => (int) $run->rule_id,
            'rule_version' => (int) $run->rule_version,
            'trigger'      => $run->trigger_type,
            'status'       => $run->status,
            'counts'       => ['matched' => (int) $run->matched, 'written' => (int) $run->written, 'skipped' => (int) $run->skipped, 'refused' => (int) $run->refused],
        ], assetId: null);
    }

    private function queue(string $event, array $data, ?int $assetId): void
    {
        try {
            if (! app(WebhookRepository::class)->hasActiveForEvent($event)) {
                return;
            }
        } catch (\Throwable) {
            // No webhooks table (a bare DAM install): nothing to deliver.
            return;
        }

        AutomationLog::write(event: 'webhook.queued', ctx: ['event' => $event, 'asset_id' => (int) $assetId]);
        SendDamWebhook::dispatch(event: $event, data: $data, assetId: $assetId, occurredAt: now()->toIso8601String())->afterCommit();
    }
}
