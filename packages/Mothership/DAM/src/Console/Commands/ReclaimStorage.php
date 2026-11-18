<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Services\StorageIntegrity;
use Mothership\DAM\Support\ReclaimOptions;

/**
 * Operator-run only, never scheduled: the gate sequence is in docs/ops/storage-integrity.md, "Reclaim (slice 4)".
 */
class ReclaimStorage extends Command
{
    protected $signature = 'dam:storage:reclaim
        {--dry-run : Report only. This is also the default with no flags}
        {--force : Delete what the report marks reclaimable. Requires --backup}
        {--backup= : Name of a storage/dam-backups/<name>/ pair taken by dam:storage:backup before this run}';

    protected $description = 'Delete Library copies, legacy editor working files and path-keyed derivatives a blob or the database no longer needs, behind a backup gate (ADR 0009 slice 4)';

    /** Kept reasons that need a person or a rerun, with the warning each prints. */
    private const WARNINGS = [
        'kept_no_blob'         => 'No blob holds these bytes; never deleted, review by hand',
        'kept_changed'         => 'Changed during the run; kept, rerun --force',
        'kept_blob_unverified' => 'Its blob did not verify during the run; kept, review by hand',
        'kept_now_named'       => 'A row named it during the run; kept, rerun --force',
        'kept_unlink_failed'   => 'Could not delete; nothing was queued, rerun --force',
        'kept_error'           => 'Could not be checked; kept, see the log',
    ];

    public function handle(StorageIntegrity $integrity): int
    {
        $backup = $this->option('backup');
        $result = $integrity->reclaim(new ReclaimOptions(
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
            backup: $backup === null ? null : (string) $backup,
        ));

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

        if ($result['refused'] !== null) {
            $this->error('Refused: '.$result['refused']['message']);

            foreach (['missing', 'wrong_size'] as $gap) {
                foreach ($result['refused'][$gap]['sample'] ?? [] as $path) {
                    $this->warn(($gap === 'missing' ? 'Not in the backup archive: ' : 'In the backup archive at another size: ').$path);
                }
            }

            return self::FAILURE;
        }

        // A name that is not valid UTF-8 is printed percent-encoded, byte for byte, as dam:storage:orphans does.
        foreach ($result['files'] as $file) {
            if ($file['action'] === 'kept_invalid_utf8') {
                $this->warn("Name is not valid UTF-8; never deleted, review by hand: {$file['encoded_path']} (".($file['size'] ?? '?').' bytes)');
            } elseif (isset(self::WARNINGS[$file['action']])) {
                $this->warn(self::WARNINGS[$file['action']].": {$file['path']} (".($file['size'] ?? '?').' bytes)');
            }
        }

        foreach ($result['skipped_dot_folder']['sample'] as $path) {
            $this->warn("In a dot-folder no reclaim set covers; never deleted, review by hand: {$path}");
        }

        if ($result['skipped_dot_folder']['count'] > count($result['skipped_dot_folder']['sample'])) {
            $this->warn(sprintf('…and %d more files in such dot-folders.', $result['skipped_dot_folder']['count'] - count($result['skipped_dot_folder']['sample'])));
        }

        foreach ($result['pruned_directories']['retained_invalid_utf8']['sample'] as $encoded) {
            $this->warn("Directory name is not valid UTF-8; never removed, review by hand: {$encoded}");
        }

        if ($result['dry_run']) {
            $this->warn(sprintf(
                'Dry run: nothing was changed. %d files (%d bytes) are reclaimable; rerun with --force --backup=<name>.',
                $result['reclaimable']['count'],
                $result['reclaimable']['bytes'],
            ));
        }

        return $result['clean'] ? self::SUCCESS : self::FAILURE;
    }
}
