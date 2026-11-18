<?php

namespace Mothership\Webhook\Repositories;

use Mothership\Core\Eloquent\Repository;
use Mothership\Webhook\Models\WebhookLog;

class LogsRepository extends Repository
{
    /**
     * Specify Model class name
     */
    public function model(): string
    {
        return WebhookLog::class;
    }
}
