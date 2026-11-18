<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mothership\DAM\Support\RequestId;

/**
 * Operator-run only, never scheduled. `audits` is the version history of assets and catalog records (the History
 * tab), and the operator has set no retention period for it (§3.6.7, Q1). This reports by default; only
 * --apply deletes.
 */
class PruneAudits extends Command
{
    private const DEFAULT_CHUNK = 1000;

    protected $signature = 'dam:audits:prune
        {--older-than= : Required. Age in days; audit rows created before now minus this many days are counted, or deleted with --apply}
        {--apply : Delete them. Without it the command only reports, per auditable_type}
        {--chunk=1000 : Rows deleted per statement with --apply}';

    protected $description = 'Report, or with --apply delete, audit (version history) rows older than --older-than days';

    protected $help = <<<'HELP'
A production run needs operator approval. --apply permanently destroys version history (the History tab of
assets, products, categories and every other audited record) older than the cutoff; it cannot be restored
except from a database backup. No retention period has been set for audits, so nothing schedules this.

Without --apply the command deletes nothing and prints how many rows each auditable_type has before the cutoff.
HELP;

    public function handle(): int
    {
        $days = (string) ($this->option('older-than') ?? '');

        if (! ctype_digit($days) || (int) $days < 1) {
            $this->error('--older-than=DAYS is required: a whole number of days, at least 1. There is no default.');

            return self::INVALID;
        }

        $chunk = (string) $this->option('chunk');
        $chunk = ctype_digit($chunk) && (int) $chunk > 0 ? (int) $chunk : self::DEFAULT_CHUNK;
        $apply = (bool) $this->option('apply');
        $runId = (string) Str::uuid();
        $started = microtime(true);
        $cutoff = now()->subDays((int) $days);

        $counts = DB::table('audits')->where('created_at', '<', $cutoff)
            ->selectRaw('auditable_type, count(*) as rows_before_cutoff')
            ->groupBy('auditable_type')->orderBy('auditable_type')->get();

        $this->table(['auditable_type', 'rows before cutoff'], $counts->map(fn ($row) => [$row->auditable_type, (int) $row->rows_before_cutoff])->all());

        $deleted = 0;

        if ($apply) {
            do {
                $ids = DB::table('audits')->where('created_at', '<', $cutoff)->orderBy('id')->limit($chunk)->pluck('id');
                $deleted += $ids->isEmpty() ? 0 : DB::table('audits')->whereIn('id', $ids->all())->delete();
            } while ($ids->count() === $chunk);
        }

        $this->log(runId: $runId, event: $apply ? 'audits.pruned' : 'audits.prune_reported', ctx: [
            'older_than_days' => (int) $days,
            'types'           => $counts->count(),
            'rows'            => (int) $counts->sum('rows_before_cutoff'),
            'rows_deleted'    => $deleted,
            'duration_ms'     => (int) round((microtime(true) - $started) * 1000),
        ]);

        $this->info($apply
            ? "Deleted {$deleted} audit row(s) created before {$cutoff->toDateString()}."
            : 'Dry run: nothing deleted. Rerun with --apply, with operator approval, to delete these rows.');

        return self::SUCCESS;
    }

    /** @param array<string, int> $ctx */
    private function log(string $runId, string $event, array $ctx): void
    {
        $record = ['ts' => now()->toIso8601String(), 'run_id' => $runId, 'level' => 'info', 'event' => $event,
            'loc'       => self::class, 'ctx' => RequestId::ctx($ctx)];

        @file_put_contents(storage_path('logs/audits-prune.jsonl'), json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
