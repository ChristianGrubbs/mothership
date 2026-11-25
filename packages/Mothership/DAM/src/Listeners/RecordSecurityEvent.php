<?php

declare(strict_types=1);

namespace Mothership\DAM\Listeners;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Mothership\Admin\Contracts\SsoProvider;
use Mothership\DAM\Services\Security\SecurityEvent;
use Mothership\DAM\Services\Security\SecurityEventRecorder;

/**
 * Laravel's auth events, plus the provider sign-in path (Mothership\Admin\Sso, Google today), as
 * dam_security_events rows. The recorder never throws, so none of these can fail a login or a logout.
 */
class RecordSecurityEvent
{
    /** Request attribute set while an SSO callback logs an admin in, so the Login row says how. */
    private const SSO_PROVIDER = 'dam_security_sso_provider';

    /** The SSO Login row, held until SsoAuthenticator has regenerated the session for the last time. */
    private const SSO_PENDING = 'dam_security_sso_pending_login';

    public function __construct(private readonly SecurityEventRecorder $recorder) {}

    public function login(Login $event): void
    {
        $provider = request()->attributes->get(self::SSO_PROVIDER);
        $method = $provider === null ? 'password' : 'sso';
        if ($event->guard === 'admin' && auth('admin')->viaRemember()) {
            $method = 'remember';
        }
        $row = new SecurityEvent(
            name: SecurityEvent::LOGIN_SUCCEEDED,
            guard: $event->guard,
            actorAdminId: $this->actorId(user: $event->user),
            identifier: $this->loginOf(user: $event->user),
            ctx: ['method' => $method, 'provider' => $provider, 'remember' => (bool) $event->remember],
        );

        // SsoAuthenticator regenerates the session again after login(): recorded now, this row would carry a
        // session id the admin never uses, and one SSO session would count as two (P1a review).
        if ($provider !== null) {
            request()->attributes->set(self::SSO_PENDING, $row);

            return;
        }

        $this->recorder->record($row);
    }

    /** `mothership.admin.sso.login.after`: the session is final, so the held SSO Login row is recorded now. */
    public function ssoLoginCompleted(mixed $admin, SsoProvider $provider): void
    {
        $row = request()->attributes->get(self::SSO_PENDING);
        request()->attributes->remove(self::SSO_PENDING);
        request()->attributes->remove(self::SSO_PROVIDER);

        if ($row instanceof SecurityEvent) {
            $this->recorder->record($row);
        }
    }

    public function failed(Failed $event): void
    {
        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::LOGIN_FAILED,
            guard: $event->guard,
            identifier: is_string($event->credentials['email'] ?? null) ? $event->credentials['email'] : null,
            ctx: ['method' => 'password', 'account_exists' => $event->user !== null],
        ));
    }

    public function lockout(Lockout $event): void
    {
        $email = $event->request->input('email');

        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::LOGIN_LOCKED_OUT,
            guard: $event->request->routeIs('admin.session.*') ? 'admin' : null,
            identifier: is_string($email) ? $email : null,
            ctx: ['method' => 'password'],
        ));
    }

    public function logout(Logout|CurrentDeviceLogout $event): void
    {
        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::LOGOUT_SUCCEEDED,
            guard: $event->guard,
            actorAdminId: $this->actorId(user: $event->user),
            identifier: $this->loginOf(user: $event->user),
        ));
    }

    public function passwordReset(PasswordReset $event): void
    {
        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::PASSWORD_RESET,
            actorAdminId: $this->actorId(user: $event->user),
            identifier: $this->loginOf(user: $event->user),
        ));
    }

    /** `mothership.admin.sso.login.before`: the next Login on this request came through $provider. */
    public function ssoLoginStarting(mixed $admin, SsoProvider $provider): void
    {
        request()->attributes->set(self::SSO_PROVIDER, $provider->getCode());
    }

    /** `mothership.admin.sso.login.failed`: the identity provider answered, but no admin session was opened. */
    public function ssoLoginFailed(?string $email, SsoProvider $provider, string $reason): void
    {
        $this->recorder->record(new SecurityEvent(
            name: SecurityEvent::LOGIN_FAILED,
            guard: 'admin',
            identifier: $email,
            ctx: ['method' => 'sso', 'provider' => $provider->getCode(), 'reason' => $reason],
        ));
    }

    private function actorId(?Authenticatable $user): ?int
    {
        $id = $user?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    private function loginOf(?Authenticatable $user): ?string
    {
        $email = $user instanceof Model ? $user->getAttribute('email') : null;

        return is_string($email) ? $email : null;
    }
}
