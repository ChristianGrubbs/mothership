<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\StorageIntegrity;

class RepairStorageIntegrity extends Command
{
    protected $signature = 'dam:storage:repair
        {--force : Repoint or copy locators onto their content-addressed blob and ingest pre-blob image editor resources (without it, a dry run); not accepted with --verify}
        {--verify : Report-only: re-hash Library files against their current original instead of repairing locators (resumable; rerun to continue from the cursor)}
        {--limit= : Stop the --verify pass after this many assets; rerun the command to resume from the cursor}';

    protected $description = 'Repair the FileObject locator invariant, or report Library files that differ from their original (ADR 0009)';

    public function handle(StorageIntegrity $integrity): int
    {
        if ($this->option('verify') && $this->option('force')) {
            // Since slice 3, writers put bytes in a blob: a Library file may be stale or belong to another asset.
            $this->error('--verify is report-only since ADR 0009 slice 3: a Library file is never ingested as an asset\'s content.');

            return self::FAILURE;
        }

        return $this->option('verify')
            ? $this->verify($integrity)
            : $this->repair($integrity);
    }

    private function repair(StorageIntegrity $integrity): int
    {
        $force = (bool) $this->option('force');
        $result = $integrity->repair(dryRun: ! $force);
        $result['editor_resources'] = $integrity->repairEditorResources(dryRun: ! $force);

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        if (! $force) {
            $this->warn('Dry run: nothing was changed. Rerun with --force to repoint locators and ingest editor resources.');
        }

        $broken = $result['unresolved']['count'] > 0
            || $result['originals_unresolved']['count'] > 0
            || $result['editor_resources']['unresolved']['count'] > 0;

        return $broken ? self::FAILURE : self::SUCCESS;
    }

    private function verify(StorageIntegrity $integrity): int
    {
        $limit = $this->option('limit');

        $result = $integrity->verifyContent(limit: $limit === null ? null : (int) $limit);

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        if (! $result['complete']) {
            $this->warn('Verification paused at the limit; rerun the command to resume from the cursor.');
        }

        $failing = $result['totals']['divergent'] > 0 || $result['totals']['failed'] > 0;

        return $failing ? self::FAILURE : self::SUCCESS;
    }
}
