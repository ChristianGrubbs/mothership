<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\Retention;
use Mothership\DAM\Support\AnalyticsLog;

/**
 * The analytics deletion contract for one person (§3.6.7): their raw events and search terms go, and the days
 * those events were on are recomputed. Daily counts older than the raw window carry no actor and stay as they are.
 * Operator-run only.
 */
class AnalyticsForget extends Command
{
    protected $signature = 'dam:analytics:forget
        {--admin= : The admin id to forget}
        {--dry-run : Count what would be deleted and delete nothing}';

    protected $description = "Delete one admin's raw analytics events and search terms, and recompute the affected daily counts";

    public function handle(Retention $retention): int
    {
        // A string from the command line, an int from Artisan::call.
        $admin = is_int($this->option('admin')) ? (string) $this->option('admin') : $this->option('admin');

        if (! is_string($admin) || ! ctype_digit($admin) || (int) $admin < 1) {
            $this->error('--admin takes an admin id.');

            return self::INVALID;
        }

        $runId = (string) Str::uuid();
        $dryRun = (bool) $this->option('dry-run');
        $result = $retention->forgetAdmin(adminId: (int) $admin, dryRun: $dryRun);

        AnalyticsLog::write(level: 'info', event: $dryRun ? 'analytics.forget_dry_run' : 'analytics.forgot_admin', runId: $runId, ctx: ['admin_id' => (int) $admin] + $result);

        $this->info(sprintf('%s %d event(s) and %d search term(s) for admin %d; %d day(s) %s.',
            $dryRun ? 'Would delete' : 'Deleted', $result['events'], $result['search_terms'], (int) $admin,
            $result['days_recomputed'], $dryRun ? 'would be recomputed' : 'recomputed'));

        return self::SUCCESS;
    }
}
