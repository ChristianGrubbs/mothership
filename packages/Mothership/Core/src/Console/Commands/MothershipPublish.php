<?php

namespace Mothership\Core\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Mothership\Core\Providers\CoreServiceProvider;
use Mothership\Product\Providers\ProductServiceProvider;

#[Description('Publish the available assets')]
#[Signature('mothership:publish { --force : Overwrite any existing files }')]
class MothershipPublish extends Command
{
    /**
     * List of providers.
     *
     * @var array
     */
    protected $providers = [
        /**
         * Mothership providers.
         */
        [
            'name'     => 'Core',
            'provider' => CoreServiceProvider::class,
        ],
        [
            'name'     => 'Product',
            'provider' => ProductServiceProvider::class,
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->publishAllPackages();
    }

    /**
     * Publish all packages.
     */
    public function publishAllPackages(): void
    {
        collect($this->providers)->each(function (array $provider): void {
            $this->publishPackage($provider);
        });
    }

    /**
     * Publish package.
     */
    public function publishPackage(array $provider): void
    {
        $this->line('');
        $this->line('-----------------------------------------');
        $this->info('Publishing '.$provider['name']);
        $this->line('-----------------------------------------');

        $this->call('vendor:publish', [
            '--provider' => $provider['provider'],
            '--force'    => $this->option('force'),
        ]);
    }
}
