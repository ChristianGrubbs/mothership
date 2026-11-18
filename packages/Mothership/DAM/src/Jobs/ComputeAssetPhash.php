<?php

declare(strict_types=1);

namespace Mothership\DAM\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\Similarity\PerceptualHashes;
use Mothership\DAM\Support\DuplicateLog;
use Throwable;

/**
 * P5c (D16): the asset's perceptual hash and near-duplicate pairs, queued at ingest after the preview jobs and again
 * by GenerateVideoThumbnail once a poster exists. Idempotent: the same bytes are not hashed twice. A failure is
 * logged and the upload stays successful; `dam:phash:backfill` picks the asset up later.
 */
class ComputeAssetPhash implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public int $assetId) {}

    public function handle(PerceptualHashes $hashes): void
    {
        $asset = Asset::query()->where('id', $this->assetId)->first();

        if ($asset === null) {
            return;
        }

        try {
            $hashes->ensure($asset);
        } catch (Throwable $error) {
            DuplicateLog::write(event: 'phash.failed', ctx: ['asset_id' => $this->assetId, 'error' => mb_substr(class_basename($error), 0, 32)], level: 'warning');
        }
    }
}
