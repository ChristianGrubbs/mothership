<?php

namespace Mothership\Admin\Tests\Support;

use Mothership\Admin\Sso\AbstractOAuthProvider;
use Mothership\Admin\Sso\SsoIdentity;
use Mothership\Admin\Sso\SsoToken;

class FakeSsoProvider extends AbstractOAuthProvider
{
    public static bool $enabled = true;

    public static ?string $email = 'fake-sso-user@example.com';

    public function getCode(): string
    {
        return 'fake';
    }

    public function getLabel(): string
    {
        return 'Sign in with Fake';
    }

    public function isEnabled(): bool
    {
        return static::$enabled;
    }

    protected function buildAuthorizationUrl(string $state, string $codeChallenge, string $nonce): string
    {
        return 'https://fake-idp.test/authorize?state='.$state.'&code_challenge='.$codeChallenge;
    }

    protected function exchangeCodeForToken(string $authorizationCode, string $codeVerifier): ?SsoToken
    {
        return new SsoToken(accessToken: 'fake-access-token');
    }

    protected function fetchIdentity(SsoToken $token): ?SsoIdentity
    {
        return static::$email === null
            ? null
            : new SsoIdentity(identifier: 'fake-subject-'.static::$email, email: static::$email, emailVerified: true);
    }
}
