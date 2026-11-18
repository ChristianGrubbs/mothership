<?php

declare(strict_types=1);

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Helpers\DamDemoDataInstaller;

use function Laravel\Prompts\confirm;

class DamInstaller extends Command
{
    protected $signature = 'dam-package:install';

    protected $description = 'Install the Mothership package';

    public function handle(): int
    {
        $this->info('Installing The Mothership...');

        $exitCode = $this->runMigrations();
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $exitCode = $this->publishAssets([
            'dam-config',
            'dam-defaults',
        ]);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        $this->info('The Mothership package installed successfully!');

        if (confirm('Would you like to seed demo data (sample directories and assets)?', false)) {
            $this->seedDemoData();
        }

        return self::SUCCESS;
    }

    protected function runMigrations(): int
    {
        if (! $this->confirm('Would you like to run the migrations now?', true)) {
            return self::SUCCESS;
        }

        $exitCode = $this->call('migrate', ['--force' => true]);
        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        return $this->call('db:seed', [
            '--class' => 'Mothership\DAM\Database\Seeders\DirectoryTableSeeder',
            '--force' => true,
        ]);
    }

    protected function publishAssets(array $tags): int
    {
        foreach ($tags as $tag) {
            $exitCode = $this->call('vendor:publish', ['--tag' => $tag, '--force' => true]);
            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        return self::SUCCESS;
    }

    protected function seedDemoData(): void
    {
        $result = app(DamDemoDataInstaller::class)
            ->seed(fn (string $message) => $this->warn('Step: '.$message));

        if (! ($result['success'] ?? false)) {
            $this->error("Failed to seed DAM demo data: {$result['error']}");

            return;
        }

        if ($result['skipped'] ?? false) {
            $this->info('DAM demo data already present — skipping.');

            return;
        }

        $this->info('DAM demo data seeded successfully.');
    }
}
