<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Database\Backfill\TagSourceBackfill;

/**
 * Rehearses (--dry-run) or repeats the P4 D8 tag `source` backfill. Migration 2026_10_07_001002 runs the same
 * backfill; on a restored copy of production, run this with --dry-run first and keep its JSON report.
 */
class TagsBackfillSource extends Command
{
    protected $signature = 'dam:tags:backfill-source {--dry-run : Run inside a rolled-back transaction and only report}';

    protected $description = 'Label tag rows that came from an accepted AI suggestion as source=ai, and reconcile the counts';

    public function handle(TagSourceBackfill $backfill): int
    {
        try {
            $report = $backfill->run(dryRun: (bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($report['violations'] !== []) {
            $this->error('The backfill does not reconcile; nothing was kept.');

            return self::FAILURE;
        }

        $this->info($report['dry_run'] ? 'Dry run reconciles; nothing was written.' : 'Backfill reconciles and was kept.');

        return self::SUCCESS;
    }
}
