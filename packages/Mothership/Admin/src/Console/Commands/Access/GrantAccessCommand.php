<?php

namespace Mothership\Admin\Console\Commands\Access;

use Illuminate\Console\Command;
use Mothership\Admin\Access\AccessGrantor;
use RuntimeException;

class GrantAccessCommand extends Command
{
    protected $signature = 'mothership:access:grant
        {request : The access request id from mothership:access:list}
        {--admin : Give the new account the full-access role}
        {--role= : Give the new account the role with this exact name instead}';

    protected $description = 'Create an admin from a waiting provider sign-in and link the provider account to it.';

    public function handle(AccessGrantor $grantor): int
    {
        $requestId = (string) $this->argument('request');

        if (! ctype_digit($requestId)) {
            $this->components->error('The request id must be a number.');

            return self::INVALID;
        }

        $role = $this->option('role');

        try {
            $admin = $grantor->grant(
                requestId: (int) $requestId,
                roleName: is_string($role) && $role !== '' ? $role : null,
                fullAdmin: (bool) $this->option('admin'),
            );
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Admin {$admin->id} ({$admin->email}) created. They can sign in now.");

        return self::SUCCESS;
    }
}
