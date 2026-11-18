<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mothership\DAM\Analytics\Rollups;
use Mothership\DAM\Support\AnalyticsLog;

/**
 * Recomputes the daily analytics counts from the raw events (P1b, §3.6.5). With no range it redoes yesterday and
 * today, so events that arrive up to a day late still land; scheduled hourly, which also keeps today's dashboards
 * within the hour. Idempotent: any range can be rerun and gives the same rows.
 */
class AnalyticsRollup extends Command
{
    protected $signature = 'dam:analytics:rollup
        {--from= : First day to recompute (Y-m-d); default yesterday}
        {--to= : Last day to recompute (Y-m-d); default today}';

    protected $description = 'Recompute the daily product-analytics counts for a date range (default: yesterday and today)';

    public function handle(Rollups $rollups): int
    {
        $runId = (string) Str::uuid();
        $started = microtime(true);

        try {
            $from = $this->option('from') ? Carbon::createFromFormat('Y-m-d', (string) $this->option('from'))->startOfDay() : now()->subDay()->startOfDay();
            $to = $this->option('to') ? Carbon::createFromFormat('Y-m-d', (string) $this->option('to'))->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            $this->error('--from and --to take a date as Y-m-d.');

            return self::INVALID;
        }

        if ($from->gt($to)) {
            $this->error('--from is after --to.');

            return self::INVALID;
        }

        $written = $rollups->range(from: $from, to: $to);

        AnalyticsLog::write(level: 'info', event: 'analytics.rolled_up', runId: $runId, ctx: [
            'from'        => $from->toDateString(), 'to' => $to->toDateString(), 'days' => count($written),
            'rows'        => array_sum($written),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        $this->info('Recomputed '.count($written).' day(s), '.array_sum($written).' row(s).');

        return self::SUCCESS;
    }
}
