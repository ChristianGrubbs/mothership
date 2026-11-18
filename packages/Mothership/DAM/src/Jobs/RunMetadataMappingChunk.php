<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Services\Automation\MetadataMappingService;

/** P5b (D18): one chunk of a mapping's on-demand run. Add-if-absent and set-if-different make a redelivery a no-op. */
class RunMetadataMappingChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    /** @param  list<int>  $assetIds */
    public function __construct(public int $mappingId, public int $version, public array $assetIds) {}

    public function handle(MetadataMappingService $mappings): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $mappings->runChunk(mappingId: $this->mappingId, version: $this->version, assetIds: $this->assetIds);
    }
}
