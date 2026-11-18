<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Mothership\DAM\Models\Share;
use Mothership\DAM\Support\ShareLog;

/**
 * P2 S5a: the review of public links that never expire. It reports every active link with no expiry, and with
 * --apply --days=N gives exactly those links an expiry N days out. Never scheduled.
 */
class ReviewShareExpiry extends Command
{
    /** The longest expiry the app itself offers (web and REST cap expiry_days at 365). */
    private const MAX_DAYS = 365;

    protected $signature = 'dam:shares:review-expiry
        {--json : Print the report as JSON instead of a table}
        {--apply : Set an expiry on every link in the report (needs --days)}
        {--days= : With --apply, the expiry in days from now, 1 to 365}
        {--force : With --apply, skip the confirmation}';

    protected $description = 'Report active share links that never expire; with --apply --days=N, give exactly those an expiry. An --apply run in production needs operator approval first; never scheduled';

    protected $help = <<<'HELP'
        Without --apply this only reports: every active (not revoked) link whose expires_at is empty, with its id,
        type, target id, creator, created_at, last_accessed_at and view/download counts.

        --apply --days=N sets expires_at to now + N days on exactly the links in that report, in one write that
        skips any link revoked or given an expiry meanwhile. It asks for confirmation unless --force.

        A production --apply run needs operator approval before it runs: every link it touches stops working
        after N days. Every run appends to storage/logs/dam-shares.jsonl.
        HELP;

    public function handle(): int
    {
        $days = null;

        if ($this->option('apply')) {
            $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_DAYS]]);

            if ($days === false) {
                $this->error('--apply needs --days=N, a whole number of days from 1 to '.self::MAX_DAYS.'. Nothing was written.');

                return self::FAILURE;
            }
        }

        $links = $this->neverExpiring();
        $this->report($links);
        ShareLog::write('share_expiry.reviewed', ['count' => $links->count()]);

        if ($days === null || $links->isEmpty()) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Set an expiry of {$days} days on these {$links->count()} links? Each stops working when it passes.")) {
            $this->line('Nothing was written.');
            ShareLog::write('share_expiry.declined', ['count' => $links->count(), 'days' => $days]);

            return self::SUCCESS;
        }

        $updated = 0;

        foreach ($links->pluck('id')->chunk(500) as $ids) {
            $updated += Share::query()
                ->whereIn('id', $ids->all())
                ->whereNull('expires_at')
                ->active()
                ->update(['expires_at' => now()->addDays($days)]);
        }

        $this->info("{$updated} links now expire in {$days} days.");
        ShareLog::write('share_expiry.applied', ['count' => $updated, 'days' => $days, 'reviewed' => $links->count()]);

        return self::SUCCESS;
    }

    /** @return Collection<int, Share> */
    private function neverExpiring(): Collection
    {
        return Share::query()
            ->with('creator:id,name')
            ->active()
            ->whereNull('expires_at')
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, Share> $links */
    private function report(Collection $links): void
    {
        $rows = $links->map(fn (Share $share) => [
            'id'               => $share->id,
            'type'             => $share->share_type,
            'target_id'        => (int) $share->target_id,
            'creator_id'       => $share->created_by === null ? null : (int) $share->created_by,
            'creator'          => $share->creator?->name,
            'created_at'       => $share->created_at?->toIso8601String(),
            'last_accessed_at' => $share->last_accessed_at?->toIso8601String(),
            'view_count'       => (int) $share->view_count,
            'download_count'   => (int) $share->download_count,
        ])->values();

        if ($this->option('json')) {
            $this->line(json_encode($rows->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        if ($rows->isEmpty()) {
            $this->info('No active link is without an expiry.');

            return;
        }

        $this->table(array_keys($rows->first()), $rows->map(fn (array $row) => array_map(fn ($value) => $value ?? '-', $row))->all());
    }
}
