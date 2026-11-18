<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Models\Directory;
use Mothership\DAM\Services\ChunkUploadService;
use Mothership\DAM\Services\ContentStore;

class ExpireChunkUploads extends Command
{
    protected $signature = 'dam:uploads:expire';

    protected $description = 'Expire abandoned resumable uploads, remove their staged chunks, and sweep abandoned staging files and old warm copies.';

    public function handle(ChunkUploadService $service, ContentStore $store): int
    {
        $expired = $service->expire(now());
        $assetDisk = Directory::getAssetDisk();
        $uploadDisk = $store->uploadStagingDisk($assetDisk);
        $swept = $store->sweepStaging(disk: $assetDisk);

        if ($uploadDisk !== $assetDisk) {
            // A remote asset disk: uploads assemble on the local staging disk (ADR 0017).
            $swept += $store->sweepStaging(disk: $uploadDisk);
        }

        $warm = $store->sweepWarm();

        $this->info("Expired chunk uploads: {$expired}");
        $this->info("Swept staging files: {$swept}");
        $this->info("Swept warm copies: {$warm}");

        return self::SUCCESS;
    }
}
