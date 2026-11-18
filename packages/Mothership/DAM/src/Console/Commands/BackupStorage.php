<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Helpers\DamUpdater;
use Mothership\DAM\Models\Directory;
use Throwable;

/**
 * The backup step of the reclaim gate (docs/ops/storage-integrity.md, "Reclaim (slice 4)"): the DAM-table dump
 * and the asset archive DamUpdater::backup() writes, taken together, under a name dam:storage:reclaim --backup
 * and dam:update:restore take.
 */
class BackupStorage extends Command
{
    protected $signature = 'dam:storage:backup';

    protected $description = 'Back up the DAM tables and the assets/ tree together as one pair under storage/dam-backups (for dam:storage:reclaim --backup and dam:update:restore)';

    public function handle(DamUpdater $updater): int
    {
        $disk = Directory::getAssetDisk();

        // DamUpdater::archiveAssetFiles() archives only a local tree; on any other disk the pair would be half a pair.
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            $this->error("dam:storage:backup needs a local asset disk, and [{$disk}] is not: its archive covers only a local tree.");

            return self::FAILURE;
        }

        try {
            $backup = $updater->backup(now()->format('Y-m-d_H-i-s'));
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        clearstatcache();
        $sqlBytes = is_file($backup['sql']) ? (int) filesize($backup['sql']) : 0;
        $filesBytes = $backup['files'] !== null && is_file($backup['files']) ? (int) filesize($backup['files']) : 0;

        $this->line(json_encode([
            'name'        => basename($backup['dir']),
            'sql'         => $backup['sql'],
            'sql_bytes'   => $sqlBytes,
            'files'       => $backup['files'],
            'files_bytes' => $filesBytes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($sqlBytes === 0 || $filesBytes === 0) {
            $this->error('The backup is incomplete: '.($sqlBytes === 0 ? 'the database dump is empty or missing' : 'there is no archive of assets/').'. Do not reclaim against it.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
