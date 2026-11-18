<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\DirectoryTreeIntegrity;
use Throwable;

class RepairDirectoryTree extends Command
{
    protected $signature = 'dam:directories:repair {--force : Rewrite _lft/_rgt from parent_id under the tree lock; without it only the report is printed}';

    protected $description = 'Repair the dam_directories nested set from parent_id, verifying every path before commit';

    public function handle(DirectoryTreeIntegrity $integrity): int
    {
        if (! $this->option('force')) {
            $report = $integrity->report();
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
            $this->warn($report['broken'] ? 'Tree is broken. Take a database backup, stop the queue workers, then rerun with --force.' : 'Tree is consistent; nothing to repair.');

            return $report['broken'] ? self::FAILURE : self::SUCCESS;
        }

        try {
            $result = $integrity->repair();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
