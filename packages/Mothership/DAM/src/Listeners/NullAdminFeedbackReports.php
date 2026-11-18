<?php

declare(strict_types=1);

namespace Mothership\DAM\Listeners;

use Illuminate\Database\Eloquent\Model;
use Mothership\DAM\Models\FeedbackReport;

/**
 * Keeps a deleted admin's feedback reports, without a reporter, before the admin row goes. The foreign key does the
 * same on every driver but SQL Server, where it is NO ACTION (the migration explains why) and would refuse the delete.
 */
class NullAdminFeedbackReports
{
    public function handle(Model $admin): void
    {
        FeedbackReport::query()->where('admin_id', $admin->getKey())->update(['admin_id' => null]);
    }
}
