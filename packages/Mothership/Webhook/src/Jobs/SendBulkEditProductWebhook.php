<?php

namespace Mothership\Webhook\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Mothership\User\Models\AdminProxy;
use Mothership\Webhook\Services\WebhookService;

class SendBulkEditProductWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $ids
     */
    public function __construct(protected array $ids, protected $userId) {}

    /**
     * Execute the job.
     */
    public function handle(WebhookService $webhookService): void
    {
        if ($this->userId && ($user = AdminProxy::find($this->userId))) {
            Auth::login($user);
        }

        $webhookService->sendBatchForBulkEdit($this->ids);
    }
}
