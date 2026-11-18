<?php

namespace Mothership\DAM\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The one StylePress access rule: the feature switch, the DAM permission, the role's Home widget allowlist
 * and the env email/user-id allowlists. The controller aborts through authorize(); views ask permits(), so a
 * link shows exactly when the routes would let the user in.
 */
class StylePressAccess
{
    public function authorize(): void
    {
        abort_unless(config('stylepress.enabled'), 404);
        abort_unless(bouncer()->hasPermission('dam.assets'), 403);
        // Per-role Home widget allowlist (NULL = ungated, full admins pass): a role without the
        // StylePress widget must not reach these routes directly either.
        app(HomeWidgetRegistry::class)->allowed('stylepress');

        $user = auth()->guard('admin')->user();
        $allowedEmails = array_map('strtolower', array_filter((array) config('stylepress.access.allowed_emails', [])));
        $allowedUserIds = array_map('strval', array_filter((array) config('stylepress.access.allowed_user_ids', [])));

        if ($allowedEmails === [] && $allowedUserIds === []) {
            return;
        }

        $email = strtolower((string) ($user->email ?? ''));
        $id = (string) ($user->id ?? $user->admin_id ?? '');
        abort_unless(in_array($email, $allowedEmails, true) || in_array($id, $allowedUserIds, true), 403);
    }

    public function permits(): bool
    {
        try {
            $this->authorize();
        } catch (HttpException) {
            return false;
        }

        return true;
    }
}
