<?php

use Illuminate\Console\Attributes\Signature;
use Mothership\Installer\Console\Commands\Installer;

/**
 * The `mothership:install` command can optionally pull in official open-source
 * add-on packages (DAM and Shopify connectors) and always advertises
 * Mothership cloud hosting at the end. These tests drive the new package-resolution
 * and banner methods in isolation by overriding handle(), so the heavy
 * migrate:fresh + seed pipeline never runs.
 */
it('offers nothing while optional packages are disabled', function () {
    config(['installer.optional_packages.enabled' => false]);

    $this->app->extend(Installer::class, fn () => new #[Signature('mothership:install {--skip-env-check} {--skip-admin-creation} {--with-demo-data} {--with-packages=}')] class extends Installer
    {
        public function handle(): void
        {
            $this->line('RESOLVED:'.implode(',', $this->resolveSelectedPackages()));
        }
    });

    $this->artisan('mothership:install', [
        '--with-packages'       => 'dam,shopify',
        '--skip-admin-creation' => true,
    ])
        ->expectsOutputToContain('Optional add-on packages are disabled in this release')
        ->expectsOutputToContain('RESOLVED:')
        ->assertExitCode(0);
});

it('resolves valid --with-packages keys', function () {
    config(['installer.optional_packages.enabled' => true]);

    $this->app->extend(Installer::class, fn () => new #[Signature('mothership:install {--skip-env-check} {--skip-admin-creation} {--with-demo-data} {--with-packages=}')] class extends Installer
    {
        public function handle(): void
        {
            $this->line('RESOLVED:'.implode(',', $this->resolveSelectedPackages()));

        }
    });

    $this->artisan('mothership:install', [
        '--with-packages'       => 'dam,shopify',
        '--skip-admin-creation' => true,
    ])
        ->expectsOutputToContain('RESOLVED:dam,shopify')
        ->assertExitCode(0);
});

it('skips unknown packages passed to --with-packages', function () {
    config(['installer.optional_packages.enabled' => true]);

    $this->app->extend(Installer::class, fn () => new #[Signature('mothership:install {--skip-env-check} {--skip-admin-creation} {--with-demo-data} {--with-packages=}')] class extends Installer
    {
        public function handle(): void
        {
            $this->line('RESOLVED:'.implode(',', $this->resolveSelectedPackages()));

        }
    });

    $this->artisan('mothership:install', [
        '--with-packages'       => 'dam,nope',
        '--skip-admin-creation' => true,
    ])
        ->expectsOutputToContain('Skipping unknown package: nope')
        ->expectsOutputToContain('RESOLVED:dam')
        ->assertExitCode(0);
});

it('installs nothing when no packages are selected', function () {
    $this->app->extend(Installer::class, fn () => new #[Signature('mothership:install {--skip-env-check} {--skip-admin-creation} {--with-demo-data} {--with-packages=}')] class extends Installer
    {
        public function handle(): void
        {
            $this->installOptionalPackages([]);

            $this->line('DONE');

        }
    });

    $this->artisan('mothership:install', [
        '--with-packages'       => '',
        '--skip-admin-creation' => true,
    ])
        ->expectsOutputToContain('DONE')
        ->assertExitCode(0);
});
