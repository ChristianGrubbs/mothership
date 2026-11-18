<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Mothership\DAM\Models\Asset;
use Mothership\DAM\Services\AssetTrashService;

/** P2 S4: the daily purge of the trash. Each expired asset is deleted for good through AssetDeletionService. */
class PurgeTrash extends Command
{
    protected $signature = 'dam:trash:purge';

    protected $description = 'Delete for good every asset trashed more than '.Asset::TRASH_RETENTION_DAYS.' days ago; exit 1 if any could not be deleted';

    public function handle(AssetTrashService $trash): int
    {
        $result = $trash->purgeExpired();

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        if ($result['purged'] > 0 || $result['skipped'] > 0 || $result['failed'] > 0) {
            Log::info('DAM trash purged.', ['event' => 'trash.purged'] + $result);
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
