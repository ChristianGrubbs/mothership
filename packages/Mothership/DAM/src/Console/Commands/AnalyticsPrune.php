<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\Retention;
use Mothership\DAM\Support\AnalyticsLog;

/**
 * Analytics retention as the operator set it (Q1, 2026-10-06): raw events 90 days, daily counts 25 months, search
 * terms 30 days. Scheduled daily; a rerun the same day deletes nothing.
 */
class AnalyticsPrune extends Command
{
    protected $signature = 'dam:analytics:prune {--dry-run : Count what would be deleted and delete nothing}';

    protected $description = 'Delete raw analytics events older than 90 days, daily counts older than 25 months and search terms older than 30 days';

    public function handle(Retention $retention): int
    {
        $runId = (string) Str::uuid();
        $dryRun = (bool) $this->option('dry-run');
        $deleted = $retention->prune(dryRun: $dryRun);

        AnalyticsLog::write(level: 'info', event: $dryRun ? 'analytics.prune_dry_run' : 'analytics.pruned', runId: $runId, ctx: $deleted);

        $this->info(sprintf('%s %d raw event(s), %d daily count row(s) and %d search term(s).',
            $dryRun ? 'Would delete' : 'Deleted', $deleted['events'], $deleted['counts'], $deleted['search_terms']));

        return self::SUCCESS;
    }
}
