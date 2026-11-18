<?php

declare(strict_types=1);

namespace Mothership\DAM\Services\Feedback;

use Mothership\DAM\Models\FeedbackReport;
use Mothership\User\Models\Admin;

/**
 * The one answer to "who may send, read and triage feedback". Admin::hasPermission() and bouncer() disagree on
 * all-permission roles (only bouncer() lets them through), and bouncer() lets an all-role API account through, so every
 * feedback check (controllers, the screenshot route, notification recipients) goes through here instead.
 */
final class FeedbackAccess
{
    public const MANAGE = 'dam.feedback.manage';

    /** Every signed-in person, never an API (robot) account. */
    public function canSubmit(?Admin $admin): bool
    {
        return $admin !== null && ! $admin->isApiUser() && $admin->role !== null;
    }

    public function canManage(?Admin $admin): bool
    {
        if (! $this->canSubmit($admin)) {
            return false;
        }

        $role = $admin->role;

        return $role->permission_type === 'all' || in_array(self::MANAGE, (array) $role->permissions, true);
    }

    /** The reporter reads their own report; a triager reads every report. */
    public function canView(?Admin $admin, FeedbackReport $report): bool
    {
        if (! $this->canSubmit($admin)) {
            return false;
        }

        return ($report->admin_id !== null && $report->admin_id === (int) $admin->getKey()) || $this->canManage($admin);
    }
}
