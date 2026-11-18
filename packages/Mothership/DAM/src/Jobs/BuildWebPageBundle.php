<?php

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\WebPageBundler;

class BuildWebPageBundle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * The version is bound by the caller, not read here: a re-upload that lands first must win,
     * and this job must then publish nothing rather than overwrite it.
     */
    public function __construct(protected int $assetId, protected int $version) {}

    public function handle(WebPageBundler $bundler): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset && $asset->file_type === 'web') {
            $bundler->build($asset, $this->version);
        }
    }
}
