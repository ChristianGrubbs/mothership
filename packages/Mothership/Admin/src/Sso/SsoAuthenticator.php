<?php

namespace Mothership\Admin\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mothership\Admin\Access\AccessGrantor;
use Mothership\Admin\Access\AccessRequestRecorder;
use Mothership\Admin\Contracts\SsoProvider;
use Mothership\Admin\Exceptions\SsoAccessPendingException;
use Mothership\Admin\Exceptions\SsoAuthenticationException;
use Mothership\User\Contracts\Admin;
use Mothership\User\Models\AdminIdentity;

class SsoAuthenticator
{
    public function __construct(
        protected readonly AccessRequestRecorder $accessRequests,
        protected readonly AccessGrantor $accessGrantor,
    ) {}

    /**
     * Log the resolved identity in against the admin it is linked to. An identity linked to no
     * admin opens a session only by redeeming a preapproval for its verified email. Otherwise it
     * is parked as an access request, which an administrator approves on Settings > Users or with
     * mothership:access:grant.
     *
     * @throws SsoAuthenticationException
     */
    public function authenticate(SsoIdentity $identity, SsoProvider $provider, Request $request, bool $remember = false): Admin
    {
        Event::dispatch('mothership.admin.sso.identity.resolved', [$identity, $provider]);

        // Looked up again after a failed redemption: a second callback for this account may have just redeemed it.
        $link = $this->linkedIdentity(identity: $identity, provider: $provider)
            ?? $this->accessGrantor->redeemPreapproval(identity: $identity, provider: $provider->getCode())
            ?? $this->linkedIdentity(identity: $identity, provider: $provider);

        $admin = $link?->admin;

        if (! $admin) {
            $accessRequest = $this->accessRequests->record(identity: $identity, provider: $provider, request: $request);

            throw new SsoAccessPendingException(requestId: $accessRequest->id, email: $identity->email);
        }

        if ($admin->isApiUser()) {
            throw SsoAuthenticationException::rejected($identity->email);
        }

        if (! $admin->status) {
            throw SsoAuthenticationException::inactive($identity->email);
        }

        Event::dispatch('mothership.admin.sso.login.before', [$admin, $provider]);

        auth()->guard('admin')->login($admin, remember: $remember);

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $link->forceFill(['last_used_at' => now()])->save();

        Event::dispatch('mothership.admin.sso.login.after', [$admin, $provider]);

        return $admin;
    }

    private function linkedIdentity(SsoIdentity $identity, SsoProvider $provider): ?AdminIdentity
    {
        return AdminIdentity::query()
            ->with('admin')
            ->where('provider', $provider->getCode())
            ->where('subject', $identity->identifier)
            ->first();
    }
}
