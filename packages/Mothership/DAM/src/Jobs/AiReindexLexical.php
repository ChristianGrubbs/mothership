<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\AiIndex\AiIndexStore;
use Mothership\DAM\Models\Asset;

/** Re-renders only the words (name, folders, tags, properties) after a rename, move or tag edit. No AI calls. */
class AiReindexLexical implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param list<int> $assetIds */
    public function __construct(public array $assetIds)
    {
        $this->onConnection('ai-index');
        $this->onQueue('ai-index');
    }

    public function handle(AiIndexStore $store): void
    {
        Asset::query()->whereKey($this->assetIds)->get()->each(fn (Asset $asset) => $store->refreshLexical($asset));
    }
}
