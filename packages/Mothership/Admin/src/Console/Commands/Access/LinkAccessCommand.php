<?php

namespace Mothership\Admin\Console\Commands\Access;

use Illuminate\Console\Command;
use Mothership\Admin\Access\AccessGrantor;
use RuntimeException;

class LinkAccessCommand extends Command
{
    protected $signature = 'mothership:access:link
        {admin : The existing admin, by id or email}
        {request : The access request id from mothership:access:list}';

    protected $description = 'Let an existing admin sign in with the provider account of a waiting sign-in.';

    public function handle(AccessGrantor $grantor): int
    {
        $requestId = (string) $this->argument('request');

        if (! ctype_digit($requestId)) {
            $this->components->error('The request id must be a number.');

            return self::INVALID;
        }

        try {
            $admin = $grantor->link(adminRef: (string) $this->argument('admin'), requestId: (int) $requestId);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Admin {$admin->id} ({$admin->email}) can now sign in with that provider account.");

        return self::SUCCESS;
    }
}
