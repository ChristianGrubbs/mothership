<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\DirectoryTreeIntegrity;

class CheckDirectoryTree extends Command
{
    protected $signature = 'dam:directories:check';

    protected $description = 'Report dam_directories nested-set integrity as one JSON line (detection only; exit 1 when broken)';

    public function handle(DirectoryTreeIntegrity $integrity): int
    {
        $report = $integrity->report();

        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['broken'] ? self::FAILURE : self::SUCCESS;
    }
}
