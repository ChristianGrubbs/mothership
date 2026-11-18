<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Services\Security\SecurityEventRecorder;
use Mothership\DAM\Support\SecurityEventLog;

/**
 * Retention for dam_security_events, as the operator set it (Q1, 2026-10-06): the full IP is kept 90 days and
 * then only its /24 or /48, and a row is kept 1 year. Scheduled daily; a rerun the same day changes nothing.
 */
class PruneSecurityEvents extends Command
{
    public const FULL_IP_DAYS = 90;

    public const RETENTION_DAYS = 365;

    private const CHUNK = 1000;

    protected $signature = 'dam:security-events:prune';

    protected $description = 'Truncate security-event IPs older than 90 days and delete security events older than 1 year';

    public function handle(): int
    {
        $runId = (string) Str::uuid();
        $started = microtime(true);
        $table = SecurityEventRecorder::TABLE;
        $ipCutoff = now()->subDays(self::FULL_IP_DAYS);
        $deleteCutoff = now()->subDays(self::RETENTION_DAYS);

        // A row written without its truncated form (none should be) gets it before the full address goes.
        DB::table($table)->where('occurred_at', '<', $ipCutoff)->whereNotNull('ip')->whereNull('ip_truncated')
            ->orderBy('id')->chunkById(self::CHUNK, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'ip_truncated' => SecurityEventRecorder::truncateIp(ip: $row->ip),
                    ]);
                }
            });

        $truncated = DB::table($table)->where('occurred_at', '<', $ipCutoff)->whereNotNull('ip')->update(['ip' => null]);

        $deleted = 0;

        do {
            $ids = DB::table($table)->where('occurred_at', '<', $deleteCutoff)->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table($table)->whereIn('id', $ids->all())->delete();
        } while ($ids->count() === self::CHUNK);

        SecurityEventLog::write(level: 'info', event: 'security_events.pruned', runId: $runId, ctx: [
            'ips_truncated' => $truncated,
            'rows_deleted'  => $deleted,
            'duration_ms'   => (int) round((microtime(true) - $started) * 1000),
        ]);

        $this->info("Truncated {$truncated} IP address(es) older than ".self::FULL_IP_DAYS." days; deleted {$deleted} event(s) older than ".self::RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
