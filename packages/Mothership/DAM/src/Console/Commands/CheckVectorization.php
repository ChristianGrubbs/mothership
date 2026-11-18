<?php

namespace Mothership\DAM\Console\Commands;

use Illuminate\Console\Command;
use Mothership\DAM\Vectorization\Vectorizer;
use Mothership\User\Models\Admin;

class CheckVectorization extends Command
{
    protected $signature = 'dam:vectorizations:check';

    protected $description = 'Check vectorization configuration without contacting the provider';

    public function handle(Vectorizer $provider): int
    {
        $owner = Admin::humans()
            ->whereKey((int) config('vectorization.owner_admin_id'))
            ->where('status', 1)
            ->exists();

        if (! $owner || ! $provider->ready()) {
            $this->error('Vectorization is not configured with an active owner and shared credentials.');

            return self::FAILURE;
        }

        $this->info('Vectorization configuration ready.');

        return self::SUCCESS;
    }
}
