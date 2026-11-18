<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\StorageIntegrity;

class CheckStorageIntegrity extends Command
{
    protected $signature = 'dam:storage:check';

    protected $description = 'Report the FileObject content-addressed locator invariant as one JSON line (detection only; exit 1 when not clean)';

    public function handle(StorageIntegrity $integrity): int
    {
        $report = $integrity->report();

        // library_orphans samples file names, which need not be valid UTF-8.
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));

        // Orphan Library files are informational: wasted bytes, not a broken invariant (dam:storage:orphans).
        return $report['clean'] ? self::SUCCESS : self::FAILURE;
    }
}
