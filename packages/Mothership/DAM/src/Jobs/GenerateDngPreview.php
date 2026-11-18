<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\DngPreviewService;

class GenerateDngPreview implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 80;

    public int $uniqueFor = 90;

    public function __construct(protected int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(): void
    {
        if ($asset = Asset::find($this->assetId)) {
            app(DngPreviewService::class)->ensure($asset);
        }
    }
}
