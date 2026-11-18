<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\StylePressCatalogImporter;

class SyncStyles extends Command
{
    protected $signature = 'mothership:sync-styles {manifest} {--dry-run}';

    protected $description = 'Install a complete vault StylePress catalog package';

    public function handle(StylePressCatalogImporter $importer): int
    {
        try {
            $this->line(json_encode($importer->importManifest(manifestPath: $this->argument('manifest'), dryRun: (bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->line(json_encode(['ok'=>false, 'message'=>$error->getMessage()], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
