<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\GenerationPackageImporter;

class ImportGeneration extends Command
{
    protected $signature = 'mothership:import-generation {manifest} {--dry-run}';

    protected $description = 'Import a verified generated-media package and its reproduction data.';

    public function handle(GenerationPackageImporter $importer): int
    {
        try {
            $this->line(json_encode($importer->importManifest(manifestPath: $this->argument('manifest'), dryRun: (bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
