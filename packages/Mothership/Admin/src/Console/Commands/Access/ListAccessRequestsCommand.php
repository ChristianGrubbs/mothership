<?php

namespace Mothership\Admin\Console\Commands\Access;

use Illuminate\Console\Command;
use Mothership\User\Models\AdminAccessRequest;

class ListAccessRequestsCommand extends Command
{
    protected $signature = 'mothership:access:list {--json : Print the open requests as JSON}';

    protected $description = 'List provider sign-ins that are waiting for an admin grant.';

    public function handle(): int
    {
        $requests = AdminAccessRequest::query()->open()->orderBy('id')->get();

        $rows = $requests->map(fn (AdminAccessRequest $accessRequest): array => [
            'id'             => $accessRequest->id,
            'provider'       => $accessRequest->provider,
            'email'          => $accessRequest->email,
            'email_verified' => $accessRequest->email_verified,
            'name'           => $accessRequest->name,
            'requested_at'   => $accessRequest->updated_at?->toIso8601ZuluString(),
            'expires_at'     => $accessRequest->expires_at->toIso8601ZuluString(),
        ])->all();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->info('No sign-ins are waiting for access.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Provider', 'Email', 'Verified', 'Name', 'Requested', 'Expires'],
            array_map(fn (array $row): array => [
                $row['id'], $row['provider'], $row['email'], $row['email_verified'] ? 'yes' : 'no',
                $row['name'] ?? '', $row['requested_at'], $row['expires_at'],
            ], $rows),
        );

        $this->line('Grant one with: php artisan mothership:access:grant <id> --admin');

        return self::SUCCESS;
    }
}
