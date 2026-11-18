<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Mothership\DAM\Services\AssetDeletionService;

class ReapFileDeletions extends Command
{
    protected $signature = 'dam:storage:reap';

    protected $description = 'Retry every pending file deletion (tombstone), each on its own; exit 1 while any is still pending';

    public function handle(AssetDeletionService $deletions): int
    {
        $result = $deletions->retryPendingFileDeletions();

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        if ($result['cleared'] > 0 || $result['pending'] > 0) {
            Log::info('DAM pending file deletions retried.', ['event' => 'deletions.reaped'] + $result);
        }

        return $result['pending'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
