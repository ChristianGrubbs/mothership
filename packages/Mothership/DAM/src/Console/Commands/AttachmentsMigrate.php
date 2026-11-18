<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Database\Backfill\AttachmentSlotBackfill;
use Mothership\DAM\Database\Backfill\AttachmentSlotBackfillException;

/**
 * Rehearses (--dry-run) or repairs the P3a S2 slot backfill. Migration 2026_10_06_000201 runs the same
 * backfill; on a restored copy of production, run this with --dry-run first and keep its JSON report.
 */
class AttachmentsMigrate extends Command
{
    protected $signature = 'dam:attachments-migrate
        {--dry-run : Run inside a rolled-back transaction and only report}
        {--asset=* : Limit the run to these asset ids}';

    protected $description = 'Give every asset file without a slot its attachment slot and revision number, and reconcile the counts';

    public function handle(AttachmentSlotBackfill $backfill): int
    {
        $assetIds = array_map('intval', (array) $this->option('asset')) ?: null;

        try {
            $report = $backfill->run(assetIds: $assetIds, dryRun: (bool) $this->option('dry-run'));
        } catch (AttachmentSlotBackfillException $e) {
            $report = $e->report;
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
