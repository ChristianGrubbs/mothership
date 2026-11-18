<?php

namespace Mothership\Admin\Sso;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Mothership\Admin\Contracts\SsoProvider;
use Throwable;

/**
 * Adapts a Laravel Socialite driver to the SsoProvider contract, so every provider still signs
 * in through SsoAuthenticator. Socialite keeps the CSRF state (and the PKCE verifier) in the
 * session and refuses a callback whose state does not match.
 */
abstract class SocialiteDriverProvider implements SsoProvider
{
    /**
     * The Socialite driver name, which is also the `services.<name>` config key.
     */
    abstract protected function driverName(): string;

    /**
     * Whether the provider vouches for the address on this user.
     */
    abstract protected function emailVerified(SocialiteUser $user): bool;

    public function getIconView(): ?string
    {
        return null;
    }

    public function getSort(): int
    {
        return 0;
    }

    public function isEnabled(): bool
    {
        $config = (array) config('services.'.$this->driverName(), []);

        return (bool) ($config['enabled'] ?? false)
            && (string) ($config['client_id'] ?? '') !== ''
            && (string) ($config['client_secret'] ?? '') !== '';
    }

    public function getRedirectUrl(): string
    {
        return route('admin.session.sso.redirect', ['provider' => $this->getCode()]);
    }

    public function getCallbackUrl(): string
    {
        return route('admin.session.sso.callback', ['provider' => $this->getCode()]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->away($this->driver()->redirect()->getTargetUrl());
    }

    public function resolveIdentity(Request $request): ?SsoIdentity
    {
        try {
            $user = $this->driver()->user();
        } catch (Throwable $exception) {
            // A bad state, a refused code exchange or a provider outage all end the same way: no identity.
            report($exception);

            return null;
        }

        $identifier = (string) $user->getId();

        $email = (string) $user->getEmail();

        if ($identifier === '' || $email === '') {
            return null;
        }

        return new SsoIdentity(
            identifier: $identifier,
            email: $email,
            name: $user->getName() ?: null,
            raw: method_exists($user, 'getRaw') ? (array) $user->getRaw() : [],
            emailVerified: $this->emailVerified($user),
        );
    }

    /**
     * The configured driver, pointed at this app's callback route whatever `services.*.redirect` says.
     */
    protected function driver(): SocialiteProvider
    {
        /** @var AbstractProvider $driver Socialite's OAuth 2 drivers (or its test fake, which forwards to one). */
        $driver = Socialite::driver($this->driverName());

        return $driver->redirectUrl($this->getCallbackUrl())->enablePKCE();
    }
}
