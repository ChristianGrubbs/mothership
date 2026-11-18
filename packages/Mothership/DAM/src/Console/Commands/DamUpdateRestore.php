<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Helpers\DamUpdater;

class DamUpdateRestore extends Command
{
    protected $signature = 'dam:update:restore {timestamp? : The backup folder name under storage/dam-backups}';

    protected $description = 'Restore DAM database tables and asset files from a backup.';

    public function handle(DamUpdater $updater): int
    {
        $timestamp = $this->argument('timestamp');

        if (! $timestamp) {
            $backups = $updater->listBackups();
            if ($backups === []) {
                $this->error('No backups found under storage/dam-backups.');

                return self::FAILURE;
            }
            $this->line('Available backups:');
            foreach ($backups as $b) {
                $this->line('  '.$b);
            }

            return self::SUCCESS;
        }

        if (! in_array($timestamp, $updater->listBackups(), true)) {
            $this->error("No backup found: {$timestamp}");

            return self::FAILURE;
        }

        $this->warn('This overwrites current DAM tables and replaces the asset tree with the backup. Put the app in maintenance mode first.');
        if (! $this->confirm('Continue?', false)) {
            return self::SUCCESS;
        }

        try {
            $result = $updater->restore($timestamp);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore complete from: '.$timestamp);
        $this->line(sprintf('Verified %d file object blob(s); %d recovered from the replaced tree.', $result['checked'], $result['recovered']));
        if ($result['previous'] !== null) {
            $this->line('The replaced asset tree is kept at '.$result['previous'].'. Delete '.dirname($result['previous']).' once the restore checks out.');
        }

        return self::SUCCESS;
    }
}
