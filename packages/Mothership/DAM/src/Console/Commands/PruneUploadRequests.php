<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Models\UploadSubmission;
use Mothership\DAM\Support\GuestIntakeLog;

/**
 * P5a (D1): minimization of what guests told us about themselves. A submission's self-reported name and email are
 * cleared `dam.upload_requests.identity_retention_days` after it began; the submission, its counts and its files stay.
 * Scheduled daily; a rerun the same day changes nothing.
 */
class PruneUploadRequests extends Command
{
    protected $signature = 'dam:upload-requests:prune {--dry-run : Count what would be cleared, change nothing}';

    protected $description = 'Clear the self-reported guest name and email of upload-request submissions past their retention';

    public function handle(): int
    {
        $days = max(1, (int) config('dam.upload_requests.identity_retention_days'));
        $query = UploadSubmission::query()
            ->whereNull('identity_pruned_at')
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($query) => $query->whereNotNull('guest_name')->orWhereNotNull('guest_email'));

        $due = (clone $query)->count();
        $cleared = $this->option('dry-run') ? 0 : $query->update(['guest_name' => null, 'guest_email' => null, 'identity_pruned_at' => now()]);

        GuestIntakeLog::write(event: 'identity.pruned', ctx: ['due' => $due, 'cleared' => $cleared, 'days' => $days, 'dry_run' => (bool) $this->option('dry-run')]);
        $this->info($this->option('dry-run')
            ? "{$due} submission(s) would have their guest name and email cleared (older than {$days} days)."
            : "Cleared the guest name and email of {$cleared} submission(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
