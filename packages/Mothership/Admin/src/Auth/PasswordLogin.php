<?php

namespace Mothership\Admin\Auth;

use Mothership\Admin\Sso\SsoManager;

/**
 * Whether email-and-password sign-in is offered (MOTHERSHIP_PASSWORD_LOGIN). With it off, the sign-in
 * page shows only provider buttons and the password routes (sign-in POST, forgot and reset password,
 * invite acceptance) answer 404. It stays on while no provider sign-in is enabled, so turning the flag
 * off before a provider is configured cannot leave the sign-in page empty.
 */
class PasswordLogin
{
    public function __construct(protected readonly SsoManager $ssoManager) {}

    public function enabled(): bool
    {
        return (bool) config('admin.auth.password_login', true) || $this->ssoManager->enabled()->isEmpty();
    }
}
