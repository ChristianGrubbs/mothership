<?php

namespace Mothership\DAM\Services;

use Mothership\User\Models\Admin;

/**
 * Whether an account sees the SIGNAL announcement strip and its Announcement alerts bell.
 *
 * `admins.signal_alerts` is nullable: an explicit true or false is the person's own choice and always wins, the
 * exception accounts included. NULL ("never chose") falls back to the default-plus-exceptions rule: off, unless
 * the account is one of `dam-home.signal_default_on`. No row is ever bulk-updated, so removing the exception list
 * (or dropping the column) restores the earlier behaviour without touching user data.
 *
 * This is only the user's switch. The layout still asks HomeWidgetRegistry::permits('rss'), so a role that may not
 * use the RSS tool gets no strip whatever this says.
 */
class SignalAlerts
{
    public function enabledFor(?Admin $admin): bool
    {
        if ($admin === null) {
            return false;
        }

        return $admin->signal_alerts ?? $this->isDefaultOn(admin: $admin);
    }

    /**
     * Whether the account is one of the configured exceptions: its id and its normalised email must both match.
     */
    public function isDefaultOn(Admin $admin): bool
    {
        $email = $this->normalise(email: $admin->email);

        if ($email === '') {
            return false;
        }

        foreach ((array) config('dam-home.signal_default_on', []) as $exception) {
            if (! is_array($exception) || ! isset($exception['id'], $exception['email'])) {
                continue;
            }

            if ((int) $admin->getKey() === (int) $exception['id'] && $email === $this->normalise(email: (string) $exception['email'])) {
                return true;
            }
        }

        return false;
    }

    private function normalise(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
