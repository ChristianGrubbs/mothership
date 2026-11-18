<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Services\Automation\AutomationEngine;

/** P5b (D19): one chunk of a rule's manual run over a folder or selection. A redelivery writes and logs nothing new. */
class RunAutomationChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    /** @param  list<int>  $assetIds */
    public function __construct(public int $runId, public array $assetIds) {}

    public function handle(AutomationEngine $engine): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $engine->runChunk(runId: $this->runId, assetIds: $this->assetIds);
    }
}
