<?php

namespace Mothership\Admin\Sso;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Sign in with Google (OpenID Connect through Laravel Socialite). Switched on by
 * GOOGLE_SSO_ENABLED plus a client id and secret from a Google Cloud "Web application"
 * OAuth client whose redirect URI is this provider's callback route.
 */
class GoogleProvider extends SocialiteDriverProvider
{
    public function getCode(): string
    {
        return 'google';
    }

    public function getLabel(): string
    {
        return trans('admin::app.users.sessions.sso-sign-in-with-google');
    }

    public function getIconView(): ?string
    {
        return 'admin::components.sso.icons.google';
    }

    protected function driverName(): string
    {
        return 'google';
    }

    /**
     * Google's userinfo answer carries `email_verified`; Socialite also copies it to `verified_email`.
     */
    protected function emailVerified(SocialiteUser $user): bool
    {
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];

        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return $verified === true || $verified === 'true';
    }
}
