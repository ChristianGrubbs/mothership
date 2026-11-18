<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Services\Review\ReviewNotifier;

/**
 * One P7 review notification on its way (ReviewNotifier::notify). Access is decided again when the job runs, not
 * only when it was queued: by then a recipient may have lost the asset or been disabled, or the asset trashed or
 * made unavailable, and the notification would still name it. Only ids and the prepared text travel.
 */
class DeliverReviewNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<int>  $adminIds
     * @param  array{title: string, description: string}  $text
     * @param  array<string, int|string|null>  $context
     */
    public function __construct(
        public int $assetId,
        public array $adminIds,
        public string $type,
        public array $text,
        public array $context = [],
    ) {}

    public function handle(ReviewNotifier $notifier): void
    {
        $notifier->deliver(
            assetId: $this->assetId,
            adminIds: $this->adminIds,
            type: $this->type,
            text: $this->text,
            context: $this->context,
        );
    }
}
