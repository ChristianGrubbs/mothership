<?php

namespace Mothership\Admin\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Mothership\Admin\Auth\PasswordLogin;
use Mothership\Admin\Http\Controllers\Controller;
use Mothership\Admin\Http\Middleware\EnsurePimEnabled;
use Mothership\Admin\Sso\SsoManager;
use Mothership\Admin\Traits\ResolvesLandingUrl;

class SessionController extends Controller
{
    use ResolvesLandingUrl;

    public function __construct(
        protected readonly SsoManager $ssoManager,
        protected readonly PasswordLogin $passwordLogin,
    ) {}

    /**
     * Show the form for creating a new resource.
     *
     * @return View
     */
    public function create(): View|RedirectResponse
    {
        if (auth()->guard('admin')->check()) {
            return redirect()->to($this->firstAllowedUrl());
        }

        $this->discardDisabledPimIntendedUrl();

        $previous = url()->previous();
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $previousHost = parse_url($previous, PHP_URL_HOST);

        if ($previousHost === $appHost && str_contains($previous, 'admin')) {
            $intendedUrl = $previous;
        } else {
            $intendedUrl = null;
        }

        if ($intendedUrl && ! $this->isDisabledPimUrl($intendedUrl)) {
            session()->put('url.intended', $intendedUrl);
        }

        return view('admin::users.sessions.create', [
            'ssoProviders'  => $this->ssoManager->enabled(),
            'passwordLogin' => $this->passwordLogin->enabled(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse|JsonResponse
    {
        $this->validate(request(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $wantsJson = request()->wantsJson();

        $remember = request()->boolean('remember');

        if (! auth()->guard('admin')->attempt(request(['email', 'password']), $remember)) {
            $message = trans('admin::app.settings.users.login-error');

            if ($wantsJson) {
                return response()->json(['message' => $message], 401);
            }

            session()->flash('error', $message);

            return redirect()->route('admin.session.create')->withInput(request()->only('email'));
        }

        if (auth()->guard('admin')->user()->isApiUser()) {
            auth()->guard('admin')->logout();

            $message = trans('admin::app.settings.users.login-error');

            if ($wantsJson) {
                return response()->json(['message' => $message], 401);
            }

            session()->flash('error', $message);

            return redirect()->route('admin.session.create')->withInput(request()->only('email'));
        }

        if (! auth()->guard('admin')->user()->status) {
            auth()->guard('admin')->logout();

            $message = trans('admin::app.settings.users.activate-warning');

            if ($wantsJson) {
                return response()->json(['type' => 'warning', 'message' => $message], 403);
            }

            session()->flash('warning', $message);

            return redirect()->route('admin.session.create')->withInput(request()->only('email'));
        }

        $redirectUrl = $this->pullAllowedIntendedUrl();

        if ($wantsJson) {
            return response()->json([
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect()->to($redirectUrl);
    }

    /**
     * A GET to the logout URL (typed, bookmarked or reopened). Logging out stays a DELETE with a
     * CSRF token, so this only sends a signed-in admin Home. It must not use firstAllowedUrl(),
     * whose no-reachable-route fallback logs the admin out.
     */
    public function showLogout(): RedirectResponse
    {
        return redirect()->to(Route::has('admin.home.index') ? route('admin.home.index') : url(config('app.admin_url')));
    }

    /**
     * The current CSRF token, for the session notice in the admin bundle. Reaching it at all proves the browser
     * is signed in (the `admin` middleware answers 401 otherwise), so a page rendered before the session
     * expired can submit its forms again without a reload.
     */
    public function token(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()]);
    }

    /**
     * Log the admin out and retire the session id. The file and database session handlers do not
     * lock, so a request still in flight on the old id (a DAM thumbnail) writes its signed-in payload
     * back after logout; a new id leaves that write on a session no browser holds.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $guard = auth()->guard('admin');
        if ($request->cookies->has($guard->getRecallerName())) {
            $guard->logout();
        } else {
            // This device holds no persistent credential to revoke. Leave the user's remembered devices signed in.
            $guard->logoutCurrentDevice();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.session.create');
    }

    /**
     * Pull the intended URL only when its matched route is available in the
     * current surface. This prevents both HTML and JSON logins from bouncing an
     * admin straight back into a disabled PIM route.
     */
    protected function pullAllowedIntendedUrl(): string
    {
        $intendedUrl = session()->pull('url.intended');

        if (! is_string($intendedUrl) || $this->isDisabledPimUrl($intendedUrl)) {
            return $this->firstAllowedUrl();
        }

        return $intendedUrl;
    }

    /**
     * SSO starts from the same login page, so discard a stale or newly captured
     * PIM destination before handing the browser to the identity provider.
     */
    protected function discardDisabledPimIntendedUrl(): void
    {
        if ($this->isDisabledPimUrl(session('url.intended'))) {
            session()->forget('url.intended');
        }
    }

    protected function isDisabledPimUrl(mixed $url): bool
    {
        if (config('mothership.pim_enabled', false) || ! is_string($url) || $url === '') {
            return false;
        }

        $intendedHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($intendedHost && $appHost && strcasecmp($intendedHost, $appHost) !== 0) {
            return false;
        }

        try {
            $matchedRoute = Route::getRoutes()->match(Request::create($url, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        return collect($matchedRoute->gatherMiddleware())->contains(
            fn ($middleware): bool => is_string($middleware)
                && $middleware === EnsurePimEnabled::class
        );
    }
}
