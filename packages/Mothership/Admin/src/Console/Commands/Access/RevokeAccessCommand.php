<?php

namespace Mothership\Admin\Console\Commands\Access;

use Illuminate\Console\Command;
use Mothership\Admin\Access\AccessGrantor;
use RuntimeException;

class RevokeAccessCommand extends Command
{
    protected $signature = 'mothership:access:revoke
        {admin : The admin, by id or email}
        {--provider= : Remove only this provider\'s link (for example google)}
        {--deactivate : Also deactivate the admin}
        {--force : Allow deactivating the last active full admin}';

    protected $description = 'Remove an admin\'s provider sign-in links, and optionally deactivate the admin.';

    public function handle(AccessGrantor $grantor): int
    {
        $provider = $this->option('provider');

        try {
            $removed = $grantor->revoke(
                adminRef: (string) $this->argument('admin'),
                provider: is_string($provider) && $provider !== '' ? $provider : null,
                deactivate: (bool) $this->option('deactivate'),
                force: (bool) $this->option('force'),
            );
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Removed {$removed} provider link(s).".($this->option('deactivate') ? ' The admin is deactivated.' : ''));

        return self::SUCCESS;
    }
}
