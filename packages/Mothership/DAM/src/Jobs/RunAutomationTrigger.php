<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Automation\AutomationEngine;
use Mothership\DAM\Services\Automation\AutomationTriggers;

/**
 * P5b (D19): evaluates every enabled rule listening to one triggering event on one asset. Safe to redeliver: each
 * rule's run is keyed (rule, version, event id), so a second delivery finds its runs and writes nothing.
 */
class RunAutomationTrigger implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    /** @param  list<int>  $fieldIds */
    public function __construct(
        public string $trigger,
        public string $eventId,
        public int $assetId,
        public array $fieldIds = [],
    ) {}

    public function handle(AutomationEngine $engine): void
    {
        if (! Asset::query()->where('id', $this->assetId)->exists()) {
            return;
        }

        foreach (AutomationTriggers::rulesFor($this->trigger, $this->fieldIds)->get() as $rule) {
            $engine->runForEvent(rule: $rule, triggerType: $this->trigger, eventId: $this->eventId, assetId: $this->assetId);
        }
    }
}
