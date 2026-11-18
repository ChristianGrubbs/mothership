<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Mothership\DAM\Processing\BatchService;
use Mothership\DAM\Services\Access\AccessContext;
use Mothership\DAM\Services\Access\AssetAccessDecision;
use Mothership\DAM\Services\Webhooks\DamWebhookEvents;
use Mothership\DAM\Support\AutomationLog;
use Mothership\User\Models\Admin;
use Mothership\Webhook\Models\Webhook;
use Mothership\Webhook\Repositories\WebhookRepository;
use Mothership\Webhook\Services\WebhookService;

/**
 * Delivers one DAM event (P5b) to every active webhook subscribed to it, decided per webhook when the job runs:
 *
 * - the webhook needs an owner who still exists, is active and may edit webhooks (configuration.webhook.edit, the
 *   rule #138 set for a webhook's history); otherwise it gets no DAM event at all;
 * - an asset event goes only to a webhook whose owner may list the asset through the access seam, so a payload never
 *   names an asset its owner cannot see (a trashed asset is asked as the Trash page asks);
 * - a run event goes only to an owner who may manage automation.
 *
 * A withheld delivery writes no delivery-log row and is logged by id. Signing, the SSRF guard and the delivery log
 * are the Webhook package's (WebhookService::deliverEvent).
 */
class SendDamWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    /** A created event raised before the upload files the asset waits for the folder link this many times. */
    public const UNFILED_RETRIES = 3;

    public int $tries = self::UNFILED_RETRIES + 1;

    public function __construct(
        public string $event,
        public array $data,
        public ?int $assetId,
        public string $occurredAt,
    ) {}

    public function handle(WebhookRepository $webhooks, WebhookService $delivery, AssetAccessDecision $access): void
    {
        $payload = ['event' => $this->event, 'timestamp' => $this->occurredAt, 'data' => $this->data];
        $reference = $this->assetId !== null ? 'asset:'.$this->assetId : 'run:'.($this->data['run_id'] ?? 0);

        // The web upload links a new file to its folder after creating it, outside the creating transaction. Asked
        // before the link exists, a limited owner would be refused a file they are about to see: wait for it a few
        // times, then decide on whatever is there (an unfiled asset stays hidden, so this never leaks).
        if ($this->event === DamWebhookEvents::ASSET_CREATED && $this->attempts() <= self::UNFILED_RETRIES && $this->job !== null
            && ! DB::table('dam_asset_directory')->where('asset_id', $this->assetId)->exists()) {
            AutomationLog::write(event: 'webhook.awaiting_folder', ctx: ['asset_id' => (int) $this->assetId, 'attempt' => $this->attempts()]);
            $this->release(10);

            return;
        }

        foreach ($webhooks->getActiveForEvent($this->event) as $webhook) {
            if (! $this->ownerMaySee(webhook: $webhook, access: $access)) {
                AutomationLog::write(event: 'webhook.withheld', ctx: ['event' => $this->event, 'webhook_id' => (int) $webhook->id, 'asset_id' => (int) $this->assetId], level: 'notice');

                continue;
            }

            $delivery->deliverEvent(webhook: $webhook, payload: $payload, event: $this->event, reference: $reference);
            AutomationLog::write(event: 'webhook.delivered', ctx: ['event' => $this->event, 'webhook_id' => (int) $webhook->id, 'asset_id' => (int) $this->assetId]);
        }
    }

    private function ownerMaySee(Webhook $webhook, AssetAccessDecision $access): bool
    {
        $owner = $webhook->owner_id === null ? null : Admin::query()->find($webhook->owner_id);

        if ($owner === null || ! $owner->status || ! BatchService::adminMay($owner, 'configuration.webhook.edit')) {
            return false;
        }

        if ($this->assetId === null) {
            return BatchService::adminMay($owner, 'dam.automation.manage');
        }

        $context = AccessContext::forAdmin($owner, AccessContext::PURPOSE_LIST);
        $decision = $this->event === DamWebhookEvents::ASSET_TRASHED
            ? $access->forTrashed(context: $context, asset: $this->assetId)
            : $access->for(context: $context, asset: $this->assetId);

        return $decision->visible;
    }
}
