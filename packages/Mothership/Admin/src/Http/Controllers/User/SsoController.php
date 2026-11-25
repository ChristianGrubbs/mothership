<?php

namespace Mothership\Admin\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Mothership\Admin\Contracts\SsoProvider;
use Mothership\Admin\Exceptions\SsoAccessPendingException;
use Mothership\Admin\Exceptions\SsoAuthenticationException;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Sso\SsoAuthenticator;
use Mothership\Admin\Sso\SsoManager;
use Mothership\Admin\Traits\ResolvesLandingUrl;

class SsoController extends Controller
{
    use ResolvesLandingUrl;

    public function __construct(
        protected readonly SsoManager $ssoManager,
        protected readonly SsoAuthenticator $authenticator,
    ) {}

    /**
     * Hand the visitor off to the identity provider.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->resolveProvider($provider);

        if (! $driver) {
            return redirect()->route('admin.session.create');
        }

        $request->session()->put("sso_remember.{$provider}", $request->boolean('remember'));

        return $driver->redirect($request);
    }

    /**
     * Handle the identity provider callback and open an admin session.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $driver = $this->resolveProvider($provider);

        if (! $driver) {
            return redirect()->route('admin.session.create');
        }

        $remember = $request->session()->pull("sso_remember.{$provider}", false);
        $identity = $driver->resolveIdentity($request);

        if (! $identity) {
            Event::dispatch('mothership.admin.sso.login.failed', [null, $driver, 'no_identity']);

            session()->flash('error', trans('admin::app.settings.users.login-error'));

            return redirect()->route('admin.session.create');
        }

        try {
            $this->authenticator->authenticate(identity: $identity, provider: $driver, request: $request, remember: $remember);
        } catch (SsoAccessPendingException $exception) {
            Event::dispatch('mothership.admin.sso.login.failed', [$exception->email, $driver, 'pending']);

            $request->session()->put('sso_access_request_id', $exception->requestId);

            return redirect()->route('admin.session.sso.pending');
        } catch (SsoAuthenticationException $exception) {
            Event::dispatch('mothership.admin.sso.login.failed', [
                $exception->email, $driver, $exception->flashType === 'warning' ? 'inactive' : 'rejected',
            ]);

            session()->flash($exception->flashType, $exception->getMessage());

            return redirect()->route('admin.session.create')->withInput(['email' => $exception->email]);
        }

        return redirect()->intended($this->firstAllowedUrl());
    }

    /**
     * Tell a provider account with no admin that it is waiting for the owner to approve it. Only the
     * request id is shown, never the account's email or name, and never a command that grants access.
     */
    public function pending(Request $request): View|RedirectResponse
    {
        $requestId = $request->session()->get('sso_access_request_id');

        if (! is_int($requestId)) {
            return redirect()->route('admin.session.create');
        }

        return view('admin::users.sessions.pending', [
            'requestId' => $requestId,
            'ttlHours'  => max(1, (int) config('admin.auth.access_requests.ttl_hours', 24)),
        ]);
    }

    protected function resolveProvider(string $provider): ?SsoProvider
    {
        return $this->ssoManager->getEnabled($provider);
    }
}
