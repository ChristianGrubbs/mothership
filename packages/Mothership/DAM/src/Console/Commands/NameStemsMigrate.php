<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Database\Backfill\NameStemBackfill;

/**
 * Rehearses (--dry-run) or repairs the P3b-2 name-stem backfill. Migration 2026_10_07_000601 runs the same
 * backfill; on a restored copy of production, run this with --dry-run first and keep its JSON report. Run it again
 * after the FilenameStem rules change.
 */
class NameStemsMigrate extends Command
{
    protected $signature = 'dam:name-stems {--dry-run : Run inside a rolled-back transaction and only report}';

    protected $description = 'Fill every asset\'s normalized filename stem, used by stack suggestions, and reconcile the counts';

    public function handle(NameStemBackfill $backfill): int
    {
        try {
            $report = $backfill->run(dryRun: (bool) $this->option('dry-run'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($report['mismatches'] !== []) {
            $this->error('The backfill does not reconcile; nothing was kept.');

            return self::FAILURE;
        }

        $this->info($report['dry_run'] ? 'Dry run reconciles; nothing was written.' : 'Backfill reconciles and was kept.');

        return self::SUCCESS;
    }
}
